<?php
// ===== الاتصال بقاعدة البيانات (MYSQLI) =====
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "rental_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { die("فشل الاتصال: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

// ===== مسار المرفقات =====
$uploadDir = "uploads/expenses";

// ===== مكتبات التصدير (اختياري) =====
$hasPhpSpreadsheet = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        $hasPhpSpreadsheet = true;
    }
}

// TCPDF للتصدير PDF (مطلوب لزر PDF)
require_once __DIR__ . '/tcpdf/tcpdf.php';

// ===== جلب القوائم: العقارات والوحدات =====
$properties = $conn->query("SELECT id, name FROM properties ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$units = $conn->query("
    SELECT u.id, u.code, p.name AS property_name
    FROM units u
    LEFT JOIN properties p ON p.id = u.property_id
    ORDER BY p.name, u.code
")->fetch_all(MYSQLI_ASSOC);

// ===== بناء شروط البحث =====
$where  = [];
$params = [];
$types  = '';

if (!empty($_GET['q']))      { $where[] = "e.title LIKE ?";      $params[] = "%".$_GET['q']."%"; $types .= 's'; }
if (!empty($_GET['date_from'])) { $where[] = "e.expense_date >= ?";  $params[] = $_GET['date_from']; $types .= 's'; }
if (!empty($_GET['date_to']))   { $where[] = "e.expense_date <= ?";  $params[] = $_GET['date_to'];   $types .= 's'; }
if (!empty($_GET['property_id'])) { $where[] = "e.property_id = ?";  $params[] = (int)$_GET['property_id']; $types .= 'i'; }
if (!empty($_GET['unit_id']))   { $where[] = "e.unit_id = ?";       $params[] = (int)$_GET['unit_id'];      $types .= 'i'; }
if (!empty($_GET['currency']))   { $where[] = "e.currency = ?";      $params[] = $_GET['currency'];  $types .= 's'; }

$whereSQL = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// ===== جلب النتائج الأساسية =====
$sql = "
    SELECT e.*,
            p.name AS property_name,
            u.code AS unit_code
    FROM expenses e
    LEFT JOIN properties p ON p.id = e.property_id
    LEFT JOIN units u ON u.id = e.unit_id
    $whereSQL
    ORDER BY e.expense_date DESC, e.id DESC
";
$stmt = $conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);

// ===== جلب ملفات كل فاتورة (expense_files) مرة واحدة وتجميعها =====
$filesByExpense = [];
if (!empty($rows)) {
    $ids = array_column($rows, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $typesIn = str_repeat('i', count($ids));
    $sqlF = "SELECT id, expense_id, file_name, original_name, mime_type, created_at
             FROM expense_files
             WHERE expense_id IN ($placeholders)
             ORDER BY id ASC";
    $stmtF = $conn->prepare($sqlF);
    $stmtF->bind_param($typesIn, ...$ids);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    while ($f = $resF->fetch_assoc()) {
        $filesByExpense[$f['expense_id']][] = $f;
    }
}

// ===== حساب المجاميع حسب العملة =====
$total_iqd = 0.0;
$total_usd = 0.0;
foreach ($rows as $r) {
    if ($r['currency'] === 'IQD') $total_iqd += (float)$r['amount'];
    if ($r['currency'] === 'USD') $total_usd += (float)$r['amount'];
}

// ===== التصدير: Excel أو CSV  =====
if (isset($_GET['export'])) {
    if ($_GET['export'] === 'csv' || ($_GET['export'] === 'excel' && !$hasPhpSpreadsheet)) {
        // تصدير CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="expenses_report.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['#','العنوان','العقار','الوحدة','المبلغ','العملة','التاريخ','ملاحظات','عدد المرفقات']);
        foreach ($rows as $r) {
            $attachmentsCount = isset($filesByExpense[$r['id']]) ? count($filesByExpense[$r['id']]) : 0;
            fputcsv($out, [
                $r['id'],
                $r['title'],
                $r['property_name'],
                $r['unit_code'],
                number_format($r['amount'],2),
                $r['currency'],
                $r['expense_date'],
                $r['notes'],
                $attachmentsCount
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['','', '', 'الإجمالي IQD', number_format($total_iqd,2)]);
        fputcsv($out, ['','', '', 'الإجمالي USD', number_format($total_usd,2)]);
        fclose($out);
        exit;
    } elseif ($_GET['export'] === 'excel' && $hasPhpSpreadsheet) {
        // Excel عبر PhpSpreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('تقرير المصروفات');

        // رؤوس
        $headers = ['#','العنوان','العقار','الوحدة','المبلغ','العملة','التاريخ','ملاحظات','عدد المرفقات'];
        $colMap = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
        foreach ($headers as $index => $h) {
            $sheet->setCellValue($colMap[$index] . '1', $h);
        }

        // بيانات
        $rowIdx = 2;
        foreach ($rows as $r) {
            $attachmentsCount = isset($filesByExpense[$r['id']]) ? count($filesByExpense[$r['id']]) : 0;
            $sheet->setCellValue('A' . $rowIdx, $r['id']);
            $sheet->setCellValue('B' . $rowIdx, $r['title']);
            $sheet->setCellValue('C' . $rowIdx, $r['property_name'] ?: '');
            $sheet->setCellValue('D' . $rowIdx, $r['unit_code'] ?: '');
            $sheet->setCellValue('E' . $rowIdx, (float)$r['amount']);
            $sheet->setCellValue('F' . $rowIdx, $r['currency']);
            $sheet->setCellValue('G' . $rowIdx, $r['expense_date']);
            $sheet->setCellValue('H' . $rowIdx, $r['notes']);
            $sheet->setCellValue('I' . $rowIdx, $attachmentsCount);
            $rowIdx++;
        }
        
        // المجاميع
        $sheet->setCellValue("D".($rowIdx+1), "الإجمالي IQD:");
        $sheet->setCellValue("E".($rowIdx+1), (float)$total_iqd);
        $sheet->setCellValue("D".($rowIdx+2), "الإجمالي USD:");
        $sheet->setCellValue("E".($rowIdx+2), (float)$total_usd);

        // تنسيق الأرقام
        $sheet->getStyle('E2:E' . ($rowIdx+2))->getNumberFormat()->setFormatCode('#,##0.00');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
        header('Content-Disposition: attachment; filename="expenses_report.xlsx"');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

// ===== التصدير: PDF عبر TCPDF =====
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $pdf = new TCPDF('L','mm','A4', true, 'UTF-8', false);
    $pdf->setRTL(true);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('نظام إدارة العقارات');
    $pdf->SetTitle('تقرير المصروفات');
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans','',12);

    $html = '<h3 style="text-align:center;">تقرير المصروفات</h3>';
    $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%" style="border-collapse:collapse;">
        <thead>
            <tr style="background-color:#f2f2f2;">
                <th width="5%">#</th>
                <th width="22%">العنوان</th>
                <th width="20%">العقار</th>
                <th width="12%">الوحدة</th>
                <th width="9%">المبلغ</th>
                <th width="7%">العملة</th>
                <th width="12%">التاريخ</th>
                <th width="13%">ملاحظات</th>
            </tr>
        </thead>
        <tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>
            <td>'.(int)$r['id'].'</td>
            <td>'.htmlspecialchars($r['title']).'</td>
            <td>'.htmlspecialchars($r['property_name'] ?: '').'</td>
            <td>'.htmlspecialchars($r['unit_code'] ?: '').'</td>
            <td align="right">'.number_format($r['amount'],2).'</td>
            <td>'.htmlspecialchars($r['currency']).'</td>
            <td>'.htmlspecialchars($r['expense_date']).'</td>
            <td>'.htmlspecialchars($r['notes']).'</td>
        </tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<br><table border="0" cellpadding="4" cellspacing="0" width="100%">
        <tr>
            <td align="right" width="50%"><b>الإجمالي IQD:</b> '.number_format($total_iqd,2).'</td>
            <td align="right" width="50%"><b>الإجمالي USD:</b> '.number_format($total_usd,2).'</td>
        </tr>
    </table>';

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('expenses_report.pdf','D');
    exit;
}

// تحضير البيانات للمرفقات بصيغة JSON
$jsonAttachments = [];
foreach ($filesByExpense as $expId => $list) {
    $formattedFiles = [];
    foreach ($list as $f) {
        $path = $uploadDir . '/' . $f['file_name'];
        $formattedFiles[] = [
            'file_name' => $f['file_name'],
            'original_name' => $f['original_name'],
            'mime_type' => strtolower($f['mime_type']),
            'url' => $path
        ];
    }
    $jsonAttachments[$expId] = $formattedFiles;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>البحث المتقدم للمصروفات</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body{padding:20px;background:#f8f9fa;}
    .thumb {
        width: 60px; height: 60px; object-fit: cover; border-radius: 6px; cursor: pointer; border:1px solid #ddd;
    }
    .file-pill {
        display:inline-flex; align-items:center; gap:6px; padding:4px 8px; background:#eef; border-radius: 999px;
        border:1px solid #dde; font-size: 12px; margin:2px; cursor:pointer;
    }
    .modal-viewport {
        width: 100%; min-height: 70vh; display:flex; align-items:center; justify-content:center; background:#000;
    }
    .modal-viewport img { max-width: 100%; max-height: 70vh; }
    .pdf-frame, .doc-frame { width:100%; height:70vh; border:0; background:#fff; }
    
    /* ADDED: Styles for Select2 to match bootstrap */
    .select2-container .select2-selection--single { height: 38px; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
</style>
</head>
<body>
<div class="container">
    <h3 class="mb-4">البحث المتقدم للمصروفات</h3>

    <form method="get" class="row g-3 mb-4">
    <div class="col-md-3">
        <label class="form-label">كلمة بالعنوان</label>
        <input type="text" name="q" value="<?= isset($_GET['q'])?htmlspecialchars($_GET['q']):'' ?>" class="form-control" placeholder="مثال: كهرباء، صيانة...">
    </div>
    <div class="col-md-2">
        <label class="form-label">من تاريخ</label>
        <input type="date" name="date_from" value="<?= isset($_GET['date_from'])?htmlspecialchars($_GET['date_from']):'' ?>" class="form-control">
    </div>
    <div class="col-md-2">
        <label class="form-label">إلى تاريخ</label>
        <input type="date" name="date_to" value="<?= isset($_GET['date_to'])?htmlspecialchars($_GET['date_to']):'' ?>" class="form-control">
    </div>
    <div class="col-md-3">
        <label class="form-label">العقار</label>
        <select name="property_id" class="form-select select2-search">
            <option value="">— الكل —</option>
            <?php foreach($properties as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (isset($_GET['property_id']) && $_GET['property_id']==$p['id'])?'selected':'' ?>>
                    <?= htmlspecialchars($p['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">الوحدة</label>
        <select name="unit_id" class="form-select select2-search">
            <option value="">— الكل —</option>
            <?php foreach($units as $u): ?>
                <option value="<?= $u['id'] ?>" <?= (isset($_GET['unit_id']) && $_GET['unit_id']==$u['id'])?'selected':'' ?>>
                    <?= htmlspecialchars($u['property_name'].' - '.$u['code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">العملة</label>
        <select name="currency" class="form-select">
            <option value="">— الكل —</option>
            <option value="IQD" <?= (isset($_GET['currency']) && $_GET['currency']==='IQD')?'selected':'' ?>>IQD</option>
            <option value="USD" <?= (isset($_GET['currency']) && $_GET['currency']==='USD')?'selected':'' ?>>USD</option>
        </select>
    </div>

    <div class="col-12 d-flex gap-2">
        <button type="submit" class="btn btn-primary">بحث</button>
        <button type="submit" name="export" value="csv" class="btn btn-success">تصدير Excel</button>
        <button type="submit" name="export" value="pdf" class="btn btn-danger">تصدير PDF</button>
        <a href="expenses.php" class="btn btn-outline-secondary">رجوع للمصروفات</a>
    </div>
    </form>

    <?php if($rows): ?>
    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>العنوان</th>
                    <th>العقار</th>
                    <th>الوحدة</th>
                    <th class="text-end">المبلغ</th>
                    <th>العملة</th>
                    <th>التاريخ</th>
                    <th>ملاحظات</th>
                    <th>المرفقات</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars($r['title']) ?></td>
                        <td><?= htmlspecialchars($r['property_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($r['unit_code'] ?: '-') ?></td>
                        <td class="text-end"><?= number_format($r['amount'],2) ?></td>
                        <td><?= htmlspecialchars($r['currency']) ?></td>
                        <td><?= htmlspecialchars($r['expense_date']) ?></td>
                        <td><?= htmlspecialchars($r['notes']) ?></td>
                        <td>
                            <?php
                                $files = $filesByExpense[$r['id']] ?? [];
                                if ($files):
                                    foreach ($files as $idx => $f):
                                        $path = $uploadDir . '/' . $f['file_name'];
                                        $mime = strtolower($f['mime_type']);

                                        // تحديد هل هو صورة
                                        $isImg = str_starts_with($mime, 'image/');
                                ?>
                                    <?php if ($isImg): ?>
                                        <img src="<?= htmlspecialchars($path) ?>"
                                             class="thumb"
                                             title="<?= htmlspecialchars($f['original_name']) ?>"
                                             onclick="openModal(<?= (int)$r['id'] ?>, <?= (int)$idx ?>)">
                                    <?php else: ?>
                                        <span class="file-pill"
                                             title="<?= htmlspecialchars($f['original_name']) ?>"
                                             onclick="openModal(<?= (int)$r['id'] ?>, <?= (int)$idx ?>)">
                                            <?= str_contains($mime, 'pdf') ? 'PDF' : 'DOC' ?>
                                        </span>
                                    <?php endif; ?>
                            <?php
                                    endforeach;
                                else:
                                    echo '—';
                                endif;
                            ?>
                        </td>
                        <td class="d-flex gap-2">
                            <a class="btn btn-sm btn-warning" href="expenses.php?edit=<?= (int)$r['id'] ?>">تعديل</a>
                            <a class="btn btn-sm btn-danger"
                               href="expenses.php?delete=<?= (int)$r['id'] ?>"
                               onclick="return confirm('هل أنت متأكد من الحذف؟');">حذف</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="text-end">الإجمالي IQD:</th>
                    <th class="text-end"><?= number_format($total_iqd, 2) ?></th>
                    <th colspan="5"></th>
                </tr>
                <tr>
                    <th colspan="4" class="text-end">الإجمالي USD:</th>
                    <th class="text-end"><?= number_format($total_usd, 2) ?></th>
                    <th colspan="5"></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php else: ?>
        <p>لا توجد نتائج مطابقة.</p>
    <?php endif; ?>
</div>

<div class="modal fade" id="filesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">عرض المرفقات</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="modalViewport" class="modal-viewport"></div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" id="btnPrev">السابق</button>
                    <button type="button" class="btn btn-secondary" id="btnNext">التالي</button>
                </div>
                <div class="d-flex gap-2">
                    <a id="btnDownload" class="btn btn-success" download>تنزيل</a>
                    <button type="button" class="btn btn-primary" id="btnPrint">طباعة الصورة</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/ar.js"></script>

<script>
// تفعيل Select2 على العناصر التي تحمل فئة select2-search
$(document).ready(function() {
    $('.select2-search').select2({
        dir: "rtl", // لدعم اللغة العربية
        theme: "bootstrap-5" // لاستخدام تصميم Bootstrap
    });
});

// ===== الكود الخاص بعرض المرفقات (Modal) =====
const attachments = <?= json_encode($jsonAttachments, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

let currentExp = null;
let currentIdx = 0;
let filesModalInstance = null; // لتخزين كائن المودال

document.addEventListener('DOMContentLoaded', function() {
    filesModalInstance = new bootstrap.Modal(document.getElementById('filesModal'));
});


function openModal(expId, index) {
    currentExp = expId;
    currentIdx = index || 0;
    renderCurrent();
    filesModalInstance.show();
}

function renderCurrent() {
    const list = attachments[currentExp] || [];
    const item = list[currentIdx];
    const viewport = document.getElementById('modalViewport');
    const btnPrint = document.getElementById('btnPrint');
    const btnDownload = document.getElementById('btnDownload');

    if (!item) {
        viewport.innerHTML = '<div class="text-white p-3">لا يوجد ملف.</div>';
        btnDownload.style.display = 'none';
        btnPrint.style.display = 'none';
        return;
    }

    btnDownload.href = item.url;
    btnDownload.download = item.original_name || item.file_name;
    btnDownload.style.display = 'inline-block';

    const mime = item.mime_type;
    btnPrint.style.display = 'none'; 

    if (mime.startsWith('image/')) {
        viewport.innerHTML = `<img id="modalImage" src="${item.url}" alt="${item.original_name || ''}">`;
        btnPrint.style.display = 'inline-block';
    } else if (mime === 'application/pdf') {
        viewport.innerHTML = `<iframe class="pdf-frame" src="${item.url}#zoom=page-width"></iframe>`;
    } else {
        viewport.innerHTML = `
            <div class="bg-white p-3 rounded w-100">
                <h6 class="mb-2">هذا النوع لا يدعم المعاينة المباشرة.</h6>
                <p class="mb-2">الملف: <strong>${item.original_name || item.file_name}</strong></p>
                <a class="btn btn-success" href="${item.url}" download>تنزيل الملف</a>
            </div>`;
    }
}

document.getElementById('btnNext').addEventListener('click', function() {
    const list = attachments[currentExp] || [];
    if (!list.length) return;
    currentIdx = (currentIdx + 1) % list.length;
    renderCurrent();
});

document.getElementById('btnPrev').addEventListener('click', function() {
    const list = attachments[currentExp] || [];
    if (!list.length) return;
    currentIdx = (currentIdx - 1 + list.length) % list.length;
    renderCurrent();
});

document.getElementById('btnPrint').addEventListener('click', function() {
    const img = document.getElementById('modalImage');
    if (!img) return;
    const w = window.open('');
    w.document.write(`<img src="${img.src}" style="max-width:100%;">`);
    w.document.close();
    w.focus();
    w.print();
    w.close();
});
</script>

</body>
</html>