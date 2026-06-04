<?php
// modules/dashboard/statistics.php

/**
 * دریافت مجموع فروش در بازه زمانی مشخص
 */
function getTotalSales($pdo, $startDate, $endDate) {
    $sql = "SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE sale_date BETWEEN :start AND :end";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    return (float) $stmt->fetchColumn();
}

/**
 * دریافت تعداد مشتریان جدید در ماه جاری
 */
function getNewCustomersCount($pdo) {
    $firstDay = date('Y-m-01');
    $today = date('Y-m-d');
    $sql = "SELECT COUNT(*) FROM customers WHERE created_at BETWEEN :start AND :end";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $firstDay, ':end' => $today]);
    return (int) $stmt->fetchColumn();
}

/**
 * دریافت آمار پیشرفته برای داشبورد
 */
function getAdvancedStats($pdo) {
    // 1. میانگین درآمد روزانه ماه جاری
    $firstDay = date('Y-m-01');
    $today = date('Y-m-d');
    
    $sqlAvg = "SELECT AVG(daily_total) as avg_daily FROM (
        SELECT SUM(total_amount) as daily_total FROM sales 
        WHERE sale_date BETWEEN :firstDay AND :today
        UNION ALL
        SELECT SUM(cost) as daily_total FROM repairs 
        WHERE repair_date BETWEEN :firstDay AND :today
    ) AS combined";
    
    $stmt = $pdo->prepare($sqlAvg);
    $stmt->execute([':firstDay' => $firstDay, ':today' => $today]);
    $avgDaily = $stmt->fetchColumn();
    $avgDaily = $avgDaily !== false ? (float)$avgDaily : 0;

    // 2. درصد تغییر فروش نسبت به ماه قبل
    $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
    $lastMonthEnd   = date('Y-m-t', strtotime('-1 month'));
    $currentMonthSales = getTotalSales($pdo, $firstDay, $today);
    $lastMonthSales    = getTotalSales($pdo, $lastMonthStart, $lastMonthEnd);
    $percentChange = 0;
    if ($lastMonthSales > 0) {
        $percentChange = (($currentMonthSales - $lastMonthSales) / $lastMonthSales) * 100;
    }

    // 3. میانگین زمان تحویل تعمیرات (بر حسب روز)
    $sqlDelivery = "SELECT AVG(DATEDIFF(delivery_date, reception_date)) as avg_days 
                    FROM repairs 
                    WHERE status = 'delivered' AND delivery_date IS NOT NULL";
    $stmt = $pdo->prepare($sqlDelivery);
    $stmt->execute();
    $avgDeliveryDays = $stmt->fetchColumn();
    $avgDeliveryDays = $avgDeliveryDays !== false ? (float)$avgDeliveryDays : null;

    return [
        'avg_daily_income'     => round($avgDaily, 0),
        'sales_change_percent' => round($percentChange, 1),
        'avg_delivery_days'    => $avgDeliveryDays !== null ? round($avgDeliveryDays, 1) : null,
        'new_customers_month'  => getNewCustomersCount($pdo),
    ];
}
?>