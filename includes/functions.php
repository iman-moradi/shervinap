<?php
/**
 * توابع کمکی شامل:
 * - تبدیل تاریخ میلادی به شمسی (با استفاده از تابع ساده jdf)
 * - فرمت عدد به تومان
 * - بررسی دسترسی کاربر
 * - ارسال پیامک (ذخیره در لاگ به صورت نمونه)
 * - تولید CSS پویا از تنظیمات ظاهری
 */
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config/app.php';
}

require_once __DIR__ . '/jdf.php';

// بارگذاری کتابخانه تاریخ شمسی (jdf.php) - بعداً دانلود کنید
// برای سادگی، یک تابع جایگزین ساده می‌نویسیم (در صورت نیاز کتابخانه رسمی jdf را اضافه کنید)
// دریافت تاریخ جاری شمسی به فرمت YYYY/MM/DD
function now_jalali() {
    return jdate('Y/m/d');
}

// دریافت تاریخ و ساعت جاری شمسی
function now_jalali_datetime() {
    return jdate('Y/m/d H:i:s');
}

// فرمت عدد به تومان (مثال: 12500 => ۱۲,۵۰۰ تومان)
function to_toman($number) {
    if (!is_numeric($number)) return '۰ تومان';
    return number_format($number, 0, '.', ',') . ' تومان';
}

// بررسی دسترسی کاربر (با دریافت user_id و permission_key)
function has_permission($user_id, $permission_key) {
    global $db;
    $sql = "SELECT up.granted FROM user_permissions up
            JOIN permissions p ON p.id = up.permission_id
            WHERE up.user_id = :user_id AND p.permission_key = :perm_key";
    $stmt = $db->prepare($sql);
    $stmt->execute([':user_id' => $user_id, ':perm_key' => $permission_key]);
    $row = $stmt->fetch();
    return ($row && $row['granted'] == 1);
}


function get_unpaid_purchase_invoices_count($db) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM purchase_invoices WHERE payment_status IN ('unpaid', 'partial')");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}


// تابع ارسال پیامک (نمونه اولیه - بعداً با API واقعی جایگزین می‌شود)
function send_sms($mobile, $message) {
    global $db;
    // دریافت تنظیمات
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute(['sms_api_url']);
    $api_url = $stmt->fetchColumn();
    $stmt->execute(['sms_api_key']);
    $api_key = $stmt->fetchColumn();
    $stmt->execute(['sms_sender']);
    $sender = $stmt->fetchColumn();
    
    if (empty($api_url) || empty($api_key)) {
        // اگر API تنظیم نشده، فقط در لاگ ذخیره کن
        $log = $db->prepare("INSERT INTO sms_logs (mobile, message, status) VALUES (?, ?, 'disabled')");
        $log->execute([$mobile, $message]);
        return false;
    }
    
    // مثال برای درگاه SMS.ir (می‌توانید بر اساس پنل خود تغییر دهید)
    $data = [
        'to' => $mobile,
        'text' => $message,
        'sender' => $sender
    ];
    $headers = [
        'Content-Type: application/json',
        'X-API-Key: ' . $api_key
    ];
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $status = ($http_code == 200) ? 'success' : 'failed';
    $log = $db->prepare("INSERT INTO sms_logs (mobile, message, sent_at, status) VALUES (?, ?, NOW(), ?)");
    $log->execute([$mobile, $message, $status]);
    return ($status == 'success');
}

// تابع بارگذاری تنظیمات ظاهری و تولید فایل CSS
function load_appearance_settings() {
    global $db;
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_group = 'appearance'");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    // مقدار پیش‌فرض
    $font_family = $settings['font_family'] ?? 'Tahoma';
    $text_color = $settings['base_text_color'] ?? '#333';
    $bg_color = $settings['base_bg_color'] ?? '#f8f9fa';
    $font_size = $settings['font_size'] ?? '14px';
    
    // تولید محتوای CSS
    $css = "body { direction: rtl; font-family: '{$font_family}', Tahoma, sans-serif; background-color: {$bg_color}; color: {$text_color}; font-size: {$font_size}; }";
    $css .= " .navbar, .sidebar { background-color: {$bg_color}; }";
    
    // ذخیره در فایل assets/css/theme.css
   // جایگزین خط file_put_contents با این:
    $css_path = BASE_PATH . 'assets/css/theme.css';
    // اگر پوشه assets/css وجود ندارد، ایجاد کن
    if (!is_dir(dirname($css_path))) {
        mkdir(dirname($css_path), 0777, true);
    }
    file_put_contents($css_path, $css);
}


function check_and_send_reminders() {
    global $db;
    $today = date('Y/m/d'); // تاریخ شمسی جاری (باید از jdate استفاده کنید)
    
    // 1. یادآوری برای اولویت فوری که هنوز تحویل نشده و تاریخ deadline گذشته یا نزدیک است
    $stmt = $db->prepare("SELECT r.*, c.mobile, c.fullname FROM repair_tickets r 
                          JOIN customers c ON c.id = r.customer_id 
                          WHERE r.priority = 'urgent' AND r.status != 'delivered' 
                          AND r.urgent_deadline_sh IS NOT NULL AND r.reminder_sent = 0");
    $stmt->execute();
    $urgent_tickets = $stmt->fetchAll();
    foreach ($urgent_tickets as $t) {
        // اگر deadline رسیده یا فردا deadline است
        if ($t['urgent_deadline_sh'] <= $today) {
            $msg = "مشتری {$t['fullname']} - دستگاه {$t['device_type']} - مهلت فوری امروز یا گذشته است.";
            send_sms($t['mobile'], $msg);
            // همچنین می‌توان در جدول نوتیفیکیشن داخلی ذخیره کرد
            $upd = $db->prepare("UPDATE repair_tickets SET reminder_sent = 1 WHERE id = ?");
            $upd->execute([$t['id']]);
        }
    }
    
    // 2. یادآوری برای اولویت عادی: یک روز باقی مانده به پایان زمان تعمیر
    $stmt = $db->prepare("SELECT r.*, c.mobile, c.fullname, c.id as customer_id,
                          DATE_ADD(STR_TO_DATE(CONCAT(r.received_date_sh, ' 00:00:00'), '%Y/%m/%d %H:%i:%s'), INTERVAL r.normal_days DAY) as deadline_date
                          FROM repair_tickets r 
                          JOIN customers c ON c.id = r.customer_id 
                          WHERE r.priority = 'normal' AND r.status != 'delivered' 
                          AND r.normal_days IS NOT NULL AND r.reminder_sent = 0");
    $stmt->execute();
    $normal_tickets = $stmt->fetchAll();
    foreach ($normal_tickets as $t) {
        $deadline = $t['deadline_date']; // این تاریخ میلادی است
        $days_left = (strtotime($deadline) - time()) / (60*60*24);
        if ($days_left <= 1 && $days_left >= 0) {
            $msg = "مشتری {$t['fullname']} - دستگاه {$t['device_type']} - یک روز تا پایان زمان تعمیر باقی است.";
            send_sms($t['mobile'], $msg);
            $upd = $db->prepare("UPDATE repair_tickets SET reminder_sent = 1 WHERE id = ?");
            $upd->execute([$t['id']]);
        }
    }
    
    // 3. یادآوری برای وضعیت "انتظار قطعه" (هر روز یکبار)
    $stmt = $db->prepare("SELECT r.*, c.mobile, c.fullname FROM repair_tickets r 
                          JOIN customers c ON c.id = r.customer_id 
                          WHERE r.status = 'waiting_part' AND r.reminder_sent = 0");
    $stmt->execute();
    $waiting_tickets = $stmt->fetchAll();
    foreach ($waiting_tickets as $t) {
        $msg = "دستگاه {$t['device_type']} مشتری {$t['fullname']} در انتظار قطعه است. لطفاً پیگیری شود.";
        // ارسال به مدیر (شماره مدیر را از تنظیمات بگیرید)
        $admin_mobile = "09123456789"; // بعداً از جدول settings بخوانید
        send_sms($admin_mobile, $msg);
        $upd = $db->prepare("UPDATE repair_tickets SET reminder_sent = 1 WHERE id = ?");
        $upd->execute([$t['id']]);
        // هر روز مجدداً می‌توان یادآوری کرد، بنابراین بهتر است فیلد last_reminder_date اضافه شود. فعلاً ساده فرض شده.
    }
}


function display_persian_date($date_str) {
    if (empty($date_str)) return '-';
    // اگر تاریخ با 13 یا 14 شروع شود (سال شمسی) همان را برگردان
    if (preg_match('/^13\d{2}\//', $date_str) || preg_match('/^14\d{2}\//', $date_str)) {
        return $date_str;
    }
    // در غیر این صورت، فرض می‌کنیم تاریخ میلادی است و آن را به شمسی تبدیل می‌کنیم
    $timestamp = strtotime($date_str);
    if ($timestamp === false) return $date_str;
    return jdate('Y/m/d', $timestamp);
}



// فراخوانی تابع در ابتدای هر صفحه (به جز صفحات تنظیمات که ممکن دوباره بنویسند)
// اما بهتر است در header.php صدا زده شود.
?>