<?php
// backup.php

// تضمين ملف إعدادات الاتصال بقاعدة البيانات
require 'config.php';

// تعريف مسار مجلد التحميلات
// يجب أن يكون هذا المسار صحيحاً بالنسبة لموقع ملف backup.php
// إذا كان backup.php في rental_app/، فإن المسار هو uploads/
define('UPLOADS_DIR', 'uploads/');

// رسالة حالة العملية
$message = "";
$status_class = "";
$download_link = null;
$show_form = true; // متغير للتحكم في عرض النموذج

/**
 * دالة مساعدة لنسخ مجلد ومحتوياته بشكل متكرر
 * @param string $source مصدر المجلد
 * @param string $dest وجهة النسخ
 */
function copyDirectory($source, $dest) {
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($dest)) {
        mkdir($dest, 0777, true);
    }

    $dir = opendir($source);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($source . '/' . $file)) {
                copyDirectory($source . '/' . $file, $dest . '/' . $file);
            } else {
                copy($source . '/' . $file, $dest . '/' . $file);
            }
        }
    }
    closedir($dir);
    return true;
}

/**
 * دالة مساعدة لحذف مجلد ومحتوياته بشكل متكرر
 * @param string $dirPath مسار المجلد المراد حذفه
 */
function deleteDirectory($dirPath) {
    if (!is_dir($dirPath)) {
        return;
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) {
            deleteDirectory($file);
        } else {
            unlink($file);
        }
    }
    rmdir($dirPath);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $show_form = false; // إخفاء النموذج عند إرسال البيانات

    // إعدادات قاعدة البيانات من ملف config.php
    $db_host = $host;
    $db_name = $db;
    $db_user = $user;
    $db_pass = $pass;

    // 🔹 معالجة عملية النسخ الاحتياطي
    if (isset($_POST['backup'])) {
        $filename_base = !empty($_POST['file_name']) ? $_POST['file_name'] : $db_name . '_' . date('Y-m-d_H-i-s');
        $sql_filename = $filename_base . '.sql';
        $zip_filename = $filename_base . '.zip';
        $backup_dir = 'backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }
        $sql_filepath = $backup_dir . $sql_filename;
        $zip_filepath = $backup_dir . $zip_filename;

        try {
            // المسار الكامل لأداة mysqldump في XAMPP على Windows
            // تأكد من أن هذا المسار صحيح على خادمك
            $mysqldump_path = 'C:/xampp/mysql/bin/mysqldump.exe'; 
            
            // بناء أمر النسخ الاحتياطي لقاعدة البيانات
            $command = "$mysqldump_path --opt -h" . escapeshellarg($db_host) . " -u" . escapeshellarg($db_user);
            if (!empty($db_pass)) {
                $command .= " -p" . escapeshellarg($db_pass);
            }
            $command .= " " . escapeshellarg($db_name) . " > " . escapeshellarg($sql_filepath);

            exec($command, $output, $return_var);

            if ($return_var === 0) {
                // قاعدة البيانات تم نسخها بنجاح، الآن نضغط الملفات والمجلدات
                $zip = new ZipArchive();
                if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
                    // إضافة ملف SQL إلى الأرشيف
                    $zip->addFile($sql_filepath, basename($sql_filepath));

                    // إضافة مجلد uploads إلى الأرشيف
                    $uploads_dir = realpath(UPLOADS_DIR);
                    if ($uploads_dir && is_dir($uploads_dir)) {
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($uploads_dir),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        foreach ($files as $name => $file) {
                            if (!$file->isDir()) {
                                $filePath = $file->getRealPath();
                                $relativePath = substr($filePath, strlen($uploads_dir) + 1);
                                $zip->addFile($filePath, UPLOADS_DIR . $relativePath);
                            }
                        }
                    }

                    $zip->close();

                    // حذف ملف SQL المؤقت بعد ضغطه
                    unlink($sql_filepath);
                    
                    $message = "تم إنشاء النسخة الاحتياطية بنجاح في ملف مضغوط (.zip).";
                    $status_class = "alert-success";
                    $download_link = $zip_filepath;
                } else {
                    $message = "حدث خطأ أثناء إنشاء ملف ZIP.";
                    $status_class = "alert-danger";
                    unlink($sql_filepath); // حذف الملف المؤقت
                }
            } else {
                $message = "حدث خطأ أثناء عملية النسخ الاحتياطي لقاعدة البيانات. رمز الخطأ: " . $return_var . ".";
                $status_class = "alert-danger";
            }
        } catch (Exception $e) {
            $message = "حدث خطأ: " . $e->getMessage();
            $status_class = "alert-danger";
        }
        $show_form = true;
    }
    
    // 🔹 معالجة عملية الاسترجاع
    elseif (isset($_POST['restore'])) {
        if (isset($_FILES['restore_file']) && $_FILES['restore_file']['error'] === UPLOAD_ERR_OK) {
            $uploaded_file = $_FILES['restore_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['restore_file']['name'], PATHINFO_EXTENSION));

            if ($file_ext !== 'zip') {
                $message = "الرجاء رفع ملف بصيغة .zip فقط.";
                $status_class = "alert-danger";
            } else {
                $temp_restore_dir = 'temp_restore_' . time() . '/';
                mkdir($temp_restore_dir, 0777, true);

                $zip = new ZipArchive();
                if ($zip->open($uploaded_file) === TRUE) {
                    $zip->extractTo($temp_restore_dir);
                    $zip->close();
                    
                    // البحث عن ملف SQL داخل المجلد المؤقت
                    $sql_files = glob($temp_restore_dir . '*.sql');
                    if (count($sql_files) > 0) {
                        $sql_file_to_restore = $sql_files[0];
                        
                        // المسار الكامل لأداة mysql في XAMPP على Windows
                        // تأكد من أن هذا المسار صحيح على خادمك
                        $mysql_path = 'C:/xampp/mysql/bin/mysql.exe'; 
                        
                        // بناء أمر الاسترجاع
                        $command = "$mysql_path -h" . escapeshellarg($db_host) . " -u" . escapeshellarg($db_user);
                        if (!empty($db_pass)) {
                            $command .= " -p" . escapeshellarg($db_pass);
                        }
                        $command .= " " . escapeshellarg($db_name) . " < " . escapeshellarg($sql_file_to_restore);
                        
                        exec($command, $output, $return_var);
                        
                        if ($return_var === 0) {
                            // استعادة قاعدة البيانات بنجاح، الآن نسترجع مجلد uploads
                            if (is_dir($temp_restore_dir . 'uploads/')) {
                                // حذف المجلد الحالي لـ uploads قبل الاستبدال
                                deleteDirectory(UPLOADS_DIR);
                                // نقل المجلد الجديد
                                if (copyDirectory($temp_restore_dir . 'uploads/', UPLOADS_DIR)) {
                                     $message = "تم استرجاع قاعدة البيانات والملفات بنجاح.";
                                     $status_class = "alert-success";
                                } else {
                                     $message = "تم استرجاع قاعدة البيانات بنجاح، ولكن حدث خطأ في استرجاع ملفات uploads.";
                                     $status_class = "alert-warning";
                                }
                            } else {
                                $message = "تم استرجاع قاعدة البيانات بنجاح. لا يوجد مجلد 'uploads' في الملف المضغوط.";
                                $status_class = "alert-warning";
                            }
                        } else {
                            $message = "حدث خطأ أثناء عملية استرجاع قاعدة البيانات. رمز الخطأ: " . $return_var . ". تأكد من أن ملف النسخ الاحتياطي سليم.";
                            $status_class = "alert-danger";
                        }
                    } else {
                        $message = "لا يوجد ملف .sql داخل الملف المضغوط.";
                        $status_class = "alert-danger";
                    }
                    
                    // تنظيف الملفات المؤقتة
                    deleteDirectory($temp_restore_dir);

                } else {
                    $message = "حدث خطأ أثناء فتح الملف المضغوط. تأكد من أنه ملف صالح.";
                    $status_class = "alert-danger";
                }
            }
        } else {
            $message = "الرجاء اختيار ملف لرفعه.";
            $status_class = "alert-danger";
        }
        $show_form = true;
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة النسخ الاحتياطي</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
</head>
<body>
    <div class="container mt-4">
        <h2 class="mb-4">النسخ الاحتياطي والاسترجاع</h2>

        <div class="modal fade" id="loadingModal" tabindex="-1" aria-labelledby="loadingModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-body text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">الرجاء الانتظار...</span>
                        </div>
                        <h5 class="mt-3">الرجاء الانتظار...</h5>
                        <p>العملية قيد التنفيذ، قد تستغرق بعض الوقت.</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert <?= $status_class ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="card mb-4" id="backup-card" style="display: <?= $show_form ? 'block' : 'none' ?>;">
            <div class="card-header bg-primary text-white">
                إنشاء نسخة احتياطية
            </div>
            <div class="card-body">
                <form method="POST" id="backup-form">
                    <div class="mb-3">
                        <label for="file_name" class="form-label">اسم ملف النسخ الاحتياطي (اختياري)</label>
                        <input type="text" class="form-control" id="file_name" name="file_name" placeholder="مثال: backup_2023_10_27" />
                        <div class="form-text">سيتم إضافة امتداد `.zip` تلقائياً.</div>
                    </div>
                    <button type="submit" name="backup" class="btn btn-primary">إنشاء نسخة احتياطية</button>
                    
                </form>
            </div>
        </div>
        
        <?php if (isset($download_link)): ?>
            <div class="mb-4 text-center">
                <p>تم إنشاء النسخة الاحتياطية بنجاح.</p>
                <a href="<?= htmlspecialchars($download_link) ?>" class="btn btn-success" download>تنزيل الملف الآن</a>
            </div>
        <?php endif; ?>

        <div class="card mb-4" id="restore-card" style="display: <?= $show_form ? 'block' : 'none' ?>;">
            <div class="card-header bg-danger text-white">
                استرجاع نسخة احتياطية
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="restore-form">
                    <div class="mb-3">
                        <label for="restore_file" class="form-label">اختر ملف النسخة الاحتياطية (.zip)</label>
                        <input type="file" class="form-control" id="restore_file" name="restore_file" accept=".zip" required>
                        <div class="form-text text-danger">تحذير: هذه العملية ستحذف البيانات والملفات الحالية وتستبدلها ببيانات الملف المرفوع.</div>
                    </div>
                    <button type="submit" name="restore" class="btn btn-danger">استرجاع قاعدة البيانات</button>
                    <a href="index.php" class="btn btn-secondary">العودة إلى الرئيسية</a>
                </form>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));

        // إظهار المودال عند إرسال النموذج
        document.getElementById('backup-form').addEventListener('submit', function() {
            loadingModal.show();
        });

        document.getElementById('restore-form').addEventListener('submit', function() {
            loadingModal.show();
        });
    </script>
</body>
</html>
