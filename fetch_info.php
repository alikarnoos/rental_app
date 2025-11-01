<?php
require 'config.php';

if (!isset($_GET['type']) || !isset($_GET['id'])) {
    exit("طلب غير صالح.");
}

$type = $_GET['type'];
$id   = intval($_GET['id']);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* إضافة تظليل خلف أيقونات الأسهم لجعلها أكثر وضوحًا */
        .carousel-control-prev-icon,
        .carousel-control-next-icon {
            background-color: rgba(0, 0, 0, 0.4); /* خلفية سوداء شبه شفافة */
            border-radius: 50%; /* لجعلها دائرية */
            padding: 20px; /* لزيادة حجم الخلفية */
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.5); /* إضافة ظل خفيف */
        }

        /* لضمان أن الأيقونات نفسها بيضاء وواضحة */
        .carousel-control-prev-icon::before,
        .carousel-control-next-icon::before {
            color: #fff;
        }
    </style>
</head>
<body>
<div class="container mt-4">

<?php

if ($type === "property") {
    // جلب بيانات العقار
    $stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ?");
    $stmt->execute([$id]);
    $property = $stmt->fetch();

    if (!$property) exit("العقار غير موجود.");

    // جلب الملفات المرفوعة
    $files = $pdo->prepare("SELECT * FROM property_files WHERE property_id = ?");
    $files->execute([$id]);
    $files = $files->fetchAll();

    echo "<h4>".htmlspecialchars($property['name'])."</h4>";
    echo "<p><strong>الرمز:</strong> ".htmlspecialchars($property['code'])."</p>";
    echo "<p><strong>النوع:</strong> ".htmlspecialchars($property['type'])."</p>";
    echo "<p><strong>العنوان:</strong> ".htmlspecialchars($property['address'])."</p>";
    echo "<p><strong>الوصف:</strong> ".nl2br(htmlspecialchars($property['description']))."</p>";

    if ($files) {
        // تقسيم الملفات: صور / مستندات
        $images = [];
        $docs   = [];
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
            $path = "uploads/".$file['file_name'];

            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $images[] = ['path'=>$path, 'name'=>$file['original_name']];
            } else {
                $docs[] = ['path'=>$path, 'name'=>$file['original_name']];
            }
        }

        // 🔹 عرض الصور كسلايدر
        if ($images) {
            echo '<h5>الصور:</h5>';
            echo '<div id="propertyCarousel" class="carousel slide" data-bs-ride="carousel">';
            
            // المؤشرات
            echo '<div class="carousel-indicators">';
            foreach ($images as $i => $img) {
                echo '<button type="button" data-bs-target="#propertyCarousel" data-bs-slide-to="'.$i.'" '.($i==0?'class="active" aria-current="true"':'').' aria-label="Slide '.($i+1).'"></button>';
            }
            echo '</div>';

            // الشرائح
            echo '<div class="carousel-inner">';
            foreach ($images as $i => $img) {
                echo '<div class="carousel-item '.($i==0?'active':'').'">';
                echo '<img src="'.$img['path'].'" class="d-block w-100" alt="'.htmlspecialchars($img['name']).'" style="max-height:400px;object-fit:contain;">';
                echo '<div class="carousel-caption d-none d-md-block"><p>'.htmlspecialchars($img['name']).'</p></div>';
                echo '</div>';
            }
            echo '</div>';

            // أزرار التحكم
            echo '<button class="carousel-control-prev" type="button" data-bs-target="#propertyCarousel" data-bs-slide="prev">
                      <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                      <span class="visually-hidden">السابق</span>
                  </button>';
            echo '<button class="carousel-control-next" type="button" data-bs-target="#propertyCarousel" data-bs-slide="next">
                      <span class="carousel-control-next-icon" aria-hidden="true"></span>
                      <span class="visually-hidden">التالي</span>
                  </button>';

            echo '</div><br>';
        }

        // 🔹 عرض المستندات كرابط تحميل
        if ($docs) {
            echo "<h5>الملفات:</h5><ul>";
            foreach ($docs as $doc) {
                echo "<li><a href='{$doc['path']}' target='_blank'>📄 ".htmlspecialchars($doc['name'])."</a></li>";
            }
            echo "</ul>";
        }
    }
    
    // 🆕 إضافة زر التعديل
    echo '<hr>';
    echo '<a href="properties.php?edit='.htmlspecialchars($id).'" class="btn btn-warning">تعديل العقار</a>';

} elseif ($type === "unit") {
    // جلب بيانات الوحدة
    $stmt = $pdo->prepare("
        SELECT units.*, properties.name AS property_name
        FROM units 
        JOIN properties ON units.property_id = properties.id
        WHERE units.id = ?
    ");
    $stmt->execute([$id]);
    $unit = $stmt->fetch();

    if (!$unit) exit("الوحدة غير موجودة.");

    echo "<h4>الوحدة: ".htmlspecialchars($unit['code'])."</h4>";
    echo "<p><strong>العقار:</strong> ".htmlspecialchars($unit['property_name'])."</p>";
    echo "<p><strong>النوع:</strong> ".htmlspecialchars($unit['type'])."</p>";
    echo "<p><strong>الحالة:</strong> ".($unit['status']==='available'?'متاحة':'مؤجرة')."</p>";
    echo "<p><strong>الإيجار الشهري:</strong> ".number_format($unit['monthly_rent'],2)." ".htmlspecialchars($unit['currency'])."</p>";

    // المستأجر الحالي (إن وجد)
    $tenant = $pdo->prepare("SELECT full_name FROM tenants WHERE unit_id = ?");
    $tenant->execute([$id]);
    $tenant = $tenant->fetch();

    if ($tenant) {
        echo "<p><strong>المستأجر الحالي:</strong> ".htmlspecialchars($tenant['full_name'])."</p>";
    }
    
    // 🆕 إضافة زر التعديل للوحدة
    echo '<hr>';
    echo '<a href="units.php?edit='.htmlspecialchars($id).'" class="btn btn-warning">تعديل الوحدة</a>';


} elseif ($type === "tenant") {
    // جلب بيانات المستأجر
    $stmt = $pdo->prepare("
        SELECT tenants.*, units.code AS unit_code, properties.name AS property_name
        FROM tenants
        LEFT JOIN units ON tenants.unit_id = units.id
        LEFT JOIN properties ON units.property_id = properties.id
        WHERE tenants.id = ?
    ");
    $stmt->execute([$id]);
    $tenant = $stmt->fetch();

    if (!$tenant) exit("المستأجر غير موجود.");

    echo "<h4>".htmlspecialchars($tenant['full_name'])."</h4>";
    echo "<p><strong>الهاتف:</strong> ".htmlspecialchars($tenant['phone'])."</p>";
    echo "<p><strong>نوع العقد:</strong> ".htmlspecialchars($tenant['contract_type'])."</p>";
    echo "<p><strong>تاريخ بدء العقد:</strong> ".htmlspecialchars($tenant['contract_start'])."</p>";

    if ($tenant['unit_code']) {
        echo "<p><strong>الوحدة:</strong> ".htmlspecialchars($tenant['unit_code'])."</p>";
        echo "<p><strong>العقار:</strong> ".htmlspecialchars($tenant['property_name'])."</p>";
    } else {
        echo "<p><em>لا يوجد وحدة مرتبطة حالياً.</em></p>";
    }
    
    // 🆕 إضافة زر التعديل للمستأجر
    echo '<hr>';
    echo '<a href="tenants.php?edit='.htmlspecialchars($id).'" class="btn btn-warning">تعديل المستأجر</a>';
    
} else {
    exit("نوع غير مدعوم.");
}
?>

</div>
</body>
</html>