<?php
require 'config.php';

// متغير لحفظ رسالة الخطأ أو النجاح
$message = "";

// إضافة إيجار جديد أو تحديث إيجار
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rent'])) {
    $unit_id = $_POST['unit_id'];
    $tenant_id = $_POST['tenant_id'];
    $amount = $_POST['amount'];
    $currency = $_POST['currency'];
    $due_date = $_POST['due_date'];
    $paid_date = $_POST['paid_date'] ? $_POST['paid_date'] : null;
    $status = $_POST['status'];
    $notes = $_POST['notes'];

    if (!empty($_POST['rent_id'])) {
        // تحديث إيجار موجود
        $rent_id = $_POST['rent_id'];
        $stmt = $pdo->prepare("UPDATE rents SET unit_id=?, tenant_id=?, amount=?, currency=?, due_date=?, paid_date=?, status=?, notes=? WHERE id=?");
        $stmt->execute([$unit_id, $tenant_id, $amount, $currency, $due_date, $paid_date, $status, $notes, $rent_id]);
        $message = "تم تحديث الإيجار بنجاح.";
    } else {
        // إضافة إيجار جديد
        $stmt = $pdo->prepare("INSERT INTO rents (unit_id, tenant_id, amount, currency, due_date, paid_date, status, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$unit_id, $tenant_id, $amount, $currency, $due_date, $paid_date, $status, $notes]);
        $message = "تم إضافة الإيجار بنجاح.";
    }
    header("Location: rents.php?message=" . urlencode($message));
    exit;
}

// حذف إيجار
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM rents WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: rents.php?message=" . urlencode("تم حذف الإيجار."));
    exit;
}

// جلب بيانات الإيجار للتعديل
$edit_rent = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $stmt = $pdo->prepare("
        SELECT r.*, u.code AS unit_code, t.full_name AS tenant_name
        FROM rents r
        JOIN units u ON r.unit_id = u.id
        JOIN tenants t ON r.tenant_id = t.id
        WHERE r.id = ?
    ");
    $stmt->execute([$edit_id]);
    $edit_rent = $stmt->fetch();
}

// جلب الوحدات
$units = $pdo->query("SELECT id, code FROM units ORDER BY code")->fetchAll();

// جلب المستأجرين
$tenants = $pdo->query("SELECT id, full_name FROM tenants ORDER BY full_name")->fetchAll();

// جلب الإيجارات مع ميزة البحث
$searchTerm = $_GET['search'] ?? '';
$sql = "
    SELECT r.*, u.code AS unit_code, t.full_name AS tenant_name
    FROM rents r
    LEFT JOIN units u ON r.unit_id = u.id
    LEFT JOIN tenants t ON r.tenant_id = t.id
    WHERE 1=1
";
$params = [];

if (!empty($searchTerm)) {
    $sql .= " AND (u.code LIKE ? OR t.full_name LIKE ? OR r.amount LIKE ? OR r.currency LIKE ? OR r.due_date LIKE ? OR r.paid_date LIKE ? OR r.status LIKE ? OR r.notes LIKE ? OR r.created_at LIKE ?)";
    $searchWildcard = "%" . $searchTerm . "%";
    $params = [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard];
}

$sql .= " ORDER BY r.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rents = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <title>إدارة الإيجارات</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <style>
        /* لضمان توافق الحجم والاتجاه في RTL */
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 0.375rem !important;
            height: 38px !important;
            padding-right: 1.5rem !important;
        }
    </style>
</head>
<body>
<div class="container mt-4">

    <h2 class="mb-4">إدارة الإيجارات</h2>

    <?php if (isset($_GET['message'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_GET['message']) ?></div>
    <?php endif; ?>

    <form method="POST" class="row g-3 mb-4">
        <input type="hidden" name="rent_id" value="<?= $edit_rent ? htmlspecialchars($edit_rent['id']) : '' ?>" />

        <div class="col-md-3">
            <label class="form-label">الوحدة</label>
            <select name="unit_id" id="unit_id" class="form-select select2-unit" required>
                <option value="">اختر الوحدة</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?= $unit['id'] ?>" <?= ($edit_rent && $edit_rent['unit_id'] == $unit['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($unit['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">المستأجر</label>
            <select name="tenant_id" id="tenant_id" class="form-select select2-tenant" required>
                <option value="">اختر المستأجر</option>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= $tenant['id'] ?>" <?= ($edit_rent && $edit_rent['tenant_id'] == $tenant['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tenant['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">المبلغ</label>
            <input type="number" name="amount" class="form-control" required min="0" step="0.01" value="<?= $edit_rent ? htmlspecialchars($edit_rent['amount']) : '' ?>" />
        </div>

        <div class="col-md-2">
            <label class="form-label">العملة</label>
            <select name="currency" class="form-select" required>
                <option value="IQD" <?= ($edit_rent && $edit_rent['currency'] == 'IQD') ? 'selected' : '' ?>>IQD</option>
                <option value="USD" <?= ($edit_rent && $edit_rent['currency'] == 'USD') ? 'selected' : '' ?>>USD</option>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">تاريخ استحقاق الإيجار</label>
            <input type="date" name="due_date" class="form-control" required value="<?= $edit_rent ? htmlspecialchars($edit_rent['due_date']) : '' ?>" />
        </div>

        <div class="col-md-3">
            <label class="form-label">تاريخ الدفع</label>
            <input type="date" name="paid_date" class="form-control" value="<?= $edit_rent && $edit_rent['paid_date'] ? htmlspecialchars($edit_rent['paid_date']) : '' ?>" />
        </div>

        <div class="col-md-2">
            <label class="form-label">حالة الدفع</label>
            <select name="status" class="form-select" required>
                <option value="مدفوع" <?= ($edit_rent && $edit_rent['status'] == 'مدفوع') ? 'selected' : '' ?>>مدفوع</option>
                <option value="غير مدفوع" <?= ($edit_rent && $edit_rent['status'] == 'غير مدفوع') ? 'selected' : '' ?>>غير مدفوع</option>
            </select>
        </div>

        <div class="col-md-6">
            <label class="form-label">ملاحظات</label>
            <input type="text" name="notes" class="form-control" value="<?= $edit_rent ? htmlspecialchars($edit_rent['notes']) : '' ?>" />
        </div>

        <div class="col-12">
            <button type="submit" name="save_rent" class="btn btn-primary"><?= $edit_rent ? 'تحديث الإيجار' : 'حفظ الإيجار' ?></button>
            <?php if ($edit_rent): ?>
                <a href="rents.php" class="btn btn-secondary">إلغاء التعديل</a>
            <?php else: ?>
                <a href="index.php" class="btn btn-secondary">عودة للرئيسية</a>
            <?php endif; ?>
        </div>
    </form>

    <hr />

    <h4>قائمة الإيجارات</h4>
    <form method="get" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="بحث بالوحدة، المستأجر، المبلغ، الحالة، أو التواريخ..." value="<?= htmlspecialchars($searchTerm) ?>">
            <button class="btn btn-primary" type="submit">بحث</button>
            <a href="rents.php" class="btn btn-outline-secondary">عرض الكل</a>
        </div>
    </form>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>الوحدة</th>
                <th>المستأجر</th>
                <th>المبلغ</th>
                <th>العملة</th>
                <th>تاريخ استحقاق الإيجار</th>
                <th>تاريخ الدفع</th>
                <th>الحالة</th>
                <th>ملاحظات</th>
                <th>تاريخ الإضافة</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rents)): ?>
                <tr>
                    <td colspan="10" class="text-center">لا توجد نتائج مطابقة.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($rents as $rent): ?>
                    <tr>
                        <td><?= htmlspecialchars($rent['unit_code']) ?></td>
                        <td><?= htmlspecialchars($rent['tenant_name']) ?></td>
                        <td><?= htmlspecialchars($rent['amount']) ?></td>
                        <td><?= htmlspecialchars($rent['currency']) ?></td>
                        <td><?= htmlspecialchars($rent['due_date']) ?></td>
                        <td><?= htmlspecialchars($rent['paid_date'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($rent['status']) ?></td>
                        <td><?= htmlspecialchars($rent['notes']) ?></td>
                        <td><?= htmlspecialchars($rent['created_at']) ?></td>
                        <td>
                            <a href="?edit=<?= $rent['id'] ?>" class="btn btn-warning btn-sm">تعديل</a>
                            <a href="?delete=<?= $rent['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('هل تريد حذف هذا الإيجار؟');">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <a href="index.php" class="btn btn-secondary">عودة للرئيسية</a>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/ar.js"></script>

<script>
$(document).ready(function() {
    // تفعيل Select2 على قائمة الوحدات
    $('.select2-unit').select2({
        theme: "bootstrap-5",
        placeholder: "اختر الوحدة",
        allowClear: true,
        language: "ar"
    });

    // تفعيل Select2 على قائمة المستأجرين
    $('.select2-tenant').select2({
        theme: "bootstrap-5",
        placeholder: "اختر المستأجر",
        allowClear: true,
        language: "ar"
    });
});
</script>
</body>
</html>