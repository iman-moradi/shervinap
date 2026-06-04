<?php
$page_title = 'تنظیمات پیشرفته';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'settings_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ======================== تابع کمکی ========================
function getFontList() {
    $fontDir = __DIR__ . '/../../../assets/fonts/';
    if (!is_dir($fontDir)) mkdir($fontDir, 0777, true);
    $fonts = [];
    $allowed = ['ttf', 'woff', 'woff2', 'otf'];
    foreach (scandir($fontDir) as $file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if (in_array($ext, $allowed)) {
            $fonts[] = pathinfo($file, PATHINFO_FILENAME);
        }
    }
    return array_unique($fonts);
}

// ======================== پردازش فرم ظاهری ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'appearance') {
    $font_family = $_POST['font_family'] ?? 'Tahoma';
    $text_color = $_POST['base_text_color'] ?? '#333';
    $bg_color = $_POST['base_bg_color'] ?? '#f8f9fa';
    $font_size = $_POST['font_size'] ?? '14px';
    
    $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$font_family, 'font_family']);
    $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$text_color, 'base_text_color']);
    $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$bg_color, 'base_bg_color']);
    $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$font_size, 'font_size']);
    
    if (function_exists('load_appearance_settings')) {
        load_appearance_settings();
    }
    $message = '✅ تنظیمات ظاهری ذخیره شد.';
}

// ======================== آپلود فونت ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['font_file'])) {
    $targetDir = __DIR__ . '/../../../assets/fonts/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $allowed = ['ttf', 'woff', 'woff2', 'otf'];
    $ext = strtolower(pathinfo($_FILES['font_file']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, $allowed) && $_FILES['font_file']['error'] === 0) {
        $newName = uniqid('font_') . '.' . $ext;
        move_uploaded_file($_FILES['font_file']['tmp_name'], $targetDir . $newName);
        $message_font = '✅ فونت با موفقیت آپلود شد.';
    } else {
        $message_font = '❌ فرمت فایل مجاز نیست (ttf, woff, woff2, otf).';
    }
}

// ======================== بکاپ‌گیری دستی ========================
if (isset($_GET['backup_now'])) {
    $backupFile = __DIR__ . '/../../../backups/backup_' . date('Ymd_His') . '.sql';
    if (!is_dir(__DIR__ . '/../../../backups')) mkdir(__DIR__ . '/../../../backups', 0777, true);
    
    // استفاده از متغیرهای سراسری دیتابیس به جای ثابت‌های تعریف‌نشده
    global $db_user, $db_pass, $db_host, $db_name;
    $cmd = "mysqldump --user=" . $db_user . " --password=" . $db_pass . " --host=" . $db_host . " " . $db_name . " > " . $backupFile;
    exec($cmd, $output, $return);
    if ($return === 0) {
        $message_backup = '✅ بکاپ با موفقیت ایجاد شد: ' . basename($backupFile);
    } else {
        $message_backup = '⚠️ بکاپ با mysqldump انجام نشد. از روش PHP استفاده می‌شود.';
        // روش جایگزین: خروجی SQL ساده
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql = "-- Backup created at " . date('Y-m-d H:i:s') . "\n";
        foreach ($tables as $table) {
            $stmt = $db->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $sql .= $create['Create Table'] . ";\n";
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $values = array_map(function($val) use ($db) { return $db->quote($val); }, array_values($row));
                $sql .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
            }
        }
        file_put_contents($backupFile, $sql);
        $message_backup = '✅ بکاپ با موفقیت ایجاد شد (روش PHP).';
    }
    header("Location: appearance.php?msg=backup_done");
    exit;
}

// ======================== حذف فونت ========================
if (isset($_GET['delete_font'])) {
    $fontName = urldecode($_GET['delete_font']);
    $files = glob(__DIR__ . '/../../../assets/fonts/' . $fontName . '.*');
    foreach ($files as $f) {
        if (is_file($f)) unlink($f);
    }
    header('Location: appearance.php');
    exit;
}

// ======================== حذف بکاپ ========================
if (isset($_GET['delete_backup'])) {
    $file = __DIR__ . '/../../../backups/' . urldecode($_GET['delete_backup']);
    if (file_exists($file) && is_file($file)) unlink($file);
    header('Location: appearance.php');
    exit;
}

// ======================== ذخیره تنظیمات بکاپ خودکار ========================
if (isset($_POST['save_auto'])) {
    $auto = isset($_POST['auto_backup']) ? 1 : 0;
    $hour = (int)$_POST['auto_backup_hour'];
    $db->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES ('auto_backup', ?, 'general') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$auto]);
    $db->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES ('auto_backup_hour', ?, 'general') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$hour]);
    header('Location: appearance.php');
    exit;
}

// دریافت مقادیر فعلی
$stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_group = 'appearance'");
$current = [];
while ($row = $stmt->fetch()) {
    $current[$row['setting_key']] = $row['setting_value'];
}

// لیست فونت‌های موجود
$availableFonts = getFontList();
if (empty($availableFonts)) {
    $availableFonts = ['Tahoma', 'Vazirmatn', 'IRANSans', 'Shabnam'];
}

// لیست فایل‌های بکاپ موجود
$backupFiles = glob(__DIR__ . '/../../../backups/*.sql');
rsort($backupFiles);
?>
<style>
    .font-preview {
        font-size: 20px;
        margin-top: 10px;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }
</style>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#appearanceTab">🎨 ظاهر</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#fontsTab">🔤 مدیریت فونت</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#backupTab">💾 بکاپ</a></li>
        </ul>
    </div>
    <div class="card-body tab-content">
        <!-- تب ظاهر -->
        <div class="tab-pane fade show active" id="appearanceTab">
            <?php if (isset($message)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="appearance">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>فونت</label>
                        <select name="font_family" class="form-select" id="fontSelect">
                            <?php foreach ($availableFonts as $font): ?>
                                <option value="<?= htmlspecialchars($font) ?>" <?= (isset($current['font_family']) && $current['font_family'] == $font) ? 'selected' : '' ?>><?= htmlspecialchars($font) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="font-preview" id="fontPreview">نمونه متن فارسی (متن تست)</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label>رنگ متن</label>
                        <input type="color" name="base_text_color" class="form-control" value="<?= htmlspecialchars($current['base_text_color'] ?? '#333333') ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label>رنگ زمینه</label>
                        <input type="color" name="base_bg_color" class="form-control" value="<?= htmlspecialchars($current['base_bg_color'] ?? '#f8f9fa') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>سایز فونت (مثل 14px, 1rem)</label>
                        <input type="text" name="font_size" class="form-control" value="<?= htmlspecialchars($current['font_size'] ?? '14px') ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">💾 ذخیره تغییرات</button>
            </form>
        </div>

        <!-- تب مدیریت فونت -->
        <div class="tab-pane fade" id="fontsTab">
            <?php if (isset($message_font)): ?>
                <div class="alert alert-<?= strpos($message_font, '✅') !== false ? 'success' : 'danger' ?>"><?= htmlspecialchars($message_font) ?></div>
            <?php endif; ?>
            <h5>فونت‌های موجود</h5>
            <ul class="list-group mb-3">
                <?php foreach ($availableFonts as $font): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span style="font-family: '<?= htmlspecialchars($font) ?>';"><?= htmlspecialchars($font) ?> - نمونه متن تست</span>
                        <a href="?delete_font=<?= urlencode($font) ?>" class="btn btn-sm btn-danger" onclick="return confirm('فونت حذف شود؟')">🗑️</a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <h5>آپلود فونت جدید</h5>
            <form method="post" enctype="multipart/form-data">
                <div class="mb-3">
                    <input type="file" name="font_file" class="form-control" accept=".ttf,.woff,.woff2,.otf" required>
                </div>
                <button type="submit" class="btn btn-success">📤 آپلود فونت</button>
            </form>
            <small class="text-muted">فایل فونت را در پوشه assets/fonts/ ذخیره می‌کند.</small>
        </div>

        <!-- تب بکاپ -->
        <div class="tab-pane fade" id="backupTab">
            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'backup_done'): ?>
                <div class="alert alert-success">بکاپ با موفقیت ایجاد شد.</div>
            <?php endif; ?>
            <?php if (isset($message_backup)): ?>
                <div class="alert alert-info"><?= htmlspecialchars($message_backup) ?></div>
            <?php endif; ?>
            <a href="?backup_now=1" class="btn btn-danger mb-3">🔄 بکاپ دستی (هم اکنون)</a>
            <h5>بکاپ‌های ذخیره شده</h5>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead><tr><th>نام فایل</th><th>تاریخ</th><th>حجم</th><th>عملیات</th></tr></thead>
                    <tbody>
                        <?php foreach ($backupFiles as $file): 
                            $name = basename($file);
                            $size = round(filesize($file) / 1024, 2);
                            $date = date('Y-m-d H:i:s', filemtime($file));
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($name) ?></td>
                                <td><?= htmlspecialchars($date) ?></td>
                                <td><?= number_format($size, 2) ?> KB</td>
                                <td>
                                    <a href="<?= BASE_URL ?>backups/<?= urlencode($name) ?>" class="btn btn-sm btn-info" download>⬇️ دانلود</a>
                                    <a href="?delete_backup=<?= urlencode($name) ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف شود؟')">🗑️</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($backupFiles)): ?>
                            <tr><td colspan="4" class="text-center">هیچ بکاپی یافت نشد</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <hr>
            <h5>تنظیمات بکاپ خودکار</h5>
            <form method="post">
                <?php
                $auto_backup = $db->query("SELECT setting_value FROM settings WHERE setting_key='auto_backup'")->fetchColumn();
                $auto_backup_hour = $db->query("SELECT setting_value FROM settings WHERE setting_key='auto_backup_hour'")->fetchColumn();
                if ($auto_backup_hour === false) $auto_backup_hour = 2;
                ?>
                <div class="form-check mb-2">
                    <input type="checkbox" name="auto_backup" value="1" class="form-check-input" id="autoBackup" <?= $auto_backup ? 'checked' : '' ?>>
                    <label for="autoBackup" class="form-check-label">فعالسازی بکاپ خودکار (هر روز ساعت <?= htmlspecialchars($auto_backup_hour) ?>:00)</label>
                </div>
                <div class="mb-3">
                    <label>ساعت (0-23)</label>
                    <input type="number" name="auto_backup_hour" class="form-control" value="<?= htmlspecialchars($auto_backup_hour) ?>" min="0" max="23">
                </div>
                <button type="submit" name="save_auto" class="btn btn-secondary">💾 ذخیره تنظیمات</button>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('fontSelect').addEventListener('change', function() {
    var font = this.value;
    document.getElementById('fontPreview').style.fontFamily = font;
});
</script>

<?php require_once '../../includes/footer.php'; ?>