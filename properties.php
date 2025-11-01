<?php
require 'config.php';

function generateCode($length = 6) {
    return strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
}

$message = "";

// جلب تفاصيل العقار المراد تعديله وملفاته
$edit_property = null;
$edit_files = [];
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_property = $stmt->fetch();
    if ($edit_property) {
        $stmtFiles = $pdo->prepare("SELECT * FROM property_files WHERE property_id=?");
        $stmtFiles->execute([$edit_property['id']]);
        $edit_files = $stmtFiles->fetchAll();
    }
}

// معالجة طلبات POST (إضافة وتحديث)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_property']) || isset($_POST['update_property'])) {
        $name = $_POST['name'];
        $type = $_POST['type'];
        $address = $_POST['address'];
        $description = $_POST['description'];

        if (isset($_POST['update_property']) && !empty($_POST['property_id'])) {
            $property_id = $_POST['property_id'];
            $stmt = $pdo->prepare("UPDATE properties SET name=?, type=?, address=?, description=? WHERE id=?");
            $stmt->execute([$name, $type, $address, $description, $property_id]);
        } else {
            $code = generateCode();
            $stmt = $pdo->prepare("INSERT INTO properties (code, name, type, address, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$code, $name, $type, $address, $description]);
            $property_id = $pdo->lastInsertId();
        }

        // رفع ملفات جديدة
        if (!empty($_FILES['files']['name'][0])) {
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
                $originalName = $_FILES['files']['name'][$key];
                $fileName = uniqid() . "-" . basename($originalName);
                $targetFilePath = $uploadDir . $fileName;
                if (move_uploaded_file($tmp_name, $targetFilePath)) {
                    $mimeType = mime_content_type($targetFilePath);
                    $stmtFile = $pdo->prepare("INSERT INTO property_files (property_id, file_name, original_name, mime_type) VALUES (?, ?, ?, ?)");
                    $stmtFile->execute([$property_id, $fileName, $originalName, $mimeType]);
                }
            }
        }
        
        $message = "تم " . (isset($_POST['update_property']) ? "تحديث" : "إضافة") . " العقار بنجاح.";
        header("Location: properties.php?message=" . urlencode($message));
        exit();
    }
}

// معالجة طلبات GET (حذف ملف أو عقار)
if(isset($_GET['delete_file']) && ctype_digit($_GET['delete_file'])){
    $file_id = $_GET['delete_file'];
    $stmtFile = $pdo->prepare("SELECT file_name, property_id FROM property_files WHERE id=?");
    $stmtFile->execute([$file_id]);
    $f = $stmtFile->fetch();
    if($f){
        $p='uploads/'.$f['file_name'];
        if(file_exists($p)) unlink($p);
        $pdo->prepare("DELETE FROM property_files WHERE id=?")->execute([$file_id]);
    }
    header("Location: properties.php?edit=".$f['property_id']);
    exit;
}
if(isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $del_id = $_GET['delete'];
    $filesStmt = $pdo->prepare("SELECT file_name FROM property_files WHERE property_id=?");
    $filesStmt->execute([$del_id]);
    foreach($filesStmt->fetchAll() as $f){
        $p='uploads/'.$f['file_name'];
        if(file_exists($p)) unlink($p);
    }
    $pdo->prepare("DELETE FROM property_files WHERE property_id=?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM properties WHERE id=?")->execute([$del_id]);
    header("Location: properties.php?message=" . urlencode("تم حذف العقار."));
    exit;
}

// جلب جميع العقارات بناءً على البحث
$searchTerm = $_GET['search'] ?? '';
$sql = "SELECT * FROM properties WHERE 1=1 ";
$params = [];

if (!empty($searchTerm)) {
    $sql .= "AND (name LIKE ? OR code LIKE ? OR type LIKE ? OR address LIKE ?)";
    $searchWildcard = "%" . $searchTerm . "%";
    $params = [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard];
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$properties = $stmt->fetchAll();

$files_per_property = [];
if (!empty($properties)) {
    $propertyIds = array_column($properties, 'id');
    $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));
    $filesStmt = $pdo->prepare("SELECT * FROM property_files WHERE property_id IN ($placeholders)");
    $filesStmt->execute($propertyIds);
    $allFiles = $filesStmt->fetchAll();
    
    foreach($allFiles as $f) {
        $files_per_property[$f['property_id']][] = $f;
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8" />
<title>إدارة العقارات</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
body{padding:20px;background:#f8f9fa;}
.file-thumb{cursor:pointer;display:inline-block;text-align:center;margin:3px;}
.file-thumb img{width:60px;height:60px;object-fit:cover;border-radius:5px;border:1px solid #ccc;}
.file-thumb div{padding:3px;border:1px solid #ccc;border-radius:5px;width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.modal-img{width:100%;height:80vh;object-fit:contain;}
.modal-doc{width:100%;height:80vh;}
</style>
</head>
<body>
<div class="container">
<h2 class="mb-4">إدارة العقارات</h2>

<?php if (isset($_GET['message'])): ?>
<div class="alert alert-success"><?= htmlspecialchars($_GET['message']) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="row g-3 mb-4">
    <h4><?= $edit_property ? "تعديل العقار" : "إضافة عقار جديد" ?></h4>
    <?php if ($edit_property): ?>
        <input type="hidden" name="property_id" value="<?= htmlspecialchars($edit_property['id']) ?>" />
    <?php endif; ?>
    
    <div class="col-md-6">
        <label class="form-label">اسم العقار:</label>
        <input type="text" name="name" required class="form-control" value="<?= $edit_property ? htmlspecialchars($edit_property['name']) : '' ?>" />
    </div>
    
    <div class="col-md-6">
        <label class="form-label">نوع العقار:</label>
        <input type="text" name="type" required class="form-control" value="<?= $edit_property ? htmlspecialchars($edit_property['type']) : '' ?>" />
    </div>

    <div class="col-md-6">
        <label class="form-label">عنوان العقار:</label>
        <textarea name="address" class="form-control" rows="2"><?= $edit_property ? htmlspecialchars($edit_property['address']) : '' ?></textarea>
    </div>

    <div class="col-md-6">
        <label class="form-label">وصف / ملاحظات:</label>
        <textarea name="description" class="form-control" rows="2"><?= $edit_property ? htmlspecialchars($edit_property['description']) : '' ?></textarea>
    </div>
    
    <div class="col-md-6">
        <label class="form-label">رفع ملفات جديدة:</label>
        <input type="file" name="files[]" multiple class="form-control">
    </div>

    <?php if ($edit_files): ?>
        <div class="col-md-12">
            <label class="form-label">الملفات الحالية</label>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($edit_files as $k => $f):
                    $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
                    $path = 'uploads/'.$f['file_name'];
                ?>
                    <div class="file-thumb text-center" onclick='openModal(<?= json_encode($edit_files) ?>, <?= $k ?>)'>
                        <?php if(in_array($ext,['jpg','jpeg','png'])): ?>
                            <img src="<?= $path ?>">
                        <?php elseif($ext==='pdf'): ?>
                            <div>PDF</div>
                        <?php else: ?>
                            <div><?= htmlspecialchars($f['original_name']) ?></div>
                        <?php endif; ?>
                        <div>
                            <a href="properties.php?delete_file=<?= $f['id'] ?>&edit=<?= $edit_property['id'] ?>" class="btn btn-sm btn-danger mt-1"
                                onclick="return confirm('هل تريد حذف هذا الملف؟')">حذف</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="col-12 d-flex gap-2">
        <button type="submit" name="<?= $edit_property ? 'update_property' : 'add_property' ?>" class="btn btn-primary">
            <?= $edit_property ? 'تحديث العقار' : 'حفظ العقار' ?>
        </button>
        <a href="properties.php" class="btn btn-secondary">تفريغ النموذج</a>
        <a href="index.php" class="btn btn-secondary">عودة للرئيسية</a>
    </div>
</form>

<hr>

<form method="get" class="mb-4">
    <div class="input-group">
        <input type="text" name="search" class="form-control" placeholder="بحث باسم العقار، الكود، النوع، أو العنوان..." value="<?= htmlspecialchars($searchTerm) ?>">
        <button class="btn btn-primary" type="submit">بحث</button>
        <a href="properties.php" class="btn btn-outline-secondary">عرض الكل</a>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-bordered table-striped align-middle">
        <thead>
            <tr>
                <th>#</th>
                <th>الكود</th>
                <th>الاسم</th>
                <th>النوع</th>
                <th>العنوان</th>
                <th>الملفات</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($properties)): ?>
                <tr>
                    <td colspan="7" class="text-center">لا توجد عقارات مطابقة.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($properties as $prop): ?>
                <tr>
                    <td><?= (int)$prop['id'] ?></td>
                    <td><?= htmlspecialchars($prop['code']) ?></td>
                    <td><?= htmlspecialchars($prop['name']) ?></td>
                    <td><?= htmlspecialchars($prop['type']) ?></td>
                    <td><?= nl2br(htmlspecialchars($prop['address'])) ?></td>
                    <td>
                        <?php 
                        $propFiles = $files_per_property[$prop['id']] ?? [];
                        foreach($propFiles as $k=>$f):
                            $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
                            $path='uploads/'.$f['file_name'];
                        ?>
                        <div class="file-thumb" onclick='openModal(<?= json_encode($propFiles) ?>, <?= $k ?>)'>
                            <?php if(in_array($ext,['jpg','jpeg','png'])): ?>
                                <img src="<?= $path ?>">
                            <?php elseif($ext==='pdf'): ?>
                                <div>PDF</div>
                            <?php else: ?>
                                <div><?= htmlspecialchars($f['original_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <a class="btn btn-sm btn-warning" href="properties.php?edit=<?= (int)$prop['id'] ?>">تعديل</a>
                        <a class="btn btn-sm btn-danger" onclick="return confirm('هل تريد حذف هذا العقار؟');" href="properties.php?delete=<?= (int)$prop['id'] ?>">حذف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="modal fade" id="fileModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">عرض الملف</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalImage" class="modal-img d-none">
                <iframe id="modalPDF" class="modal-doc d-none"></iframe>
                <div id="modalDocDownload" class="d-none">
                    <a id="downloadLink" href="#" class="btn btn-primary mt-3" download>تحميل الملف</a>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="prevFile()">السابق</button>
                <button type="button" class="btn btn-secondary" onclick="nextFile()">التالي</button>
                <button type="button" class="btn btn-success" onclick="printFile()">طباعة</button>
                <a id="downloadBtn" class="btn btn-info" download>تنزيل</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let files=[], currentIndex=0;
function openModal(fileArray,index){
    files=fileArray; currentIndex=index; showFile();
    new bootstrap.Modal(document.getElementById('fileModal')).show();
}
function showFile(){
    const img=document.getElementById('modalImage');
    const pdf=document.getElementById('modalPDF');
    const doc=document.getElementById('modalDocDownload');
    const dl=document.getElementById('downloadBtn');
    const prevBtn=document.querySelector('.modal-footer button:nth-of-type(1)');
    const nextBtn=document.querySelector('.modal-footer button:nth-of-type(2)');

    if(!files[currentIndex]) return;
    let f=files[currentIndex];
    let ext=f.original_name.split('.').pop().toLowerCase();
    let path='uploads/'+f.file_name;
    img.classList.add('d-none'); pdf.classList.add('d-none'); doc.classList.add('d-none');
    dl.href=path; dl.download=f.original_name;

    prevBtn.disabled = (currentIndex === 0);
    nextBtn.disabled = (currentIndex === files.length - 1);
    
    if(['jpg','jpeg','png'].includes(ext)){ img.src=path; img.classList.remove('d-none'); }
    else if(ext==='pdf'){ pdf.src=path; pdf.classList.remove('d-none'); }
    else { document.getElementById('downloadLink').href=path; doc.classList.remove('d-none'); }
}
function prevFile(){ if(currentIndex>0){currentIndex--; showFile();} }
function nextFile(){ if(currentIndex<files.length-1){currentIndex++; showFile();} }
function printFile(){
    let f=files[currentIndex];
    let ext=f.original_name.split('.').pop().toLowerCase();
    let path='uploads/'+f.file_name;
    if(['jpg','jpeg','png','pdf'].includes(ext)){
        let win=window.open(path,'_blank');
        win.onload=function(){win.print();}
    } else {
        alert("لا يمكن طباعة هذا النوع من الملفات");
    }
}
</script>
</body>
</html>