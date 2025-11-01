<?php
require 'config.php';

// دالة توليد كود الوحدة
function generateCode($length = 6) {
    return strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
}

$message = "";

// معالجة إضافة أو تحديث الوحدة
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // تحديث وحدة
    if (isset($_POST['update_unit']) && !empty($_POST['unit_id'])) {
        $unit_id = $_POST['unit_id'];
        $property_id = $_POST['property_id'];
        $type = $_POST['type'];
        $status = $_POST['status'];
        $monthly_rent = $_POST['monthly_rent'];
        $currency = $_POST['currency'];

        // تأكيد أن property_id ليس فارغًا
        if (empty($property_id)) {
            $message = "يجب اختيار عقار.";
        } else {
            $stmt = $pdo->prepare("UPDATE units SET property_id=?, type=?, status=?, monthly_rent=?, currency=? WHERE id=?");
            $stmt->execute([$property_id, $type, $status, $monthly_rent, $currency, $unit_id]);
            $message = "تم تحديث الوحدة بنجاح.";
        }
        header("Location: units.php?message=" . urlencode($message));
        exit();
    }

    // إضافة وحدة جديدة
    if (isset($_POST['add_unit'])) {
        $code = generateCode();
        $property_id = $_POST['property_id'];
        $type = $_POST['type'];
        $status = $_POST['status'];
        $monthly_rent = $_POST['monthly_rent'];
        $currency = $_POST['currency'];

        // تأكيد أن property_id ليس فارغًا
        if (empty($property_id)) {
            $message = "يجب اختيار عقار.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO units (code, property_id, type, status, monthly_rent, currency) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$code, $property_id, $type, $status, $monthly_rent, $currency]);
            $message = "تم إضافة الوحدة بنجاح.";
        }
        header("Location: units.php?message=" . urlencode($message));
        exit();
    }

    // حذف وحدة
    if (isset($_POST['delete_unit'])) {
        $id = $_POST['unit_id'];
        $stmt = $pdo->prepare("DELETE FROM units WHERE id = ?");
        $stmt->execute([$id]);

        $message = "تم حذف الوحدة.";
        header("Location: units.php?message=" . urlencode($message));
        exit();
    }
}

// جلب وحدة للتعديل
$edit_unit = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $stmt = $pdo->prepare("SELECT u.*, p.name as property_name FROM units u LEFT JOIN properties p ON u.property_id = p.id WHERE u.id = ?");
    $stmt->execute([$edit_id]);
    $edit_unit = $stmt->fetch();
}

// جلب العقارات لاختيار الربط
$properties = $pdo->query("SELECT id, name FROM properties ORDER BY name")->fetchAll();

// جلب الوحدات مع ميزة البحث
$searchTerm = $_GET['search'] ?? '';
$sql = "SELECT u.*, p.name as property_name, p.code as property_code FROM units u LEFT JOIN properties p ON u.property_id = p.id WHERE 1=1";
$params = [];

if (!empty($searchTerm)) {
    $sql .= " AND (u.code LIKE ? OR u.type LIKE ? OR p.name LIKE ? OR p.code LIKE ?)";
    $searchWildcard = "%" . $searchTerm . "%";
    $params = [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard];
}

$sql .= " ORDER BY u.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$units = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <title>إدارة الوحدات</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

    <style>
        /* لضمان توافق الحجم والاتجاه في RTL */
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 0.375rem !important;
            height: 38px !important;
            /* زيادة المسافة على اليمين للزر في RTL */
            padding-right: 1.5rem !important;
        }
    </style>
</head>
<body>
<div class="container">
    <h2 class="mb-4">إدارة الوحدات</h2>

    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_GET['message']) ?></div>
    <?php endif; ?>

    <form method="post" class="mb-4">
        <h4><?= $edit_unit ? "تعديل الوحدة" : "إضافة وحدة جديدة" ?></h4>

        <?php if ($edit_unit): ?>
            <input type="hidden" name="unit_id" value="<?= htmlspecialchars($edit_unit['id']) ?>" />
        <?php endif; ?>

        <div class="mb-3">
            <label>العقار:</label>
          <select name="property_id" id="property_id" class="form-select select2-search" required>
            <option value="">— بدون —</option>
            <?php foreach ($properties as $prop): ?>
                <option value="<?= $prop['id'] ?>" <?= $edit_unit && $edit_unit['property_id'] == $prop['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($prop['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
            
        </div>
        
        <div class="mb-3">
            <label>نوع الوحدة:</label>
            <input type="text" name="type" required class="form-control" value="<?= $edit_unit ? htmlspecialchars($edit_unit['type']) : '' ?>" />
        </div>
        <div class="mb-3">
            <label>الحالة:</label>
            <select name="status" required class="form-select">
                <option value="available" <?= $edit_unit && $edit_unit['status'] == 'available' ? 'selected' : '' ?>>غير مؤجرة</option>
                <option value="rented" <?= $edit_unit && $edit_unit['status'] == 'rented' ? 'selected' : '' ?>>مؤجرة</option>
            </select>
        </div>
        <div class="mb-3">
            <label>الإيجار الشهري:</label>
           <input type="number" name="monthly_rent" step="any" required class="form-control" value="<?= $edit_unit ? htmlspecialchars($edit_unit['monthly_rent']) : '0' ?>" />

        </div>
        <div class="mb-3">
            <label>العملة:</label>
            <select name="currency" required class="form-select">
                <option value="USD" <?= $edit_unit && $edit_unit['currency'] == 'USD' ? 'selected' : '' ?>>USD</option>
                <option value="IQD" <?= $edit_unit && $edit_unit['currency'] == 'IQD' ? 'selected' : '' ?>>IQD</option>
            </select>
        </div>

        <button type="submit" name="<?= $edit_unit ? 'update_unit' : 'add_unit' ?>" class="btn btn-primary">
            <?= $edit_unit ? "تحديث الوحدة" : "إضافة الوحدة" ?>
        </button>

        <?php if ($edit_unit): ?>
            <a href="units.php" class="btn btn-secondary">إلغاء التعديل</a>
        <?php else: ?>
            <a href="index.php" class="btn btn-secondary">عودة للرئيسية</a>
        <?php endif; ?>
    </form>

    <hr />

    <h4>قائمة الوحدات</h4>
    <a href="annual_rents.php" class="btn btn-success mb-3">
    📊 التقرير السنوي للوحدات
    </a>

    <form method="get" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="بحث بكود الوحدة، النوع، أو اسم العقار..." value="<?= htmlspecialchars($searchTerm) ?>">
            <button class="btn btn-primary" type="submit">بحث</button>
            <a href="units.php" class="btn btn-outline-secondary">عرض الكل</a>
        </div>
    </form>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>معرف</th>
                <th>الكود</th>
                <th>العقار</th>
                <th>نوع الوحدة</th>
                <th>الحالة</th>
                <th>الإيجار الشهري</th>
                <th>العملة</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($units)): ?>
            <tr>
                <td colspan="8" class="text-center">لا توجد وحدات مطابقة.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($units as $unit): ?>
                <tr>
                    <td><?= $unit['id'] ?></td>
                    <td><?= htmlspecialchars($unit['code']) ?></td>
                    <td><?= htmlspecialchars($unit['property_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($unit['type']) ?></td>
                    <td><?= $unit['status'] == 'available' ? 'غير مؤجرة' : 'مؤجرة' ?></td>
                    <td><?= number_format($unit['monthly_rent'], 2) ?></td>
                    <td><?= htmlspecialchars($unit['currency']) ?></td>
                    <td>
                        <a href="?edit=<?= $unit['id'] ?>" class="btn btn-warning btn-sm">تعديل</a>

                        <form method="post" style="display:inline-block" onsubmit="return confirm('هل أنت متأكد من حذف هذه الوحدة؟');">
                            <input type="hidden" name="unit_id" value="<?= $unit['id'] ?>" />
                            <button type="submit" name="delete_unit" class="btn btn-danger btn-sm">حذف</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if (!$edit_unit): ?>
        <a href="index.php" class="btn btn-secondary">عودة للرئيسية</a>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/ar.js"></script>

<script>
$(document).ready(function() {
    // تفعيل Select2 على قائمة العقارات
    $('.select2-search').select2({
        theme: "bootstrap-5",
        placeholder: "اختر عقار",
        // السماح بإلغاء الاختيار (لعرض — بدون —)
        allowClear: true,
        language: "ar"
    });
});
</script>
</body>
</html>