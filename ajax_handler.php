<?php
/**
 * AJAX İşleyici
 * Ayrıştırılmış bot ID'lerini veritabanına eklemek için AJAX işlemlerini yönetir
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

if ($action === 'add_bots') {
    $bot_ids_text = isset($_POST['bot_ids']) ? $_POST['bot_ids'] : '';
    
    if (empty($bot_ids_text)) {
        $response['error'] = 'Bot ID\'leri boş olamaz.';
        echo json_encode($response);
        exit;
    }
    
    // Bot ID'lerini satır satır ayır
    $bot_ids = preg_split('/\r\n|\r|\n/', $bot_ids_text);
    $bot_ids = array_map('trim', $bot_ids);
    $bot_ids = array_filter($bot_ids);
    
    if (count($bot_ids) === 0) {
        $response['error'] = 'Geçerli bot ID bulunamadı.';
        echo json_encode($response);
        exit;
    }
    
    try {
        // Veritabanı bağlantısı
        $database = new Database();
        $db = $database->connect();
        
        $db->beginTransaction();
        $added_count = 0;
        $already_exists = 0;
        
        // INSERT IGNORE kullanarak var olan kayıtları atlıyoruz
        $query = "INSERT IGNORE INTO bots (bot_id) VALUES (:bot_id)";
        $stmt = $db->prepare($query);
        
        foreach ($bot_ids as $bot_id) {
            if (!empty($bot_id)) {
                $stmt->bindParam(':bot_id', $bot_id);
                if ($stmt->execute()) {
                    if ($stmt->rowCount() > 0) {
                        $added_count++;
                    } else {
                        $already_exists++;
                    }
                }
            }
        }
        
        $db->commit();
        
        $response['success'] = true;
        $response['message'] = "İşlem tamamlandı: $added_count yeni bot ID eklendi, $already_exists zaten mevcut.";
        
    } catch (PDOException $e) {
        if (isset($db)) {
            $db->rollBack();
        }
        $response['error'] = "Veritabanı hatası: " . $e->getMessage();
    }
} else {
    $response['error'] = 'Geçersiz işlem.';
}

echo json_encode($response);