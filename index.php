<?php
// ==================== بارگذاری توابع شمسی ====================
if (!function_exists('jdate')) {
    require_once __DIR__ . '/includes/jdf.php';
}

/**
 * تبدیل تاریخ شمسی به فرمت یکسان (اعداد فارسی) برای تطابق با دیتابیس
 * - اگر timestamp ارسال نشود، زمان جاری (time()) جایگزین می‌شود تا از خطای jdate جلوگیری شود.
 */
function normalize_date($format = 'Y/m/d', $timestamp = null) {
    if ($timestamp === null || $timestamp === '') {
        $timestamp = time();
    }
    return jdate($format, $timestamp);
}

$page_title = 'داشبورد مدیریت';
require_once 'includes/header.php';

// بررسی دسترسی
if (!has_permission($_SESSION['user_id'], 'dashboard_view')) {
    echo '<div class="alert alert-danger">شما دسترسی به این بخش ندارید.</div>';
    require_once 'includes/footer.php';
    exit;
}

// ==================== تبدیل تاریخ شمسی امروز به بازه میلادی (برای created_at) ====================
$today_sh = normalize_date('Y/m/d');          // مثال: ۱۴۰۵/۰۳/۱۴
list($year, $month, $day) = explode('/', $today_sh);

// تولید تایم‌استمپ ابتدا و انتهای روز جاری میلادی
$start_timestamp = jmktime(0, 0, 0, $month, $day, $year);
$end_timestamp   = jmktime(23, 59, 59, $month, $day, $year);
$start_date_mysql = date('Y-m-d H:i:s', $start_timestamp);
$end_date_mysql   = date('Y-m-d H:i:s', $end_timestamp);

// ==================== 1. فروش 7 روز اخیر ====================
$sales_data = [];
$sales_labels = [];
for ($i = 6; $i >= 0; $i--) {
    $date = normalize_date('Y/m/d', strtotime("-$i days"));
    $sales_labels[] = $date;
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE invoice_date_sh = ?");
    $stmt->execute([$date]);
    $sales_data[] = (int)$stmt->fetchColumn();
}

// ==================== 2. توزیع وضعیت تعمیرات ====================
$status_counts = [];
$status_labels = ['در انتظار', 'در حال تعمیر', 'انتظار قطعه', 'آماده تحویل', 'تحویل شده'];
$status_keys = ['pending', 'in_progress', 'waiting_part', 'ready', 'delivered'];
foreach ($status_keys as $key) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE status = ?");
    $stmt->execute([$key]);
    $status_counts[] = (int)$stmt->fetchColumn();
}

// ==================== 3. محصولات پرفروش (همه‌ی زمان) ====================
$top_products = $db->query("
    SELECT p.name, SUM(si.quantity) as total_qty
    FROM sales_items si
    JOIN products p ON p.id = si.product_id
    GROUP BY si.product_id
    ORDER BY total_qty DESC
    LIMIT 5
")->fetchAll();

// ==================== 4. آمار کلی (بدون وابستگی به تاریخ) ====================
$total_products = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE type = 'customer'");
$stmt->execute();
$total_customers = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE current_stock <= min_stock_alert");
$stmt->execute();
$low_stock = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE status = 'ready'");
$stmt->execute();
$ready_repairs = (int)$stmt->fetchColumn();

// ==================== 5. فروش امروز (بر اساس invoice_date_sh) ====================
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE invoice_date_sh = ?");
$stmt->execute([$today_sh]);
$today_sales = (int)$stmt->fetchColumn();

// ==================== 6. تعمیرات امروز (بر اساس received_date_sh) ====================
$stmt = $db->prepare("SELECT COALESCE(SUM(total_cost), 0) FROM repair_tickets WHERE received_date_sh = ?");
$stmt->execute([$today_sh]);
$today_repair = (int)$stmt->fetchColumn();

// ==================== 7. اجرت روز (بر اساس تاریخ ثبت آیتم در repair_items، نه تاریخ دریافت دستگاه) ====================
$stmt_labor = $db->prepare("
    SELECT COALESCE(SUM(ri.total_price), 0)
    FROM repair_items ri
    WHERE ri.item_type = 'labor' 
    AND ri.created_at BETWEEN :start_date AND :end_date
");
$stmt_labor->execute([
    ':start_date' => $start_date_mysql,
    ':end_date'   => $end_date_mysql
]);
$daily_labor = (int)$stmt_labor->fetchColumn();

// ==================== 8. سود فروش روز (بر اساس invoice_date_sh) ====================
$stmt_profit = $db->prepare("
    SELECT COALESCE(SUM(si.quantity * (si.unit_price - p.purchase_price)), 0)
    FROM sales_items si
    JOIN products p ON p.id = si.product_id
    JOIN sales_invoices si_inv ON si_inv.id = si.sales_invoice_id
    WHERE si_inv.invoice_date_sh = ?
");
$stmt_profit->execute([$today_sh]);
$daily_profit = (int)$stmt_profit->fetchColumn();

// ==================== پیام تبریک / هشدار برای اجرت روز ====================
$labor_message = '';
$labor_alert_class = '';
if ($daily_labor >= 3000000) {
    $labor_message = '🎉 تبریک! شما به حداقل سقف روزانه (3 میلیون تومان) رسیدید.';
    $labor_alert_class = 'alert-success';
} else {
    $labor_message = '⚠️ توجه: شما باید بیشتر تلاش کنید! هنوز به حداقل سقف روزانه 3 میلیون تومان نرسیده‌اید.';
    $labor_alert_class = 'alert-danger';
}
?>

<style>
    /* استایل‌های گرادیان مدرن برای کارت‌های آماری */
    .bg-gradient-primary {
        background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%) !important;
    }
    .bg-gradient-success {
        background: linear-gradient(135deg, #10b981 0%, #22c55e 100%) !important;
    }
    .bg-gradient-warning {
        background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%) !important;
    }
    .bg-gradient-danger {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important;
    }
    .bg-white-20 {
        background-color: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        width: 55px;
        height: 55px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
    }
    .modern-card:hover .bg-white-20 {
        background-color: rgba(255, 255, 255, 0.3);
        transform: scale(1.05);
    }
    .modern-card.bg-gradient-primary,
    .modern-card.bg-gradient-success,
    .modern-card.bg-gradient-warning,
    .modern-card.bg-gradient-danger {
        backdrop-filter: none;
        color: #fff !important;
    }
    .modern-card .text-white-50 {
        color: rgba(255, 255, 255, 0.7) !important;
    }
    .progress {
        background-color: rgba(0, 0, 0, 0.1);
    }
</style>

<script src="<?= BASE_URL ?>assets/js/chart.umd.js"></script>

<!-- هشدار اجرت روز -->
<div class="alert <?= $labor_alert_class ?> alert-glass mb-4 text-center" role="alert" style="font-size: 1.1rem; font-weight: bold;">
    <i class="fas fa-chart-line"></i> اجرت امروز شما: <strong><?= number_format($daily_labor) ?> تومان</strong> | <?= $labor_message ?>
</div>

<div class="row">
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="modern-card bg-gradient-primary text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase fw-semibold text-white-50">فروش امروز</h6>
                        <h3 class="display-6 fw-bold mb-0"><?= number_format($today_sales) ?> <small class="fs-6">تومان</small></h3>
                    </div>
                    <div class="bg-white-20">
                        <i class="fas fa-chart-line fa-2x text-white"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="modern-card bg-gradient-success text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase fw-semibold text-white-50">تعمیرات امروز</h6>
                        <h3 class="display-6 fw-bold mb-0"><?= number_format($today_repair) ?> <small class="fs-6">تومان</small></h3>
                    </div>
                    <div class="bg-white-20">
                        <i class="fas fa-tools fa-2x text-white"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="modern-card bg-gradient-warning text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase fw-semibold text-white-50">آماده تحویل</h6>
                        <h3 class="display-6 fw-bold mb-0"><?= $ready_repairs ?> <small class="fs-6">دستگاه</small></h3>
                    </div>
                    <div class="bg-white-20">
                        <i class="fas fa-boxes fa-2x text-white"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="modern-card bg-gradient-danger text-white h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase fw-semibold text-white-50">موجودی بحرانی</h6>
                        <h3 class="display-6 fw-bold mb-0"><?= $low_stock ?> <small class="fs-6">قلم کالا</small></h3>
                    </div>
                    <div class="bg-white-20">
                        <i class="fas fa-exclamation-triangle fa-2x text-white"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="modern-card h-100">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="fas fa-hand-holding-usd text-primary"></i> اجرت روز</span>
                <span class="badge bg-primary rounded-pill">امروز</span>
            </div>
            <div class="card-body text-center">
                <h2 class="display-5 fw-bold text-primary"><?= number_format($daily_labor) ?> <small class="fs-4">تومان</small></h2>
                <p class="text-muted mt-3">جمع کل اجرت تعمیراتی که امروز در سیستم ثبت شده است (مستقل از تاریخ تحویل دستگاه)</p>
                <div class="progress mt-3" style="height: 8px;">
                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= min(100, ($daily_labor / 3000000) * 100) ?>%" aria-valuenow="<?= $daily_labor ?>" aria-valuemin="0" aria-valuemax="3000000"></div>
                </div>
                <p class="mt-2 small">هدف روزانه: 3,000,000 تومان</p>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-4">
        <div class="modern-card h-100">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="fas fa-chart-line text-success"></i> سود فروش روز</span>
                <span class="badge bg-success rounded-pill">برآوردی</span>
            </div>
            <div class="card-body text-center">
                <h2 class="display-5 fw-bold text-success"><?= number_format($daily_profit) ?> <small class="fs-4">تومان</small></h2>
                <p class="text-muted mt-3">سود حاصل از فروش کالاهای امروز (قیمت فروش - قیمت خرید)</p>
                <i class="fas fa-arrow-up text-success mt-2" style="font-size: 2rem;"></i>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8 mb-4">
        <div class="modern-card h-100">
            <div class="card-header-custom"><i class="fas fa-chart-line"></i> روند فروش (۷ روز اخیر)</div>
            <div class="card-body">
                <canvas id="salesChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="modern-card h-100">
            <div class="card-header-custom"><i class="fas fa-chart-pie"></i> وضعیت تعمیرات</div>
            <div class="card-body">
                <canvas id="statusChart" width="200" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="modern-card h-100">
            <div class="card-header-custom"><i class="fas fa-trophy"></i> ۵ کالای پرفروش</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr><th>نام کالا</th><th>تعداد فروش</th></tr>
                        </thead>
                        <tbody>
                            <?php if (count($top_products) > 0): ?>
                                <?php foreach ($top_products as $p): ?>
                                    <tr>
                                        <td><i class="fas fa-box text-secondary"></i> <?= htmlspecialchars($p['name']) ?></td>
                                        <td><span class="badge bg-primary rounded-pill"><?= (int)$p['total_qty'] ?></span> عدد (قطعه) / دستگاه</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" class="text-center text-muted">هنوز فروشی ثبت نشده است</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="modern-card h-100">
            <div class="card-header-custom"><i class="fas fa-info-circle"></i> آمار کلی</div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-boxes text-primary"></i> تعداد کل کالاهای انبار</span>
                        <span class="badge bg-primary rounded-pill fs-6"><?= $total_products ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-users text-success"></i> تعداد مشتریان</span>
                        <span class="badge bg-success rounded-pill fs-6"><?= $total_customers ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-exclamation-triangle text-warning"></i> محصولات نیازمند سفارش</span>
                        <span class="badge bg-warning rounded-pill fs-6"><?= $low_stock ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-check-circle text-info"></i> تعمیرات آماده تحویل</span>
                        <span class="badge bg-info rounded-pill fs-6"><?= $ready_repairs ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // نمودار فروش 7 روزه
    var ctx1 = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx1, {
        type: 'line',
        data: {
            labels: <?= json_encode($sales_labels) ?>,
            datasets: [{
                label: 'فروش (تومان)',
                data: <?= json_encode($sales_data) ?>,
                borderColor: '#0ea5e9',
                backgroundColor: 'rgba(14, 165, 233, 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#0ea5e9',
                pointBorderColor: '#fff',
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.raw.toLocaleString() + ' تومان';
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: { callback: function(val) { return val.toLocaleString(); } },
                    title: { display: true, text: 'مبلغ (تومان)' }
                }
            }
        }
    });

    // نمودار دایره‌ای وضعیت تعمیرات
    var ctx2 = document.getElementById('statusChart').getContext('2d');
    new Chart(ctx2, {
        type: 'pie',
        data: {
            labels: <?= json_encode($status_labels) ?>,
            datasets: [{
                data: <?= json_encode($status_counts) ?>,
                backgroundColor: ['#ffc107', '#17a2b8', '#fd7e14', '#28a745', '#6c757d'],
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 12, family: 'Vazirmatn' } } }
            }
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>