<?php
// modules/dashboard/statistics.php

/**
 * این فایل به عنوان واسط (wrapper) برای توابع موجود در functions.php عمل می‌کند
 * و با ساختار واقعی دیتابیس (sales_invoices, repair_tickets) هماهنگ شده است.
 */

require_once __DIR__ . '/../../includes/functions.php';

/**
 * دریافت مجموع فروش در بازه زمانی مشخص (بر اساس invoice_date_sh)
 * @param PDO $pdo
 * @param string $startDate تاریخ شروع به فرمت شمسی (Y/m/d)
 * @param string $endDate تاریخ پایان به فرمت شمسی (Y/m/d)
 * @return float
 */
function getTotalSales($pdo, $startDate, $endDate) {
    $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE invoice_date_sh BETWEEN :start AND :end";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    return (float) $stmt->fetchColumn();
}

/**
 * دریافت تعداد مشتریان جدید در ماه جاری (بر اساس created_at در جدول customers)
 * @param PDO $pdo
 * @return int
 */
function getNewCustomersCount($pdo) {
    $firstDaySh = jdate('Y/m/01');
    $todaySh = now_jalali();
    
    // تبدیل تاریخ شمسی به میلادی برای مقایسه با created_at
    $startTimestamp = jalali_to_gregorian_timestamp($firstDaySh);
    $endTimestamp = jalali_to_gregorian_timestamp($todaySh) + 86399;
    $startDate = date('Y-m-d H:i:s', $startTimestamp);
    $endDate = date('Y-m-d H:i:s', $endTimestamp);
    
    $sql = "SELECT COUNT(*) FROM customers WHERE created_at BETWEEN :start AND :end";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    return (int) $stmt->fetchColumn();
}

/**
 * دریافت آمار پیشرفته برای داشبورد
 * @param PDO $pdo
 * @return array
 */
function getAdvancedStats($pdo) {
    $todaySh = now_jalali();
    $firstDayOfMonthSh = jdate('Y/m/01');
    $lastMonthStartSh = jdate('Y/m/01', strtotime('-1 month'));
    $lastMonthEndSh = jdate('Y/m/t', strtotime('-1 month'));
    
    // 1. میانگین درآمد روزانه ماه جاری (فروش + اجرت تعمیرات)
    $currentMonthSales = getTotalSales($pdo, $firstDayOfMonthSh, $todaySh);
    $currentMonthLabor = get_daily_labor_cost($pdo, $firstDayOfMonthSh, $todaySh); // توجه: تابع اصلاح شده
    $daysInMonth = (int)jdate('t');
    $avgDailyIncome = ($daysInMonth > 0) ? round(($currentMonthSales + $currentMonthLabor) / $daysInMonth) : 0;
    
    // 2. درصد تغییر فروش نسبت به ماه قبل
    $currentMonthSalesTotal = $currentMonthSales;
    $lastMonthSalesTotal = getTotalSales($pdo, $lastMonthStartSh, $lastMonthEndSh);
    $salesChangePercent = 0;
    if ($lastMonthSalesTotal > 0) {
        $salesChangePercent = round((($currentMonthSalesTotal - $lastMonthSalesTotal) / $lastMonthSalesTotal) * 100, 1);
    }
    
    // 3. میانگین زمان تحویل تعمیرات (بر حسب روز) با استفاده از تاریخ‌های شمسی
    $sqlDelivery = "SELECT AVG(
                        (julianday(STR_TO_DATE(REPLACE(delivered_date_sh, '/', '-'), '%Y-%m-%d')) -
                         julianday(STR_TO_DATE(REPLACE(received_date_sh, '/', '-'), '%Y-%m-%d')))
                    ) as avg_days
                    FROM repair_tickets 
                    WHERE status = 'delivered' AND delivered_date_sh IS NOT NULL AND received_date_sh IS NOT NULL";
    $stmt = $pdo->query($sqlDelivery);
    $avgDeliveryDays = $stmt->fetchColumn();
    $avgDeliveryDays = $avgDeliveryDays ? round((float)$avgDeliveryDays, 1) : null;
    
    // 4. تعداد مشتریان جدید در ماه جاری
    $newCustomers = getNewCustomersCount($pdo);
    
    return [
        'avg_daily_income'     => $avgDailyIncome,
        'sales_change_percent' => $salesChangePercent,
        'avg_delivery_days'    => $avgDeliveryDays,
        'new_customers_month'  => $newCustomers,
    ];
}
?>