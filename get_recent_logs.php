<?php
/**
 * Son gönderim loglarını getiren AJAX endpoint
 * Belirli aralıklarla bu endpoint çağrılarak son loglar güncellenebilir
 */
require_once 'database.php';

try {
    $database = new Database();
    $db = $database->connect();
    
    $query = "SELECT sl.*, sq.status as queue_status 
              FROM sms_logs sl 
              JOIN sms_queue sq ON sl.queue_id = sq.id 
              ORDER BY sl.id DESC LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($recent_logs)) {
        ?>
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
        <?php
    } else {
        echo '<p class="text-center">Henüz gönderim kaydı bulunmuyor.</p>';
    }
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Veritabanı hatası: ' . $e->getMessage() . '</div>';
}