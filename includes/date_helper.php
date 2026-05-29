<?php
/**
 * توابع کمکی برای کار با تاریخ شمسی (هماهنگ با purchase_invoice.php)
 * نیاز به فایل jdf.php در همین مسیر دارد
 * توجه: تابع now_jalali قبلاً در functions.php تعریف شده است.
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
function jalali_to_timestamp($jalali_date) {
    if (empty($jalali_date)) return 0;
    $parts = explode('/', $jalali_date);
    if (count($parts) != 3) return 0;
    list($jy, $jm, $jd) = $parts;
    if (!is_numeric($jy) || !is_numeric($jm) || !is_numeric($jd)) return 0;
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return mktime(0, 0, 0, $gm, $gd, $gy);
}

// محاسبه اختلاف روز بین دو تاریخ شمسی (دومی - اولی)
function jalali_diff_days($date1, $date2) {
    $ts1 = jalali_to_timestamp($date1);
    $ts2 = jalali_to_timestamp($date2);
    if ($ts1 == 0 || $ts2 == 0) return 0;
    return floor(($ts2 - $ts1) / (60 * 60 * 24));
}