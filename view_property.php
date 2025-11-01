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
<title>عرض العقار</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>.img-large{ max-width:220px; margin:6px; border:1px solid #ddd; padding:4px; }</style>
</head>
<body class="p-4">
  <h3><?= htmlspecialchars($property['name']) ?></h3>
  <p><strong>النوع:</strong> <?= htmlspecialchars($property['type']) ?></p>
  <p><strong>العنوان:</strong> <?= nl2br(htmlspecialchars($property['address'])) ?></p>
  <p><strong>الوصف:</strong><br><?= nl2br(htmlspecialchars($property['description'])) ?></p>

  <h5 class="mt-3">الملفات المرفقة</h5>
  <div class="mb-3">
    <?php if(empty($files)): ?>
      <p>لا توجد ملفات مرفقة.</p>
    <?php else: foreach($files as $f): 
        $path = 'uploads/properties/' . $f['file_name'];
        $mime = $f['mime_type'];
        $orig = htmlspecialchars($f['original_name']);
        if(strpos($mime,'image/') === 0): ?>
          <a href="<?= $path ?>" target="_blank"><img src="<?= $path ?>" class="img-large" alt="<?= $orig ?>"></a>
        <?php elseif($mime === 'application/pdf'): ?>
          <div><a class="btn btn-outline-primary btn-sm" href="<?= $path ?>" target="_blank">عرض PDF — <?= $orig ?></a></div>
        <?php else: ?>
          <div><a class="btn btn-outline-secondary btn-sm" href="<?= $path ?>" download>تحميل <?= $orig ?></a></div>
        <?php endif;
      endforeach; endif; ?>
  </div>

  <a class="btn btn-secondary" href="properties.php">رجوع</a>
  <a class="btn btn-warning" href="edit_property.php?id=<?= $property['id'] ?>">تعديل</a>
  <a class="btn btn-danger" href="delete_property.php?id=<?= $property['id'] ?>" onclick="return confirm('تأكيد حذف العقار وملفاته؟')">حذف</a>
</body>
</html>
