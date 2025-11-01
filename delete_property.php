<?php
require 'config.php';
$id = (int)($_GET['id'] ?? 0);
if($id <= 0) { header('Location: properties.php'); exit; }

// جلب الملفات لحذفها من القرص
$sth = $pdo->prepare("SELECT file_name FROM property_files WHERE property_id = ?");
$sth->execute([$id]);
$files = $sth->fetchAll();

$uploadDir = __DIR__ . '/uploads/properties/';
foreach($files as $f){
  $filePath = $uploadDir . $f['file_name'];
  if(file_exists($filePath)) @unlink($filePath);
}

// حذف السجل (بفضل FK ON DELETE CASCADE، السجلات في property_files ستمسح تلقائياً)
$pdo->prepare("DELETE FROM properties WHERE id = ?")->execute([$id]);

header('Location: properties.php');
exit;
