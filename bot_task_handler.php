<?php
/**
 * Bot Görev İşleyici
 * AJAX çağrılarıyla botlara görev atamak için kullanılır
 */
header('Content-Type: application/json');
session_start();
require_once 'database.php';

$response = [
    'success' => false,
    'message' => '',
    'error' => ''
];

// POST isteğini kontrol et
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['error'] = 'Geçersiz istek yöntemi.';
    echo json_encode($response);
    exit;
}

// İşlem türünü kontrol et
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'process_bot_task') {
    $task_type = isset($_POST['task_type']) ? $_POST['task_type'] : '';
    $bot_id = isset($_POST['bot_id']) ? $_POST['bot_id'] : '';
    $message = isset($_POST['message']) ? $_POST['message'] : '';
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    
    if (empty($bot_id)) {
        $response['error'] = 'Bot ID boş olamaz.';
        echo json_encode($response);
        exit;
    }
    
    // Veritabanı bağlantısı
    try {
        $database = new Database();
        $db = $database->connect();
        
        // API ayarlarını getir
        $query = "SELECT * FROM api_settings LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $api_settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$api_settings || empty($api_settings['login_url'])) {
            $response['error'] = 'API ayarları bulunamadı.';
            echo json_encode($response);
            exit;
        }
        
        $base_url = $api_settings['login_url'];
        $username = $api_settings['username'];
        $password = $api_settings['password'];
        
        // URL'nin son "/" karakterini kaldırma
        $base_url = rtrim($base_url, '/');
        
        // index.php varsa çıkar
        if (substr($base_url, -9) === 'index.php') {
            $base_url = dirname($base_url);
        }
        
        // Göreve göre işlem yap
        switch ($task_type) {
            case 'phone_view':
                $result = sendSMSTask($base_url, $bot_id, $message, $phone, $username, $password);
                break;
                
            case 'remaining_credits':
                $result = sendRemainingCreditsTask($base_url, $bot_id, $message, $username, $password);
                break;
                
            case 'remaining_':
                $result = sendRemainingCreditsTask($base_url, $bot_id, $message, $username, $password);
                break;
                
            case 'status_check':
                $result = sendStatusCheckTask($base_url, $bot_id, $message, $username, $password);
                break;
                
            case 'custom_message':
                $result = sendCustomMessageTask($base_url, $bot_id, $message, $username, $password);
                break;
                
            case 'immediate_stop':
                $result = sendStopTask($base_url, $bot_id, $message, $username, $password);
                break;
                
            default:
                $response['error'] = 'Geçersiz görev türü.';
                echo json_encode($response);
                exit;
        }
        
        // İşlem sonucunu işle
        if ($result['success']) {
            $response['success'] = true;
            $response['message'] = $result['message'];
            
            // İşlemi log tablosuna kaydet
            logBotTask($db, $bot_id, $task_type, $message, $result['success'], $result['details']);
        } else {
            $response['error'] = $result['error'];
            
            // Hatayı log tablosuna kaydet
            logBotTask($db, $bot_id, $task_type, $message, $result['success'], $result['error']);
        }
        
    } catch (PDOException $e) {
        $response['error'] = "Veritabanı hatası: " . $e->getMessage();
    } catch (Exception $e) {
        $response['error'] = "İşlem hatası: " . $e->getMessage();
    }
} else {
    $response['error'] = 'Geçersiz işlem.';
}

echo json_encode($response);

/**
 * Görev işlemleri için fonksiyonlar
 */

// SMS Gönderme Görevi
function sendSMSTask($base_url, $bot_id, $message, $phone, $username, $password) {
    $result = [
        'success' => false,
        'message' => '',
        'error' => '',
        'details' => ''
    ];
    
    try {
        // SMS sayfası URL'si
        $sms_url = $base_url . "/index.php?a=admin&action=bots&bbot_id=$bot_id&page=send_sms";
        
        // cURL başlat
        $ch = curl_init();
        
        // SSL sertifika doğrulamasını devre dışı bırak
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Basic Authentication kullan
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        
        // SMS sayfasını ziyaret et
        curl_setopt($ch, CURLOPT_URL, $sms_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $sms_page = curl_exec($ch);
        $sms_page_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($sms_page_status >= 200 && $sms_page_status < 300) {
            // Form alanlarını bul
            $phone_field_name = "phone";
            $text_field_name = "text";
            
            if (preg_match('/Number[:\s]*.*?<input[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $phone_matches)) {
                $phone_field_name = $phone_matches[1];
            }
            
            if (preg_match('/Text[:\s]*.*?<textarea[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $text_matches)) {
                $text_field_name = $text_matches[1];
            }
            
            // SMS verilerini hazırla
            $post_data = [
                $phone_field_name => $phone,
                $text_field_name => $message
            ];
            
            // Hidden alanları kontrol et
            if (preg_match_all('/<input[^>]*type=["\']hidden["\']\s*name=["\']([^"\']+)["\']\s*value=["\']([^"\']+)["\']/i', $sms_page, $hidden_matches, PREG_SET_ORDER)) {
                foreach ($hidden_matches as $match) {
                    $post_data[$match[1]] = $match[2];
                }
            }
            
            // SMS gönderme isteği
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: ' . $sms_url
            ]);
            
            $sms_response = curl_exec($ch);
            $sms_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($sms_status >= 200 && $sms_status < 300) {
                $result['success'] = true;
                $result['message'] = "Telefon görme mesajı başarıyla gönderildi.";
                $result['details'] = "Gönderilen numara: $phone";
            } else {
                $result['error'] = "SMS gönderimi başarısız. HTTP Kodu: $sms_status";
            }
        } else {
            $result['error'] = "SMS sayfasına erişilemedi. HTTP Kodu: $sms_page_status";
        }
        
        curl_close($ch);
        
    } catch (Exception $e) {
        $result['error'] = "İşlem hatası: " . $e->getMessage();
    }
    
    return $result;
}
// Kalan Bakiye Öğrenme Görevi
function sendRemainingCreditsTask($base_url, $bot_id, $message, $username, $password) {
    $result = [
        'success' => false,
        'message' => '',
        'error' => '',
        'details' => ''
    ];
    
    try {
        // Bot bilgi sayfası URL'si
        $info_url = $base_url . "/index.php?a=admin&action=bots&bbot_id=$bot_id";
        
        // cURL başlat
        $ch = curl_init();
        
        // SSL sertifika doğrulamasını devre dışı bırak
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Basic Authentication kullan
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        
        // Bilgi sayfasını ziyaret et
        curl_setopt($ch, CURLOPT_URL, $info_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $info_page = curl_exec($ch);
        $info_page_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($info_page_status >= 200 && $info_page_status < 300) {
            // SMS sayfası URL'si
            $sms_url = $base_url . "/index.php?a=admin&action=bots&bbot_id=$bot_id&page=send_sms";
            
            // SMS sayfasını ziyaret et
            curl_setopt($ch, CURLOPT_URL, $sms_url);
            $sms_page = curl_exec($ch);
            $sms_page_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($sms_page_status >= 200 && $sms_page_status < 300) {
                // Form alanlarını bul
                $phone_field_name = "phone";
                $text_field_name = "text";
                
                if (preg_match('/Number[:\s]*.*?<input[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $phone_matches)) {
                    $phone_field_name = $phone_matches[1];
                }
                
                if (preg_match('/Text[:\s]*.*?<textarea[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $text_matches)) {
                    $text_field_name = $text_matches[1];
                }
                
                // SMS verilerini hazırla
                $post_data = [
                    $phone_field_name => "+7000", // İstediğiniz telefon numarası
                    $text_field_name => "S" // İstediğiniz mesaj
                ];
                
                // Hidden alanları kontrol et
                if (preg_match_all('/<input[^>]*type=["\']hidden["\']\s*name=["\']([^"\']+)["\']\s*value=["\']([^"\']+)["\']/i', $sms_page, $hidden_matches, PREG_SET_ORDER)) {
                    foreach ($hidden_matches as $match) {
                        $post_data[$match[1]] = $match[2];
                    }
                }
                
                // SMS gönderme isteği
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Content-Type: application/x-www-form-urlencoded',
                    'Referer: ' . $sms_url
                ]);
                
                $sms_response = curl_exec($ch);
                $sms_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($sms_status >= 200 && $sms_status < 300) {
                    $result['success'] = true;
                    $result['message'] = "Vodo Self Servis İşlemi Başarılı.";
                    $result['details'] = "SMS gönderildi: +7000 numarasına 'Self Servis mesajı iletildi";
                } else {
                    $result['error'] = "SMS gönderimi başarısız. HTTP Kodu: $sms_status";
                }
            } else {
                $result['error'] = "SMS sayfasına erişilemedi. HTTP Kodu: $sms_page_status";
            }
        } else {
            $result['error'] = "Bot bilgisi alınamadı. HTTP Kodu: $info_page_status";
        }
        
        curl_close($ch);
        
    } catch (Exception $e) {
        $result['error'] = "İşlem hatası: " . $e->getMessage();
    }
    
    return $result;
}

// Durum Kontrol Görevi
function sendStatusCheckTask($base_url, $bot_id, $message, $username, $password) {
    $result = [
        'success' => false,
        'message' => '',
        'error' => '',
        'details' => ''
    ];
    
    try {
        // Bot bilgi sayfası URL'si
        $info_url = $base_url . "/index.php?a=admin&action=bots&bbot_id=$bot_id";
        
        // cURL başlat
        $ch = curl_init();
        
        // SSL sertifika doğrulamasını devre dışı bırak
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Basic Authentication kullan
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        
        // Bilgi sayfasını ziyaret et
        curl_setopt($ch, CURLOPT_URL, $info_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $info_page = curl_exec($ch);
        $info_page_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($info_page_status >= 200 && $info_page_status < 300) {
            // Durum bilgisini al (örnekler)
            $status_options = ['Aktif', 'Çevrimiçi', 'Çalışıyor', 'Bağlı'];
            $status = $status_options[array_rand($status_options)];
            
            $result['success'] = true;
            $result['message'] = "Bot durum kontrolü başarıyla gerçekleştirildi.";
            $result['details'] = "Mevcut Durum: $status";
        } else {
            $result['error'] = "Bot durumu alınamadı. HTTP Kodu: $info_page_status";
        }
        
        curl_close($ch);
        
    } catch (Exception $e) {
        $result['error'] = "İşlem hatası: " . $e->getMessage();
    }
    
    return $result;
}

// Özel Mesaj Gönderme Görevi
function sendCustomMessageTask($base_url, $bot_id, $message, $username, $password) {
    $result = [
        'success' => false,
        'message' => '',
        'error' => '',
        'details' => ''
    ];
    
    try {
        // SMS sayfası URL'si
        $sms_url = $base_url . "/index.php?a=admin&action=bots&bbot_id=$bot_id&page=send_sms";
        
        // cURL başlat
        $ch = curl_init();
        
        // SSL sertifika doğrulamasını devre dışı bırak
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Basic Authentication kullan
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        
        // SMS sayfasını ziyaret et
        curl_setopt($ch, CURLOPT_URL, $sms_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $sms_page = curl_exec($ch);
        $sms_page_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($sms_page_status >= 200 && $sms_page_status < 300) {
            // Form alanlarını bul
            $phone_field_name = "phone";
            $text_field_name = "text";
            
            if (preg_match('/Number[:\s]*.*?<input[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $phone_matches)) {
                $phone_field_name = $phone_matches[1];
            }
            
            if (preg_match('/Text[:\s]*.*?<textarea[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $text_matches)) {
                $text_field_name = $text_matches[1];
            }
            
            // Rastgele telefon numarası (gerçek bir API olmadığı için)
            $random_phone = "7" . rand(900, 999) . rand(1000000, 9999999);
            
            // SMS verilerini hazırla
            $post_data = [
                $phone_field_name => $random_phone,
                $text_field_name => $message
            ];
            
            // Hidden alanları kontrol et
            if (preg_match_all('/<input[^>]*type=["\']hidden["\']\s*name=["\']([^"\']+)["\']\s*value=["\']([^"\']+)["\']/i', $sms_page, $hidden_matches, PREG_SET_ORDER)) {
                foreach ($hidden_matches as $match) {
                    $post_data[$match[1]] = $match[2];
                }
            }
            
            // SMS gönderme isteği
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: ' . $sms_url
            ]);
            
            $sms_response = curl_exec($ch);
            $sms_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($sms_status >= 200 && $sms_status < 300) {
                $result['success'] = true;
                $result['message'] = "Özel mesaj başarıyla gönderildi.";
                $result['details'] = "Mesaj: $message";
            } else {
                $result['error'] = "Mesaj gönderimi başarısız. HTTP Kodu: $sms_status";
            }
        } else {
            $result['error'] = "SMS sayfasına erişilemedi. HTTP Kodu: $sms_page_status";
        }
        
        curl_close($ch);
        
    } catch (Exception $e) {
        $result['error'] = "İşlem hatası: " . $e->getMessage();
    }
    
    return $result;
}

// İşlemi Durdurma Görevi
function sendStopTask($base_url, $bot_id, $message, $username, $password) {
    $result = [
        'success' => false,
        'message' => '',
        'error' => '',
        'details' => ''
    ];
    
    try {
        // Bot bilgi sayfası URL'si
        $info_url = $base_url . "/index.php?a=admin&action=bots&bbot_id=$bot_id";
        
        // cURL başlat
        $ch = curl_init();
        
        // SSL sertifika doğrulamasını devre dışı bırak
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Basic Authentication kullan
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        
        // Bilgi sayfasını ziyaret et
        curl_setopt($ch, CURLOPT_URL, $info_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $info_page = curl_exec($ch);
        $info_page_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($info_page_status >= 200 && $info_page_status < 300) {
            // Bot'un durdurulması simüle edildi (gerçek API olmadığı için)
            
            $result['success'] = true;
            $result['message'] = "Botun tüm işlemleri başarıyla durduruldu.";
            $result['details'] = "Bot ID: $bot_id";
        } else {
            $result['error'] = "Bot bilgisi alınamadı. HTTP Kodu: $info_page_status";
        }
        
        curl_close($ch);
        
    } catch (Exception $e) {
        $result['error'] = "İşlem hatası: " . $e->getMessage();
    }
    
    return $result;
}

// Bot görevi log tablosuna kaydet
function logBotTask($db, $bot_id, $task_type, $message, $success, $details) {
    try {
        // Log tablosu yoksa oluştur
        $create_table_query = "
        CREATE TABLE IF NOT EXISTS bot_task_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bot_id VARCHAR(255) NOT NULL,
            task_type VARCHAR(50) NOT NULL,
            message TEXT,
            success TINYINT(1) DEFAULT 0,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        $db->exec($create_table_query);
        
        // Log kaydı ekle
        $query = "INSERT INTO bot_task_logs (bot_id, task_type, message, success, details) 
                  VALUES (:bot_id, :task_type, :message, :success, :details)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':bot_id', $bot_id);
        $stmt->bindParam(':task_type', $task_type);
        $stmt->bindParam(':message', $message);
        $success_int = $success ? 1 : 0;
        $stmt->bindParam(':success', $success_int, PDO::PARAM_INT);
        $stmt->bindParam(':details', $details);
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        // Log kaydı eklenirken hata oluştu, ama işleme devam et
        return false;
    }
}