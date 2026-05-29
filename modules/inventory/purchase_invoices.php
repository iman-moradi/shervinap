<?php
$page_title = 'لیست فاکتورهای خرید';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$filter = $_GET['filter'] ?? 'all'; // all, unpaid, partial, paid

$sql = "SELECT * FROM purchase_invoices ORDER BY invoice_date_sh DESC";
if ($filter == 'unpaid') {
    $sql = "SELECT * FROM purchase_invoices WHERE payment_status = 'unpaid' ORDER BY invoice_date_sh DESC";
} elseif ($filter == 'partial') {
    $sql = "SELECT * FROM purchase_invoices WHERE payment_status = 'partial' ORDER BY invoice_date_sh DESC";
} elseif ($filter == 'paid') {
    $sql = "SELECT * FROM purchase_invoices WHERE payment_status = 'paid' ORDER BY invoice_date_sh DESC";
}

$invoices = $db->query($sql)->fetchAll();
?>
<div class="card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item"><a class="nav-link <?= $filter == 'all' ? 'active' : '' ?>" href="?filter=all">همه</a></li>
            <li class="nav-item"><a class="nav-link <?= $filter == 'unpaid' ? 'active' : '' ?>" href="?filter=unpaid">نسیه (پرداخت نشده)</a></li>
            <li class="nav-item"><a class="nav-link <?= $filter == 'partial' ? 'active' : '' ?>" href="?filter=partial">نسيه (بدهی جزیی)</a></li>
            <li class="nav-item"><a class="nav-link <?= $filter == 'paid' ? 'active' : '' ?>" href="?filter=paid">تسویه شده</a></li>
        </ul>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>شماره فاکتور</th><th>تأمین‌کننده</th><th>تاریخ</th>
                        <th>مبلغ کل</th><th>پرداخت شده</th><th>مانده</th><th>وضعیت</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): 
                        $remaining = $inv['total_amount'] - $inv['paid_amount'];
                        $status_label = '';
                        if ($inv['payment_status'] == 'paid') $status_label = 'تسویه شده';
                        elseif ($inv['payment_status'] == 'partial') $status_label = 'بدهی جزیی';
                        else $status_label = 'پرداخت نشده';
                        $row_class = ($remaining > 0) ? 'table-warning' : '';
                    ?>
                        <tr class="<?= $row_class ?>">
                            <td><?= htmlspecialchars($inv['invoice_no']) ?></td>
                            <td><?= htmlspecialchars($inv['supplier_name']) ?></td>
                            <td><?= htmlspecialchars($inv['invoice_date_sh']) ?></td>
                            <td><?= number_format($inv['total_amount']) ?> تومان</td>
                            <td><?= number_format($inv['paid_amount']) ?> تومان</td>
                            <td><strong><?= number_format($remaining) ?> تومان</strong></td>
                            <td><?= $status_label ?></td>
                            <td>
                                <?php if ($remaining > 0): ?>
                                    <a href="purchase_payment.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-info">💰 پرداخت قسط</a>
                                <?php else: ?>
                                    <span class="text-success">✓ تسویه</span>
                                <?php endif; ?>
                             </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($invoices) == 0): ?>
                        <tr><td colspan="8" class="text-center">هیچ فاکتوری یافت نشد</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>