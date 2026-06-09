<?php
ob_start(); // بافر خروجی برای جلوگیری از هر گونه خروجی ناخواسته
$page_title = 'لیست فاکتورهای فروش';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$filter = $_GET['filter'] ?? 'all';

// ساخت کوئری با JOIN برای دریافت نام مشتری
$sql = "SELECT s.*, c.fullname as customer_name 
        FROM sales_invoices s
        LEFT JOIN customers c ON c.id = s.customer_id
        ORDER BY s.invoice_date_sh DESC";
$where = "";

if ($filter == 'unpaid') {
    $where = "HAVING remaining > 0 AND paid_amount = 0";
} elseif ($filter == 'partial') {
    $where = "HAVING paid_amount > 0 AND remaining > 0";
} elseif ($filter == 'paid') {
    $where = "HAVING remaining <= 0";
}

// استفاده از subquery برای فیلتر کردن بهتر
$sql = "SELECT *, (total_amount - paid_amount) as remaining 
        FROM (
            SELECT s.*, c.fullname as customer_name 
            FROM sales_invoices s
            LEFT JOIN customers c ON c.id = s.customer_id
        ) AS t
        ORDER BY invoice_date_sh DESC";
        
// اجرای کوئری ساده و فیلتر کردن در PHP برای جلوگیری از خطای HAVING
$stmt = $db->query("SELECT s.*, c.fullname as customer_name,
                    (s.total_amount - s.paid_amount) as remaining
                    FROM sales_invoices s
                    LEFT JOIN customers c ON c.id = s.customer_id
                    ORDER BY s.invoice_date_sh DESC");
$all_invoices = $stmt->fetchAll();

// اعمال فیلتر در PHP
$invoices = [];
foreach ($all_invoices as $inv) {
    if ($filter == 'unpaid' && $inv['remaining'] > 0 && $inv['paid_amount'] == 0) {
        $invoices[] = $inv;
    } elseif ($filter == 'partial' && $inv['paid_amount'] > 0 && $inv['remaining'] > 0) {
        $invoices[] = $inv;
    } elseif ($filter == 'paid' && $inv['remaining'] <= 0) {
        $invoices[] = $inv;
    } elseif ($filter == 'all') {
        $invoices[] = $inv;
    }
}

// محاسبه آمار برای کارت‌های بالا
$total_sales = 0;
$total_paid = 0;
$total_unpaid = 0;
foreach ($all_invoices as $inv) {
    $total_sales += $inv['total_amount'];
    $total_paid += $inv['paid_amount'];
}
$total_unpaid = $total_sales - $total_paid;
$unpaid_count = 0;
foreach ($all_invoices as $inv) {
    if ($inv['remaining'] > 0) $unpaid_count++;
}
?>

<style>
    .stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 15px;
        padding: 20px;
        color: white;
        text-align: center;
        transition: transform 0.3s;
    }
    .stat-card:hover { transform: translateY(-5px); }
    .stat-card h3 { font-size: 28px; margin: 0; font-weight: bold; }
    .stat-card p { margin: 10px 0 0; opacity: 0.9; }
    .stat-card.income { background: linear-gradient(135deg, #11998e, #38ef7d); }
    .stat-card.paid { background: linear-gradient(135deg, #4facfe, #00f2fe); }
    .stat-card.unpaid { background: linear-gradient(135deg, #f093fb, #f5576c); }
    .filter-tabs { margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; }
    .filter-tabs .nav-link { border: none; padding: 12px 24px; font-weight: 500; color: #4a5568; transition: all 0.3s; }
    .filter-tabs .nav-link:hover { background: #f7fafc; border-radius: 10px 10px 0 0; }
    .filter-tabs .nav-link.active { color: #2c7da0; border-bottom: 3px solid #2c7da0; background: transparent; }
    .badge-paid { background-color: #28a745; }
    .badge-partial { background-color: #ffc107; color: #333; }
    .badge-unpaid { background-color: #dc3545; }
    .table-invoice th { background-color: #2d3748; color: white; text-align: center; vertical-align: middle; }
    .table-invoice td { vertical-align: middle; text-align: center; }
    .btn-group-sm .btn { margin: 0 2px; }
</style>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-0 py-3">
        <h4 class="mb-0 text-primary"><i class="fas fa-file-invoice-dollar"></i> مدیریت فاکتورهای فروش</h4>
    </div>
    <div class="card-body">
        <!-- کارت‌های آماری -->
        <div class="row mb-4 g-3">
            <div class="col-md-4">
                <div class="stat-card income">
                    <h3><?= number_format($total_sales) ?></h3>
                    <p>💰 کل فروش (تومان)</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card paid">
                    <h3><?= number_format($total_paid) ?></h3>
                    <p>✅ مبلغ پرداختی مشتریان</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card unpaid">
                    <h3><?= number_format($total_unpaid) ?></h3>
                    <p>📌 مانده مطالبات (<?= $unpaid_count ?> فاکتور)</p>
                </div>
            </div>
        </div>

        <!-- تب‌های فیلتر -->
        <ul class="nav filter-tabs">
            <li class="nav-item">
                <a class="nav-link <?= $filter == 'all' ? 'active' : '' ?>" href="?filter=all"><i class="fas fa-list"></i> همه</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $filter == 'unpaid' ? 'active' : '' ?>" href="?filter=unpaid"><i class="fas fa-times-circle text-danger"></i> پرداخت نشده</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $filter == 'partial' ? 'active' : '' ?>" href="?filter=partial"><i class="fas fa-hourglass-half text-warning"></i> بدهی جزیی</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $filter == 'paid' ? 'active' : '' ?>" href="?filter=paid"><i class="fas fa-check-circle text-success"></i> تسویه شده</a>
            </li>
        </ul>

        <!-- جدول فاکتورها -->
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-invoice">
                <thead>
                    <tr>
                        <th>شماره فاکتور</th>
                        <th>مشتری</th>
                        <th>تاریخ</th>
                        <th>مبلغ کل</th>
                        <th>پرداخت شده</th>
                        <th>مانده</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($invoices) == 0): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">هیچ فاکتوری یافت نشد</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): 
                            $remaining = $inv['remaining'];
                            if ($remaining <= 0) {
                                $status_text = 'تسویه شده';
                                $status_class = 'badge-paid';
                            } elseif ($inv['paid_amount'] > 0) {
                                $status_text = 'بدهی جزیی';
                                $status_class = 'badge-partial';
                            } else {
                                $status_text = 'پرداخت نشده';
                                $status_class = 'badge-unpaid';
                            }
                        ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($inv['invoice_no']) ?></td>
                                <td><?= htmlspecialchars($inv['customer_name'] ?? 'مشتری عمومی') ?></td>
                                <td><?= htmlspecialchars($inv['invoice_date_sh']) ?></td>
                                <td class="text-success fw-bold"><?= number_format($inv['total_amount']) ?></td>
                                <td class="text-info"><?= number_format($inv['paid_amount']) ?></td>
                                <td class="<?= $remaining > 0 ? 'text-danger fw-bold' : 'text-secondary' ?>"><?= number_format($remaining) ?></td>
                                <td><span class="badge <?= $status_class ?> px-3 py-2"><?= $status_text ?></span></td>
                                <td class="btn-group-sm">
                                    <?php if ($remaining > 0): ?>
                                        <a href="sale_payment.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-success" title="ثبت پرداخت"><i class="fas fa-hand-holding-usd"></i> پرداخت</a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-secondary disabled" title="تسویه شده"><i class="fas fa-check"></i> تسویه</button>
                                    <?php endif; ?>
                                    <a href="view_invoice.php?type=sale&id=<?= $inv['id'] ?>" class="btn btn-sm btn-primary" title="مشاهده جزئیات"><i class="fas fa-eye"></i> جزئیات</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3 text-muted small">
            <i class="fas fa-info-circle"></i> نکته: برای ثبت پرداخت جدید روی دکمه <strong>پرداخت</strong> کلیک کنید.
        </div>
    </div>
</div>

<?php
ob_end_flush(); // پایان بافر و ارسال خروجی
require_once '../../includes/footer.php'; 
?>