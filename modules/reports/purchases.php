<?php
$page_title = 'گزارش خرید';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reports_view')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$purchases = [];

$sql = "SELECT * FROM purchase_invoices WHERE 1=1";
$params = [];
if ($from_date) {
    $sql .= " AND invoice_date_sh >= ?";
    $params[] = $from_date;
}
if ($to_date) {
    $sql .= " AND invoice_date_sh <= ?";
    $params[] = $to_date;
}
$sql .= " ORDER BY id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$total_amount = array_sum(array_column($purchases, 'total_amount'));
?>
<div class="card">
    <div class="card-header">📦 گزارش خرید</div>
    <div class="card-body">
        <form method="get" class="row g-3 mb-4">
            <div class="col-md-3"><label>از تاریخ</label><input type="text" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>"></div>
            <div class="col-md-3"><label>تا تاریخ</label><input type="text" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>"></div>
            <div class="col-md-2 align-self-end"><button type="submit" class="btn btn-primary">نمایش</button><a href="purchases.php" class="btn btn-secondary ms-2">پاک کردن</a></div>
        </form>
        <?php if (count($purchases) > 0): ?>
            <table class="table table-bordered">
                <thead><tr><th>شماره فاکتور</th><th>تاریخ</th><th>تأمین‌کننده</th><th>مبلغ کل</th></tr></thead>
                <tbody>
                    <?php foreach ($purchases as $p): ?>
                    <tr><td><?= htmlspecialchars($p['invoice_no']) ?></td><td><?= htmlspecialchars($p['invoice_date_sh']) ?></td><td><?= htmlspecialchars($p['supplier_name']) ?></td><td class="text-start"><?= number_format($p['total_amount']) ?> تومان</td></tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="table-dark"><th colspan="3">جمع کل</th><th class="text-start"><?= number_format($total_amount) ?> تومان</th></tr></tfoot>
            </table>
        <?php else: ?>
            <div class="alert alert-info">هیچ فاکتوری یافت نشد.</div>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>