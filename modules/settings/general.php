<?php
$page_title = 'تنظیمات عمومی و پیامک';
require_once '../../includes/header.php';
require_once '../../includes/SMSManager.php';

if (!has_permission($_SESSION['user_id'], 'settings_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shop_name = trim($_POST['shop_name']);
    $shop_phone = trim($_POST['shop_phone']);
    $shop_address = trim($_POST['shop_address']);
    $admin_mobile = trim($_POST['admin_mobile']);
    $sms_api_key = trim($_POST['sms_api_key']);
    $sms_api_url = trim($_POST['sms_api_url']);
    $sms_sender = trim($_POST['sms_sender']);
    $sms_status = isset($_POST['sms_status']) ? 1 : 0;
    
    $settings = [
        'shop_name' => $shop_name,
        'shop_phone' => $shop_phone,
        'shop_address' => $shop_address,
        'admin_mobile' => $admin_mobile,
        'sms_api_key' => $sms_api_key,
        'sms_api_url' => $sms_api_url,
        'sms_sender' => $sms_sender,
        'sms_status' => $sms_status
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $db->prepare("REPLACE INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general')");
        $stmt->execute([$key, $value]);
    }
    $success = 'تنظیمات با موفقیت ذخیره شد.';
}

// دریافت مقادیر فعلی
$stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_group = 'general'");
$current = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $current[$row['setting_key']] = $row['setting_value'];
}

if (!isset($current['sms_status'])) $current['sms_status'] = '0';
$sms_status_checked = ($current['sms_status'] == '1') ? 'checked' : '';

$smsManager = new SMSManager($db);
?>
<div class="card">
    <div class="card-header">تنظیمات عمومی و پیامک</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        
        <?php if (!$smsManager->isAvailable()): ?>
            <div class="alert alert-warning">⚠️ سرویس پیامک در حال حاضر غیرفعال است یا کلید API وارد نشده.</div>
        <?php else: ?>
            <div class="alert alert-success">✅ سرویس پیامک فعال است.</div>
        <?php endif; ?>
        
        <form method="post">
            <h5>اطلاعات فروشگاه</h5>
            <div class="row">
                <div class="col-md-6 mb-3"><label>نام فروشگاه</label><input type="text" name="shop_name" class="form-control" value="<?= htmlspecialchars($current['shop_name'] ?? '') ?>"></div>
                <div class="col-md-6 mb-3"><label>شماره تماس</label><input type="text" name="shop_phone" class="form-control" value="<?= htmlspecialchars($current['shop_phone'] ?? '') ?>"></div>
                <div class="col-md-12 mb-3"><label>آدرس</label><textarea name="shop_address" class="form-control"><?= htmlspecialchars($current['shop_address'] ?? '') ?></textarea></div>
                <div class="col-md-6 mb-3"><label>شماره موبایل مدیر (برای هشدارها)</label><input type="text" name="admin_mobile" class="form-control" value="<?= htmlspecialchars($current['admin_mobile'] ?? '') ?>"></div>
            </div>
            <h5>تنظیمات درگاه پیامک (فراز اس ام اس)</h5>
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label>کلید API (API Key)</label>
                    <input type="text" name="sms_api_key" class="form-control" value="<?= htmlspecialchars($current['sms_api_key'] ?? '') ?>">
                    <small class="text-muted">کلید API خود را از پنل فراز اس ام اس دریافت کنید. <a href="https://panel.iranpayamak.com" target="_blank">ورود به پنل</a></small>
                </div>

                <div class="col-md-12 mb-3">
                    <label>آدرس API (در صورت نیاز)</label>
                    <input type="text" name="sms_api_url" class="form-control" placeholder="https://api.iranpayamak.com/ws/v1/sms/simple" value="<?= htmlspecialchars($current['sms_api_url'] ?? '') ?>">
                    <small class="text-muted">اختیاری – در صورت خالی بودن از آدرس پیش‌فرض استفاده می‌شود.</small>
                </div>

                <div class="col-md-6 mb-3">
                    <label>شماره فرستنده (خط اختصاصی)</label>
                    <input type="text" name="sms_sender" class="form-control" value="<?= htmlspecialchars($current['sms_sender'] ?? '') ?>">
                    <small class="text-muted">اختیاری – در صورت خالی بودن از خط عمومی 90008361 استفاده می‌شود.</small>
                </div>
                <div class="col-md-12 mb-3">
                    <div class="form-check">
                        <input type="checkbox" name="sms_status" class="form-check-input" id="sms_status" value="1" <?= $sms_status_checked ?>>
                        <label class="form-check-label" for="sms_status">فعال کردن سرویس پیامک</label>
                        <small class="text-muted d-block">با فعال‌سازی، امکان ارسال پیامک‌های دستی و خودکار فراهم می‌شود.</small>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات</button>
            <a href="../sms/manual_send.php" class="btn btn-success">📨 ارسال پیامک دلخواه</a>
            <a href="../sms/templates.php" class="btn btn-info">📝 مدیریت قالب‌ها</a>
            <a href="../sms/history.php" class="btn btn-secondary">📜 تاریخچه ارسال</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>