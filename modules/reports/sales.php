<?php
$page_title = 'گزارش فروش';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reports_view')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$sales = [];

$sql = "SELECT s.*, c.fullname as customer_name, a.account_name 
        FROM sales_invoices s 
        LEFT JOIN customers c ON c.id = s.customer_id 
        LEFT JOIN accounts a ON a.id = s.account_id 
        WHERE 1=1";
$params = [];
if ($from_date) {
    $sql .= " AND s.invoice_date_sh >= ?";
    $params[] = $from_date;
}
if ($to_date) {
    $sql .= " AND s.invoice_date_sh <= ?";
    $params[] = $to_date;
}
$sql .= " ORDER BY s.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// محاسبه جمع کل
$total_amount = array_sum(array_column($sales, 'total_amount'));
$total_paid = array_sum(array_column($sales, 'paid_amount'));
?>
<div class="card">
    <div class="card-header">📊 گزارش فروش</div>
    <div class="card-body">
        <form method="get" class="row g-3 mb-4">
            <div class="col-md-3">
                <label>از تاریخ (مثال 1402/01/01)</label>
                <input type="text" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-md-3">
                <label>تا تاریخ</label>
                <input type="text" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-md-2 align-self-end">
                <button type="submit" class="btn btn-primary">نمایش</button>
                <a href="sales.php" class="btn btn-secondary">پاک کردن فیلتر</a>
            </div>
        </form>
        
        <?php if (count($sales) > 0): ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr><th>شماره فاکتور</th><th>تاریخ</th><th>مشتری</th><th>حساب</th><th>مبلغ کل</th><th>پرداختی</th><th>مانده</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($s['invoice_no']) ?></td>
                            <td><?= htmlspecialchars($s['invoice_date_sh']) ?></td>
                            <td><?= htmlspecialchars($s['customer_name'] ?? 'عمومی') ?></td>
                            <td><?= htmlspecialchars($s['account_name'] ?? '-') ?></td>
                            <td class="text-start"><?= number_format($s['total_amount']) ?> تومان</td>
                            <td class="text-start"><?= number_format($s['paid_amount']) ?> تومان</td>
                            <td class="text-start"><?= number_format($s['total_amount'] - $s['paid_amount']) ?> تومان</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-dark">
                            <th colspan="4">جمع کل</th>
                            <th class="text-start"><?= number_format($total_amount) ?> تومان</th>
                            <th class="text-start"><?= number_format($total_paid) ?> تومان</th>
                            <th class="text-start"><?= number_format($total_amount - $total_paid) ?> تومان</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">هیچ فاکتوری یافت نشد.</div>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>