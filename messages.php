<?php
session_start();
require_once 'database.php';

$database = new Database();
$db = $database->connect();

// Bot listesini getir
try {
    $query = "SELECT * FROM bots ORDER BY id DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Telefon numarası sayısını getir
try {
    $query = "SELECT COUNT(*) as total FROM phones";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_phones = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Mesaj şablonu kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    $name = trim($_POST['template_name']);
    $content = trim($_POST['template_content']);
    
    if (empty($name) || empty($content)) {
        $template_error = "Şablon adı ve içeriği boş olamaz.";
    } else {
        try {
            $query = "INSERT INTO message_templates (name, content) VALUES (:name, :content)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':content', $content);
            
            if ($stmt->execute()) {
                $template_success = "Mesaj şablonu başarıyla kaydedildi.";
            } else {
                $template_error = "Mesaj şablonu kaydedilirken bir hata oluştu.";
            }
        } catch (PDOException $e) {
            $template_error = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

// Mesaj şablonlarını getir
try {
    $query = "SELECT * FROM message_templates ORDER BY id DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Şablon silme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_template'])) {
    $template_id = $_POST['delete_template'];
    
    try {
        $query = "DELETE FROM message_templates WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $template_id);
        
        if ($stmt->execute()) {
            $template_success = "Şablon başarıyla silindi.";
            // Şablon listesini yeniden yükle
            $query = "SELECT * FROM message_templates ORDER BY id DESC";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $template_error = "Şablon silinirken bir hata oluştu.";
        }
    } catch (PDOException $e) {
        $template_error = "Veritabanı hatası: " . $e->getMessage();
    }
}

// SMS oluştur ve kuyruğa ekle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sms'])) {
    $message_text = trim($_POST['message_text']);
    $selected_bots = isset($_POST['bots']) ? $_POST['bots'] : [];
    $bot_selection_mode = isset($_POST['bot_selection_mode']) ? $_POST['bot_selection_mode'] : 'random';
    
    if (empty($message_text) || empty($selected_bots)) {
        $sms_error = "Mesaj içeriği ve en az bir bot seçmelisiniz.";
    } else {
        try {
            // Tüm telefon numaralarını getir
            $query = "SELECT * FROM phones";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $phones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($phones) > 0) {
                $db->beginTransaction();
                $success_count = 0;
                $selected_bot_count = count($selected_bots);
                $current_bot_index = 0;
                
                foreach ($phones as $phone) {
                    // Mesaj içeriğinde isim değişkenini değiştir
                    $personalized_message = str_replace('{Telefon numarasına ait isim}', $phone['name'], $message_text);
                    
                    // Bot ID'sini seçme (rastgele veya sırayla)
                    if ($bot_selection_mode === 'random') {
                        // Rastgele bir bot ID seç
                        $random_bot_index = array_rand($selected_bots);
                        $bot_id = $selected_bots[$random_bot_index];
                    } else {
                        // Sırayla botları kullan (döngüsel olarak)
                        $bot_id = $selected_bots[$current_bot_index];
                        $current_bot_index = ($current_bot_index + 1) % $selected_bot_count;
                    }
                    
                    // SMS kuyruğuna ekle
                    $query = "INSERT INTO sms_queue (phone_id, bot_id, message) VALUES (:phone_id, :bot_id, :message)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':phone_id', $phone['id']);
                    $stmt->bindParam(':bot_id', $bot_id);
                    $stmt->bindParam(':message', $personalized_message);
                    
                    if ($stmt->execute()) {
                        $success_count++;
                    }
                }
                
                $db->commit();
                $sms_success = "Toplam " . $success_count . " SMS kuyruğa eklendi. SMS gönderimi için ana sayfaya gidin.";
                
            } else {
                $sms_error = "Veritabanında kayıtlı telefon numarası bulunmuyor.";
            }
        } catch (PDOException $e) {
            $db->rollBack();
            $sms_error = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

// Kuyruk sayısını getir
try {
    $query = "SELECT COUNT(*) as total FROM sms_queue WHERE status='pending'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $pending_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Sistemi - Mesaj Yönetimi</title>
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
        .template-card {
            cursor: pointer;
            transition: all 0.3s;
        }
        .template-card:hover {
            background-color: #f8f9fa;
            transform: translateY(-2px);
        }
        .bot-check-label {
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        .bot-check-label:hover {
            background-color: #e9ecef;
        }
        .bot-check-label.checked {
            background-color: #d1e7dd;
            border-color: #badbcc;
        }
        .select-buttons {
            margin-bottom: 15px;
        }
        .bot-selection-container {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            background-color: #f8f9fa;
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
                        <a class="nav-link" href="settings.php">Ayarlar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="messages.php">Mesaj Yönetimi</a>
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
                        <i class="bi bi-chat-dots-fill me-2"></i> Mesaj Oluşturma
                    </div>
                    <div class="card-body">
                        <?php if (isset($sms_success)): ?>
                            <div class="alert alert-success"><?php echo $sms_success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($sms_error)): ?>
                            <div class="alert alert-danger"><?php echo $sms_error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($total_phones == 0): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle-fill"></i> Veritabanında telefon numarası bulunmuyor. Önce <a href="phones.php">Telefon Numaraları</a> ekleyin.
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($bots)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle-fill"></i> Veritabanında bot bulunmuyor. Önce <a href="settings.php">Ayarlar</a> sayfasından bot ekleyin.
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($pending_count > 0): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle-fill"></i> Kuyrukta <strong><?php echo $pending_count; ?></strong> adet gönderilmeyi bekleyen SMS bulunuyor. 
                                <a href="index.php" class="alert-link">Ana sayfaya git</a> ve gönderimi başlat.
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="" id="smsForm">
                            <div class="mb-3">
                                <label for="message_text" class="form-label">Mesaj İçeriği:</label>
                                <div class="input-group mb-2">
                                    <textarea name="message_text" id="message_text" class="form-control" rows="5" required><?php echo isset($_POST['message_text']) ? htmlspecialchars($_POST['message_text']) : ''; ?></textarea>
                                    <button type="button" class="btn btn-outline-secondary" onclick="insertNameTag()">
                                        <i class="bi bi-person-plus"></i> İsim Ekle
                                    </button>
                                </div>
                                <div class="form-text">
                                    Alıcının ismini mesaj içinde kullanmak için <code>{Telefon numarasına ait isim}</code> yazın veya "İsim Ekle" butonuna tıklayın.<br>
                                    Örnek: "Merhaba {Telefon numarasına ait isim}, harika bir teklifimiz var!"
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">Şablonlar:</label>
                                <div class="row">
                                    <?php if (!empty($templates)): ?>
                                        <?php foreach ($templates as $template): ?>
                                            <div class="col-md-4 mb-3">
                                                <div class="card template-card" onclick="useTemplate('<?php echo addslashes($template['content']); ?>')">
                                                    <div class="card-body">
                                                        <h6 class="card-title"><?php echo htmlspecialchars($template['name']); ?></h6>
                                                        <p class="card-text small text-truncate"><?php echo htmlspecialchars($template['content']); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="col-12">
                                            <p class="text-muted">Henüz kaydedilmiş şablon bulunmuyor.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">Bot Seçimi:</label>
                                
                                <div class="mb-3">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="bot_selection_mode" id="randomMode" value="random" checked>
                                        <label class="form-check-label" for="randomMode">Rastgele Bot Kullan</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="bot_selection_mode" id="sequentialMode" value="sequential">
                                        <label class="form-check-label" for="sequentialMode">Sırayla Bot Kullan</label>
                                    </div>
                                </div>
                                
                                <div class="select-buttons">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAllBots()">Tümünü Seç</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllBots()">Seçimi Temizle</button>
                                    <span class="ms-3" id="selectedBotCount">0 bot seçildi</span>
                                </div>
                                
                                <div class="bot-selection-container">
                                    <div class="row">
                                        <?php if (!empty($bots)): ?>
                                            <?php foreach ($bots as $bot): ?>
                                                <div class="col-md-4 mb-2">
                                                    <label class="d-block bot-check-label" id="bot_label_<?php echo $bot['id']; ?>">
                                                        <input type="checkbox" name="bots[]" value="<?php echo $bot['id']; ?>" class="bot-checkbox" onclick="updateLabel(<?php echo $bot['id']; ?>); updateSelectedCount();"> 
                                                        <strong class="bot-id-text"><?php echo htmlspecialchars(substr($bot['bot_id'], 0, 20) . (strlen($bot['bot_id']) > 20 ? '...' : '')); ?></strong>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <button type="submit" name="create_sms" class="btn btn-primary" <?php echo ($total_phones == 0 || empty($bots)) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-send-fill"></i> SMS Oluştur ve Kuyruğa Ekle
                                </button>
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#saveTemplateModal">
                                    <i class="bi bi-save"></i> Şablon Olarak Kaydet
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-card-list me-2"></i> Kayıtlı Şablonlar
                    </div>
                    <div class="card-body">
                        <?php if (isset($template_success)): ?>
                            <div class="alert alert-success"><?php echo $template_success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($template_error)): ?>
                            <div class="alert alert-danger"><?php echo $template_error; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($templates)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Şablon Adı</th>
                                            <th>İçerik</th>
                                            <th>Oluşturulma</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($templates as $index => $template): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($template['name']); ?></td>
                                                <td class="text-truncate" style="max-width: 300px;"><?php echo htmlspecialchars($template['content']); ?></td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($template['created_at'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-primary" onclick="useTemplate('<?php echo addslashes($template['content']); ?>')">
                                                        <i class="bi bi-upload"></i> Kullan
                                                    </button>
                                                    <form method="post" action="" class="d-inline">
                                                        <input type="hidden" name="delete_template" value="<?php echo $template['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Bu şablonu silmek istediğinizden emin misiniz?');">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-center">Henüz kayıtlı şablon bulunmuyor.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Şablon Kaydetme Modal -->
    <div class="modal fade" id="saveTemplateModal" tabindex="-1" aria-labelledby="saveTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="saveTemplateModalLabel">Şablon Olarak Kaydet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="template_name" class="form-label">Şablon Adı:</label>
                            <input type="text" name="template_name" id="template_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="template_content" class="form-label">Şablon İçeriği:</label>
                            <textarea name="template_content" id="template_content" class="form-control" rows="5" readonly></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" name="save_template" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function insertNameTag() {
            const messageTextarea = document.getElementById('message_text');
            const nameTag = '{Telefon numarasına ait isim}';
            
            // Textarea'nın şu anki imlec pozisyonunu al
            const startPos = messageTextarea.selectionStart;
            const endPos = messageTextarea.selectionEnd;
            
            // İmlec konumuna etiketi ekle
            const textBefore = messageTextarea.value.substring(0, startPos);
            const textAfter = messageTextarea.value.substring(endPos, messageTextarea.value.length);
            
            messageTextarea.value = textBefore + nameTag + textAfter;
            
            // İmleci yeni pozisyona taşı
            const newCursorPos = startPos + nameTag.length;
            messageTextarea.focus();
            messageTextarea.setSelectionRange(newCursorPos, newCursorPos);
        }

        function useTemplate(content) {
            document.getElementById('message_text').value = content;
        }
        
        function updateLabel(id) {
            const checkbox = document.querySelector(`input[value="${id}"]`);
            const label = document.getElementById(`bot_label_${id}`);
            
            if (checkbox.checked) {
                label.classList.add('checked');
            } else {
                label.classList.remove('checked');
            }
        }
        
        function selectAllBots() {
            const checkboxes = document.querySelectorAll('.bot-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
                updateLabel(checkbox.value);
            });
            updateSelectedCount();
        }
        
        function deselectAllBots() {
            const checkboxes = document.querySelectorAll('.bot-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
                updateLabel(checkbox.value);
            });
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.bot-checkbox:checked');
            const countElement = document.getElementById('selectedBotCount');
            countElement.textContent = checkboxes.length + ' bot seçildi';
            
            if (checkboxes.length > 0) {
                countElement.classList.add('text-success');
                countElement.classList.remove('text-danger');
            } else {
                countElement.classList.add('text-danger');
                countElement.classList.remove('text-success');
            }
        }
        
        // Şablon kaydetme modalı açıldığında içeriği doldur
        document.getElementById('saveTemplateModal').addEventListener('show.bs.modal', function (event) {
            const messageContent = document.getElementById('message_text').value;
            document.getElementById('template_content').value = messageContent;
        });
        
        // Form gönderim kontrolü
        document.getElementById('smsForm').addEventListener('submit', function(event) {
            const bots = document.querySelectorAll('input[name="bots[]"]:checked');
            if (bots.length === 0) {
                event.preventDefault();
                alert('Lütfen en az bir bot seçin!');
            }
        });
        
        // Sayfa yüklendiğinde seçili bot sayısını güncelle
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
        });
    </script>
</body>
</html>