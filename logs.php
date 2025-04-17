<?php
session_start();
require_once 'database.php';

$database = new Database();
$db = $database->connect();

// Filtreleme parametreleri
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$phone_filter = isset($_GET['phone']) ? $_GET['phone'] : '';

// Sayfalama için
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// SQL sorgusu oluştur
$query_params = [];
$where_clauses = [];

if (!empty($status_filter)) {
    $where_clauses[] = "sq.status = :status";
    $query_params[':status'] = $status_filter;
}

if (!empty($date_from)) {
    $where_clauses[] = "sl.sent_at >= :date_from";
    $query_params[':date_from'] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $where_clauses[] = "sl.sent_at <= :date_to";
    $query_params[':date_to'] = $date_to . ' 23:59:59';
}

if (!empty($phone_filter)) {
    $where_clauses[] = "sl.phone LIKE :phone";
    $query_params[':phone'] = '%' . $phone_filter . '%';
}

$where_clause = empty($where_clauses) ? "" : "WHERE " . implode(" AND ", $where_clauses);

// Toplam kayıt sayısı
try {
    $count_query = "SELECT COUNT(*) as total FROM sms_logs sl JOIN sms_queue sq ON sl.queue_id = sq.id $where_clause";
    $count_stmt = $db->prepare($count_query);
    foreach ($query_params as $param => $value) {
        $count_stmt->bindValue($param, $value);
    }
    $count_stmt->execute();
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $per_page);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Log kayıtlarını getir
try {
    $query = "SELECT sl.*, sq.status as queue_status 
              FROM sms_logs sl 
              JOIN sms_queue sq ON sl.queue_id = sq.id 
              $where_clause
              ORDER BY sl.id DESC 
              LIMIT :offset, :per_page";
    
    $stmt = $db->prepare($query);
    foreach ($query_params as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
    $logs = [];
}

// Log detayı görüntüle
if (isset($_GET['view_log']) && $_GET['view_log'] > 0) {
    $log_id = (int)$_GET['view_log'];
    
    try {
        $query = "SELECT * FROM sms_logs WHERE id = :id LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $log_id);
        $stmt->execute();
        $log_detail = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($log_detail) {
            // Response data'yı parse et ve düzenle
            $response_data = $log_detail['response_data'];
        }
    } catch (PDOException $e) {
        $error = "Veritabanı hatası: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Sistemi - Gönderim Logları</title>
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
        .log-detail-container {
            max-height: 500px;
            overflow-y: auto;
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
                    </li><li class="nav-item">
                        <a class="nav-link active" href="logs.php">Gönderim Logları</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="fetch_bots.php">Bot ID Çekici</a>
                    </li>
                </ul>
                
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-list-check me-2"></i> SMS Gönderim Logları
                    </div>
                    <div class="card-body">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <!-- Filtreleme Formu -->
                        <form method="get" action="" class="mb-4">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="status" class="form-label">Durum:</label>
                                    <select name="status" id="status" class="form-select">
                                        <option value="">Tümü</option>
                                        <option value="sent" <?php echo $status_filter == 'sent' ? 'selected' : ''; ?>>Gönderildi</option>
                                        <option value="failed" <?php echo $status_filter == 'failed' ? 'selected' : ''; ?>>Başarısız</option>
                                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Beklemede</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="date_from" class="form-label">Başlangıç Tarihi:</label>
                                    <input type="date" name="date_from" id="date_from" class="form-control" value="<?php echo $date_from; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="date_to" class="form-label">Bitiş Tarihi:</label>
                                    <input type="date" name="date_to" id="date_to" class="form-control" value="<?php echo $date_to; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="phone" class="form-label">Telefon Numarası:</label>
                                    <input type="text" name="phone" id="phone" class="form-control" value="<?php echo htmlspecialchars($phone_filter); ?>" placeholder="Telefon ara...">
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-filter"></i> Filtrele
                                    </button>
                                    <a href="logs.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-x-circle"></i> Filtreleri Temizle
                                    </a>
                                </div>
                            </div>
                        </form>
                        
                        <!-- Log Listesi -->
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
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($logs)): ?>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td><?php echo $log['id']; ?></td>
                                                <td><?php echo htmlspecialchars($log['phone']); ?></td>
                                                <td class="text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($log['message']); ?></td>
                                                <td class="text-truncate" style="max-width: 100px;"><?php echo htmlspecialchars($log['bot_id']); ?></td>
                                                <td><?php echo $log['send_id'] ? $log['send_id'] : 'N/A'; ?></td>
                                                <td class="status-<?php echo $log['queue_status']; ?>"><?php echo ucfirst($log['queue_status']); ?></td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($log['sent_at'])); ?></td>
                                                <td>
                                                    <a href="?view_log=<?php echo $log['id']; ?><?php echo !empty($_SERVER['QUERY_STRING']) ? '&' . $_SERVER['QUERY_STRING'] : ''; ?>" class="btn btn-sm btn-info">
                                                        <i class="bi bi-eye"></i> Detay
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center">Kayıt bulunamadı.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Sayfalama -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Sayfalama" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>" aria-label="Önceki">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                    
                                    <?php 
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $start_page + 4);
                                    
                                    if ($start_page > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="?page=1' . (!empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : '') . '">1</a></li>';
                                        if ($start_page > 2) {
                                            echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++): 
                                    ?>
                                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php 
                                    endfor;
                                    
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                                        }
                                        echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . (!empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : '') . '">' . $total_pages . '</a></li>';
                                    }
                                    ?>
                                    
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>" aria-label="Sonraki">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                        
                    </div>
                </div>
                
                <!-- Log Detayı Modal -->
                <?php if (isset($log_detail)): ?>
                <div class="modal fade" id="logDetailModal" tabindex="-1" aria-labelledby="logDetailModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="logDetailModalLabel">Log Detayı #<?php echo $log_detail['id']; ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                            </div>
                            <div class="modal-body">
                                <div class="log-detail-container">
                                    <dl class="row">
                                        <dt class="col-sm-3">Telefon:</dt>
                                        <dd class="col-sm-9"><?php echo htmlspecialchars($log_detail['phone']); ?></dd>
                                        
                                        <dt class="col-sm-3">Mesaj:</dt>
                                        <dd class="col-sm-9"><?php echo htmlspecialchars($log_detail['message']); ?></dd>
                                        
                                        <dt class="col-sm-3">Bot ID:</dt>
                                        <dd class="col-sm-9"><?php echo htmlspecialchars($log_detail['bot_id']); ?></dd>
                                        
                                        <dt class="col-sm-3">Kuyruk ID:</dt>
                                        <dd class="col-sm-9"><?php echo $log_detail['queue_id']; ?></dd>
                                        
                                        <dt class="col-sm-3">Gönderim ID:</dt>
                                        <dd class="col-sm-9"><?php echo $log_detail['send_id'] ? $log_detail['send_id'] : 'N/A'; ?></dd>
                                        
                                        <dt class="col-sm-3">Durum:</dt>
                                        <dd class="col-sm-9"><?php echo $log_detail['status']; ?></dd>
                                        
                                        <dt class="col-sm-3">Gönderim Tarihi:</dt>
                                        <dd class="col-sm-9"><?php echo date('d.m.Y H:i:s', strtotime($log_detail['sent_at'])); ?></dd>
                                        
                                        <dt class="col-sm-3">Güncelleme Tarihi:</dt>
                                        <dd class="col-sm-9"><?php echo date('d.m.Y H:i:s', strtotime($log_detail['updated_at'])); ?></dd>
                                        
                                        <dt class="col-sm-12">API Yanıtı:</dt>
                                        <dd class="col-sm-12">
                                            <div class="card">
                                                <div class="card-body">
                                                    <pre class="text-muted" style="max-height: 300px; overflow-y: auto;"><?php 
                                                        echo htmlspecialchars(substr($log_detail['response_data'], 0, 1000)); 
                                                        if (strlen($log_detail['response_data']) > 1000) {
                                                            echo "\n... (yanıt çok uzun, ilk 1000 karakter gösteriliyor)";
                                                        }
                                                    ?></pre>
                                                </div>
                                            </div>
                                        </dd>
                                    </dl>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    // Sayfa yüklenir yüklenmez modalı göster
                    document.addEventListener('DOMContentLoaded', function() {
                        var logDetailModal = new bootstrap.Modal(document.getElementById('logDetailModal'));
                        logDetailModal.show();
                    });
                </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>