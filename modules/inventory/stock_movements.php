<?php
$page_title = 'گزارش گردش موجودی';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$product = null;
$movements = [];

if ($product_id) {
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();
    if ($product) {
        $stmt = $db->prepare("SELECT * FROM stock_movements WHERE product_id = ? ORDER BY created_at DESC");
        $stmt->execute([$product_id]);
        $movements = $stmt->fetchAll();
    }
}

// لیست محصولات برای انتخاب
$products = $db->query("SELECT id, name, sku FROM products ORDER BY name")->fetchAll();
?>
<div class="card">
    <div class="card-header">گزارش گردش موجودی</div>
    <div class="card-body">
        <form method="get" class="row mb-4">
            <div class="col-md-6">
                <select name="product_id" class="form-select" required>
                    <option value="">انتخاب کالا</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($product_id == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary">نمایش</button>
            </div>
        </form>
        
        <?php if ($product): ?>
            <h5>اطلاعات کالا: <?= htmlspecialchars($product['name']) ?></h5>
            <p>موجودی فعلی: <?= $product['current_stock'] ?> | کد: <?= htmlspecialchars($product['sku']) ?></p>
            
            <?php if (count($movements) > 0): ?>
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr><th>تاریخ</th><th>نوع حرکت</th><th>تعداد</th><th>قیمت واحد</th><th>مرجع</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $m): ?>
                            <tr>
                                <td><?= jdate('Y/m-d H:i:s', strtotime($m['created_at'])) ?></td>
                                <td><?= ($m['movement_type'] == 'in') ? 'ورود (خرید)' : 'خروج (فروش/مصرف)' ?></td>
                                <td><?= $m['quantity'] ?></td>
                                <td><?= number_format($m['price']) ?> تومان</td>
                                <td><?= htmlspecialchars($m['ref_type'] . ' (#' . $m['ref_id'] . ')') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="alert alert-info">هیچ حرکتی برای این کالا ثبت نشده است.</div>
            <?php endif; ?>
        <?php elseif ($product_id): ?>
            <div class="alert alert-danger">کالا یافت نشد.</div>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>