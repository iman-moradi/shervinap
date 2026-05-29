<?php
$page_title = 'جزئیات تعمیر';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$ticket_id = $_GET['id'] ?? 0;
if (!$ticket_id) {
    header('Location: index.php');
    exit;
}

// دریافت اطلاعات تیکت و مشتری
$stmt = $db->prepare("SELECT r.*, c.fullname, c.mobile, c.address FROM repair_tickets r JOIN customers c ON c.id = r.customer_id WHERE r.id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();
if (!$ticket) {
    echo '<div class="alert alert-danger">تیکت یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ========== پردازش اضافه کردن اجرت یا قطعه ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $item_type = $_POST['item_type'];
    $description = trim($_POST['description']);
    $quantity = (int)($_POST['quantity'] ?? 1);
    $unit_price = (int)$_POST['unit_price'];
    $total_price = $quantity * $unit_price;
    $product_id = ($item_type == 'part' && !empty($_POST['product_id'])) ? (int)$_POST['product_id'] : null;
    
    $db->beginTransaction();
    try {
        if ($item_type == 'part' && $product_id) {
            $checkStock = $db->prepare("SELECT current_stock FROM products WHERE id = ?");
            $checkStock->execute([$product_id]);
            $current_stock = $checkStock->fetchColumn();
            if ($current_stock < $quantity) {
                throw new Exception("موجودی کالا کافی نیست. موجودی فعلی: $current_stock");
            }
            $db->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?")->execute([$quantity, $product_id]);
            $mov = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id) VALUES (?, 'out', ?, ?, 'repair_item', ?)");
            $mov->execute([$product_id, $quantity, $unit_price, $ticket_id]);
        }
        
        $ins = $db->prepare("INSERT INTO repair_items (ticket_id, item_type, description, quantity, unit_price, total_price, product_id) VALUES (?,?,?,?,?,?,?)");
        $ins->execute([$ticket_id, $item_type, $description, $quantity, $unit_price, $total_price, $product_id]);
        
        $db->prepare("UPDATE repair_tickets SET total_cost = (SELECT COALESCE(SUM(total_price),0) FROM repair_items WHERE ticket_id = ?) WHERE id = ?")->execute([$ticket_id, $ticket_id]);
        $db->commit();
        header("Location: view.php?id=$ticket_id");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $error = "خطا: " . $e->getMessage();
    }
}

// ========== حذف آیتم ==========
if (isset($_GET['delete_item'])) {
    $item_id = (int)$_GET['delete_item'];
    $stmtItem = $db->prepare("SELECT * FROM repair_items WHERE id = ? AND ticket_id = ?");
    $stmtItem->execute([$item_id, $ticket_id]);
    $item = $stmtItem->fetch();
    if ($item && $item['item_type'] == 'part' && $item['product_id']) {
        $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
        $mov = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id) VALUES (?, 'in', ?, ?, 'repair_item_undo', ?)");
        $mov->execute([$item['product_id'], $item['quantity'], $item['unit_price'], $ticket_id]);
    }
    $db->prepare("DELETE FROM repair_items WHERE id = ? AND ticket_id = ?")->execute([$item_id, $ticket_id]);
    $db->prepare("UPDATE repair_tickets SET total_cost = (SELECT COALESCE(SUM(total_price),0) FROM repair_items WHERE ticket_id = ?) WHERE id = ?")->execute([$ticket_id, $ticket_id]);
    header("Location: view.php?id=$ticket_id");
    exit;
}

// دریافت لیست اقلام تعمیر
$stmtItems = $db->prepare("SELECT * FROM repair_items WHERE ticket_id = ? ORDER BY id DESC");
$stmtItems->execute([$ticket_id]);
$items = $stmtItems->fetchAll();

$status_map = [
    'pending' => 'در انتظار',
    'in_progress' => 'در حال تعمیر',
    'waiting_part' => 'انتظار قطعه',
    'ready' => 'آماده تحویل',
    'delivered' => 'تحویل شده'
];
?>

<style>
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 12px;
        background: #f8fafc;
        padding: 1rem;
        border-radius: 16px;
    }
    .info-item {
        display: flex;
        justify-content: space-between;
        border-bottom: 1px dashed #cbd5e1;
        padding: 6px 0;
    }
    .info-label {
        font-weight: 600;
        color: #334155;
    }
    .info-value {
        color: #1e293b;
    }
    .suggestions-box {
        position: absolute;
        z-index: 1000;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        width: 100%;
        max-height: 200px;
        overflow-y: auto;
        display: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .suggestion-item {
        padding: 8px 12px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .suggestion-item:hover {
        background-color: #f1f5f9;
    }
</style>

<div class="row g-4">
    <!-- اطلاعات پذیرش -->
    <div class="col-md-6">
        <div class="modern-card h-100">
            <div class="card-header-custom">
                <i class="fas fa-info-circle"></i> اطلاعات پذیرش
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-glass"><?= $error ?></div>
                <?php endif; ?>
                <div class="info-grid">
                    <div class="info-item"><span class="info-label">شماره تیکت:</span><span class="info-value"><?= htmlspecialchars($ticket['ticket_no']) ?></span></div>
                    <div class="info-item"><span class="info-label">مشتری:</span><span class="info-value"><?= htmlspecialchars($ticket['fullname']) ?> - <?= $ticket['mobile'] ?></span></div>
                    <div class="info-item"><span class="info-label">آدرس:</span><span class="info-value"><?= nl2br(htmlspecialchars($ticket['address'])) ?></span></div>
                    <div class="info-item"><span class="info-label">دستگاه:</span><span class="info-value"><?= htmlspecialchars($ticket['device_type'] . ' ' . $ticket['brand'] . ' ' . $ticket['model']) ?></span></div>
                    <div class="info-item"><span class="info-label">شماره سریال:</span><span class="info-value"><?= htmlspecialchars($ticket['serial_no']) ?></span></div>
                    <div class="info-item"><span class="info-label">خرابی:</span><span class="info-value"><?= nl2br(htmlspecialchars($ticket['reported_fault'])) ?></span></div>
                    <div class="info-item"><span class="info-label">قطعات همراه:</span><span class="info-value"><?= nl2br(htmlspecialchars($ticket['accompanying_parts'])) ?></span></div>
                    <div class="info-item"><span class="info-label">وضعیت ظاهری:</span><span class="info-value"><?= htmlspecialchars($ticket['physical_condition']) ?></span></div>
                    <div class="info-item"><span class="info-label">بیعانه:</span><span class="info-value"><?= to_toman($ticket['deposit']) ?></span></div>
                    <div class="info-item"><span class="info-label">اولویت:</span><span class="info-value"><?= ($ticket['priority'] == 'urgent') ? 'فوری' : 'عادی' ?></span></div>
                    <?php if ($ticket['priority'] == 'urgent' && $ticket['urgent_deadline_sh']): ?>
                        <div class="info-item"><span class="info-label">تاریخ تحویل توافقی:</span><span class="info-value"><?= htmlspecialchars($ticket['urgent_deadline_sh']) ?></span></div>
                    <?php elseif ($ticket['priority'] == 'normal' && $ticket['normal_days']): ?>
                        <div class="info-item"><span class="info-label">زمان تعمیر (روز):</span><span class="info-value"><?= $ticket['normal_days'] ?> روز</span></div>
                    <?php endif; ?>
                    <div class="info-item"><span class="info-label">وضعیت:</span><span class="info-value"><span class="badge-status <?= match($ticket['status']){'pending'=>'badge-pending','in_progress'=>'badge-in_progress','waiting_part'=>'badge-waiting_part','ready'=>'badge-ready','delivered'=>'badge-delivered'} ?>"><?= $status_map[$ticket['status']] ?? $ticket['status'] ?></span></span></div>
                    <div class="info-item"><span class="info-label">جمع هزینه:</span><span class="info-value fw-bold"><?= to_toman($ticket['total_cost']) ?></span></div>
                    <div class="info-item"><span class="info-label">پرداختی:</span><span class="info-value"><?= to_toman($ticket['paid_amount']) ?></span></div>
                    <div class="info-item"><span class="info-label">تاریخ پذیرش:</span><span class="info-value"><?= htmlspecialchars($ticket['received_date_sh']) ?></span></div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <a href="edit.php?id=<?= $ticket_id ?>" class="btn btn-modern btn-sm"><i class="fas fa-edit"></i> ویرایش</a>
                    <a href="change_status.php?id=<?= $ticket_id ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-exchange-alt"></i> تغییر وضعیت</a>
                </div>
            </div>
        </div>
    </div>

    <!-- افزودن اجرت یا قطعه -->
    <div class="col-md-6">
        <div class="modern-card h-100">
            <div class="card-header-custom">
                <i class="fas fa-plus-circle"></i> افزودن اجرت یا قطعه
            </div>
            <div class="card-body">
                <form method="post" id="addItemForm">
                    <div class="mb-3">
                        <label>نوع آیتم</label>
                        <select name="item_type" id="itemType" class="form-select" required>
                            <option value="labor">اجرت تعمیر</option>
                            <option value="part">قطعه مصرفی</option>
                        </select>
                    </div>
                    <div class="mb-3 position-relative">
                        <label>توضیح / جستجوی قطعه</label>
                        <input type="text" name="description" id="descriptionField" class="form-control" placeholder="توضیح (مثل: تعمیر برد، موتور)" required>
                        <div id="productSuggestions" class="suggestions-box"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label>تعداد</label>
                            <input type="number" name="quantity" class="form-control" value="1" min="1">
                        </div>
                        <div class="col">
                            <label>قیمت واحد (تومان)</label>
                            <input type="number" name="unit_price" id="priceField" class="form-control" required>
                        </div>
                    </div>
                    <input type="hidden" name="product_id" id="selectedProductId">
                    <button type="submit" name="add_item" class="btn btn-modern w-100"><i class="fas fa-save"></i> افزودن</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- لیست اقلام -->
<div class="modern-card mt-4">
    <div class="card-header-custom">
        <i class="fas fa-list-ul"></i> لیست اجرت‌ها و قطعات مصرفی
    </div>
    <div class="card-body">
        <?php if (count($items) == 0): ?>
            <div class="alert alert-info alert-glass">هیچ آیتمی ثبت نشده است.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr><th>نوع</th><th>توضیحات</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th><th>عملیات</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= $item['item_type'] == 'labor' ? '<i class="fas fa-wrench"></i> اجرت' : '<i class="fas fa-microchip"></i> قطعه' ?></td>
                                <td><?= htmlspecialchars($item['description']) ?></td>
                                <td><?= number_format($item['quantity']) ?></td>
                                <td><?= to_toman($item['unit_price']) ?></td>
                                <td><?= to_toman($item['total_price']) ?></td>
                                <td><a href="?id=<?= $ticket_id ?>&delete_item=<?= $item['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف شود؟')"><i class="fas fa-trash-alt"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function(){
    var searchTimeout = null;
    var productSearchInput = $('#descriptionField');
    var suggestionsBox = $('#productSuggestions');
    var priceField = $('#priceField');
    var productIdField = $('#selectedProductId');
    var itemTypeSelect = $('#itemType');
    
    function searchProducts(query) {
        $.ajax({
            url: '../../ajax_search.php',
            type: 'GET',
            data: { type: 'products', query: query },
            dataType: 'json',
            success: function(data) {
                if (data.length > 0) {
                    var html = '';
                    for (var i = 0; i < data.length; i++) {
                        var p = data[i];
                        html += '<div class="suggestion-item" data-id="'+p.id+'" data-name="'+escapeHtml(p.name)+'" data-price="'+p.sale_price+'">';
                        html += '<strong>'+escapeHtml(p.name)+'</strong> - موجودی: '+p.current_stock+' - قیمت: '+formatNumber(p.sale_price)+' تومان';
                        html += '</div>';
                    }
                    suggestionsBox.html(html).show();
                } else {
                    suggestionsBox.hide();
                }
            }
        });
    }
    
    productSearchInput.off('keyup').on('keyup', function() {
        if (itemTypeSelect.val() !== 'part') {
            suggestionsBox.hide();
            return;
        }
        var query = $(this).val().trim();
        clearTimeout(searchTimeout);
        if (query.length < 2) {
            suggestionsBox.hide();
            return;
        }
        searchTimeout = setTimeout(function() {
            searchProducts(query);
        }, 300);
    });
    
    $(document).on('click', '.suggestion-item', function(e) {
        e.preventDefault();
        var name = $(this).data('name');
        var price = $(this).data('price');
        var pid = $(this).data('id');
        productSearchInput.val(name);
        priceField.val(price);
        productIdField.val(pid);
        suggestionsBox.hide();
    });
    
    $(document).click(function(e) {
        if (!$(e.target).closest('#descriptionField, #productSuggestions').length) {
            suggestionsBox.hide();
        }
    });
    
    itemTypeSelect.on('change', function() {
        if ($(this).val() === 'labor') {
            productSearchInput.attr('placeholder', 'توضیح اجرت (مثل: تعمیر برد)');
            suggestionsBox.hide();
            productIdField.val('');
            productSearchInput.off('keyup');
        } else {
            productSearchInput.attr('placeholder', 'جستجوی قطعه از انبار...');
            productSearchInput.off('keyup').on('keyup', function() {
                var query = $(this).val().trim();
                clearTimeout(searchTimeout);
                if (query.length < 2) {
                    suggestionsBox.hide();
                    return;
                }
                searchTimeout = setTimeout(function() {
                    searchProducts(query);
                }, 300);
            });
        }
    });
    
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }
});
</script>
<?php require_once '../../includes/footer.php'; ?>