<?php
require 'config.php';
$id = (int)($_GET['id'] ?? 0);
if($id <= 0){ header('Location: properties.php'); exit; }

$sth = $pdo->prepare("SELECT * FROM properties WHERE id = ?");
$sth->execute([$id]);
$property = $sth->fetch();
if(!$property){ header('Location: properties.php'); exit; }

$sth2 = $pdo->prepare("SELECT * FROM property_files WHERE property_id = ?");
$sth2->execute([$id]);
$files = $sth2->fetchAll();
?>
<!doctype html>
<html lang="ar">
<head>
<meta charset="utf-8">
<title>تعديل عقار</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.thumb{ max-width:120px; margin-right:6px; }</style>
</head>
<body class="p-4">
  <h3>تعديل العقار</h3>
  <form method="post" action="update_property.php" enctype="multipart/form-data" class="border p-3 mb-4">
    <input type="hidden" name="id" value="<?= $property['id'] ?>">
    <div class="mb-2">
      <label>اسم العقار</label>
      <input name="name" class="form-control" value="<?= htmlspecialchars($property['name']) ?>" required>
    </div>
    <div class="mb-2">
      <label>النوع</label>
      <input name="type" class="form-control" value="<?= htmlspecialchars($property['type']) ?>">
    </div>
    <div class="mb-2">
      <label>العنوان</label>
      <input name="address" class="form-control" value="<?= htmlspecialchars($property['address']) ?>">
    </div>
    <div class="mb-2">
      <label>الوصف</label>
      <textarea name="description" class="form-control"><?= htmlspecialchars($property['description']) ?></textarea>
    </div>

    <h6>الملفات الحالية</h6>
    <div class="mb-2">
      <?php if(empty($files)) echo "<p>لا توجد ملفات</p>"; ?>
      <?php foreach($files as $f): 
        $path = 'uploads/properties/' . $f['file_name'];
        $mime = $f['mime_type'];
        $orig = htmlspecialchars($f['original_name']);
        if(strpos($mime,'image/') === 0){
          echo "<div style='display:inline-block; text-align:center; margin:6px;'><img src='$path' class='thumb'><br><a href='delete_file.php?id={$f['id']}&prop={$property['id']}' class='btn btn-sm btn-danger' onclick=\"return confirm('حذف هذا الملف؟')\">حذف</a></div>";
        } else {
          echo "<div style='margin-bottom:8px;'><a href='$path' target='_blank'>$orig</a> — <a href='delete_file.php?id={$f['id']}&prop={$property['id']}' class='btn btn-sm btn-danger' onclick=\"return confirm('حذف هذا الملف؟')\">حذف</a></div>";
        }
      endforeach; ?>
    </div>

    <div class="mb-2">
      <label>رفع ملفات جديدة (اختياري)</label>
      <input type="file" name="files[]" multiple accept=".png,.jpg,.jpeg,.pdf,.doc,.docx">
    </div>

    <button class="btn btn-primary" type="submit">حفظ التعديلات</button>
    <a class="btn btn-secondary" href="view_property.php?id=<?= $property['id'] ?>">إلغاء</a>
  </form>
</body>
</html>
