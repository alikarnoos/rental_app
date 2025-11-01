<?php
require 'config.php';
ini_set('display_errors',1);

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  header('Location: properties.php'); exit;
}

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$type = trim($_POST['type'] ?? '');
$address = trim($_POST['address'] ?? '');
$description = trim($_POST['description'] ?? '');

if($id <= 0 || $name === ''){
  die('بيانات غير صحيحة');
}

// تحديث الجدول
$sth = $pdo->prepare("UPDATE properties SET name = ?, type = ?, address = ?, description = ? WHERE id = ?");
$sth->execute([$name, $type, $address, $description, $id]);

// رفع ملفات جديدة (نفس قواعد save_property.php)
$uploadDir = __DIR__ . '/uploads/properties/';
if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$allowedMimes = [
  'image/jpeg','image/png','image/jpg',
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

if(!empty($_FILES['files']) && is_array($_FILES['files']['tmp_name'])){
  foreach($_FILES['files']['tmp_name'] as $i => $tmpName){
    if(!isset($_FILES['files']['error'][$i]) || $_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
    $origName = $_FILES['files']['name'][$i];
    $mime = mime_content_type($tmpName);
    if(!in_array($mime, $allowedMimes)) continue;
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    $safeName = uniqid('prop_') . '.' . $ext;
    $dest = $uploadDir . $safeName;
    if(move_uploaded_file($tmpName, $dest)){
      $ins = $pdo->prepare("INSERT INTO property_files (property_id, file_name, original_name, mime_type) VALUES (?, ?, ?, ?)");
      $ins->execute([$id, $safeName, $origName, $mime]);
    }
  }
}

header("Location: view_property.php?id=$id");
exit;
