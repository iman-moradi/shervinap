<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
session_start();
require_once 'includes/jdf.php';
$today = jdate('Y/m/d');

// فقط Ajax یا اجرای مجاز
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    if (!isset($_SESSION['user_id']) || !has_permission($_SESSION['user_id'], 'reports_view')) {
        die('دسترسی غیرمجاز');
    }
}

$today = date('Y/m/d'); // بعداً با jdate جایگزین شود
$now = time();

// 1. تیکت‌های فوری
$stmt = $db->prepare("SELECT r.*, c.mobile, c.fullname FROM repair_tickets r 
                      JOIN customers c ON c.id = r.customer_id 
                      WHERE r.priority = 'urgent' AND r.status != 'delivered' 
                      AND r.urgent_deadline_sh IS NOT NULL 
                      AND r.urgent_deadline_sh <= ? 
                      AND (r.last_reminder_date IS NULL OR r.last_reminder_date < DATE_SUB(NOW(), INTERVAL 1 DAY))");
$stmt->execute([$today]);
$urgent = $stmt->fetchAll();
foreach ($urgent as $t) {
    $msg = "⚠️ یادآوری فوری: دستگاه {$t['device_type']} مشتری {$t['fullname']} باید تحویل شود. تاریخ توافق: {$t['urgent_deadline_sh']}";
    send_sms($t['mobile'], $msg);
    $upd = $db->prepare("UPDATE repair_tickets SET last_reminder_date = NOW() WHERE id = ?");
    $upd->execute([$t['id']]);
}

// 2. تیکت‌های عادی یک روز مانده
$stmt = $db->prepare("SELECT r.*, c.mobile, c.fullname,
                      DATE_ADD(STR_TO_DATE(CONCAT(r.received_date_sh, ' 00:00:00'), '%Y/%m/%d %H:%i:%s'), INTERVAL r.normal_days DAY) as deadline_date
                      FROM repair_tickets r 
                      JOIN customers c ON c.id = r.customer_id 
                      WHERE r.priority = 'normal' AND r.status != 'delivered' 
                      AND r.normal_days IS NOT NULL
                      AND (r.last_reminder_date IS NULL OR r.last_reminder_date < DATE_SUB(NOW(), INTERVAL 1 DAY))");
$stmt->execute();
$normal = $stmt->fetchAll();
foreach ($normal as $t) {
    $deadline = strtotime($t['deadline_date']);
    $days_left = ($deadline - $now) / (60*60*24);
    if ($days_left <= 1 && $days_left >= 0) {
        $msg = "🔔 یادآوری: دستگاه {$t['device_type']} مشتری {$t['fullname']} یک روز تا پایان زمان تعمیر باقی است.";
        send_sms($t['mobile'], $msg);
        $upd = $db->prepare("UPDATE repair_tickets SET last_reminder_date = NOW() WHERE id = ?");
        $upd->execute([$t['id']]);
    }
}

// 3. تیکت‌های در انتظار قطعه
$stmt = $db->prepare("SELECT r.*, c.fullname FROM repair_tickets r 
                      JOIN customers c ON c.id = r.customer_id 
                      WHERE r.status = 'waiting_part' 
                      AND (r.last_reminder_date IS NULL OR r.last_reminder_date < DATE_SUB(NOW(), INTERVAL 1 DAY))");
$stmt->execute();
$waiting = $stmt->fetchAll();
if (count($waiting) > 0) {
    $admin_mobile = "09123456789"; // از تنظیمات بعداً بخوانید
    $msg = "⚠️ تعداد " . count($waiting) . " دستگاه در انتظار قطعه هستند. لطفاً پیگیری شود.";
    send_sms($admin_mobile, $msg);
    foreach ($waiting as $t) {
        $upd = $db->prepare("UPDATE repair_tickets SET last_reminder_date = NOW() WHERE id = ?");
        $upd->execute([$t['id']]);
    }
}

echo json_encode(['status' => 'ok', 'urgent_count' => count($urgent), 'normal_count' => count($normal), 'waiting_count' => count($waiting)]);