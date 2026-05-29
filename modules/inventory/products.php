<?php
$page_title = 'مدیریت انبار';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">شما دسترسی به این بخش ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ================================
// دریافت پارامترهای فیلتر و صفحه‌بندی
// ================================
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$search = trim($_GET['search'] ?? '');
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$type = $_GET['type'] ?? '';
$stock_status = $_GET['stock_status'] ?? ''; // low, normal, all

// ساخت شرط WHERE پویا
$where_clauses = [];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(p.name LIKE ? OR p.sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category_id > 0) {
    $where_clauses[] = "p.category_id = ?";
    $params[] = $category_id;
}
if (in_array($type, ['new_part','used_part','new_appliance','used_appliance'])) {
    $where_clauses[] = "p.type = ?";
    $params[] = $type;
}
// شرط وضعیت موجودی (روی current_stock و min_stock_alert)
if ($stock_status === 'low') {
    $where_clauses[] = "p.current_stock <= p.min_stock_alert";
} elseif ($stock_status === 'normal') {
    $where_clauses[] = "p.current_stock > p.min_stock_alert";
}

$where_sql = empty($where_clauses) ? '' : 'WHERE ' . implode(' AND ', $where_clauses);

// ================================
// کوئری شمارش کل رکوردها (برای صفحه‌بندی)
// ================================
$count_sql = "SELECT COUNT(*) as total FROM products p $where_sql";
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($params);
$total_rows = $count_stmt->fetch()['total'];
$total_pages = ceil($total_rows / $per_page);
$offset = ($page - 1) * $per_page;

// ================================
// کوئری اصلی با JOIN و ORDER BY
// ================================
$limit = (int)$per_page;
$offset = (int)$offset;
$sql = "
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN product_categories c ON c.id = p.category_id 
    $where_sql
    ORDER BY p.name ASC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// ================================
// محاسبه آمار برای کارت‌های بالای صفحه
// ================================
$stats_sql = "SELECT 
    COUNT(*) as total_products,
    SUM(CASE WHEN current_stock <= min_stock_alert THEN 1 ELSE 0 END) as low_stock_count,
    SUM(current_stock * purchase_price) as total_inventory_value
    FROM products";
$stats = $db->query($stats_sql)->fetch();

// ================================
// دریافت لیست دسته‌بندی‌ها برای فیلتر
// ================================
$categories = $db->query("SELECT id, name FROM product_categories ORDER BY name")->fetchAll();

// نقشه نوع کالا برای نمایش فارسی
$type_map = [
    'new_part' => 'قطعه نو',
    'used_part' => 'قطعه دست دوم',
    'new_appliance' => 'لوازم خانگی نو',
    'used_appliance' => 'لوازم خانگی دست دوم'
];

// ================================
// بررسی مجوزهای جزئی
// ================================
$can_view_purchase = has_permission($_SESSION['user_id'], 'view_purchase_price');
// برای قیمت فروش، ابتدا مجوز اختصاصی view_sale_price را چک می‌کنیم، در غیر این صورت همان inventory_access مبنا قرار می‌گیرد
$can_view_sale = has_permission($_SESSION['user_id'], 'view_sale_price') || has_permission($_SESSION['user_id'], 'inventory_access');
$can_export = has_permission($_SESSION['user_id'], 'export_data');
?>

<!-- ================================ -->
<!-- کارت‌های آماری (با آیکون) -->
<!-- ================================ -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-white bg-primary">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title">کل کالاها</h5>
                    <p class="card-text display-6"><?= number_format($stats['total_products']) ?></p>
                </div>
                <i class="bi bi-box-seam fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-white bg-danger">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title">کمبود موجودی</h5>
                    <p class="card-text display-6"><?= number_format($stats['low_stock_count']) ?></p>
                </div>
                <i class="bi bi-exclamation-triangle fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-white bg-success">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title">ارزش موجودی (قیمت خرید)</h5>
                    <p class="card-text display-6"><?= number_format($stats['total_inventory_value']) ?></p>
                    <small>تومان</small>
                </div>
                <i class="bi bi-coin fs-1 opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<!-- ================================ -->
<!-- نوار ابزار: دکمه‌های اصلی + فرم فیلتر -->
<!-- ================================ -->
<div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <a href="product_add.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> افزودن کالا</a>
            <a href="purchase.php" class="btn btn-success"><i class="bi bi-cart-plus"></i> خرید</a>
            <a href="sale.php" class="btn btn-warning"><i class="bi bi-bag-check"></i> فروش</a>
            <a href="stock_movements.php" class="btn btn-info"><i class="bi bi-clock-history"></i> گردش موجودی</a>
            <a href="purchase_invoices.php" class="btn btn-secondary"><i class="bi bi-receipt"></i> فاکتورهای خرید</a>
        </div>
        <div>
            <?php if ($can_export): ?>
                <a href="?export=1&<?= http_build_query($_GET) ?>" class="btn btn-outline-success" id="exportExcelBtn"><i class="bi bi-file-excel"></i> خروجی Excel</a>
            <?php else: ?>
                <button class="btn btn-outline-secondary" disabled><i class="bi bi-file-excel"></i> خروجی Excel (دسترسی ندارید)</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end" id="filterForm">
            <div class="col-md-3">
                <label class="form-label">جستجو (نام / کد)</label>
                <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="مثال: پنکه یا PN-001">
            </div>
            <div class="col-md-2">
                <label class="form-label">دسته‌بندی</label>
                <select name="category_id" class="form-select">
                    <option value="0">همه</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $category_id == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">نوع کالا</label>
                <select name="type" class="form-select">
                    <option value="">همه</option>
                    <?php foreach ($type_map as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $type == $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">وضعیت موجودی</label>
                <select name="stock_status" class="form-select">
                    <option value="">همه</option>
                    <option value="low" <?= $stock_status == 'low' ? 'selected' : '' ?>>کمبود (موجودی ≤ هشدار)</option>
                    <option value="normal" <?= $stock_status == 'normal' ? 'selected' : '' ?>>سالم (موجودی > هشدار)</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">تعداد در صفحه</label>
                <select name="per_page" class="form-select">
                    <?php foreach ([10, 20, 50, 100] as $num): ?>
                        <option value="<?= $num ?>" <?= $per_page == $num ? 'selected' : '' ?>><?= $num ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> فیلتر</button>
            </div>
            <div class="col-md-1">
                <a href="products.php" class="btn btn-secondary w-100"><i class="bi bi-arrow-repeat"></i> بازنشانی</a>
            </div>
        </form>
    </div>
</div>

<!-- ================================ -->
<!-- جدول نمایش محصولات -->
<!-- ================================ -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table"></i> لیست کالاها</span>
        <span class="badge bg-secondary"><?= number_format($total_rows) ?> کالا</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>کد کالا</th>
                        <th>نام کالا</th>
                        <th>دسته</th>
                        <th>نوع</th>
                        <th>واحد</th>
                        <th>موجودی</th>
                        <?php if ($can_view_purchase): ?><th>قیمت خرید</th><?php endif; ?>
                        <?php if ($can_view_sale): ?><th>قیمت فروش</th><?php endif; ?>
                        <th>هشدار کمبود</th>
                        <th>محل نگهداری</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="100" class="text-center text-danger">هیچ محصولی با فیلترهای انتخاب شده یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php $counter = $offset + 1; ?>
                        <?php foreach ($products as $p): 
                            $isLowStock = ($p['current_stock'] <= $p['min_stock_alert']);
                            $rowClass = $isLowStock ? 'table-warning' : '';
                        ?>
                            <tr class="<?= $rowClass ?>">
                                <td><?= $counter++ ?></td>
                                <td><?= htmlspecialchars($p['sku']) ?></td>
                                <td><?= htmlspecialchars($p['name']) ?></td>
                                <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                                <td><?= $type_map[$p['type']] ?? $p['type'] ?></td>
                                <td><?= htmlspecialchars($p['unit']) ?></td>
                                <td>
                                    <?= number_format($p['current_stock']) ?>
                                    <?php if ($isLowStock): ?>
                                        <span class="badge bg-danger ms-1">کمبود</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($can_view_purchase): ?>
                                    <td><?= number_format($p['purchase_price']) ?> تومان</td>
                                <?php endif; ?>
                                <?php if ($can_view_sale): ?>
                                    <td><?= number_format($p['sale_price']) ?> تومان</td>
                                <?php endif; ?>
                                <td><?= $p['min_stock_alert'] ?> عدد</td>
                                <td><?= htmlspecialchars($p['storage_location'] ?? '-') ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="product_edit.php?id=<?= $p['id'] ?>" class="btn btn-outline-warning" title="ویرایش"><i class="bi bi-pencil-square"></i></a>
                                        <a href="stock_movements.php?product_id=<?= $p['id'] ?>" class="btn btn-outline-info" title="گردش موجودی"><i class="bi bi-clock-history"></i></a>
                                        <a href="purchase.php?product_id=<?= $p['id'] ?>" class="btn btn-outline-success" title="خرید این کالا"><i class="bi bi-cart-plus"></i></a>
                                        <a href="sale.php?product_id=<?= $p['id'] ?>" class="btn btn-outline-danger" title="فروش این کالا"><i class="bi bi-bag-check"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total_pages > 1): ?>
        <div class="card-footer">
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">قبلی</a></li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link">قبلی</span></li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">بعدی</a></li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link">بعدی</span></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- ================================ -->
<!-- اسکریپت خروجی Excel (در صورت درخواست export) -->
<!-- ================================ -->
<?php
if (isset($_GET['export']) && $_GET['export'] == '1') {
    // بررسی مجوز خروجی
    if (!$can_export) {
        die('<h3>شما مجوز خروجی گرفتن از اطلاعات را ندارید.</h3>');
    }
    
    // جلوگیری از خروجی HTML قبلی
    ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products_export_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    
    // ساخت هدر ستون‌ها بر اساس مجوزها
    $headers = ['ردیف', 'کد کالا', 'نام کالا', 'دسته‌بندی', 'نوع', 'واحد', 'موجودی'];
    if ($can_view_purchase) $headers[] = 'قیمت خرید';
    if ($can_view_sale) $headers[] = 'قیمت فروش';
    $headers[] = 'هشدار کمبود';
    $headers[] = 'محل نگهداری';
    fputcsv($output, $headers);
    
    $i = 1;
    foreach ($products as $p) {
        $row = [
            $i++,
            $p['sku'],
            $p['name'],
            $p['category_name'] ?? '-',
            $type_map[$p['type']] ?? $p['type'],
            $p['unit'],
            $p['current_stock']
        ];
        if ($can_view_purchase) $row[] = $p['purchase_price'];
        if ($can_view_sale) $row[] = $p['sale_price'];
        $row[] = $p['min_stock_alert'];
        $row[] = $p['storage_location'] ?? '-';
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
?>

<script>
document.getElementById('filterForm').addEventListener('submit', function(e) {
    // فرم به روش GET ارسال می‌شود
});
</script>

<?php require_once '../../includes/footer.php'; ?>