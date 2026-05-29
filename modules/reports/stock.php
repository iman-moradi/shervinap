<?php
$page_title = 'گزارش موجودی انبار';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reports_view')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$low_only = isset($_GET['low_only']) ? true : false;
$sql = "SELECT * FROM products ORDER BY name";
if ($low_only) {
    $sql = "SELECT * FROM products WHERE current_stock <= min_stock_alert ORDER BY name";
}
$products = $db->query($sql)->fetchAll();
?>
<div class="card">
    <div class="card-header">
        📦 گزارش موجودی انبار
        <a href="?low_only=1" class="btn btn-warning btn-sm float-start">نمایش کالاهای با موجودی کم</a>
        <a href="stock.php" class="btn btn-secondary btn-sm float-start ms-2">همه کالاها</a>
    </div>
    <div class="card-body">
        <?php if (count($products) > 0): ?>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr><th>کد کالا</th><th>نام کالا</th><th>نوع</th><th>واحد</th><th>موجودی</th><th>حداقل هشدار</th><th>قیمت خرید</th><th>قیمت فروش</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): 
                        $alert_class = ($p['current_stock'] <= $p['min_stock_alert']) ? 'table-warning' : '';
                    ?>
                    <tr class="<?= $alert_class ?>">
                        <td><?= htmlspecialchars($p['sku']) ?></td>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= $p['type'] ?></td>
                        <td><?= htmlspecialchars($p['unit']) ?></td>
                        <td><?= $p['current_stock'] ?></td>
                        <td><?= $p['min_stock_alert'] ?></td>
                        <td class="text-start"><?= number_format($p['purchase_price']) ?> تومان</td>
                        <td class="text-start"><?= number_format($p['sale_price']) ?> تومان</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-info">هیچ کالایی یافت نشد.</div>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>