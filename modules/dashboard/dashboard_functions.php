<?php
/**
 * توابع اختصاصی داشبورد
 */

function get_sales_stats($db, $period = 'today') {
    $today_sh = jdate('Y/m/d');
    switch($period) {
        case 'today':
            $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE invoice_date_sh = ?");
            $stmt->execute([$today_sh]);
            return (int)$stmt->fetchColumn();
        case 'week':
            // محاسبه هفته جاری
            $start_week = jdate('Y/m/d', strtotime('-6 days'));
            $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE invoice_date_sh BETWEEN ? AND ?");
            $stmt->execute([$start_week, $today_sh]);
            return (int)$stmt->fetchColumn();
        case 'month':
            $first_day = jdate('Y/m/01');
            $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE invoice_date_sh BETWEEN ? AND ?");
            $stmt->execute([$first_day, $today_sh]);
            return (int)$stmt->fetchColumn();
    }
}

function get_repair_stats($db, $period = 'today') {
    // مشابه تابع بالا
}

function get_daily_labor_by_created_date($db, $date_sh) {
    // محاسبه دقیق بر اساس created_at
    $timestamp = jmktime(0, 0, 0, ...);
    // ...
}