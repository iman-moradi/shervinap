<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// اتصال به دیتابیس
require_once 'config/database.php';
require_once 'includes/SMSManager.php';

// بررسی تنظیمات
$stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_group = 'general'");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

echo "<pre>";
echo "=== تنظیمات پیامک ===\n";
echo "sms_status: " . ($settings['sms_status'] ?? '0') . "\n";
echo "sms_api_key: " . (empty($settings['sms_api_key']) ? 'خالی' : 'وجود دارد') . "\n";
echo "sms_sender: " . ($settings['sms_sender'] ?? '90008361') . "\n";
echo "sms_api_url: " . ($settings['sms_api_url'] ?? 'پیش‌فرض') . "\n";
echo "=====================\n\n";

// ایجاد شیء SMSManager
$sms = new SMSManager($db);
echo "isAvailable(): " . ($sms->isAvailable() ? 'true' : 'false') . "\n\n";

if (!$sms->isAvailable()) {
    echo "❌ سرویس پیامک فعال نیست. لطفاً تنظیمات را بررسی کنید.\n";
    exit;
}

// شماره تست (شماره واقعی خود را وارد کنید)
$testMobile = '09137962775'; // ← شماره خودتان
$testMessage = 'پیام تست خوش‌آمدگویی از اسکریپت جداگانه';

echo "ارسال به شماره: $testMobile\n";
echo "متن: $testMessage\n\n";

$result = $sms->send($testMobile, $testMessage);
echo "نتیجه:\n";
print_r($result);
echo "</pre>";
?>