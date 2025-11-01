<?php
require 'config.php';
require_once 'tcpdf/tcpdf.php';

// جلب كل العقارات، الوحدات، والمستأجرين
$properties = $pdo->query("SELECT * FROM properties ORDER BY name")->fetchAll();
$units = $pdo->query("SELECT * FROM units ORDER BY code")->fetchAll();
$tenants = $pdo->query("SELECT * FROM tenants ORDER BY full_name")->fetchAll();

// تحضير شروط البحث
$whereClauses = [];
$params = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['property_id'])) { $whereClauses[] = "units.property_id = ?"; $params[] = $_GET['property_id']; }
    if (!empty($_GET['unit_id'])) { $whereClauses[] = "rents.unit_id = ?"; $params[] = $_GET['unit_id']; }
    if (!empty($_GET['tenant_id'])) { $whereClauses[] = "rents.tenant_id = ?"; $params[] = $_GET['tenant_id']; }
    if (!empty($_GET['status'])) { $whereClauses[] = "rents.status = ?"; $params[] = $_GET['status']; }
    if (!empty($_GET['currency'])) { $whereClauses[] = "rents.currency = ?"; $params[] = $_GET['currency']; }
    if (!empty($_GET['due_date_from'])) { $whereClauses[] = "rents.due_date >= ?"; $params[] = $_GET['due_date_from']; }
    if (!empty($_GET['due_date_to'])) { $whereClauses[] = "rents.due_date <= ?"; $params[] = $_GET['due_date_to']; }
    if (!empty($_GET['paid_date_from'])) { $whereClauses[] = "rents.paid_date >= ?"; $params[] = $_GET['paid_date_from']; }
    if (!empty($_GET['paid_date_to'])) { $whereClauses[] = "rents.paid_date <= ?"; $params[] = $_GET['paid_date_to']; }
    if (!empty($_GET['contract_date_from'])) { $whereClauses[] = "tenants.contract_start >= ?"; $params[] = $_GET['contract_date_from']; }
    if (!empty($_GET['contract_date_to'])) { $whereClauses[] = "tenants.contract_start <= ?"; $params[] = $_GET['contract_date_to']; }
}

$whereSQL = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";

// جلب البيانات
$sql = "
SELECT rents.*, units.code AS unit_code, units.id AS unit_id, tenants.full_name AS tenant_name, tenants.id AS tenant_id, properties.name AS property_name, properties.id AS property_id
FROM rents
LEFT JOIN tenants ON rents.tenant_id = tenants.id
LEFT JOIN units ON rents.unit_id = units.id
LEFT JOIN properties ON units.property_id = properties.id
{$whereSQL}
ORDER BY rents.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

// حساب المجموع لكل عملة على حدة
$total_iqd = 0;
$total_usd = 0;
foreach ($results as $row) { 
    if ($row['currency'] === 'IQD') {
        $total_iqd += $row['amount'] ?? 0;
    } elseif ($row['currency'] === 'USD') {
        $total_usd += $row['amount'] ?? 0;
    }
}

// تصدير Excel
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=rents_export.csv');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['العقار','الوحدة','المستأجر','المبلغ','العملة','تاريخ الاستحقاق','تاريخ الدفع','الحالة','ملاحظات']);
    foreach ($results as $row) {
        fputcsv($output, [
            $row['property_name'] ?? '-', $row['unit_code'] ?? '-', $row['tenant_name'] ?? '-',
            number_format($row['amount'] ?? 0,2),
            $row['currency'] ?? '-', $row['due_date'] ?? '-', $row['paid_date'] ?? '-', $row['status'] ?? '-', $row['notes'] ?? '-'
        ]);
    }
    fputcsv($output, ['','','','','','','','','']);
    fputcsv($output, ['الإجمالي بالدينار العراقي (IQD):', number_format($total_iqd, 2)]);
    fputcsv($output, ['الإجمالي بالدولار الأمريكي (USD):', number_format($total_usd, 2)]);

    fclose($output); exit;
}

// تصدير PDF
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new TCPDF('L','mm','A4',true,'UTF-8',false);
    $pdf->SetCreator(PDF_CREATOR); $pdf->SetAuthor('نظام إدارة العقارات'); $pdf->SetTitle('تقرير الإيجارات');
    $pdf->setRTL(true); $pdf->SetFont('dejavusans','',12); $pdf->AddPage();
    $pdf->Cell(0,10,'تقرير الإيجارات',0,1,'C');
    $html = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse: collapse;">
    <thead style="background-color:#f2f2f2;">
        <tr><th>العقار</th><th>الوحدة</th><th>المستأجر</th><th>المبلغ</th><th>العملة</th><th>تاريخ الاستحقاق</th><th>تاريخ الدفع</th><th>الحالة</th><th>ملاحظات</th></tr>
    </thead><tbody>';
    foreach ($results as $row) {
        $paid_date = $row['paid_date'] ?? '-';
        $html .= '<tr>
            <td>'.htmlspecialchars($row['property_name'] ?? '-').'</td>
            <td>'.htmlspecialchars($row['unit_code'] ?? '-').'</td>
            <td>'.htmlspecialchars($row['tenant_name'] ?? '-').'</td>
            <td align="right">'.number_format($row['amount'] ?? 0,2).'</td>
            <td>'.htmlspecialchars($row['currency'] ?? '-').'</td>
            <td>'.htmlspecialchars($row['due_date'] ?? '-').'</td>
            <td>'.htmlspecialchars($paid_date).'</td>
            <td>'.htmlspecialchars($row['status'] ?? '-').'</td>
            <td>'.htmlspecialchars($row['notes'] ?? '-').'</td>
        </tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<br/><table border="0" cellpadding="4" cellspacing="0">';
    $html .= '<tr style="font-weight:bold;"><td>الإجمالي بالدينار العراقي (IQD):</td><td align="right">'.number_format($total_iqd,2).'</td></tr>';
    $html .= '<tr style="font-weight:bold;"><td>الإجمالي بالدولار الأمريكي (USD):</td><td align="right">'.number_format($total_usd,2).'</td></tr>';
    $html .= '</table>';

    $pdf->writeHTML($html,true,false,true,false,'');
    $pdf->Output('rents_report.pdf','D'); exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>بحث متقدم في إدارة العقارات</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
.cursor-pointer { cursor:pointer; text-decoration:underline; color:#0d6efd; }
/* تعديل Select2 ليتوافق مع اللغة العربية */
.select2-container .select2-selection--single {
    height: calc(2.25rem + 2px); /* ارتفاع حقل الإدخال */
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 2.25rem;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 2.25rem;
}
</style>
</head>
<body>
<div class="container mt-4">
<h2 class="mb-4">بحث متقدم في إدارة العقارات</h2>

<form method="GET" class="row g-3 mb-4">
    <div class="col-md-3">
      <label class="form-label">اختر العقار</label>
      <select name="property_id" class="form-select select2-search">
        <option value="">-- الكل --</option>
        <?php foreach ($properties as $property): ?>
          <option value="<?= $property['id'] ?>" <?= (isset($_GET['property_id']) && $_GET['property_id']==$property['id'])?'selected':''?>><?= htmlspecialchars($property['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">اختر الوحدة</label>
      <select name="unit_id" class="form-select select2-search">
        <option value="">-- الكل --</option>
        <?php foreach ($units as $unit): ?>
          <option value="<?= $unit['id'] ?>" <?= (isset($_GET['unit_id']) && $_GET['unit_id']==$unit['id'])?'selected':''?>><?= htmlspecialchars($unit['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">اختر المستأجر</label>
      <select name="tenant_id" class="form-select select2-search">
        <option value="">-- الكل --</option>
        <?php foreach ($tenants as $tenant): ?>
          <option value="<?= $tenant['id'] ?>" <?= (isset($_GET['tenant_id']) && $_GET['tenant_id']==$tenant['id'])?'selected':''?>><?= htmlspecialchars($tenant['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">العملة</label>
      <select name="currency" class="form-select">
        <option value="">-- الكل --</option>
        <option value="IQD" <?= (isset($_GET['currency']) && $_GET['currency']=='IQD')?'selected':''?>>IQD</option>
        <option value="USD" <?= (isset($_GET['currency']) && $_GET['currency']=='USD')?'selected':''?>>USD</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">حالة الإيجار</label>
      <select name="status" class="form-select">
        <option value="">-- الكل --</option>
        <option value="مدفوع" <?= (isset($_GET['status']) && $_GET['status']=='مدفوع')?'selected':''?>>مدفوع</option>
        <option value="غير مدفوع" <?= (isset($_GET['status']) && $_GET['status']=='غير مدفوع')?'selected':''?>>غير مدفوع</option>
      </select>
    </div>
    <div class="col-md-3"><label>تاريخ الاستحقاق من</label><input type="date" name="due_date_from" class="form-control" value="<?= $_GET['due_date_from'] ?? '' ?>"></div>
    <div class="col-md-3"><label>تاريخ الاستحقاق إلى</label><input type="date" name="due_date_to" class="form-control" value="<?= $_GET['due_date_to'] ?? '' ?>"></div>
    
    <div class="col-md-3"><label>تاريخ الدفع من</label><input type="date" name="paid_date_from" class="form-control" value="<?= $_GET['paid_date_from'] ?? '' ?>"></div>
    <div class="col-md-3"><label>تاريخ الدفع إلى</label><input type="date" name="paid_date_to" class="form-control" value="<?= $_GET['paid_date_to'] ?? '' ?>"></div>
    
    <div class="col-md-3"><label>تاريخ العقد من</label><input type="date" name="contract_date_from" class="form-control" value="<?= $_GET['contract_date_from'] ?? '' ?>"></div>
    <div class="col-md-3"><label>تاريخ العقد إلى</label><input type="date" name="contract_date_to" class="form-control" value="<?= $_GET['contract_date_to'] ?? '' ?>"></div>

    <div class="col-12">
      <button type="submit" class="btn btn-primary">بحث</button>
      <button type="submit" name="export" value="excel" class="btn btn-success">تصدير Excel</button>
      <button type="submit" name="export" value="pdf" class="btn btn-danger">تصدير PDF</button>
      <a href="index.php" class="btn btn-secondary">العودة للرئيسية</a>
    </div>
</form>

<?php if ($_SERVER['REQUEST_METHOD']==='GET' && count($results)>0): ?>
    <h3>نتائج البحث</h3>
    <table class="table table-bordered table-striped">
      <thead>
        <tr>
          <th>العقار</th><th>الوحدة</th><th>المستأجر</th><th class="text-end">المبلغ</th><th>العملة</th>
          <th>تاريخ الاستحقاق</th><th>تاريخ الدفع</th><th>الحالة</th><th>ملاحظات</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($results as $row): ?>
        <tr>
          <td><span class="cursor-pointer text-primary" onclick='showInfo("property",<?= json_encode($row['property_id']) ?>)'><?= htmlspecialchars($row['property_name'] ?? '-') ?></span></td>
          <td><span class="cursor-pointer text-primary" onclick='showInfo("unit",<?= json_encode($row['unit_id']) ?>)'><?= htmlspecialchars($row['unit_code'] ?? '-') ?></span></td>
          <td><span class="cursor-pointer text-primary" onclick='showInfo("tenant",<?= json_encode($row['tenant_id']) ?>)'><?= htmlspecialchars($row['tenant_name'] ?? '-') ?></span></td>
          <td class="text-end"><?= number_format($row['amount'] ?? 0,2) ?></td>
          <td><?= htmlspecialchars($row['currency'] ?? '-') ?></td>
          <td><?= htmlspecialchars($row['due_date'] ?? '-') ?></td>
          <td><?= htmlspecialchars($row['paid_date'] ?? '-') ?></td>
          <td><?= htmlspecialchars($row['status'] ?? '-') ?></td>
          <td><?= htmlspecialchars($row['notes'] ?? '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="3" class="text-end">الإجمالي IQD:</th>
          <th class="text-end"><?= number_format($total_iqd,2) ?></th>
          <th colspan="5"></th>
        </tr>
        <tr>
          <th colspan="3" class="text-end">الإجمالي USD:</th>
          <th class="text-end"><?= number_format($total_usd,2) ?></th>
          <th colspan="5"></th>
        </tr>
      </tfoot>
    </table>
<?php else: ?>
    <p>لا توجد نتائج مطابقة.</p>
<?php endif; ?>
</div>

<div class="modal fade" id="infoModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="infoModalTitle">معلومات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="infoContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // تفعيل Select2 على الحقول التي لديها الفئة select2-search
    $('.select2-search').select2({
        dir: "rtl" // لتفعيل الوضع من اليمين إلى اليسار
    });
});

function showInfo(type, id) {
    fetch('fetch_info.php?type=' + type + '&id=' + id)
        .then(res => res.text())
        .then(html => {
            let title = type==='property'?'معلومات العقار':type==='unit'?'معلومات الوحدة':'معلومات المستأجر';
            document.getElementById('infoModalTitle').textContent = title;
            document.getElementById('infoContent').innerHTML = html;
            new bootstrap.Modal(document.getElementById('infoModal')).show();
        });
}
</script>
</body>
</html>