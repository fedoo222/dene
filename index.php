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

// Kuyrukta bekleyen SMS'leri sayma
try {
    $query = "SELECT COUNT(*) as total FROM sms_queue WHERE status = 'pending'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $pending_sms = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
    $pending_sms = 0;
}

// İstatistikleri getir
try {
    $query = "SELECT 
                (SELECT COUNT(*) FROM sms_queue WHERE status = 'sent') as sent,
                (SELECT COUNT(*) FROM sms_queue WHERE status = 'failed') as failed,
                (SELECT COUNT(*) FROM phones) as total_phones,
                (SELECT COUNT(*) FROM bots) as total_bots";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
    $stats = ['sent' => 0, 'failed' => 0, 'total_phones' => 0, 'total_bots' => 0];
}

// Son 10 log kaydını getir
try {
    $query = "SELECT sl.*, sq.status as queue_status 
              FROM sms_logs sl 
              JOIN sms_queue sq ON sl.queue_id = sq.id 
              ORDER BY sl.id DESC LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
    $recent_logs = [];
}

// AJAX işlemci dosyasının var olup olmadığını kontrol et
if (!file_exists('process_sms_batch.php')) {
    $process_file_error = true;
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Gönderim Sistemi - Ana Sayfa</title>
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
        .stats-card {
            text-align: center;
            transition: all 0.3s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .stats-number {
            font-size: 1.5rem;
            font-weight: bold;
        }
        .log-output {
            max-height: 300px;
            overflow-y: auto;
            background-color: #212529;
            color: #ffffff;
            padding: 10px;
            border-radius: 5px;
            font-family: monospace;
        }
        .status-sent {
            color: #198754;
            font-weight: bold;
        }
        .status-failed {
            color: #dc3545;
            font-weight: bold;
        }
        .status-pending {
            color: #ffc107;
            font-weight: bold;
        }
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .status-indicator.processing {
            background-color: #ffc107;
            animation: blink 1s infinite;
        }
        .status-indicator.stopped {
            background-color: #dc3545;
        }
        .status-indicator.completed {
            background-color: #198754;
        }
        @keyframes blink {
            0% { opacity: 0.5; }
            50% { opacity: 1; }
            100% { opacity: 0.5; }
        }
        #progressContainer {
            margin-top: 20px;
        }
        #logContainer {
            height: 300px;
            overflow-y: auto;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin-top: 15px;
            font-family: monospace;
            font-size: 0.9rem;
        }
        .log-entry {
            margin-bottom: 5px;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 5px;
        }
        .log-time {
            color: #6c757d;
            margin-right: 10px;
        }
        .log-success {
            color: #198754;
        }
        .log-error {
            color: #dc3545;
        }
        .log-info {
            color: #0d6efd;
        }
        .log-warning {
            color: #ffc107;
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
                        <a class="nav-link active" href="index.php">Ana Sayfa</a>
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
                        <a class="nav-link" href="fetch_bots.php">Bot ID Çekici</a>
                    </li>
                </ul>
                
                <?php if (!$api_configured): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i> API ayarları yapılandırılmamış. Lütfen <a href="settings.php">Ayarlar</a> sayfasını ziyaret edin.
                    </div>
                <?php endif; ?>
                
                <?php if (isset($process_file_error)): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i> AJAX işleyici dosyası bulunamadı. Lütfen process_sms_batch.php dosyasının varlığını kontrol edin.
                    </div>
                <?php endif; ?>
                
                <!-- İstatistikler -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-bar-chart-fill me-2"></i> SMS İstatistikleri
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="card stats-card">
                                    <div class="card-body">
                                        <div class="stats-icon text-primary">
                                            <i class="bi bi-clock-history"></i>
                                        </div>
                                        <div class="stats-number text-primary" id="pendingSmsCount"><?php echo $pending_sms; ?></div>
                                        <div class="stats-title">Bekleyen SMS</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stats-card">
                                    <div class="card-body">
                                        <div class="stats-icon text-success">
                                            <i class="bi bi-check-circle"></i>
                                        </div>
                                        <div class="stats-number text-success" id="sentSmsCount"><?php echo $stats['sent']; ?></div>
                                        <div class="stats-title">Gönderilen SMS</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stats-card">
                                    <div class="card-body">
                                        <div class="stats-icon text-danger">
                                            <i class="bi bi-x-circle"></i>
                                        </div>
                                        <div class="stats-number text-danger" id="failedSmsCount"><?php echo $stats['failed']; ?></div>
                                        <div class="stats-title">Başarısız SMS</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card stats-card">
                                    <div class="card-body">
                                        <div class="stats-icon text-info">
                                            <i class="bi bi-people"></i>
                                        </div>
                                        <div class="stats-number text-info"><?php echo $stats['total_phones']; ?></div>
                                        <div class="stats-title">Toplam Numara</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- SMS Gönderme Formu -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-send-fill me-2"></i> SMS Gönderim Kontrolü
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($pending_sms > 0): ?>
                            <div id="processingControls">
                                <div class="row mb-4">
                                    <div class="col-md-12">
                                        <h5>
                                            <span id="statusIndicator" class="status-indicator stopped"></span>
                                            İşlem Durumu: <span id="statusText" class="fw-bold">Durduruldu</span>
                                        </h5>
                                    </div>
                                </div>
                                
                                <div class="row align-items-end">
                                    <div class="col-md-3 mb-3">
                                        <label for="batchSize" class="form-label">İşlem Başına SMS Sayısı:</label>
                                        <input type="number" id="batchSize" class="form-control" value="5" min="1" max="20">
                                        <div class="form-text">Her seferde kaç SMS gönderilecek</div>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label for="batchInterval" class="form-label">İşlem Aralığı (saniye):</label>
                                        <input type="number" id="batchInterval" class="form-control" value="3" min="1" max="60">
                                        <div class="form-text">İki işlem arasında beklenecek süre</div>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label for="successStatus" class="form-label">Başarılı Durum Kelimesi:</label>
                                        <input type="text" id="successStatus" class="form-control" value="Executed">
                                        <div class="form-text">Başarılı sayılacak durum değeri</div>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <button type="button" id="startProcessingBtn" class="btn btn-success w-100" <?php echo !$api_configured ? 'disabled' : ''; ?>>
                                            <i class="bi bi-play-fill"></i> İşlemi Başlat
                                        </button>
                                        <button type="button" id="stopProcessingBtn" class="btn btn-danger w-100 d-none">
                                            <i class="bi bi-stop-fill"></i> İşlemi Durdur
                                        </button>
                                    </div>
                                </div>
                                
                                <div id="progressContainer" class="d-none">
                                    <div class="row mb-3">
                                        <div class="col-md-12">
                                            <div class="progress" style="height: 25px;">
                                                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-2">
                                        <div class="col-md-4">
                                            <strong>Tamamlanan:</strong> <span id="completedCount">0</span> / <span id="totalCount"><?php echo $pending_sms; ?></span>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Başarılı:</strong> <span id="successCount" class="text-success">0</span>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Başarısız:</strong> <span id="failureCount" class="text-danger">0</span>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div id="logContainer">
                                                <div class="text-muted text-center">
                                                    İşlem başlayınca loglar burada görüntülenecek...
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle-fill"></i> Kuyrukta bekleyen SMS bulunmuyor. 
                                <a href="messages.php" class="alert-link">Mesaj Yönetimi</a> sayfasından yeni SMS oluşturabilirsiniz.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Son Gönderimler -->
                <div class="card mt-4">
                    <div class="card-header">
                        <i class="bi bi-list-check me-2"></i> Son Gönderimler
                    </div>
                    <div class="card-body">
                        <div id="recentLogsContainer">
                            <?php if (!empty($recent_logs)): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Telefon</th>
                                                <th>Mesaj</th>
                                                <th>Bot ID</th>
                                                <th>Gönderim ID</th>
                                                <th>Durum</th>
                                                <th>Tarih</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_logs as $log): ?>
                                                <tr>
                                                    <td><?php echo $log['id']; ?></td>
                                                    <td><?php echo htmlspecialchars($log['phone']); ?></td>
                                                    <td class="text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($log['message']); ?></td>
                                                    <td class="text-truncate" style="max-width: 100px;"><?php echo htmlspecialchars($log['bot_id']); ?></td>
                                                    <td><?php echo $log['send_id'] ? $log['send_id'] : 'N/A'; ?></td>
                                                    <td class="status-<?php echo $log['queue_status']; ?>"><?php echo ucfirst($log['queue_status']); ?></td>
                                                    <td><?php echo date('d.m.Y H:i', strtotime($log['sent_at'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="logs.php" class="btn btn-outline-primary">Tüm Logları Görüntüle</a>
                                </div>
                            <?php else: ?>
                                <p class="text-center">Henüz gönderim kaydı bulunmuyor.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global değişkenler
        let isProcessing = false;
        let processingInterval = null;
        let totalSmsCount = <?php echo $pending_sms; ?>;
        let completedCount = 0;
        let successCount = 0;
        let failureCount = 0;
        
        // DOM elementleri
        const startBtn = document.getElementById('startProcessingBtn');
        const stopBtn = document.getElementById('stopProcessingBtn');
        const statusIndicator = document.getElementById('statusIndicator');
        const statusText = document.getElementById('statusText');
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        const batchSizeInput = document.getElementById('batchSize');
        const batchIntervalInput = document.getElementById('batchInterval');
        const successStatusInput = document.getElementById('successStatus');
        const logContainer = document.getElementById('logContainer');
        const completedCountEl = document.getElementById('completedCount');
        const totalCountEl = document.getElementById('totalCount');
        const successCountEl = document.getElementById('successCount');
        const failureCountEl = document.getElementById('failureCount');
        const pendingSmsCountEl = document.getElementById('pendingSmsCount');
        const sentSmsCountEl = document.getElementById('sentSmsCount');
        const failedSmsCountEl = document.getElementById('failedSmsCount');
        
        // Event listener'lar
        if (startBtn) {
            startBtn.addEventListener('click', startProcessing);
        }
        
        if (stopBtn) {
            stopBtn.addEventListener('click', stopProcessing);
        }
        
        // İşlemi başlat
        function startProcessing() {
            // Zaten çalışıyorsa çık
            if (isProcessing) return;
            
            isProcessing = true;
            
            // UI'ı güncelle
            startBtn.classList.add('d-none');
            stopBtn.classList.remove('d-none');
            statusIndicator.classList.remove('stopped');
            statusIndicator.classList.add('processing');
            statusText.textContent = 'İşleniyor...';
            progressContainer.classList.remove('d-none');
            
            // Input'ları devre dışı bırak
            batchSizeInput.disabled = true;
            batchIntervalInput.disabled = true;
            successStatusInput.disabled = true;
            
            // Log alanını temizle
            logContainer.innerHTML = '';
            
            // İlk işlemi başlat
            processBatch();
            
            // Belirli aralıklarla işlem yap
            const interval = parseInt(batchIntervalInput.value) * 1000;
            processingInterval = setInterval(processBatch, interval);
            
            // Log ekle
            addLogEntry('İşlem başlatıldı. Her ' + batchIntervalInput.value + ' saniyede bir ' + batchSizeInput.value + ' SMS işlenecek.', 'info');
        }
        
        // İşlemi durdur
        function stopProcessing() {
            if (!isProcessing) return;
            
            isProcessing = false;
            
            // Interval'ı temizle
            if (processingInterval) {
                clearInterval(processingInterval);
                processingInterval = null;
            }
            
            // UI'ı güncelle
            stopBtn.classList.add('d-none');
            startBtn.classList.remove('d-none');
            statusIndicator.classList.remove('processing');
            statusIndicator.classList.add('stopped');
            statusText.textContent = 'Durduruldu';
            
            // Input'ları etkinleştir
            batchSizeInput.disabled = false;
            batchIntervalInput.disabled = false;
            successStatusInput.disabled = false;
            
            // Log ekle
            addLogEntry('İşlem durduruldu.', 'warning');
        }
        
        // Batch işlemi
        function processBatch() {
            // İşlenecek SMS kalmadıysa işlemi durdur
            if (completedCount >= totalSmsCount) {
                stopProcessing();
                statusIndicator.classList.remove('processing', 'stopped');
                statusIndicator.classList.add('completed');
                statusText.textContent = 'Tamamlandı';
                addLogEntry('Tüm SMS\'ler işlendi. İşlem tamamlandı.', 'success');
                return;
            }
            
            const batchSize = parseInt(batchSizeInput.value);
            const successStatus = successStatusInput.value.trim();
            
            // AJAX isteği gönder
            fetch('process_sms_batch.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `batch_size=${batchSize}&success_status=${successStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    addLogEntry('Hata: ' + data.error, 'error');
                    return;
                }
                
                // Log'ları ekle
                data.logs.forEach(log => {
                    let logType = 'info';
                    if (log.includes('BAŞARILI')) logType = 'success';
                    if (log.includes('BAŞARISIZ') || log.includes('HATA')) logType = 'error';
                    addLogEntry(log, logType);
                });
                
                // Sayaçları güncelle
                completedCount += data.processed;
                successCount += data.success;
                failureCount += data.failed;
                
                // İstatistikleri güncelle
                updateStats(data.pending, data.sent, data.failed_total);
                
                // İlerlemeyi güncelle
                updateProgress();
            })
            .catch(error => {
                addLogEntry('AJAX Hatası: ' + error.message, 'error');
            });
        }
        
        // İlerleme çubuğunu güncelle
        function updateProgress() {
            const progress = Math.floor((completedCount / totalSmsCount) * 100);
            progressBar.style.width = progress + '%';
            progressBar.textContent = progress + '%';
            progressBar.setAttribute('aria-valuenow', progress);
            
            completedCountEl.textContent = completedCount;
            successCountEl.textContent = successCount;
            failureCountEl.textContent = failureCount;
        }
        
        // İstatistikleri güncelle
        function updateStats(pending, sent, failed) {
            pendingSmsCountEl.textContent = pending;
            sentSmsCountEl.textContent = sent;
            failedSmsCountEl.textContent = failed;
        }
        
        // Log ekle
        function addLogEntry(message, type) {
            const now = new Date();
            const timeStr = now.toTimeString().split(' ')[0];
            
            const logEntry = document.createElement('div');
            logEntry.className = 'log-entry';
            
            const timeSpan = document.createElement('span');
            timeSpan.className = 'log-time';
            timeSpan.textContent = timeStr;
            
            const messageSpan = document.createElement('span');
            messageSpan.className = 'log-' + type;
            messageSpan.textContent = message;
            
            logEntry.appendChild(timeSpan);
            logEntry.appendChild(messageSpan);
            
            logContainer.appendChild(logEntry);
            
            // Otomatik kaydır
            logContainer.scrollTop = logContainer.scrollHeight;
        }
        
        // Son gönderim log listesini belirli aralıklarla güncelle (2 dakikada bir)
        function refreshRecentLogs() {
            fetch('get_recent_logs.php')
            .then(response => response.text())
            .then(html => {
                document.getElementById('recentLogsContainer').innerHTML = html;
            })
            .catch(error => {
                console.error('Loglar güncellenirken hata oluştu:', error);
            });
        }
        
        // Sayfa kapatılırken onay mesajı göster (işlem devam ediyorsa)
        window.addEventListener('beforeunload', function(e) {
            if (isProcessing) {
                const message = 'İşlem hala devam ediyor. Sayfadan ayrılırsanız işlem duracak. Devam etmek istiyor musunuz?';
                e.returnValue = message;
                return message;
            }
        });
        
        // Her 2 dakikada bir son logları güncelle
        setInterval(refreshRecentLogs, 120000);
    </script>
</body>
</html>