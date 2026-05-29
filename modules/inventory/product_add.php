<?php
// ==================== ابتدا شرط‌های AJAX ====================
if (isset($_GET['generate_sku']) || isset($_GET['search_product']) || isset($_GET['load_product'])) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/jdf.php';
    session_start();
    
    if (!isset($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'دسترسی غیرمجاز']);
        exit;
    }
    
    function generateSku($type, $db) {
        $prefix = '';
        switch ($type) {
            case 'new_part': $prefix = 'PN'; break;
            case 'used_part': $prefix = 'PS'; break;
            case 'new_appliance': $prefix = 'AN'; break;
            case 'used_appliance': $prefix = 'AS'; break;
            default: $prefix = 'PR';
        }
        $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING(sku, 4) AS UNSIGNED)) as max_num FROM products WHERE sku LIKE ?");
        $stmt->execute([$prefix . '-%']);
        $row = $stmt->fetch();
        $next = ($row && $row['max_num'] !== null) ? $row['max_num'] + 1 : 1;
        return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }
    
    if (isset($_GET['generate_sku'])) {
        header('Content-Type: application/json');
        $type = $_GET['generate_sku'];
        $new_sku = generateSku($type, $db);
        echo json_encode(['sku' => $new_sku]);
        exit;
    }
    
    if (isset($_GET['search_product'])) {
        $query = trim($_GET['search_product']);
        if (strlen($query) < 2) {
            echo json_encode([]);
            exit;
        }
        $stmt = $db->prepare("SELECT id, name, sku, type FROM products WHERE name LIKE ? LIMIT 10");
        $stmt->execute(["%$query%"]);
        $results = $stmt->fetchAll();
        header('Content-Type: application/json');
        echo json_encode($results);
        exit;
    }
    
    if (isset($_GET['load_product']) && is_numeric($_GET['load_product'])) {
        header("Location: product_edit.php?id=" . (int)$_GET['load_product']);
        exit;
    }
}

// ==================== بقیه کد اصلی صفحه ====================
$page_title = 'افزودن کالای جدید';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

function generateSkuForPage($type, $db) {
    $prefix = '';
    switch ($type) {
        case 'new_part': $prefix = 'PN'; break;
        case 'used_part': $prefix = 'PS'; break;
        case 'new_appliance': $prefix = 'AN'; break;
        case 'used_appliance': $prefix = 'AS'; break;
        default: $prefix = 'PR';
    }
    $stmt = $db->prepare("SELECT MAX(CAST(SUBSTRING(sku, 4) AS UNSIGNED)) as max_num FROM products WHERE sku LIKE ?");
    $stmt->execute([$prefix . '-%']);
    $row = $stmt->fetch();
    $next = ($row && $row['max_num'] !== null) ? $row['max_num'] + 1 : 1;
    return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_new_product'])) {
    $sku = trim($_POST['sku']);
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $unit = trim($_POST['unit']);
    $purchase_price = (int)$_POST['purchase_price'];
    $sale_price = (int)$_POST['sale_price'];
    $min_stock_alert = (int)$_POST['min_stock_alert'];
    $storage_location = trim($_POST['storage_location'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $initial_stock = (int)($_POST['initial_stock'] ?? 0);
    if ($initial_stock < 0) $initial_stock = 0;
    
    $check = $db->prepare("SELECT id FROM products WHERE name = ? AND type = ?");
    $check->execute([$name, $type]);
    if ($check->fetch()) {
        $error = '❌ کالایی با همین نام و نوع قبلاً ثبت شده است. از جستجو استفاده کنید.';
    } else {
        $stmt = $db->prepare("INSERT INTO products (sku, name, category_id, type, unit, purchase_price, sale_price, min_stock_alert, storage_location, current_stock) VALUES (?,?,?,?,?,?,?,?,?,?)");
        if ($stmt->execute([$sku, $name, $category_id, $type, $unit, $purchase_price, $sale_price, $min_stock_alert, $storage_location, $initial_stock])) {
            $success = '✅ کالا با موفقیت اضافه شد.';
            echo '<meta http-equiv="refresh" content="1;url=products.php">';
        } else {
            $error = 'خطا در ثبت کالا.';
        }
    }
}

$categories = $db->query("SELECT id, name FROM product_categories ORDER BY name")->fetchAll();
$default_type = 'new_part';
$generated_sku = generateSkuForPage($default_type, $db);
?>
<style>
    .suggestions-box {
        position: absolute;
        z-index: 1000;
        background: white;
        border: 1px solid #ccc;
        border-top: none;
        width: 100%;
        max-height: 250px;
        overflow-y: auto;
        display: none;
    }
    .suggestion-item {
        padding: 8px 12px;
        cursor: pointer;
        border-bottom: 1px solid #eee;
    }
    .suggestion-item:hover {
        background-color: #f0f0f0;
    }
</style>

<div class="card">
    <div class="card-header">➕ افزودن کالای جدید</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        
        <form method="post" id="productForm">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label>نوع کالا *</label>
                    <select name="type" id="typeSelect" class="form-select" required>
                        <option value="new_part">🔧 قطعه نو</option>
                        <option value="used_part">🔩 قطعه دست دوم</option>
                        <option value="new_appliance">📺 لوازم خانگی نو</option>
                        <option value="used_appliance">📻 لوازم خانگی دست دوم</option>
                    </select>
                    <small class="text-muted">بر اساس نوع، کد کالا تولید می‌شود</small>
                </div>
                <div class="col-md-3 mb-3">
                    <label>کد کالا (SKU)</label>
                    <input type="text" name="sku" id="sku" class="form-control" value="<?= htmlspecialchars($generated_sku) ?>" readonly>
                </div>
                <div class="col-md-6 mb-3 position-relative">
                    <label>نام کالا *</label>
                    <input type="text" name="name" id="productName" class="form-control" autocomplete="off" required>
                    <div id="suggestionsBox" class="suggestions-box"></div>
                    <small class="text-muted">در صورت تکراری بودن، روی گزینه کلیک کنید تا به صفحه ویرایش بروید.</small>
                </div>
                <div class="col-md-3 mb-3">
                    <label>دسته‌بندی کالا</label>
                    <select name="category_id" class="form-select">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small><a href="categories.php" target="_blank">مدیریت دسته‌بندی‌ها</a></small>
                </div>
                <div class="col-md-3 mb-3">
                    <label>واحد</label>
                    <input type="text" name="unit" class="form-control" value="عدد">
                </div>
                <div class="col-md-2 mb-3">
                    <label>قیمت خرید (تومان)</label>
                    <input type="number" name="purchase_price" class="form-control" value="0">
                </div>
                <div class="col-md-2 mb-3">
                    <label>قیمت فروش (تومان)</label>
                    <input type="number" name="sale_price" class="form-control" value="0">
                </div>
                <div class="col-md-2 mb-3">
                    <label>هشدار کمبود</label>
                    <input type="number" name="min_stock_alert" class="form-control" value="5">
                </div>
                <div class="col-md-3 mb-3">
                    <label>محل نگهداری</label>
                    <input type="text" name="storage_location" class="form-control" placeholder="مثال: قفسه A، انبار مرکزی">
                </div>
                <!-- فیلد جدید: موجودی اولیه -->
                <div class="col-md-2 mb-3">
                    <label>موجودی اولیه</label>
                    <input type="number" name="initial_stock" class="form-control" value="0" min="0">
                    <small class="text-muted">بدون تأثیر در حسابداری</small>
                </div>
            </div>
            <button type="submit" name="submit_new_product" class="btn btn-primary">💾 ثبت کالای جدید</button>
            <a href="products.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    var searchTimeout = null;
    
    function refreshSku(type) {
        $('#sku').css('background-color', '#fff3cd');
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: { generate_sku: type, _: Date.now() },
            dataType: 'json',
            cache: false,
            success: function(data) {
                $('#sku').css('background-color', '');
                if (data && data.sku) $('#sku').val(data.sku);
            },
            error: function() { $('#sku').css('background-color', '#f8d7da'); }
        });
    }
    
    refreshSku($('#typeSelect').val());
    $('#typeSelect').on('change', function(){ refreshSku($(this).val()); });
    
    $('#productName').on('keyup', function() {
        var query = $(this).val().trim();
        clearTimeout(searchTimeout);
        if(query.length < 2) { $('#suggestionsBox').hide(); return; }
        searchTimeout = setTimeout(function() {
            $.ajax({
                url: window.location.href,
                data: { search_product: query },
                dataType: 'json',
                success: function(data) {
                    if(data && data.length) {
                        var html = '';
                        for(var i=0;i<data.length;i++) {
                            html += '<div class="suggestion-item" data-id="'+data[i].id+'" data-name="'+escapeHtml(data[i].name)+'" data-sku="'+escapeHtml(data[i].sku)+'">';
                            html += '<strong>'+escapeHtml(data[i].name)+'</strong><br><small>کد: '+escapeHtml(data[i].sku)+'</small>';
                            html += '</div>';
                        }
                        $('#suggestionsBox').html(html).show();
                    } else $('#suggestionsBox').hide();
                }
            });
        }, 300);
    });
    
    $(document).on('click', '.suggestion-item', function(e) {
        e.preventDefault();
        var productId = $(this).data('id');
        var productName = $(this).data('name');
        if(confirm('کالای "'+productName+'" قبلاً ثبت شده است. آیا می‌خواهید آن را ویرایش کنید؟')) {
            window.location.href = 'product_edit.php?id='+productId;
        }
    });
    
    $(document).click(function(e) {
        if(!$(e.target).closest('#productName, #suggestionsBox').length) $('#suggestionsBox').hide();
    });
    
    function escapeHtml(str) {
        if(!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if(m==='&') return '&amp;';
            if(m==='<') return '&lt;';
            if(m==='>') return '&gt;';
            return m;
        });
    }
});
</script>
<?php require_once '../../includes/footer.php'; ?>