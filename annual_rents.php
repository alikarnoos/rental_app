<?php
require 'config.php';
require_once 'tcpdf/tcpdf.php';

// جلب كل الوحدات مع معلومات العقار
$sql = "SELECT units.*, properties.name AS property_name 
        FROM units 
        LEFT JOIN properties ON units.property_id = properties.id
        ORDER BY properties.name, units.type";
$stmt = $pdo->query($sql);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// حساب الإيجار السنوي والإجمالي حسب العملة
$total_iqd_annual = 0;
$total_usd_annual = 0;

foreach ($units as &$unit) {
    $unit['annual_rent'] = $unit['monthly_rent'] * 12;
    if ($unit['currency'] === 'IQD') {
        $total_iqd_annual += $unit['annual_rent'];
    } elseif ($unit['currency'] === 'USD') {
        $total_usd_annual += $unit['annual_rent'];
    }
}
unset($unit);

// تصدير إلى CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=annual_rents.csv');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['العقار', 'نوع الوحدة', 'الحالة', 'الإيجار الشهري', 'الإيجار السنوي', 'العملة']);
    foreach ($units as $unit) {
        fputcsv($output, [
            $unit['property_name'],
            $unit['type'],
            $unit['status'] == 'available' ? 'غير مؤجرة' : 'مؤجرة',
            number_format($unit['monthly_rent'], 2),
            number_format($unit['annual_rent'], 2),
            $unit['currency']
        ]);
    }
    fputcsv($output, []);
    fputcsv($output, ['الإجمالي السنوي بالدينار العراقي (IQD):', '', '', '', number_format($total_iqd_annual, 2)]);
    fputcsv($output, ['الإجمالي السنوي بالدولار الأمريكي (USD):', '', '', '', number_format($total_usd_annual, 2)]);
    fclose($output);
    exit;
}

// تصدير إلى PDF
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('نظام إدارة العقارات');
    $pdf->SetTitle('تقرير الإيجارات السنوية');
    $pdf->setRTL(true);
    $pdf->SetFont('dejavusans', '', 12);
    $pdf->AddPage();
    $pdf->Cell(0, 10, 'تقرير الإيجارات السنوية للوحدات', 0, 1, 'C');

    $html = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;">
        <thead style="background-color:#f2f2f2;">
            <tr>
                <th>العقار</th>
                <th>نوع الوحدة</th>
                <th>الحالة</th>
                <th>الإيجار الشهري</th>
                <th>الإيجار السنوي</th>
                <th>العملة</th>
            </tr>
        </thead><tbody>';

    foreach ($units as $unit) {
        $html .= '<tr>
            <td>' . htmlspecialchars($unit['property_name']) . '</td>
            <td>' . htmlspecialchars($unit['type']) . '</td>
            <td>' . ($unit['status'] == 'available' ? 'غير مؤجرة' : 'مؤجرة') . '</td>
            <td align="right">' . number_format($unit['monthly_rent'], 2) . '</td>
            <td align="right">' . number_format($unit['annual_rent'], 2) . '</td>
            <td>' . htmlspecialchars($unit['currency']) . '</td>
        </tr>';
    }

    $html .= '</tbody></table>';
    $html .= '<br><table border="0" cellpadding="5">
        <tr><td><strong>الإجمالي السنوي بالدينار العراقي (IQD):</strong></td><td align="right">' . number_format($total_iqd_annual, 2) . '</td></tr>
        <tr><td><strong>الإجمالي السنوي بالدولار الأمريكي (USD):</strong></td><td align="right">' . number_format($total_usd_annual, 2) . '</td></tr>
    </table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('annual_rents.pdf', 'D');
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تقرير الإيجارات السنوية للوحدات</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { font-family: 'Tahoma', sans-serif; background-color: #f9f9f9; }
.table th, .table td { vertical-align: middle; }
</style>
</head>
<body>
<div class="container mt-4">
    <h2 class="mb-4">تقرير الإيجارات السنوية للوحدات</h2>

    <div class="mb-3">
        <form method="GET" style="display:inline-block;">
            <button type="submit" name="export" value="csv" class="btn btn-success">تصدير Excel</button>
        </form>
        <form method="GET" style="display:inline-block;">
            <button type="submit" name="export" value="pdf" class="btn btn-danger">تصدير PDF</button>
        </form>
        <a href="index.php" class="btn btn-secondary">العودة للرئيسية</a>
    </div>
    <div class="mb-3">
        <a href="units.php" class="btn btn-secondary">⬅️ عودة إلى إدارة الوحدات</a>
    </div>

    <table class="table table-bordered table-striped">
        <thead>
            <tr class="table-primary">
                <th>العقار</th>
                <th>نوع الوحدة</th>
                <th>الحالة</th>
                <th class="text-end">الإيجار الشهري</th>
                <th class="text-end">الإيجار السنوي</th>
                <th>العملة</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($units as $unit): ?>
                <tr>
                    <td><?= htmlspecialchars($unit['property_name']) ?></td>
                    <td><?= htmlspecialchars($unit['type']) ?></td>
                    <td><?= $unit['status'] == 'available' ? 'غير مؤجرة' : 'مؤجرة' ?></td>
                    <td class="text-end"><?= number_format($unit['monthly_rent'], 2) ?></td>
                    <td class="text-end"><?= number_format($unit['annual_rent'], 2) ?></td>
                    <td><?= htmlspecialchars($unit['currency']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="fw-bold">
                <td colspan="4" class="text-end">الإجمالي السنوي (IQD):</td>
                <td class="text-end"><?= number_format($total_iqd_annual, 2) ?></td>
                <td>IQD</td>
            </tr>
            <tr class="fw-bold">
                <td colspan="4" class="text-end">الإجمالي السنوي (USD):</td>
                <td class="text-end"><?= number_format($total_usd_annual, 2) ?></td>
                <td>USD</td>
            </tr>
        </tfoot>
    </table>
</div>
</body>
</html>
