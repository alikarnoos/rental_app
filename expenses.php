<?php
require 'config.php';

// جلب العقارات والوحدات
$properties = $pdo->query("SELECT id, name FROM properties ORDER BY name")->fetchAll();
$units = $pdo->query("SELECT u.id, u.code, p.name AS property_name 
                      FROM units u 
                      LEFT JOIN properties p ON p.id=u.property_id 
                      ORDER BY p.name, u.code")->fetchAll();

// تعديل إذا وُجد
$edit_item = null;
$edit_files = [];
if (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
    $stmt->execute([$_GET['edit']]);
    $edit_item = $stmt->fetch();
    if ($edit_item) {
        $stmtFiles = $pdo->prepare("SELECT * FROM expense_files WHERE expense_id=?");
        $stmtFiles->execute([$edit_item['id']]);
        $edit_files = $stmtFiles->fetchAll();
    }
}

// حفظ أو تحديث
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_expense'])) {
    $title = trim($_POST['title']);
    $property_id = !empty($_POST['property_id']) ? $_POST['property_id'] : null;
    $unit_id = !empty($_POST['unit_id']) ? $_POST['unit_id'] : null;
    $amount = (float) $_POST['amount'];
    $currency = $_POST['currency'];
    $expense_date = $_POST['expense_date'];
    $notes = trim($_POST['notes']);

    if (!empty($_POST['expense_id'])) {
        $id = (int) $_POST['expense_id'];
        $stmt = $pdo->prepare("UPDATE expenses 
                                SET title=?, property_id=?, unit_id=?, amount=?, currency=?, expense_date=?, notes=? 
                                WHERE id=?");
        $stmt->execute([$title,$property_id,$unit_id,$amount,$currency,$expense_date,$notes,$id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO expenses (title,property_id,unit_id,amount,currency,expense_date,notes) 
                                VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$title,$property_id,$unit_id,$amount,$currency,$expense_date,$notes]);
        $id = $pdo->lastInsertId();
    }

    // رفع ملفات جديدة
    if(!empty($_FILES['files']['name'][0])) {
        $uploadDir = 'uploads/expenses/';
        if(!is_dir($uploadDir)) mkdir($uploadDir,0777,true);
        foreach($_FILES['files']['tmp_name'] as $k=>$tmp_name){
            $originalName = $_FILES['files']['name'][$k];
            $fileName = uniqid()."-".basename($originalName);
            $target = $uploadDir.$fileName;
            if(move_uploaded_file($tmp_name,$target)){
                $mime = mime_content_type($target);
                $stmtFile = $pdo->prepare("INSERT INTO expense_files (expense_id,file_name,original_name,mime_type) VALUES (?,?,?,?)");
                $stmtFile->execute([$id,$fileName,$originalName,$mime]);
            }
        }
    }

    header("Location: expenses.php");
    exit;
}

// حذف ملف محدد
if(isset($_GET['delete_file']) && ctype_digit($_GET['delete_file'])){
    $file_id = $_GET['delete_file'];
    $stmtFile = $pdo->prepare("SELECT file_name, expense_id FROM expense_files WHERE id=?");
    $stmtFile->execute([$file_id]);
    $f = $stmtFile->fetch();
    if($f){
        $p='uploads/expenses/'.$f['file_name']; 
        if(file_exists($p)) unlink($p); 
        $pdo->prepare("DELETE FROM expense_files WHERE id=?")->execute([$file_id]);
    }
    header("Location: expenses.php?edit=".$f['expense_id']); 
    exit;
}

// حذف مصروف كامل
if(isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $del_id = $_GET['delete'];
    $filesStmt = $pdo->prepare("SELECT file_name FROM expense_files WHERE expense_id=?");
    $filesStmt->execute([$del_id]);
    foreach($filesStmt->fetchAll() as $f){ 
        $p='uploads/expenses/'.$f['file_name']; 
        if(file_exists($p)) unlink($p); 
    }
    $pdo->prepare("DELETE FROM expense_files WHERE expense_id=?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$del_id]);
    header("Location: expenses.php"); exit;
}

// جلب جميع المصروفات
$rows = $pdo->query("SELECT e.*, p.name AS property_name, u.code AS unit_code 
                      FROM expenses e 
                      LEFT JOIN properties p ON p.id=e.property_id 
                      LEFT JOIN units u ON u.id=e.unit_id 
                      ORDER BY e.expense_date DESC,e.id DESC")->fetchAll();

// جلب الملفات لكل مصروف
$files_per_expense = [];
foreach($rows as $r){
    $stmtF = $pdo->prepare("SELECT * FROM expense_files WHERE expense_id=?");
    $stmtF->execute([$r['id']]);
    $files_per_expense[$r['id']] = $stmtF->fetchAll();
}

// المجاميع
$total_iqd=$total_usd=0;
foreach($rows as $r){ 
    if($r['currency']==='IQD') $total_iqd+=(float)$r['amount']; 
    if($r['currency']==='USD') $total_usd+=(float)$r['amount']; 
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>إدارة المصروفات</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
body{padding:20px;background:#f8f9fa;}
.file-thumb{cursor:pointer;display:inline-block;text-align:center;margin:3px;}
.file-thumb img{width:60px;height:60px;object-fit:cover;border-radius:5px;border:1px solid #ccc;}
.file-thumb div{padding:3px;border:1px solid #ccc;border-radius:5px;width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.modal-img{width:100%;height:80vh;object-fit:contain;}
.modal-doc{width:100%;height:80vh;}
/* ADDED: Style for Select2 to match bootstrap */
.select2-container .select2-selection--single { height: 38px; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
</style>
</head>
<body>
<div class="container">
<h2 class="mb-4">إدارة المصروفات</h2>

<form method="post" enctype="multipart/form-data" class="row g-3 mb-3">
<input type="hidden" name="expense_id" value="<?= $edit_item ? (int)$edit_item['id'] : '' ?>">

<div class="col-md-4">
<label class="form-label">عنوان الفاتورة</label>
<input type="text" name="title" required class="form-control" value="<?= $edit_item ? htmlspecialchars($edit_item['title']) : '' ?>">
</div>

<div class="col-md-3">
<label class="form-label">العقار</label>
<select name="property_id" class="form-select select2-search">
<option value="">— بدون —</option>
<?php foreach ($properties as $p): ?>
<option value="<?= $p['id'] ?>" <?= $edit_item && $edit_item['property_id']==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-3">
<label class="form-label">الوحدة</label>
<select name="unit_id" class="form-select select2-search">
<option value="">— بدون —</option>
<?php foreach ($units as $u): ?>
<option value="<?= $u['id'] ?>" <?= $edit_item && $edit_item['unit_id']==$u['id']?'selected':'' ?>><?= htmlspecialchars($u['property_name'].' - '.$u['code']) ?></option>
<?php endforeach; ?>
</select>
</div>

<div class="col-md-2">
<label class="form-label">المبلغ</label>
<input type="number" step="0.01" min="0" name="amount" required class="form-control" value="<?= $edit_item ? htmlspecialchars($edit_item['amount']) : '' ?>">
</div>

<div class="col-md-2">
<label class="form-label">العملة</label>
<select name="currency" class="form-select" required>
<option value="IQD" <?= $edit_item && $edit_item['currency']==='IQD'?'selected':'' ?>>IQD</option>
<option value="USD" <?= $edit_item && $edit_item['currency']==='USD'?'selected':'' ?>>USD</option>
</select>
</div>

<div class="col-md-3">
<label class="form-label">تاريخ الفاتورة</label>
<input type="date" name="expense_date" required class="form-control" value="<?= $edit_item ? htmlspecialchars($edit_item['expense_date']) : '' ?>">
</div>

<div class="col-md-6">
<label class="form-label">ملاحظات</label>
<input type="text" name="notes" class="form-control" value="<?= $edit_item ? htmlspecialchars($edit_item['notes']) : '' ?>">
</div>

<div class="col-md-6">
<label class="form-label">رفع ملفات جديدة</label>
<input type="file" name="files[]" class="form-control" multiple>
</div>

<?php if ($edit_files): ?>
<div class="col-md-12">
  <label class="form-label">الملفات الحالية</label>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($edit_files as $f): 
      $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
      $path = 'uploads/expenses/'.$f['file_name'];
    ?>
      <div class="file-thumb text-center" onclick='openModal(<?= json_encode($edit_files) ?>, <?= array_search($f,$edit_files) ?>)'>
        <?php if(in_array($ext,['jpg','jpeg','png'])): ?>
          <img src="<?= $path ?>">
        <?php elseif($ext==='pdf'): ?>
          <div>PDF</div>
        <?php else: ?>
          <div><?= htmlspecialchars($f['original_name']) ?></div>
        <?php endif; ?>
        <div>
          <a href="expenses.php?delete_file=<?= $f['id'] ?>&edit=<?= $edit_item['id'] ?>" class="btn btn-sm btn-danger mt-1"
             onclick="return confirm('هل تريد حذف هذا الملف؟')">حذف</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="col-12 d-flex gap-2">
<button type="submit" name="save_expense" class="btn btn-primary"><?= $edit_item?'تحديث الفاتورة':'حفظ الفاتورة' ?></button>
<a href="expenses.php" class="btn btn-secondary">تفريغ النموذج</a>
<a href="expenses_search.php" class="btn btn-dark">البحث المتقدم للمصروفات</a>
<a href="index.php" class="btn btn-secondary">عودة للرئيسية</a>
</div>
</form>

<hr>
<div class="table-responsive">
<table class="table table-bordered table-striped align-middle">
<thead>
<tr><th>#</th><th>العنوان</th><th>العقار</th><th>الوحدة</th><th>المبلغ</th><th>العملة</th><th>تاريخ</th><th>ملفات</th><th>إجراءات</th></tr>
</thead>
<tbody>
<?php foreach($rows as $r): ?>
<tr>
<td><?= (int)$r['id'] ?></td>
<td><?= htmlspecialchars($r['title']) ?></td>
<td><?= htmlspecialchars($r['property_name']?:'-') ?></td>
<td><?= htmlspecialchars($r['unit_code']?:'-') ?></td>
<td><?= number_format($r['amount'],2) ?></td>
<td><?= htmlspecialchars($r['currency']) ?></td>
<td><?= htmlspecialchars($r['expense_date']) ?></td>
<td>
<?php foreach($files_per_expense[$r['id']] as $k=>$f):
$ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
$path='uploads/expenses/'.$f['file_name'];
?>
<div class="file-thumb" onclick='openModal(<?= json_encode($files_per_expense[$r['id']]) ?>, <?= $k ?>)'>
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
<a class="btn btn-sm btn-warning" href="expenses.php?edit=<?= (int)$r['id'] ?>">تعديل</a>
<a class="btn btn-sm btn-danger" onclick="return confirm('هل تريد حذف هذه الفاتورة؟');" href="expenses.php?delete=<?= (int)$r['id'] ?>">حذف</a>
</td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr><th colspan="4" class="text-end">الإجمالي IQD:</th><th><?= number_format($total_iqd,2) ?></th><th colspan="4"></th></tr>
<tr><th colspan="4" class="text-end">الإجمالي USD:</th><th><?= number_format($total_usd,2) ?></th><th colspan="4"></th></tr>
</tfoot>
</table>
</div>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
// ADDED: Initialize Select2
$(document).ready(function() {
    $('.select2-search').select2({
        dir: "rtl" // لدعم اللغة العربية
    });
});

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
    if(!files[currentIndex]) return;
    let f=files[currentIndex];
    let ext=f.original_name.split('.').pop().toLowerCase();
    let path='uploads/expenses/'+f.file_name;
    img.classList.add('d-none'); pdf.classList.add('d-none'); doc.classList.add('d-none');
    dl.href=path; dl.download=f.original_name;
    if(['jpg','jpeg','png'].includes(ext)){ img.src=path; img.classList.remove('d-none'); }
    else if(ext==='pdf'){ pdf.src=path; pdf.classList.remove('d-none'); }
    else { document.getElementById('downloadLink').href=path; doc.classList.remove('d-none'); }
}
function prevFile(){ if(currentIndex>0){currentIndex--; showFile();} }
function nextFile(){ if(currentIndex<files.length-1){currentIndex++; showFile();} }
function printFile(){
    let f=files[currentIndex];
    let ext=f.original_name.split('.').pop().toLowerCase();
    let path='uploads/expenses/'+f.file_name;
    if(['jpg','jpeg','png','pdf'].includes(ext)){
        let win=window.open(path,'_blank');
        win.print();
    } else {
        alert("لا يمكن طباعة هذا النوع من الملفات");
    }
}
</script>
</body>
</html>