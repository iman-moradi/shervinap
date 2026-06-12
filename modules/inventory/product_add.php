<?php
// ==================== ابتدا شرط‌های AJAX ====================
if (isset($_GET['generate_sku']) || isset($_GET['search_product']) || isset($_GET['load_product']) || isset($_POST['add_category_ajax'])) {
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
    
    // پردازش AJAX برای افزودن دسته‌بندی
    if (isset($_POST['add_category_ajax'])) {
        header('Content-Type: application/json');
        $name = trim($_POST['name'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        
        if (empty($name)) {
            echo json_encode(['success' => false, 'error' => 'نام دسته الزامی است.']);
            exit;
        }
        
        $check = $db->prepare("SELECT id FROM product_categories WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'این دسته قبلاً ثبت شده است.']);
            exit;
        }
        
        $stmt = $db->prepare("INSERT INTO product_categories (name, parent_id) VALUES (?, ?)");
        if ($stmt->execute([$name, $parent_id])) {
            $newId = $db->lastInsertId();
            echo json_encode([
                'success' => true,
                'id' => $newId,
                'name' => $name
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'خطا در ثبت دسته']);
        }
        exit;
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
    /* استایل جستجوی محصول */
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
    
    /* ========== استایل مودال سفارشی (بدون بوتاسترپ) ========== */
    .custom-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        backdrop-filter: blur(3px);
    }
    
    .custom-modal-content {
        background-color: #fff;
        margin: 10% auto;
        width: 90%;
        max-width: 500px;
        border-radius: 16px;
        box-shadow: 0 20px 35px rgba(0,0,0,0.2);
        animation: modalSlideIn 0.3s ease;
    }
    
    @keyframes modalSlideIn {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .custom-modal-header {
        padding: 18px 20px;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .custom-modal-header h4 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 600;
    }
    
    .custom-modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: #6c757d;
        transition: color 0.2s;
    }
    
    .custom-modal-close:hover {
        color: #dc3545;
    }
    
    .custom-modal-body {
        padding: 20px;
    }
    
    .custom-modal-footer {
        padding: 15px 20px;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    
    .btn-custom-primary {
        background-color: #0ea5e9;
        color: white;
        border: none;
        padding: 8px 20px;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    
    .btn-custom-primary:hover {
        background-color: #0284c7;
    }
    
    .btn-custom-secondary {
        background-color: #e2e8f0;
        color: #334155;
        border: none;
        padding: 8px 20px;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    
    .btn-custom-secondary:hover {
        background-color: #cbd5e1;
    }
    
    .btn-add-category {
        background: none;
        border: none;
        color: #0ea5e9;
        cursor: pointer;
        font-size: 0.85rem;
        margin-top: 5px;
        padding: 0;
    }
    
    .btn-add-category:hover {
        text-decoration: underline;
    }
    
    .form-group-custom {
        margin-bottom: 15px;
    }
    
    .form-group-custom label {
        display: block;
        margin-bottom: 6px;
        font-weight: 500;
        color: #334155;
    }
    
    .form-group-custom input,
    .form-group-custom select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 14px;
    }
    
    .form-group-custom input:focus,
    .form-group-custom select:focus {
        outline: none;
        border-color: #0ea5e9;
        box-shadow: 0 0 0 3px rgba(14,165,233,0.1);
    }
    
    /* پیام toast */
    .custom-toast {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #22c55e;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        z-index: 10000;
        display: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .custom-toast.success {
        background: #22c55e;
    }
    
    .custom-toast.error {
        background: #ef4444;
    }
    
    .alert-custom {
        padding: 10px 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        display: none;
    }
    
    .alert-custom.danger {
        background-color: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fecaca;
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
                    <select name="category_id" id="categorySelect" class="form-select">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-add-category" onclick="openCategoryModal()">
                        <i class="fas fa-plus-circle"></i> افزودن دسته جدید
                    </button>
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

<!-- مودال سفارشی افزودن دسته‌بندی (بدون بوتاسترپ) -->
<div id="categoryModal" class="custom-modal">
    <div class="custom-modal-content">
        <div class="custom-modal-header">
            <h4>➕ افزودن دسته‌بندی جدید</h4>
            <button type="button" class="custom-modal-close" onclick="closeCategoryModal()">&times;</button>
        </div>
        <div class="custom-modal-body">
            <div id="modalAlert" class="alert-custom danger"></div>
            <div class="form-group-custom">
                <label>نام دسته *</label>
                <input type="text" id="catName" class="form-control" placeholder="مثال: قطعات الکترونیکی">
            </div>
            <div class="form-group-custom">
                <label>دسته والد (اختیاری)</label>
                <select id="catParentId" class="form-select">
                    <option value="">بدون والد</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn-custom-secondary" onclick="closeCategoryModal()">انصراف</button>
            <button type="button" class="btn-custom-primary" onclick="saveCategory()">ذخیره دسته</button>
        </div>
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

// ========== توابع مودال دسته‌بندی ==========
function openCategoryModal() {
    document.getElementById('categoryModal').style.display = 'block';
    document.getElementById('catName').value = '';
    document.getElementById('catParentId').value = '';
    document.getElementById('modalAlert').style.display = 'none';
}

function closeCategoryModal() {
    document.getElementById('categoryModal').style.display = 'none';
}

function saveCategory() {
    var catName = $('#catName').val().trim();
    var catParentId = $('#catParentId').val();
    var modalAlert = $('#modalAlert');
    
    if (!catName) {
        modalAlert.text('لطفاً نام دسته را وارد کنید.').css('display', 'block');
        return;
    }
    
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            add_category_ajax: 1,
            name: catName,
            parent_id: catParentId
        },
        dataType: 'json',
        beforeSend: function() {
            modalAlert.hide();
        },
        success: function(response) {
            if (response.success) {
                // اضافه کردن گزینه جدید به select دسته‌بندی
                $('#categorySelect').append('<option value="' + response.id + '" selected>' + escapeHtmlForModal(response.name) + '</option>');
                closeCategoryModal();
                showToast('دسته‌بندی با موفقیت اضافه شد', 'success');
            } else {
                modalAlert.text(response.error).css('display', 'block');
            }
        },
        error: function() {
            modalAlert.text('خطا در ارتباط با سرور').css('display', 'block');
        }
    });
}

function escapeHtmlForModal(str) {
    if(!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if(m==='&') return '&amp;';
        if(m==='<') return '&lt;';
        if(m==='>') return '&gt;';
        return m;
    });
}

function showToast(message, type) {
    var toast = $('<div class="custom-toast ' + type + '">' + message + '</div>');
    $('body').append(toast);
    toast.fadeIn(300);
    setTimeout(function() {
        toast.fadeOut(300, function() { $(this).remove(); });
    }, 3000);
}

// بستن مودال با کلیک بیرون از آن
$(document).mouseup(function(e) {
    var modal = $('#categoryModal');
    if (modal.is(':visible') && !$(e.target).closest('.custom-modal-content').length) {
        modal.hide();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>