<?php
/**
 * توابع کمکی برای کار با تاریخ شمسی (هماهنگ با purchase_invoice.php)
 * نیاز به فایل jdf.php در همین مسیر دارد
 */
require_once __DIR__ . '/jdf.php';

// تابع now_jalali قبلاً در functions.php وجود دارد، بنابراین تعریف مجدد نمی‌کنیم.

// اعتبارسنجی فرمت تاریخ شمسی (Y/m/d)
function is_valid_jalali_date($date) {
    if (!preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $date)) return false;
    $parts = explode('/', $date);
    $year = (int)$parts[0];
    $month = (int)$parts[1];
    $day = (int)$parts[2];
    if ($year < 1300 || $year > 1500) return false;
    if ($month < 1 || $month > 12) return false;
    $maxDays = ($month <= 6) ? 31 : (($month < 12) ? 30 : 29);
    return ($day >= 1 && $day <= $maxDays);
}

// تبدیل تاریخ شمسی به تایم‌استمپ یونیکس (برای محاسبه اختلاف)
// تبدیل تاریخ شمسی به تایم‌استمپ یونیکس (برای محاسبه اختلاف)
function jalali_to_timestamp($jalali_date) {
    if (empty($jalali_date)) return 0;
    
    // تبدیل اعداد فارسی به انگلیسی
    $persian_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $jalali_date = str_replace($persian_numbers, $english_numbers, $jalali_date);
    
    // حذف کاراکترهای اضافی
    $jalali_date = preg_replace('/[^0-9\/]/', '', $jalali_date);
    
    $parts = explode('/', $jalali_date);
    if (count($parts) != 3) return 0;
    
    list($jy, $jm, $jd) = $parts;
    if (!is_numeric($jy) || !is_numeric($jm) || !is_numeric($jd)) return 0;
    
    // اعتبارسنجی سال (باید بین 1300 تا 1500 باشد)
    if ($jy < 1300 || $jy > 1500) return 0;
    
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return mktime(0, 0, 0, $gm, $gd, $gy);
}

// تبدیل تایم‌استمپ یونیکس به تاریخ شمسی
function timestamp_to_jalali($timestamp) {
    if ($timestamp <= 0) return '';
    $gregorian = date('Y-m-d', $timestamp);
    list($gy, $gm, $gd) = explode('-', $gregorian);
    list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
}

// محاسبه اختلاف روز بین دو تاریخ شمسی (دومی - اولی)
function jalali_diff_days($date1, $date2) {
    $ts1 = jalali_to_timestamp($date1);
    $ts2 = jalali_to_timestamp($date2);
    if ($ts1 == 0 || $ts2 == 0) return 0;
    return floor(($ts2 - $ts1) / (60 * 60 * 24));
}

// افزودن روز به تاریخ شمسی و برگرداندن تاریخ شمسی جدید
// افزودن روز به تاریخ شمسی و برگرداندن تاریخ شمسی جدید
function jalali_add_days($jalali_date, $days) {
    $timestamp = jalali_to_timestamp($jalali_date);
    if ($timestamp == 0) return '';
    $new_timestamp = $timestamp + ($days * 86400);
    return timestamp_to_jalali($new_timestamp);
}

// دریافت تاریخ شمسی امروز
function today_jalali() {
    return jdate('Y/m/d');
}
?>