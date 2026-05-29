<?php
// cron/send_reminders.php
require_once '../includes/db.php';
require_once '../includes/SMSManager.php';

// ایجاد ستون last_reminder_date در صورت نیاز
try {
    $db->exec("ALTER TABLE repair_tickets ADD COLUMN IF NOT EXISTS last_reminder_date DATETIME NULL");
    $db->exec("ALTER TABLE credit_sales ADD COLUMN IF NOT EXISTS last_reminder_date DATETIME NULL");
} catch (PDOException $e) {
    // ستون ممکن است از قبل موجود باشد
}

$sms = new SMSManager($db);
if (!$sms->isAvailable()) {
    file_put_contents(__DIR__ . '/cron_log.txt', date('Y-m-d H:i:s') . " - سرویس پیامک فعال نیست\n", FILE_APPEND);
    exit;
}

// تاریخ امروز (میلادی - در صورت نیاز شمسی را تبدیل کنید)
$today = date('Y-m-d');

// 1. یادآوری تعمیرات
$stmt = $db->prepare("SELECT rt.*, c.mobile, c.fullname 
    FROM repair_tickets rt 
    JOIN customers c ON rt.customer_id = c.id 
    WHERE rt.status = 'ready' 
    AND (rt.last_reminder_date IS NULL OR DATE(rt.last_reminder_date) < DATE_SUB(NOW(), INTERVAL 2 DAY))");
$stmt->execute();
while ($ticket = $stmt->fetch()) {
    $message = "خدمات فنی: دستگاه {$ticket['device_type']} شما آماده تحویل است.";
    $result = $sms->send($ticket['mobile'], $message);
    if ($result['success']) {
        $upd = $db->prepare("UPDATE repair_tickets SET last_reminder_date = NOW() WHERE id = ?");
        $upd->execute([$ticket['id']]);
        file_put_contents(__DIR__ . '/cron_log.txt', date('Y-m-d H:i:s') . " - یادآوری ارسال شد به {$ticket['mobile']}\n", FILE_APPEND);
    } else {
        file_put_contents(__DIR__ . '/cron_log.txt', date('Y-m-d H:i:s') . " - خطا برای {$ticket['mobile']}: {$result['error']}\n", FILE_APPEND);
    }
}

// 2. یادآوری فروش نسیه (۳ روز مانده)
$stmt = $db->prepare("SELECT cs.*, c.mobile, c.fullname 
    FROM credit_sales cs 
    JOIN customers c ON cs.customer_id = c.id 
    WHERE cs.status != 'paid' 
    AND cs.due_date = DATE_ADD(?, INTERVAL 3 DAY) 
    AND (cs.last_reminder_date IS NULL OR DATE(cs.last_reminder_date) < DATE_SUB(NOW(), INTERVAL 5 DAY))");
$stmt->execute([$today]);
while ($cs = $stmt->fetch()) {
    $message = "مشتری گرامی {$cs['fullname']}، مبلغ {$cs['total_amount']} تومان فروش نسیه شما تا تاریخ {$cs['due_date']} سررسید می‌شود.";
    $result = $sms->send($cs['mobile'], $message);
    if ($result['success']) {
        $upd = $db->prepare("UPDATE credit_sales SET last_reminder_date = NOW() WHERE id = ?");
        $upd->execute([$cs['id']]);
        file_put_contents(__DIR__ . '/cron_log.txt', date('Y-m-d H:i:s') . " - یادآوری اعتباری ارسال شد به {$cs['mobile']}\n", FILE_APPEND);
    }
}

echo "Cron job executed at " . date('Y-m-d H:i:s') . "\n";
?>