<?php
/**
 * SMS Batch İşleyici
 * AJAX çağrılarıyla çalışır ve belirlenen sayıda SMS'i işler
 */
header('Content-Type: application/json');
session_start();
require_once 'database.php';

$response = [
    'success' => false,
    'processed' => 0,
    'success' => 0,
    'failed' => 0,
    'pending' => 0,
    'sent' => 0,
    'failed_total' => 0,
    'logs' => []
];

// İstek kontrolleri
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['error'] = 'Geçersiz istek yöntemi.';
    echo json_encode($response);
    exit;
}

$batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 5;
$success_status = isset($_POST['success_status']) ? trim($_POST['success_status']) : 'Executed';

// Veritabanı bağlantısı
try {
    $database = new Database();
    $db = $database->connect();
} catch (Exception $e) {
    $response['error'] = 'Veritabanı bağlantı hatası: ' . $e->getMessage();
    echo json_encode($response);
    exit;
}

// API ayarlarını getir
try {
    $query = "SELECT * FROM api_settings LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $api_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$api_settings || empty($api_settings['login_url'])) {
        $response['error'] = 'API ayarları bulunamadı.';
        echo json_encode($response);
        exit;
    }
} catch (PDOException $e) {
    $response['error'] = 'API ayarları alınamadı: ' . $e->getMessage();
    echo json_encode($response);
    exit;
}

// En fazla batch_size kadar bekleyen SMS'i getir
try {
    $query = "SELECT sq.id, sq.message, sq.bot_id as bot_db_id, p.phone, p.name, b.bot_id 
              FROM sms_queue sq 
              JOIN phones p ON sq.phone_id = p.id 
              JOIN bots b ON sq.bot_id = b.id 
              WHERE sq.status = 'pending' 
              LIMIT :batch_size";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':batch_size', $batch_size, PDO::PARAM_INT);
    $stmt->execute();
    $sms_batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($sms_batch) === 0) {
        $response['logs'][] = "İşlenecek SMS bulunamadı.";
        $response['success'] = true;
        
        // İstatistikleri güncelle
        updateStats($db, $response);
        
        echo json_encode($response);
        exit;
    }
    
    // API ayarlarını al
    $login_url = $api_settings['login_url'];
    $username = $api_settings['username'];
    $password = $api_settings['password'];
    
    // Çerez dosyası
    $cookie_file = dirname(__FILE__) . "/cookies.txt";
    
    // URL'den base URL ve path bilgilerini çıkar
    $url_parts = parse_url($login_url);
    $base_url = $url_parts['scheme'] . '://' . $url_parts['host'];
    $path = isset($url_parts['path']) ? $url_parts['path'] : '';
    $path = rtrim(dirname($path), '/');
    
    $response['logs'][] = "İşlenecek SMS sayısı: " . count($sms_batch);
    
    // Her bir SMS için
    foreach ($sms_batch as $sms) {
        $queue_id = $sms['id'];
        $phone = $sms['phone'];
        $message = $sms['message'];
        $bot_id = $sms['bot_id'];
        
        $response['logs'][] = "SMS #" . $queue_id . " işleniyor: " . $phone . " numarasına...";
        
        // cURL başlat
        $ch = curl_init();
        
        // SSL sertifika doğrulamasını devre dışı bırak
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Basic Authentication kullan
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        
        // SMS sayfası URL'si
        $sms_url = $base_url . $path . "/index.php?a=admin&action=bots&bbot_id=$bot_id&page=send_sms";
        
        // SMS sayfasını ziyaret et
        curl_setopt($ch, CURLOPT_URL, $sms_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $sms_page = curl_exec($ch);
        $sms_page_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $response['processed']++;
        
        if ($sms_page_status >= 200 && $sms_page_status < 300) {
            // Varsayılan alan adları
            $phone_field_name = "phone";
            $text_field_name = "text";
            
            // Form alanlarını bul
            if (preg_match('/Number[:\s]*.*?<input[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $phone_matches)) {
                $phone_field_name = $phone_matches[1];
            }
            
            if (preg_match('/Text[:\s]*.*?<textarea[^>]*name=["\']([^"\']+)["\']/is', $sms_page, $text_matches)) {
                $text_field_name = $text_matches[1];
            }
            
            // Form action
            $form_action = $sms_url;
            
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
            curl_setopt($ch, CURLOPT_URL, $form_action);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Content-Type: application/x-www-form-urlencoded',
                'Referer: ' . $sms_url
            ]);
            
            $sms_response = curl_exec($ch);
            $sms_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // Görevler sayfası URL'si (send_id ve status'u almak için)
            $tasks_url = $base_url . $path . "/index.php?a=admin&action=bots&bbot_id=$bot_id&page=tasks";
            
            // 3 saniye bekle
            sleep(2);
            
            // Görevler sayfasını ziyaret et
            curl_setopt($ch, CURLOPT_URL, $tasks_url);
            curl_setopt($ch, CURLOPT_POST, false);
            
            $tasks_response = curl_exec($ch);
            $tasks_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            $send_id = null;
            $status = 'unknown';
            
            // Görevler tablosunda mesajımızı bul
            $phone_pattern = preg_quote($phone, '/');
            if (preg_match('/<tr[^>]*>.*?' . $phone_pattern . '\|' . preg_quote($message, '/') . '.*?<\/tr>/is', $tasks_response, $task_row)) {
                // Send ID ve status bilgilerini çıkar
                if (preg_match('/<td[^>]*>(\d+)<\/td>/i', $task_row[0], $id_match)) {
                    $send_id = $id_match[1];
                }
                
                // Status için hem eski hem yeni yöntemi dene
                if (preg_match('/<td[^>]*>([^<]+)<\/td>\s*<td[^>]*>([^<]+)<\/td>/i', $task_row[0], $status_match)) {
                    $status = trim($status_match[2]);
                }
            }
            
            // Log tablosuna kaydet
            $query = "INSERT INTO sms_logs (queue_id, bot_id, phone, message, send_id, status, response_data) 
                      VALUES (:queue_id, :bot_id, :phone, :message, :send_id, :status, :response_data)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':queue_id', $queue_id);
            $stmt->bindParam(':bot_id', $bot_id);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':message', $message);
            $stmt->bindParam(':send_id', $send_id);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':response_data', $tasks_response);
            $stmt->execute();
            
            // SMS_queue tablosunu güncelle - Başarı kontrolü
            // Status, belirtilen başarılı değere eşit mi kontrol et
            $new_status = 'failed';
            if ($status == $success_status || 
                stripos($status, $success_status) !== false || 
                stripos($status, 'success') !== false || 
                stripos($status, 'sent') !== false || 
                stripos($status, 'completed') !== false) {
                
                $new_status = 'sent';
                $response['success']++;
                $response['logs'][] = "SMS #" . $queue_id . " BAŞARILI gönderildi! Status: " . $status;
            } else {
                $response['failed']++;
                $response['logs'][] = "SMS #" . $queue_id . " BAŞARISIZ! Status: " . $status;
            }
            
            $query = "UPDATE sms_queue SET status = :status WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $new_status);
            $stmt->bindParam(':id', $queue_id);
            $stmt->execute();
            
        } else {
            $response['logs'][] = "SMS #" . $queue_id . " - Sayfaya erişim başarısız! HTTP Kodu: " . $sms_page_status;
            
            // SMS_queue tablosunu başarısız olarak güncelle
            $new_status = 'failed';
            $response['failed']++;
            
            $query = "UPDATE sms_queue SET status = :status WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $new_status);
            $stmt->bindParam(':id', $queue_id);
            $stmt->execute();
            
            // Log tablosuna kaydet
            $query = "INSERT INTO sms_logs (queue_id, bot_id, phone, message, status, response_data) 
                      VALUES (:queue_id, :bot_id, :phone, :message, 'failed', :response_data)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':queue_id', $queue_id);
            $stmt->bindParam(':bot_id', $bot_id);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':message', $message);
            $response_data = "HTTP Status: " . $sms_page_status;
            $stmt->bindParam(':response_data', $response_data);
            $stmt->execute();
        }
        
        curl_close($ch);
        
        // Sunucuya yük bindirmemek için kısa bir bekleme (0.5 sn)
        usleep(500000);
    }
    
    $response['success'] = true;
    $response['logs'][] = "Toplam " . $response['processed'] . " SMS işlendi. Başarılı: " . $response['success'] . ", Başarısız: " . $response['failed'];
    
    // İstatistikleri güncelle
    updateStats($db, $response);
    
} catch (PDOException $e) {
    $response['error'] = 'Veritabanı hatası: ' . $e->getMessage();
}

// İstatistik bilgilerini almak için yardımcı fonksiyon
function updateStats($db, &$response) {
    try {
        $query = "SELECT 
                    (SELECT COUNT(*) FROM sms_queue WHERE status = 'pending') as pending,
                    (SELECT COUNT(*) FROM sms_queue WHERE status = 'sent') as sent,
                    (SELECT COUNT(*) FROM sms_queue WHERE status = 'failed') as failed";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $response['pending'] = (int)$stats['pending'];
        $response['sent'] = (int)$stats['sent'];
        $response['failed_total'] = (int)$stats['failed'];
    } catch (PDOException $e) {
        $response['error'] = 'İstatistik hatası: ' . $e->getMessage();
    }
}

echo json_encode($response);