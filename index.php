<?php
// index.php
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8" />
<title>نظام إدارة العقارات</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
<style>
  body { padding: 20px; background-color: #f8f9fa; }
  .btn-main { width: 100%; margin-bottom: 15px; font-size: 1.2rem; }
</style>
</head>
<body>
  <div class="container">
    <h2 class="mb-4 text-center">نظام إدارة العقارات</h2>
    <div class="row">
      <div class="col-md-4">
        <a href="properties.php" class="btn btn-primary btn-main">إدارة العقارات</a>
      </div>
      <div class="col-md-4">
        <a href="units.php" class="btn btn-success btn-main">إدارة الوحدات</a>
      </div>
      <div class="col-md-4">
        <a href="tenants.php" class="btn btn-warning btn-main">إدارة المستأجرين</a>
      </div>
      <div class="col-md-4">
        <a href="rents.php" class="btn btn-info btn-main">إدارة الإيجارات</a>
      </div>
      <div class="col-md-4">
        <a href="search.php" class="btn btn-dark btn-main">البحث المتقدم</a>
      </div>
     
      <div class="col-md-4">
  <a href="expenses.php" class="btn btn-danger btn-main">إدارة المصروفات</a>
</div>
 <div class="col-md-4">
        <a href="backup.php" class="btn btn-secondary btn-main">نسخ احتياطي لقاعدة البيانات</a>
      </div>

    </div>
  </div>
</body>
</html>
