<?php
$page_title = 'تنظیمات عمومی و پیامک';
require_once '../../includes/header.php';

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
    $sms_api_url = trim($_POST['sms_api_url']);
    $sms_api_key = trim($_POST['sms_api_key']);
    $sms_sender = trim($_POST['sms_sender']);
    
    // به‌روزرسانی در جدول settings
    $settings = [
        'shop_name' => $shop_name,
        'shop_phone' => $shop_phone,
        'shop_address' => $shop_address,
        'admin_mobile' => $admin_mobile,
        'sms_api_url' => $sms_api_url,
        'sms_api_key' => $sms_api_key,
        'sms_sender' => $sms_sender
    ];
    
    foreach ($settings as $key => $value) {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, $value]);
    }
    $success = 'تنظیمات با موفقیت ذخیره شد.';
}

// دریافت مقادیر فعلی
$stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_group = 'general'");
$current = [];
while ($row = $stmt->fetch()) {
    $current[$row['setting_key']] = $row['setting_value'];
}
?>
<div class="card">
    <div class="card-header">تنظیمات عمومی و پیامک</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <form method="post">
            <h5>اطلاعات فروشگاه</h5>
            <div class="row">
                <div class="col-md-6 mb-3"><label>نام فروشگاه</label><input type="text" name="shop_name" class="form-control" value="<?= htmlspecialchars($current['shop_name'] ?? '') ?>"></div>
                <div class="col-md-6 mb-3"><label>شماره تماس</label><input type="text" name="shop_phone" class="form-control" value="<?= htmlspecialchars($current['shop_phone'] ?? '') ?>"></div>
                <div class="col-md-12 mb-3"><label>آدرس</label><textarea name="shop_address" class="form-control"><?= htmlspecialchars($current['shop_address'] ?? '') ?></textarea></div>
                <div class="col-md-6 mb-3"><label>شماره موبایل مدیر (برای هشدارها)</label><input type="text" name="admin_mobile" class="form-control" value="<?= htmlspecialchars($current['admin_mobile'] ?? '') ?>"></div>
            </div>
            <h5>تنظیمات درگاه پیامک</h5>
            <div class="row">
                <div class="col-md-6 mb-3"><label>آدرس API</label><input type="text" name="sms_api_url" class="form-control" placeholder="مثال: https://api.sms.ir/v1/send" value="<?= htmlspecialchars($current['sms_api_url'] ?? '') ?>"></div>
                <div class="col-md-6 mb-3"><label>کلید API (API Key)</label><input type="text" name="sms_api_key" class="form-control" value="<?= htmlspecialchars($current['sms_api_key'] ?? '') ?>"></div>
                <div class="col-md-6 mb-3"><label>شماره فرستنده (خط اختصاصی)</label><input type="text" name="sms_sender" class="form-control" value="<?= htmlspecialchars($current['sms_sender'] ?? '') ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary">💾 ذخیره تنظیمات</button>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>