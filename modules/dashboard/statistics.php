<?php
// modules/dashboard/statistics.php
function getAdvancedStats($pdo) {
    // 1. میانگین درآمد روزانه ماه جاری
    $firstDay = date('Y-m-01');
    $today = date('Y-m-d');
    $sqlAvg = "SELECT AVG(daily_total) as avg_daily FROM (
        SELECT SUM(total_amount) as daily_total FROM sales 
        WHERE sale_date BETWEEN '$firstDay' AND '$today'
        UNION ALL
        SELECT SUM(cost) as daily_total FROM repairs 
        WHERE repair_date BETWEEN '$firstDay' AND '$today'
    ) as combined";
    $avgDaily = $pdo->query($sqlAvg)->fetchColumn();

    // 2. درصد تغییر فروش نسبت به ماه قبل
    $lastMonthStart = date('Y-m-01', strtotime('-1 month'));
    $lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
    $currentMonthSales = getTotalSales($pdo, $firstDay, $today);
    $lastMonthSales = getTotalSales($pdo, $lastMonthStart, $lastMonthEnd);
    $percentChange = $lastMonthSales ? (($currentMonthSales - $lastMonthSales) / $lastMonthSales) * 100 : 0;

    // 3. میانگین زمان تحویل تعمیرات (بر حسب روز)
    $sqlDelivery = "SELECT AVG(DATEDIFF(delivery_date, reception_date)) as avg_days 
                    FROM repairs WHERE status='delivered' AND delivery_date IS NOT NULL";
    $avgDeliveryDays = $pdo->query($sqlDelivery)->fetchColumn();

    return [
        'avg_daily_income' => round($avgDaily, 0),
        'sales_change_percent' => round($percentChange, 1),
        'avg_delivery_days' => round($avgDeliveryDays, 1),
        'new_customers_month' => getNewCustomersCount($pdo),
    ];
}