<?php
session_start();
require_once 'database.php';

$database = new Database();
$db = $database->connect();

// Tek numara ekleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_phone'])) {
    $phone = trim($_POST['phone']);
    $name = trim($_POST['name']);
    
    if (empty($phone)) {
        $error = "Telefon numarası boş olamaz.";
    } else {
        try {
            $query = "INSERT INTO phones (phone, name) VALUES (:phone, :name)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':name', $name);
            
            if ($stmt->execute()) {
                $success = "Telefon numarası başarıyla eklendi.";
            } else {
                $error = "Telefon numarası eklenirken bir hata oluştu.";
            }
        } catch (PDOException $e) {
            $error = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

// CSV Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_phones'])) {
    if (!empty($_FILES['csv_file']['name'])) {
        $file_info = pathinfo($_FILES['csv_file']['name']);
        
        if ($file_info['extension'] == 'csv') {
            $filename = $_FILES['csv_file']['tmp_name'];
            
            if (($handle = fopen($filename, 'r')) !== FALSE) {
                $imported = 0;
                $errors = 0;
                
                try {
                    $db->beginTransaction();
                    
                    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                        if (count($data) >= 2) {
                            $phone = trim($data[0]);
                            $name = trim($data[1]);
                            
                            if (!empty($phone)) {
                                $query = "INSERT INTO phones (phone, name) VALUES (:phone, :name)";
                                $stmt = $db->prepare($query);
                                $stmt->bindParam(':phone', $phone);
                                $stmt->bindParam(':name', $name);
                                
                                if ($stmt->execute()) {
                                    $imported++;
                                } else {
                                    $errors++;
                                }
                            }
                        }
                    }
                    
                    $db->commit();
                    $import_success = "Toplam $imported numara başarıyla içe aktarıldı. $errors hata oluştu.";
                    
                } catch (PDOException $e) {
                    $db->rollBack();
                    $import_error = "Veritabanı hatası: " . $e->getMessage();
                }
                
                fclose($handle);
            } else {
                $import_error = "Dosya açılamadı.";
            }
        } else {
            $import_error = "Lütfen geçerli bir CSV dosyası yükleyin.";
        }
    } else {
        $import_error = "Lütfen bir dosya seçin.";
    }
}

// Telefon silme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_phone'])) {
    $phone_id = $_POST['delete_phone'];
    
    try {
        $query = "DELETE FROM phones WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $phone_id);
        
        if ($stmt->execute()) {
            $success = "Telefon numarası başarıyla silindi.";
        } else {
            $error = "Telefon numarası silinirken bir hata oluştu.";
        }
    } catch (PDOException $e) {
        $error = "Veritabanı hatası: " . $e->getMessage();
    }
}

// Toplu silme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_phones'])) {
    try {
        $query = "TRUNCATE TABLE phones";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute()) {
            $success = "Tüm telefon numaraları başarıyla silindi.";
        } else {
            $error = "Telefon numaraları silinirken bir hata oluştu.";
        }
    } catch (PDOException $e) {
        $error = "Veritabanı hatası: " . $e->getMessage();
    }
}

// Sayfalama için
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Toplam kayıt sayısı
try {
    $count_query = "SELECT COUNT(*) as total FROM phones";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute();
    $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $per_page);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}

// Telefon listesini getir
try {
    $query = "SELECT * FROM phones ORDER BY id DESC LIMIT :offset, :per_page";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindParam(':per_page', $per_page, PDO::PARAM_INT);
    $stmt->execute();
    $phones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Veritabanı hatası: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Sistemi - Telefon Numaraları</title>
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
                        <a class="nav-link" href="settings.php">Ayarlar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">Mesaj Yönetimi</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="phones.php">Telefon Numaraları</a>
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
                        <i class="bi bi-telephone-fill me-2"></i> Telefon Numaraları Yönetimi
                    </div>
                    <div class="card-body">
                        <?php if (isset($success)): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="pills-add-tab" data-bs-toggle="pill" data-bs-target="#pills-add" type="button" role="tab" aria-controls="pills-add" aria-selected="true">Tek Numara Ekle</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="pills-import-tab" data-bs-toggle="pill" data-bs-target="#pills-import" type="button" role="tab" aria-controls="pills-import" aria-selected="false">Toplu İçe Aktar (CSV)</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="pills-tabContent">
                            <div class="tab-pane fade show active" id="pills-add" role="tabpanel" aria-labelledby="pills-add-tab">
                                <form method="post" action="" class="mb-4">
                                    <div class="row">
                                        <div class="col-md-5">
                                            <label for="phone" class="form-label">Telefon Numarası:</label>
                                            <input type="text" name="phone" id="phone" class="form-control" placeholder="1555666222" required>
                                        </div>
                                        <div class="col-md-5">
                                            <label for="name" class="form-label">İsim Soyisim:</label>
                                            <input type="text" name="name" id="name" class="form-control" placeholder="Ahmet Yılmaz">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="submit" name="add_phone" class="btn btn-primary w-100">Ekle</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="tab-pane fade" id="pills-import" role="tabpanel" aria-labelledby="pills-import-tab">
                                <?php if (isset($import_success)): ?>
                                    <div class="alert alert-success"><?php echo $import_success; ?></div>
                                <?php endif; ?>
                                
                                <?php if (isset($import_error)): ?>
                                    <div class="alert alert-danger"><?php echo $import_error; ?></div>
                                <?php endif; ?>
                                
                                <form method="post" action="" enctype="multipart/form-data" class="mb-4">
                                    <div class="mb-3">
                                        <label for="csv_file" class="form-label">CSV Dosyası Seçin:</label>
                                        <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                                        <div class="form-text">
                                            CSV dosyanız şu formatta olmalıdır: <code>telefon,isim</code><br>
                                            Örnek: <code>1555666222,Ahmet Yılmaz</code>
                                        </div>
                                    </div>
                                    <button type="submit" name="import_phones" class="btn btn-primary">İçe Aktar</button>
                                </form>
                                
                                <a href="sample_phones.csv" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-download"></i> Örnek CSV Dosyasını İndir
                                </a>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Telefon Numaraları Listesi (Toplam: <?php echo $total_records; ?>)</h5>
                            <form method="post" action="" onsubmit="return confirm('Tüm telefon numaralarını silmek istediğinizden emin misiniz?');">
                                <button type="submit" name="delete_all_phones" class="btn btn-danger btn-sm">
                                    <i class="bi bi-trash"></i> Tümünü Sil
                                </button>
                            </form>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Telefon</th>
                                        <th>İsim</th>
                                        <th>Eklenme Tarihi</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($phones)): ?>
                                        <?php foreach ($phones as $index => $phone): ?>
                                            <tr>
                                                <td><?php echo $offset + $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($phone['phone']); ?></td>
                                                <td><?php echo htmlspecialchars($phone['name'] ?: 'Belirtilmemiş'); ?></td>
                                                <td><?php echo date('d.m.Y H:i', strtotime($phone['created_at'])); ?></td>
                                                <td>
                                                    <form method="post" action="" class="d-inline">
                                                        <input type="hidden" name="delete_phone" value="<?php echo $phone['id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Bu numarayı silmek istediğinizden emin misiniz?');">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center">Henüz telefon numarası eklenmemiş.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Sayfalama">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Önceki">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                    
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Sonraki">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>