<?php
session_start();
require_once 'database.php';

$database = new Database();
$db = $database->connect();

// Ayarları kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $login_url = trim($_POST['login_url']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Temel doğrulama
    if (empty($login_url) || empty($username) || empty($password)) {
        $error = "Tüm alanları doldurunuz.";
    } else {
        try {
            // Mevcut ayar var mı kontrol et
            $check_query = "SELECT id FROM api_settings LIMIT 1";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                // Güncelle
                $settings_id = $check_stmt->fetch(PDO::FETCH_ASSOC)['id'];
                $query = "UPDATE api_settings SET login_url = :login_url, username = :username, password = :password WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $settings_id);
            } else {
                // Yeni ekle
                $query = "INSERT INTO api_settings (login_url, username, password) VALUES (:login_url, :username, :password)";
                $stmt = $db->prepare($query);
            }
            
            $stmt->bindParam(':login_url', $login_url);
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $password);
            
            if ($stmt->execute()) {
                $success = "Ayarlar başarıyla kaydedildi.";
            } else {
                $error = "Ayarlar kaydedilirken bir hata oluştu.";
            }
        } catch (PDOException $e) {
            $error = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

// Bot ID'leri için
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bot'])) {
    $bot_id = trim($_POST['bot_id']);
    
    if (empty($bot_id)) {
        $bot_error = "Bot ID boş olamaz.";
    } else {
        try {
            $query = "INSERT INTO bots (bot_id) VALUES (:bot_id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':bot_id', $bot_id);
            
            if ($stmt->execute()) {
                $bot_success = "Bot başarıyla eklendi.";
            } else {
                $bot_error = "Bot eklenirken bir hata oluştu.";
            }
        } catch (PDOException $e) {
            $bot_error = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

// Çoklu Bot Ekleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_multi_bots'])) {
    $multi_bot_ids = trim($_POST['multi_bot_ids']);
    
    if (empty($multi_bot_ids)) {
        $bot_error = "Bot ID'leri boş olamaz.";
    } else {
        // Satır satır ayır
        $bot_lines = preg_split('/\r\n|\r|\n/', $multi_bot_ids);
        $bot_lines = array_map('trim', $bot_lines);
        $bot_lines = array_filter($bot_lines); // Boş satırları kaldır
        
        if (count($bot_lines) === 0) {
            $bot_error = "Geçerli Bot ID bulunamadı.";
        } else {
            try {
                $db->beginTransaction();
                
                $success_count = 0;
                $query = "INSERT INTO bots (bot_id) VALUES (:bot_id)";
                $stmt = $db->prepare($query);
                
                foreach ($bot_lines as $bot_id) {
                    if (!empty($bot_id)) {
                        $stmt->bindParam(':bot_id', $bot_id);
                        if ($stmt->execute()) {
                            $success_count++;
                        }
                    }
                }
                
                $db->commit();
                $bot_success = "$success_count Bot ID başarıyla eklendi.";
                
            } catch (PDOException $e) {
                $db->rollBack();
                $bot_error = "Veritabanı hatası: " . $e->getMessage();
            }
        }
    }
}

// Mevcut ayarları getir
try {
    $query = "SELECT * FROM api_settings LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Bot listesini getir
try {
    $query = "SELECT * FROM bots ORDER BY id DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Bot silme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bot'])) {
    $bot_id = $_POST['delete_bot'];
    
    try {
        $query = "DELETE FROM bots WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $bot_id);
        
        if ($stmt->execute()) {
            $bot_success = "Bot başarıyla silindi.";
            // Listeyi yenile
            $query = "SELECT * FROM bots ORDER BY id DESC";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $bot_error = "Bot silinirken bir hata oluştu.";
        }
    } catch (PDOException $e) {
        $bot_error = "Veritabanı hatası: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Sistemi - Ayarlar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f5f5f5;
            padding-top: 20px;
        }
        .container {
            max-width: 1200px;
        }
        .card {
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .card-header {
            background-color: #0d6efd;
            color: white;
            font-weight: bold;
        }
        .btn-primary {
            background-color: #0d6efd;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
            color: #0d6efd;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row mb-4">
            <div class="col-md-12">
                <h1 class="text-center mb-4">SMS Gönderim Sistemi</h1>
                
                <ul class="nav nav-tabs mb-4">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Ana Sayfa</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="settings.php">Ayarlar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">Mesaj Yönetimi</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="phones.php">Telefon Numaraları</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logs.php">Gönderim Logları</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="fetch_bots.php">Bot ID Çekici</a>
                    </li>
                </ul>
                
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-gear-fill me-2"></i> API Bağlantı Ayarları
                    </div>
                    <div class="card-body">
                        <?php if (isset($success)): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="login_url" class="form-label">Panel URL:</label>
                                <input type="text" name="login_url" id="login_url" class="form-control" value="<?php echo isset($settings['login_url']) ? htmlspecialchars($settings['login_url']) : ''; ?>" required>
                                <div class="form-text">Örnek: https://213.209.150.234/MzhiMTg0NTAwOTY5S/index.php</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">Kullanıcı Adı:</label>
                                <input type="text" name="username" id="username" class="form-control" value="<?php echo isset($settings['username']) ? htmlspecialchars($settings['username']) : ''; ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Şifre:</label>
                                <input type="password" name="password" id="password" class="form-control" value="<?php echo isset($settings['password']) ? htmlspecialchars($settings['password']) : ''; ?>" required>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" name="save_settings" class="btn btn-primary">Ayarları Kaydet</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-hdd-network-fill me-2"></i> Bot ID Yönetimi
                    </div>
                    <div class="card-body">
                        <?php if (isset($bot_success)): ?>
                            <div class="alert alert-success"><?php echo $bot_success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($bot_error)): ?>
                            <div class="alert alert-danger"><?php echo $bot_error; ?></div>
                        <?php endif; ?>
                        
                        <ul class="nav nav-pills mb-3" id="botTabContent" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="single-bot-tab" data-bs-toggle="pill" data-bs-target="#single-bot" type="button" role="tab" aria-controls="single-bot" aria-selected="true">Tek Bot Ekle</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="multi-bot-tab" data-bs-toggle="pill" data-bs-target="#multi-bot" type="button" role="tab" aria-controls="multi-bot" aria-selected="false">Toplu Bot Ekle</button>
                            </li>
                        </ul>

                        <div class="tab-content" id="botTabContent">
                            <div class="tab-pane fade show active" id="single-bot" role="tabpanel" aria-labelledby="single-bot-tab">
                                <form method="post" action="" class="mb-4">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <label for="bot_id" class="form-label">Bot ID:</label>
                                            <input type="text" name="bot_id" id="bot_id" class="form-control" required>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <button type="submit" name="add_bot" class="btn btn-primary w-100">Ekle</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="tab-pane fade" id="multi-bot" role="tabpanel" aria-labelledby="multi-bot-tab">
                                <form method="post" action="" class="mb-4">
                                    <div class="mb-3">
                                        <label for="multi_bot_ids" class="form-label">Bot ID'leri (Her satıra bir ID):</label>
                                        <textarea name="multi_bot_ids" id="multi_bot_ids" class="form-control" rows="6" placeholder="Bot ID'lerini her satıra bir tane olacak şekilde girin..."></textarea>
                                        <div class="form-text">Her satıra bir Bot ID yazın.</div>
                                    </div>
                                    <div class="d-grid">
                                        <button type="submit" name="add_multi_bots" class="btn btn-primary">Toplu Bot Ekle</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <h5 class="mb-3">Bot Listesi</h5>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Bot ID</th>
                                        <th>Eklenme Tarihi</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bots)): ?>
                                        <?php foreach ($bots as $index => $bot): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($bot['bot_id']); ?></td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($bot['created_at'])); ?></td>
                                                <td>
                                                    <form method="post" action="" class="d-inline">
                                                        <input type="hidden" name="delete_bot" value="<?php echo $bot['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Bu botu silmek istediğinizden emin misiniz?');">
                                                            <i class="bi bi-trash"></i> Sil
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center">Henüz bot eklenmemiş.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>