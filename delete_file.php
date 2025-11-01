<?php
require 'config.php';
$id = (int)($_GET['id'] ?? 0);
$prop = (int)($_GET['prop'] ?? 0);

if($id <= 0){ header('Location: properties.php'); exit; }

// جلب اسم الملف
$sth = $pdo->prepare("SELECT file_name FROM property_files WHERE id = ?");
$sth->execute([$id]);
$f = $sth->fetch();
if($f){
  $filePath = __DIR__ . '/uploads/properties/' . $f['file_name'];
  if(file_exists($filePath)) @unlink($filePath);
  $pdo->prepare("DELETE FROM property_files WHERE id = ?")->execute([$id]);
}

if($prop > 0) header("Location: edit_property.php?id=$prop");
else header("Location: properties.php");
exit;
