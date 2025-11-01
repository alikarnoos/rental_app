<?php
require 'config.php';

// مصفوفة الربط بين القيم المخزنة والقيم المعروضة بالعربية
$contractTypes = [
    'annual' => 'سنوي',
    'monthly' => 'شهري',
    'weekly' => 'اسبوعي',
    'daily' => 'يومي',
];

// مصفوفة معكوسة للبحث عن القيمة الإنجليزية من القيمة العربية
$inversedContractTypes = array_flip($contractTypes);

// تحقق إذا تم إرسال نموذج حفظ (إضافة أو تعديل)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // حفظ مستأجر جديد أو تعديل مستأجر موجود
    if (isset($_POST['save_tenant'])) {
        $tenant_id = $_POST['tenant_id'] ?? null;
        $full_name = $_POST['full_name'];
        $phone = $_POST['phone'];
        $unit_id = $_POST['unit_id'];
        $contract_type = $_POST['contract_type'];
        $start_date = $_POST['start_date'];

        if ($tenant_id) {
            // تحديث مستأجر موجود
            $stmt = $pdo->prepare("UPDATE tenants SET full_name = ?, phone = ?, unit_id = ?, contract_type = ?, contract_start = ? WHERE id = ?");
            $stmt->execute([$full_name, $phone, $unit_id, $contract_type, $start_date, $tenant_id]);
        } else {
            // إضافة مستأجر جديد
            $stmt = $pdo->prepare("INSERT INTO tenants (full_name, phone, unit_id, contract_type, contract_start) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$full_name, $phone, $unit_id, $contract_type, $start_date]);
        }

        header("Location: tenants.php");
        exit();
    }

    // حذف مستأجر
    if (isset($_POST['delete_tenant'])) {
        $tenant_id = $_POST['tenant_id'];
        $stmt = $pdo->prepare("DELETE FROM tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);

        header("Location: tenants.php");
        exit();
    }
}

// إذا طلب تعديل مستأجر (عن طريق GET)
$edit_tenant = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT t.*, u.code AS unit_code, p.name AS property_name FROM tenants t LEFT JOIN units u ON t.unit_id = u.id LEFT JOIN properties p ON u.property_id = p.id WHERE t.id = ?");
    $stmt->execute([$id]);
    $edit_tenant = $stmt->fetch();
}

// جلب العقارات والوحدات لربط المستأجرين
$properties = $pdo->query("SELECT id, name FROM properties ORDER BY name")->fetchAll();
$units = $pdo->query("SELECT u.id, u.code, p.name as property_name FROM units u LEFT JOIN properties p ON u.property_id = p.id ORDER BY p.name, u.code")->fetchAll();

// جلب المستأجرين مع معلومات الوحدة والعقار مع ميزة البحث
$searchTerm = $_GET['search'] ?? '';
$sql = "
    SELECT 
        t.*, 
        u.code AS unit_code, 
        p.name AS property_name 
    FROM tenants t
    LEFT JOIN units u ON t.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    WHERE 1=1
";
$params = [];

if (!empty($searchTerm)) {
    // التحقق مما إذا كانت قيمة البحث هي قيمة عربية من مصفوفة العقود
    $searchContractValue = $inversedContractTypes[$searchTerm] ?? $searchTerm;

    $sql .= " AND (t.full_name LIKE ? OR t.phone LIKE ? OR u.code LIKE ? OR p.name LIKE ? OR t.contract_type LIKE ? OR t.contract_start LIKE ?)";
    $searchWildcard = "%" . $searchContractValue . "%";
    $params = [$searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard, $searchWildcard];
    
    // إضافة شرط للبحث عن القيمة العربية الأصلية في حال عدم وجودها في المصفوفة المعكوسة
    if ($searchContractValue === $searchTerm) {
        $sql .= " OR t.contract_type LIKE ?";
        $params[] = $searchWildcard;
    }
}

$sql .= " ORDER BY t.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tenants = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <title>إدارة المستأجرين</title>
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
<div class="container">
    <h2 class="mb-4">إدارة المستأجرين</h2>

    <form method="post" class="mb-4">
        <h4><?= $edit_tenant ? 'تعديل مستأجر' : 'إضافة مستأجر جديد' ?></h4>
        <input type="hidden" name="tenant_id" value="<?= $edit_tenant['id'] ?? '' ?>" />

        <div class="mb-3">
            <label>الاسم الكامل:</label>
            <input type="text" name="full_name" required class="form-control" 
                   value="<?= htmlspecialchars($edit_tenant['full_name'] ?? '') ?>" />
        </div>
        <div class="mb-3">
            <label>رقم الهاتف:</label>
            <input type="text" name="phone" required class="form-control" 
                   value="<?= htmlspecialchars($edit_tenant['phone'] ?? '') ?>" />
        </div>
        
        <div class="mb-3">
            <label>الوحدة:</label>
            <select name="unit_id" id="unit_id" class="form-select select2-unit" required>
                <option value="">اختر الوحدة</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?= $unit['id'] ?>"
                            data-property="<?= htmlspecialchars($unit['property_name']) ?>"
                            <?= (isset($edit_tenant['unit_id']) && $edit_tenant['unit_id'] == $unit['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($unit['property_name']) . ' - ' . htmlspecialchars($unit['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="mb-3">
            <label>نوع العقد:</label>
            <select name="contract_type" required class="form-select">
                <option value="">اختر نوع العقد</option>
                <?php foreach ($contractTypes as $value => $label): ?>
                    <option value="<?= $value ?>" 
                        <?= (isset($edit_tenant['contract_type']) && $edit_tenant['contract_type'] === $value) ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label>تاريخ بدء العقد:</label>
            <input type="date" name="start_date" required class="form-control" 
                   value="<?= htmlspecialchars($edit_tenant['contract_start'] ?? '') ?>" />
        </div>
        
        <button type="submit" name="save_tenant" class="btn btn-primary"><?= $edit_tenant ? 'تحديث' : 'حفظ' ?></button>
        <?php if ($edit_tenant): ?>
            <a href="tenants.php" class="btn btn-secondary">إلغاء</a>
        <?php else: ?>
            <a href="index.php" class="btn btn-secondary">عودة للرئيسية</a>
        <?php endif; ?>
    </form>

    <hr />

    <h4>قائمة المستأجرين</h4>
    <form method="get" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="بحث بالاسم، رقم الهاتف، الوحدة، العقار، أو نوع العقد..." value="<?= htmlspecialchars($searchTerm) ?>">
            <button class="btn btn-primary" type="submit">بحث</button>
            <a href="tenants.php" class="btn btn-outline-secondary">عرض الكل</a>
        </div>
    </form>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>معرف</th>
                <th>الاسم الكامل</th>
                <th>رقم الهاتف</th>
                <th>العقار</th>
                <th>الوحدة</th>
                <th>نوع العقد</th>
                <th>تاريخ بدء العقد</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tenants)): ?>
                <tr>
                    <td colspan="8" class="text-center">لا توجد نتائج مطابقة.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($tenants as $tenant): ?>
                <tr>
                    <td><?= $tenant['id'] ?></td>
                    <td><?= htmlspecialchars($tenant['full_name']) ?></td>
                    <td><?= htmlspecialchars($tenant['phone']) ?></td>
                    <td><?= htmlspecialchars($tenant['property_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($tenant['unit_code'] ?? '-') ?></td>
                    <td><?= $contractTypes[$tenant['contract_type']] ?? htmlspecialchars($tenant['contract_type']) ?></td>
                    <td><?= htmlspecialchars($tenant['contract_start']) ?></td>
                    <td>
                        <a href="?edit=<?= $tenant['id'] ?>" class="btn btn-warning btn-sm">تعديل</a>
                        <form method="post" style="display:inline-block" onsubmit="return confirm('هل تريد حذف هذا المستأجر؟');">
                            <input type="hidden" name="tenant_id" value="<?= $tenant['id'] ?>" />
                            <button type="submit" name="delete_tenant" class="btn btn-danger btn-sm">حذف</button>
                        </form>
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
    $('#unit_id').select2({
        theme: "bootstrap-5",
        placeholder: "اختر الوحدة",
        allowClear: true,
        language: "ar"
    });
});
</script>

</body>
</html>