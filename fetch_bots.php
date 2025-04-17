<?php
session_start();
require_once 'database.php';

$database = new Database();
$db = $database->connect();

// API ayarlarını getir
try {
    $query = "SELECT * FROM api_settings LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $api_settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $api_configured = isset($api_settings['login_url']) && !empty($api_settings['login_url']);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
    $api_configured = false;
}

$base_url = "";
$username = "";
$password = "";

if ($api_configured) {
    $base_url = $api_settings['login_url'];
    $username = $api_settings['username'];
    $password = $api_settings['password'];
    
    // URL'nin son "/" karakterini kaldırma
    $base_url = rtrim($base_url, '/');
    
    // index.php varsa çıkar
    if (substr($base_url, -9) === 'index.php') {
        $base_url = dirname($base_url);
    }
}

$bot_ids = [];
$total_bots = 0;
$error_message = '';
$success_message = '';

// Form gönderildi mi kontrol et
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $use_custom_url = isset($_POST['use_custom_url']) && $_POST['use_custom_url'] === 'on';
    $custom_url = isset($_POST['custom_url']) ? trim($_POST['custom_url']) : '';
    $api_username = isset($_POST['api_username']) ? trim($_POST['api_username']) : $username;
    $api_password = isset($_POST['api_password']) ? trim($_POST['api_password']) : $password;
    
    $fetch_url = $use_custom_url ? $custom_url : $base_url . '/index.php?page_number=1&rows=1000&set_filter=1&alive_only=on&comment=&tag=&sort_by=last_seen_desc&country=all&ip=';
    
    if (empty($fetch_url)) {
        $error_message = "URL boş olamaz.";
    } else {
        try {
            // cURL ile bot listesini al
            $ch = curl_init();
            
            // SSL sertifika doğrulamasını devre dışı bırak
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            // Basic Authentication kullan
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, "$api_username:$api_password");
            
            // URL'yi ayarla
            curl_setopt($ch, CURLOPT_URL, $fetch_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
            // User-Agent ayarla
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36');
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($http_code === 200) {
                // Bot ID'leri ayıkla
                preg_match_all('/\b[a-f0-9]{32}\b/i', $response, $matches);
                
                if (isset($matches[0]) && !empty($matches[0])) {
                    $bot_ids = array_unique($matches[0]); // Tekrar eden ID'leri kaldır
                    $total_bots = count($bot_ids);
                    $success_message = "Toplam $total_bots bot ID'si başarıyla çekildi!";
                    
                } else {
                    $error_message = "Sayfada bot ID bulunamadı. URL ve giriş bilgilerini kontrol edin.";
                }
            } else {
                $error_message = "Botlar çekilirken bir hata oluştu. HTTP Kodu: $http_code";
            }
            
            curl_close($ch);
            
        } catch (Exception $e) {
            $error_message = "İstek yapılırken hata oluştu: " . $e->getMessage();
        }
    }
}

// Botlar sayfasının başlığı
$title = "Bot Görev Yöneticisi";
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - SMS Gönderim Sistemi</title>
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
        .bot-id-box {
            max-height: 300px;
            overflow-y: auto;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            font-family: monospace;
        }
        .actions-toolbar {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }
        .custom-url-container {
            display: none;
        }
        .bot-id-item {
            padding: 5px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
        }
        .bot-id-item:hover {
            background-color: #e9ecef;
        }
        .bot-checkbox {
            margin-right: 10px;
        }
        .task-buttons-container {
            margin-top: 20px;
            display: none;
            border-top: 1px solid #dee2e6;
            padding-top: 20px;
        }
        .task-button {
            margin-bottom: 10px;
            width: 100%;
            text-align: left;
            position: relative;
            padding-left: 3.5rem;
            font-weight: bold;
        }
        .task-button .task-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.5rem;
        }
        .task-progress {
            margin-top: 20px;
            display: none;
        }
        .log-container {
            max-height: 300px;
            overflow-y: auto;
            background-color: #212529;
            color: #ffffff;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
            margin-top: 15px;
        }
        .log-entry {
            margin-bottom: 5px;
            border-bottom: 1px solid #444;
            padding-bottom: 5px;
        }
        .log-time {
            color: #6c757d;
            margin-right: 10px;
        }
        .log-success {
            color: #28a745;
        }
        .log-error {
            color: #dc3545;
        }
        .log-info {
            color: #17a2b8;
        }
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .status-indicator.active {
            background-color: #28a745;
            animation: blink 1s infinite;
        }
        .status-indicator.inactive {
            background-color: #dc3545;
        }
        @keyframes blink {
            0% { opacity: 0.5; }
            50% { opacity: 1; }
            100% { opacity: 0.5; }
        }
        #selectAllContainer {
            margin-bottom: 10px;
            padding: 5px;
            background-color: #e9ecef;
            border-radius: 5px;
        }
        .bot-task-description {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.8);
            margin-top: 2px;
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
                        <a class="nav-link" href="messages.php">Mesaj Yönetimi</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="phones.php">Telefon Numaraları</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logs.php">Gönderim Logları</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="bot_tasks.php">Bot Görevleri</a>
                    </li>
                </ul>
                
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-robot me-2"></i> Bot ID Çekici ve Görev Yöneticisi
                    </div>
                    <div class="card-body">
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill"></i> <?php echo $success_message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$api_configured): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle-fill"></i> API ayarları yapılandırılmamış. Sistem ayarlarından yapılandırabilir veya özel URL kullanabilirsiniz.
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="useCustomUrl" name="use_custom_url">
                                    <label class="form-check-label" for="useCustomUrl">Özel URL Kullan</label>
                                </div>
                            </div>
                            
                            <div id="customUrlContainer" class="custom-url-container mb-3">
                                <label for="customUrl" class="form-label">Özel Bot Listesi URL:</label>
                                <input type="text" class="form-control" id="customUrl" name="custom_url" placeholder="https://örnek.com/panel/index.php?page_number=1&rows=1000&set_filter=1&alive_only=on">
                                <div class="form-text">Bot listesini içeren sayfanın tam URL'sini girin. Sayfa başına 1000 bot görmek için URL'ye "&rows=1000" ekleyin.</div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="apiUsername" class="form-label">Kullanıcı Adı:</label>
                                    <input type="text" class="form-control" id="apiUsername" name="api_username" value="<?php echo htmlspecialchars($username); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="apiPassword" class="form-label">Şifre:</label>
                                    <input type="password" class="form-control" id="apiPassword" name="api_password" value="<?php echo htmlspecialchars($password); ?>">
                                </div>
                            </div>
                            
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-cloud-download"></i> Bot ID'leri Çek
                                </button>
                            </div>
                        </form>
                        
                        <?php if (!empty($bot_ids)): ?>
                            <div class="mt-4">
                                <h5>Bulunan Bot ID'leri (<?php echo $total_bots; ?>)</h5>
                                
                                <div id="selectAllContainer">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="selectAllBots">
                                        <label class="form-check-label" for="selectAllBots">
                                            <strong>Tüm botları seç</strong> <span id="selectedBotCount" class="badge bg-primary">0 / <?php echo $total_bots; ?></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="bot-id-box" id="botIdsContainer">
                                    <?php foreach ($bot_ids as $id): ?>
                                        <div class="bot-id-item">
                                            <input type="checkbox" class="form-check-input bot-checkbox" value="<?php echo htmlspecialchars($id); ?>">
                                            <span><?php echo htmlspecialchars($id); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="actions-toolbar">
                                    <button type="button" class="btn btn-outline-primary" id="copySelectedBtn">
                                        <i class="bi bi-clipboard"></i> Seçilenleri Kopyala
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="downloadSelectedBtn">
                                        <i class="bi bi-download"></i> Seçilenleri İndir
                                    </button>
                                    <button type="button" class="btn btn-success" id="showTasksBtn">
                                        <i class="bi bi-play-fill"></i> Görev Atama Panelini Göster
                                    </button>
                                </div>
                                
                                <!-- Görev Butonları Paneli -->
                                <div class="task-buttons-container" id="taskButtonsContainer">
                                    <h5>Görev Seçin</h5>
                                    <p>Seçtiğiniz botlarda çalıştırmak istediğiniz görevi seçin.</p>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <button type="button" class="btn btn-primary task-button" id="phoneNumberTask">
                                                <i class="bi bi-phone task-icon"></i> Telefon Gör
                                                <div class="bot-task-description">Telefon numaranızı bot'un görmesini sağlar ve numaranız ile mesaj gönderir.</div>
                                            </button>
                                            
                                            <button type="button" class="btn btn-success task-button" id="remainingCreditsTask">
                                                <i class="bi bi-cash-coin task-icon"></i> Vodo Self Servis
                                                <div class="bot-task-description">Botta Vodo Self Servis Sorgu bilgisini gönderir.</div>
                                            </button>
                                            
                                            <button type="button" class="btn btn-info task-button" id="statusCheckTask">
                                                <i class="bi bi-info-circle task-icon"></i> Durum Kontrol
                                                <div class="bot-task-description">Botun aktif olup olmadığını ve durumunu kontrol eder.</div>
                                            </button>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <button type="button" class="btn btn-warning task-button" id="sendCustomMessageTask">
                                                <i class="bi bi-chat-dots task-icon"></i> Özel Mesaj Gönder
                                                <div class="bot-task-description">Seçtiğiniz botlar üzerinden özel bir mesaj gönderir.</div>
                                            </button>
                                            
                                            <button type="button" class="btn btn-danger task-button" id="immediateStopTask">
                                                <i class="bi bi-x-octagon task-icon"></i> Acil Durdur
                                                <div class="bot-task-description">Seçilen botlardaki tüm aktif işlemleri anında durdurur.</div>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <!-- İşlem Durumu ve Loglar -->
                                    <div class="task-progress" id="taskProgressContainer">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h5>
                                                <span id="taskStatusIndicator" class="status-indicator inactive"></span>
                                                İşlem Durumu: <span id="taskStatusText">Bekliyor...</span>
                                            </h5>
                                            <button type="button" class="btn btn-sm btn-danger" id="stopTasksBtn">
                                                <i class="bi bi-stop-fill"></i> İşlemi Durdur
                                            </button>
                                        </div>
                                        
                                        <div class="progress mb-3" style="height: 25px;">
                                            <div id="taskProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                                 role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                        </div>
                                        
                                        <div class="d-flex justify-content-between mb-3">
                                            <div><strong>Tamamlanan:</strong> <span id="completedTaskCount">0</span> / <span id="totalTaskCount">0</span></div>
                                            <div><strong>Başarılı:</strong> <span id="successTaskCount" class="text-success">0</span></div>
                                            <div><strong>Başarısız:</strong> <span id="failedTaskCount" class="text-danger">0</span></div>
                                        </div>
                                        
                                        <div class="log-container" id="taskLogContainer">
                                            <div class="text-muted text-center">İşlem başlayınca loglar burada görüntülenecek...</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Telefon Numarası Giriş Modalı -->
    <div class="modal fade" id="phoneNumberModal" tabindex="-1" aria-labelledby="phoneNumberModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="phoneNumberModalLabel">Telefon Numarası Girin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="phoneNumberInput" class="form-label">Telefon Numarası:</label>
                        <input type="text" class="form-control" id="phoneNumberInput" placeholder="1555666222">
                        <div class="form-text">Bot, bu numarayı görecek ve size bu numara ile mesaj gönderecek.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-primary" id="confirmPhoneBtn">Onayla</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Özel Mesaj Giriş Modalı -->
    <div class="modal fade" id="customMessageModal" tabindex="-1" aria-labelledby="customMessageModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="customMessageModalLabel">Özel Mesaj Girin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="customMessageInput" class="form-label">Mesaj İçeriği:</label>
                        <textarea class="form-control" id="customMessageInput" rows="4" placeholder="Gönderilecek mesajı yazın..."></textarea>
                        <div class="form-text">
                            <code>{bot_id}</code> ifadesi mesaj içinde bot ID'si ile değiştirilecektir.<br>
                            Örnek: "Bu mesaj {bot_id} ID'li bot tarafından gönderildi."
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-primary" id="confirmMessageBtn">Onayla</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // DOM elementlerini al
            const useCustomUrlCheckbox = document.getElementById('useCustomUrl');
            const customUrlContainer = document.getElementById('customUrlContainer');
            const selectAllBots = document.getElementById('selectAllBots');
            const botCheckboxes = document.querySelectorAll('.bot-checkbox');
            const copySelectedBtn = document.getElementById('copySelectedBtn');
            const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
            const showTasksBtn = document.getElementById('showTasksBtn');
            const selectedBotCount = document.getElementById('selectedBotCount');
            const taskButtonsContainer = document.getElementById('taskButtonsContainer');
            const taskProgressContainer = document.getElementById('taskProgressContainer');
            const taskStatusIndicator = document.getElementById('taskStatusIndicator');
            const taskStatusText = document.getElementById('taskStatusText');
            const taskProgressBar = document.getElementById('taskProgressBar');
            const completedTaskCount = document.getElementById('completedTaskCount');
            const totalTaskCount = document.getElementById('totalTaskCount');
            const successTaskCount = document.getElementById('successTaskCount');
            const failedTaskCount = document.getElementById('failedTaskCount');
            const taskLogContainer = document.getElementById('taskLogContainer');
            const stopTasksBtn = document.getElementById('stopTasksBtn');
            
            // Görev butonları
            const phoneNumberTask = document.getElementById('phoneNumberTask');
            const remainingCreditsTask = document.getElementById('remainingCreditsTask');
            const statusCheckTask = document.getElementById('statusCheckTask');
            const sendCustomMessageTask = document.getElementById('sendCustomMessageTask');
            const immediateStopTask = document.getElementById('immediateStopTask');
            
            // Modaller
            const phoneNumberModal = new bootstrap.Modal(document.getElementById('phoneNumberModal'));
            const customMessageModal = new bootstrap.Modal(document.getElementById('customMessageModal'));
            const confirmPhoneBtn = document.getElementById('confirmPhoneBtn');
            const confirmMessageBtn = document.getElementById('confirmMessageBtn');
            
            // Global task variables
            let isTaskRunning = false;
            let taskQueue = [];
            let currentTaskType = '';
            let currentTaskData = {};
            let completedTasks = 0;
            let successfulTasks = 0;
            let failedTasks = 0;
            let totalTasks = 0;
            let activeTaskCount = 0;
            let maxConcurrentTasks = 10;
            let taskTimeout = null;
            
            // Özel URL checkbox değişikliği
            if (useCustomUrlCheckbox) {
                useCustomUrlCheckbox.addEventListener('change', function() {
                    customUrlContainer.style.display = this.checked ? 'block' : 'none';
                });
                
                // Sayfa yüklendiğinde checkbox durumunu kontrol et
                if (useCustomUrlCheckbox.checked) {
                    customUrlContainer.style.display = 'block';
                }
            }
            
            // Tüm botları seçme/kaldırma
            if (selectAllBots) {
                selectAllBots.addEventListener('change', function() {
                    const isChecked = this.checked;
                    botCheckboxes.forEach(checkbox => {
                        checkbox.checked = isChecked;
                    });
                    updateSelectedCount();
                });
            }
            
            // Bot seçildiğinde sayacı güncelle
            if (botCheckboxes.length > 0) {
                botCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', updateSelectedCount);
                });
                updateSelectedCount(); // İlk yüklemede sayacı güncelle
            }
            
            // Seçilenleri kopyala
            if (copySelectedBtn) {
                copySelectedBtn.addEventListener('click', function() {
                    const selectedBots = getSelectedBots();
                    if (selectedBots.length === 0) {
                        alert('Lütfen en az bir bot seçin.');
                        return;
                    }
                    
                    copyToClipboard(selectedBots.join('\n'));
                    alert('Seçili botlar panoya kopyalandı!');
                });
            }
            
            // Seçilenleri indir
            if (downloadSelectedBtn) {
                downloadSelectedBtn.addEventListener('click', function() {
                    const selectedBots = getSelectedBots();
                    if (selectedBots.length === 0) {
                        alert('Lütfen en az bir bot seçin.');
                        return;
                    }
                    
                    downloadFile(selectedBots.join('\n'), 'selected_bots.txt', 'text/plain');
                });
            }
            
            // Görev panelini göster/gizle
            if (showTasksBtn) {
                showTasksBtn.addEventListener('click', function() {
                    const selectedBots = getSelectedBots();
                    if (selectedBots.length === 0) {
                        alert('Lütfen en az bir bot seçin.');
                        return;
                    }
                    
                    taskButtonsContainer.style.display = 'block';
                    this.style.display = 'none';
                    
                    // Sayfayı task paneline kaydır
                    taskButtonsContainer.scrollIntoView({ behavior: 'smooth' });
                });
            }
            
            // Telefon Gör görevi
            if (phoneNumberTask) {
                phoneNumberTask.addEventListener('click', function() {
                    if (isTaskRunning) {
                        alert('Şu anda başka bir görev çalışıyor. Lütfen önce o görevi durdurun veya tamamlanmasını bekleyin.');
                        return;
                    }
                    
                    // Telefon numarası modalını göster
                    phoneNumberModal.show();
                });
            }
            
            // Telefon numarası onaylama
            if (confirmPhoneBtn) {
                confirmPhoneBtn.addEventListener('click', function() {
                    const phoneNumber = document.getElementById('phoneNumberInput').value.trim();
                    if (!phoneNumber) {
                        alert('Lütfen bir telefon numarası girin.');
                        return;
                    }
                    
                    phoneNumberModal.hide();
                    
                    // Görevi başlat
                    startTask('phone_view', {
                        phone: phoneNumber,
                        message: `FREESPIN ASKINA! 1000 TL Yatiriminiza 500 Freespin! KUPON: {bot_id} https://cutt.ly/195v2 `
                    });
                });
            }
            
            // Kalan Bakiye Öğren görevi
            if (remainingCreditsTask) {
                remainingCreditsTask.addEventListener('click', function() {
                    if (isTaskRunning) {
                        alert('Şu anda başka bir görev çalışıyor. Lütfen önce o görevi durdurun veya tamamlanmasını bekleyin.');
                        return;
                    }
                    
                    // Görevi başlat
                    startTask('remaining_credits', {
                        message: `S`
                    });
                });
            }
            
            // Durum Kontrol görevi
            if (statusCheckTask) {
                statusCheckTask.addEventListener('click', function() {
                    if (isTaskRunning) {
                        alert('Şu anda başka bir görev çalışıyor. Lütfen önce o görevi durdurun veya tamamlanmasını bekleyin.');
                        return;
                    }
                    
                    // Görevi başlat
                    startTask('status_check', {
                        message: `Bot durum kontrolü yapılıyor. ID: {bot_id}`
                    });
                });
            }
            
            // Özel Mesaj Gönder görevi
            if (sendCustomMessageTask) {
                sendCustomMessageTask.addEventListener('click', function() {
                    if (isTaskRunning) {
                        alert('Şu anda başka bir görev çalışıyor. Lütfen önce o görevi durdurun veya tamamlanmasını bekleyin.');
                        return;
                    }
                    
                    // Özel mesaj modalını göster
                    customMessageModal.show();
                });
            }
            
            // Özel mesaj onaylama
            if (confirmMessageBtn) {
                confirmMessageBtn.addEventListener('click', function() {
                    const customMessage = document.getElementById('customMessageInput').value.trim();
                    if (!customMessage) {
                        alert('Lütfen bir mesaj girin.');
                        return;
                    }
                    
                    customMessageModal.hide();
                    
                    // Görevi başlat
                    startTask('custom_message', {
                        message: customMessage
                    });
                });
            }
            
            // Acil Durdur görevi
            if (immediateStopTask) {
                immediateStopTask.addEventListener('click', function() {
                    if (isTaskRunning) {
                        alert('Şu anda başka bir görev çalışıyor. Lütfen önce o görevi durdurun veya tamamlanmasını bekleyin.');
                        return;
                    }
                    
                    // Görevi başlat
                    startTask('immediate_stop', {
                        message: `Tüm işlemler acil olarak durduruldu. ID: {bot_id}`
                    });
                });
            }
            
            // İşlemi durdur
            if (stopTasksBtn) {
                stopTasksBtn.addEventListener('click', stopTasks);
            }
            
            // Görevi başlat
            function startTask(taskType, taskData = {}) {
                if (isTaskRunning) {
                    return;
                }
                
                const selectedBots = getSelectedBots();
                if (selectedBots.length === 0) {
                    alert('Lütfen en az bir bot seçin.');
                    return;
                }
                
                // Görev değişkenlerini sıfırla
                isTaskRunning = true;
                taskQueue = [...selectedBots];
                currentTaskType = taskType;
                currentTaskData = taskData;
                completedTasks = 0;
                successfulTasks = 0;
                failedTasks = 0;
                totalTasks = selectedBots.length;
                activeTaskCount = 0;
                
                // UI'ı güncelle
                taskStatusIndicator.classList.remove('inactive');
                taskStatusIndicator.classList.add('active');
                taskStatusText.textContent = `${getTaskTypeName(taskType)} görevi çalışıyor...`;
                taskProgressBar.style.width = '0%';
                taskProgressBar.textContent = '0%';
                totalTaskCount.textContent = totalTasks;
                completedTaskCount.textContent = '0';
                successTaskCount.textContent = '0';
                failedTaskCount.textContent = '0';
                taskLogContainer.innerHTML = '';
                taskProgressContainer.style.display = 'block';
                
                // İlk log girişi
                addLogEntry(`${getTaskTypeName(taskType)} görevi başlatıldı. Toplam ${totalTasks} bot işlenecek.`, 'info');
                
                // Görevleri başlat
                processTasks();
            }
            
            // Görevleri işle
            function processTasks() {
                // Eğer tüm görevler tamamlandıysa veya görev durdurulduysa çık
                if (!isTaskRunning || taskQueue.length === 0 && activeTaskCount === 0) {
                    finishTasks();
                    return;
                }
                
                // Eşzamanlı görev sayısını kontrol et ve yeni görevler başlat
                while (isTaskRunning && taskQueue.length > 0 && activeTaskCount < maxConcurrentTasks) {
                    const botId = taskQueue.shift();
                    processBot(botId);
                    activeTaskCount++;
                }
                
                // 2 saniye sonra tekrar kontrol et
                taskTimeout = setTimeout(processTasks, 2000);
            }
            
            // Bir bot için görevi işle
            function processBot(botId) {
                addLogEntry(`Bot ID: ${botId} için ${getTaskTypeName(currentTaskType)} görevi başlıyor...`, 'info');
                
                // Mesajı hazırla - {bot_id} ile değiştir
                let message = currentTaskData.message || '';
                message = message.replace(/\{bot_id\}/g, botId);
                
                // API isteğini hazırla
                const formData = new FormData();
                formData.append('action', 'process_bot_task');
                formData.append('task_type', currentTaskType);
                formData.append('bot_id', botId);
                formData.append('message', message);
                
                if (currentTaskType === 'phone_view' && currentTaskData.phone) {
                    formData.append('phone', currentTaskData.phone);
                }
                
                // AJAX isteği gönder
                fetch('bot_task_handler.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    completedTasks++;
                    activeTaskCount--;
                    
                    if (data.success) {
                        successfulTasks++;
                        addLogEntry(`✅ Bot ID: ${botId} - ${data.message}`, 'success');
                    } else {
                        failedTasks++;
                        addLogEntry(`❌ Bot ID: ${botId} - ${data.error}`, 'error');
                    }
                    
                    // İlerleme çubuğunu güncelle
                    updateProgress();
                })
                .catch(error => {
                    completedTasks++;
                    failedTasks++;
                    activeTaskCount--;
                    
                    addLogEntry(`❌ Bot ID: ${botId} - İstek hatası: ${error.message}`, 'error');
                    
                    // İlerleme çubuğunu güncelle
                    updateProgress();
                });
            }
            
            // İlerleme çubuğunu güncelle
            function updateProgress() {
                const progress = Math.floor((completedTasks / totalTasks) * 100);
                taskProgressBar.style.width = `${progress}%`;
                taskProgressBar.textContent = `${progress}%`;
                completedTaskCount.textContent = completedTasks;
                successTaskCount.textContent = successfulTasks;
                failedTaskCount.textContent = failedTasks;
                
                // Tüm görevler tamamlandıysa işlemi bitir
                if (completedTasks === totalTasks) {
                    addLogEntry(`Tüm görevler tamamlandı. Başarılı: ${successfulTasks}, Başarısız: ${failedTasks}`, 'info');
                    finishTasks();
                }
            }
            
            // İşlemi tamamla veya durdur
            function finishTasks() {
                if (!isTaskRunning) return;
                
                clearTimeout(taskTimeout);
                isTaskRunning = false;
                taskStatusIndicator.classList.remove('active');
                taskStatusIndicator.classList.add('inactive');
                
                if (completedTasks === totalTasks) {
                    taskStatusText.textContent = 'Tamamlandı';
                    addLogEntry('Tüm görevler başarıyla tamamlandı!', 'success');
                } else {
                    taskStatusText.textContent = 'Durduruldu';
                    addLogEntry('İşlem kullanıcı tarafından durduruldu.', 'info');
                }
            }
            
            // İşlemi durdur
            function stopTasks() {
                if (!isTaskRunning) return;
                
                taskQueue = []; // Kuyruğu temizle
                isTaskRunning = false;
                
                addLogEntry('İşlem durduruluyor... Aktif görevlerin tamamlanması bekleniyor.', 'info');
            }
            
            // Log ekle
            function addLogEntry(message, type = 'info') {
                const now = new Date();
                const timeStr = now.toTimeString().substr(0, 8);
                
                const logEntry = document.createElement('div');
                logEntry.className = 'log-entry';
                
                const timeSpan = document.createElement('span');
                timeSpan.className = 'log-time';
                timeSpan.textContent = timeStr;
                
                const messageSpan = document.createElement('span');
                messageSpan.className = `log-${type}`;
                messageSpan.textContent = message;
                
                logEntry.appendChild(timeSpan);
                logEntry.appendChild(messageSpan);
                
                taskLogContainer.appendChild(logEntry);
                
                // Otomatik olarak aşağı kaydır
                taskLogContainer.scrollTop = taskLogContainer.scrollHeight;
            }
            
            // Seçili botları al
            function getSelectedBots() {
                const selectedBots = [];
                botCheckboxes.forEach(checkbox => {
                    if (checkbox.checked) {
                        selectedBots.push(checkbox.value);
                    }
                });
                return selectedBots;
            }
            
            // Seçilen bot sayısını güncelle
            function updateSelectedCount() {
                const selectedCount = getSelectedBots().length;
                const totalCount = botCheckboxes.length;
                selectedBotCount.textContent = `${selectedCount} / ${totalCount}`;
            }
            
            // Görev türü adını al
            function getTaskTypeName(taskType) {
                const taskNames = {
                    'phone_view': 'Telefon Gör',
                    'remaining_credits': 'Vodo Self Servis',
                    'status_check': 'Durum Kontrol',
                    'custom_message': 'Özel Mesaj Gönder',
                    'immediate_stop': 'Acil Durdur'
                };
                
                return taskNames[taskType] || taskType;
            }
            
            // Helper fonksiyonlar
            function copyToClipboard(text) {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }
            
            function downloadFile(content, fileName, contentType) {
                const a = document.createElement('a');
                const file = new Blob([content], {type: contentType});
                a.href = URL.createObjectURL(file);
                a.download = fileName;
                a.click();
                URL.revokeObjectURL(a.href);
            }
        });
    </script>
</body>
</html>