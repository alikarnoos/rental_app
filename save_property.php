<?php
require 'config.php';
ini_set('display_errors',1);

// فقط معالجة POST
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  header('Location: properties.php'); exit;
}

$name = trim($_POST['name'] ?? '');
$type = trim($_POST['type'] ?? '');
$address = trim($_POST['address'] ?? '');
$description = trim($_POST['description'] ?? '');

if($name === ''){
  die('اسم العقار مطلوب');
}

// معرف تلقائي (يمكن تغييره حسب رغبتك)
$code = 'P' . time();

// أدخل العقار
$sth = $pdo->prepare("INSERT INTO properties (code, name, type, address, description) VALUES (?, ?, ?, ?, ?)");
$sth->execute([$code, $name, $type, $address, $description]);
$property_id = $pdo->lastInsertId();

// تأكد من وجود مجلد التخزين
$uploadDir = __DIR__ . '/uploads/properties/';
if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// قبولات MIME
$allowedMimes = [
  'image/jpeg','image/png','image/jpg',
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

// معالجة الملفات المتعددة
if(!empty($_FILES['files']) && is_array($_FILES['files']['tmp_name'])){
  foreach($_FILES['files']['tmp_name'] as $i => $tmpName){
    if(!isset($_FILES['files']['error'][$i]) || $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
    $origName = $_FILES['files']['name'][$i];
    $mime = mime_content_type($tmpName);
    if(!in_array($mime, $allowedMimes)){
      // تخطي الملفات غير المسموح بها (أو يمكن إظهار رسالة)
      continue;
    }
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    $safeName = uniqid('prop_') . '.' . $ext;
    $dest = $uploadDir . $safeName;
    if(move_uploaded_file($tmpName, $dest)){
      $ins = $pdo->prepare("INSERT INTO property_files (property_id, file_name, original_name, mime_type) VALUES (?, ?, ?, ?)");
      $ins->execute([$property_id, $safeName, $origName, $mime]);
    }
  }
}

// اعادة الى الصفحة
header('Location: properties.php');
exit;
