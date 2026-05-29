<?php
$page_title = 'داشبورد گزارشات';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reports_view')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// آمار روزانه، هفتگی، ماهانه
$today = jdate('Y/m/d');
$week_ago = jdate('Y/m/d', strtotime('-7 days'));
$month_ago = jdate('Y/m/d', strtotime('-30 days'));

// مجموع فروش امروز
$stmt = $db->prepare("SELECT SUM(total_amount) FROM sales_invoices WHERE invoice_date_sh = ?");
$stmt->execute([$today]);
$sales_today = (int)$stmt->fetchColumn();

// مجموع فروش هفته جاری
$stmt = $db->prepare("SELECT SUM(total_amount) FROM sales_invoices WHERE invoice_date_sh BETWEEN ? AND ?");
$stmt->execute([$week_ago, $today]);
$sales_week = (int)$stmt->fetchColumn();

// مجموع فروش ماه جاری
$stmt->execute([$month_ago, $today]);
$sales_month = (int)$stmt->fetchColumn();

// تعداد تعمیرات امروز
$stmt = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE received_date_sh = ?");
$stmt->execute([$today]);
$repairs_today = (int)$stmt->fetchColumn();

// تعداد تعمیرات آماده تحویل
$stmt = $db->query("SELECT COUNT(*) FROM repair_tickets WHERE status = 'ready'");
$ready_repairs = (int)$stmt->fetchColumn();

// مجموع هزینه تعمیرات امروز
$stmt = $db->prepare("SELECT SUM(total_cost) FROM repair_tickets WHERE received_date_sh = ?");
$stmt->execute([$today]);
$repair_cost_today = (int)$stmt->fetchColumn();

// موجودی کل انبار (ارزش تقریبی)
$stmt = $db->query("SELECT SUM(current_stock * purchase_price) FROM products");
$total_stock_value = (int)$stmt->fetchColumn();
?>
<div class="row">
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-primary">
            <div class="card-body">
                <h5 class="card-title">فروش امروز</h5>
                <p class="card-text h3"><?= number_format($sales_today) ?> تومان</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-success">
            <div class="card-body">
                <h5 class="card-title">فروش هفته</h5>
                <p class="card-text h3"><?= number_format($sales_week) ?> تومان</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-info">
            <div class="card-body">
                <h5 class="card-title">فروش ماه</h5>
                <p class="card-text h3"><?= number_format($sales_month) ?> تومان</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-warning">
            <div class="card-body">
                <h5 class="card-title">ارزش موجودی انبار</h5>
                <p class="card-text h3"><?= number_format($total_stock_value) ?> تومان</p>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-header">تعمیرات امروز</div>
            <div class="card-body">
                <h3><?= $repairs_today ?> دستگاه</h3>
                <p>هزینه تعمیرات امروز: <?= number_format($repair_cost_today) ?> تومان</p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-header">دستگاه‌های آماده تحویل</div>
            <div class="card-body">
                <h3><?= $ready_repairs ?> دستگاه</h3>
                <a href="../reception/index.php?status=ready" class="btn btn-sm btn-info">مشاهده</a>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-header">دسترسی سریع</div>
            <div class="card-body">
                <a href="sales.php" class="btn btn-sm btn-outline-primary">گزارش فروش</a>
                <a href="purchases.php" class="btn btn-sm btn-outline-secondary">گزارش خرید</a>
                <a href="repairs.php" class="btn btn-sm btn-outline-info">گزارش تعمیرات</a>
                <a href="financial.php" class="btn btn-sm btn-outline-success">سود و زیان</a>
                <a href="stock.php" class="btn btn-sm btn-outline-warning">موجودی انبار</a>
            </div>
        </div>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>