<?php
$page_title = 'داشبورد مدیریت';
require_once 'includes/header.php';

if (!has_permission($_SESSION['user_id'], 'dashboard_view')) {
    echo '<div class="alert alert-danger">شما دسترسی به این بخش ندارید.</div>';
    require_once 'includes/footer.php';
    exit;
}

// ==================== دریافت داده‌های آماری ====================

// 1. فروش 7 روز اخیر (مقادیر شمسی)
$sales_data = [];
$sales_labels = [];
for ($i = 6; $i >= 0; $i--) {
    $date = jdate('Y/m/d', strtotime("-$i days"));
    $sales_labels[] = $date;
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE invoice_date_sh = ?");
    $stmt->execute([$date]);
    $sales_data[] = (int)$stmt->fetchColumn();
}

// 2. توزیع وضعیت تعمیرات
$status_counts = [];
$status_labels = ['در انتظار', 'در حال تعمیر', 'انتظار قطعه', 'آماده تحویل', 'تحویل شده'];
$status_keys = ['pending', 'in_progress', 'waiting_part', 'ready', 'delivered'];
foreach ($status_keys as $key) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE status = ?");
    $stmt->execute([$key]);
    $status_counts[] = (int)$stmt->fetchColumn();
}

// 3. محصولات پرفروش (بر اساس تعداد فروش)
$top_products = $db->query("
    SELECT p.name, SUM(si.quantity) as total_qty
    FROM sales_items si
    JOIN products p ON p.id = si.product_id
    GROUP BY si.product_id
    ORDER BY total_qty DESC
    LIMIT 5
")->fetchAll();

// 4. آمار کلی
$total_products = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE type = 'customer'");
$stmt->execute();
$total_customers = $stmt->fetchColumn();
$today = jdate('Y/m/d');
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE invoice_date_sh = ?");
$stmt->execute([$today]);
$today_sales = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COALESCE(SUM(total_cost), 0) FROM repair_tickets WHERE received_date_sh = ?");
$stmt->execute([$today]);
$today_repair = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE status = 'ready'");
$stmt->execute();
$ready_repairs = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE current_stock <= min_stock_alert");
$stmt->execute();
$low_stock = (int)$stmt->fetchColumn();
?>

<script src="<?= BASE_URL ?>assets/js/chart.umd.js"></script>

<div class="row">
    <!-- کارت‌های آماری -->
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-primary h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="fas fa-chart-line"></i> فروش امروز</h5>
                <p class="card-text display-6"><?= number_format($today_sales) ?> تومان</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-success h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="fas fa-tools"></i> تعمیرات امروز</h5>
                <p class="card-text display-6"><?= number_format($today_repair) ?> تومان</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-warning h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="fas fa-boxes"></i> آماده تحویل</h5>
                <p class="card-text display-6"><?= $ready_repairs ?> دستگاه</p>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card text-white bg-danger h-100">
            <div class="card-body">
                <h5 class="card-title"><i class="fas fa-exclamation-triangle"></i> موجودی بحرانی</h5>
                <p class="card-text display-6"><?= $low_stock ?> قلم کالا</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- نمودار فروش 7 روز اخیر -->
    <div class="col-md-8 mb-3">
        <div class="card h-100">
            <div class="card-header"><i class="fas fa-chart-line"></i> روند فروش (۷ روز اخیر)</div>
            <div class="card-body">
                <canvas id="salesChart" width="400" height="200"></canvas>
            </div>
        </div>
    </div>
    <!-- نمودار وضعیت تعمیرات -->
    <div class="col-md-4 mb-3">
        <div class="card h-100">
            <div class="card-header"><i class="fas fa-chart-pie"></i> وضعیت تعمیرات</div>
            <div class="card-body">
                <canvas id="statusChart" width="200" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- جدول محصولات پرفروش -->
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><i class="fas fa-trophy"></i> ۵ کالای پرفروش</div>
            <div class="card-body">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr><th>نام کالا</th><th>تعداد فروش</th></tr>
                    </thead>
                    <tbody>
                        <?php if (count($top_products) > 0): ?>
                            <?php foreach ($top_products as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['name']) ?></td>
                                    <td><?= $p['total_qty'] ?> عدد (قطعه) / دستگاه</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td><td colspan="2" class="text-center">هنوز فروشی ثبت نشده است</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- اطلاعات انبار و مشتری -->
    <div class="col-md-6 mb-3">
        <div class="card h-100">
            <div class="card-header"><i class="fas fa-info-circle"></i> آمار کلی</div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        تعداد کل کالاهای انبار
                        <span class="badge bg-primary rounded-pill"><?= $total_products ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        تعداد مشتریان
                        <span class="badge bg-success rounded-pill"><?= $total_customers ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        محصولات نیازمند سفارش
                        <span class="badge bg-warning rounded-pill"><?= $low_stock ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        تعمیرات آماده تحویل
                        <span class="badge bg-info rounded-pill"><?= $ready_repairs ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // نمودار فروش 7 روزه
    const ctx1 = document.getElementById('salesChart').getContext('2d');
    new Chart(ctx1, {
        type: 'line',
        data: {
            labels: <?= json_encode($sales_labels) ?>,
            datasets: [{
                label: 'فروش (تومان)',
                data: <?= json_encode($sales_data) ?>,
                borderColor: 'rgb(59, 130, 246)',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let val = context.raw;
                            return val.toLocaleString() + ' تومان';
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // نمودار دایره‌ای وضعیت تعمیرات
    const ctx2 = document.getElementById('statusChart').getContext('2d');
    new Chart(ctx2, {
        type: 'pie',
        data: {
            labels: <?= json_encode($status_labels) ?>,
            datasets: [{
                data: <?= json_encode($status_counts) ?>,
                backgroundColor: ['#ffc107', '#17a2b8', '#fd7e14', '#28a745', '#6c757d'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 12 } }
                }
            }
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>