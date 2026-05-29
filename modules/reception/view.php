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
        // اگر قطعه از انبار انتخاب شده، موجودی را بررسی و کم کن
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
        
        // درج آیتم تعمیر
        $ins = $db->prepare("INSERT INTO repair_items (ticket_id, item_type, description, quantity, unit_price, total_price, product_id) VALUES (?,?,?,?,?,?,?)");
        $ins->execute([$ticket_id, $item_type, $description, $quantity, $unit_price, $total_price, $product_id]);
        
        // به‌روزرسانی جمع کل هزینه در تیکت
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
        // برگرداندن موجودی به انبار
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
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">اطلاعات پذیرش</div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                <p><strong>شماره:</strong> <?= htmlspecialchars($ticket['ticket_no']) ?></p>
                <p><strong>مشتری:</strong> <?= htmlspecialchars($ticket['fullname']) ?> - <?= $ticket['mobile'] ?></p>
                <p><strong>آدرس:</strong> <?= nl2br(htmlspecialchars($ticket['address'])) ?></p>
                <p><strong>دستگاه:</strong> <?= htmlspecialchars($ticket['device_type'] . ' ' . $ticket['brand'] . ' ' . $ticket['model']) ?></p>
                <p><strong>شماره سریال:</strong> <?= htmlspecialchars($ticket['serial_no']) ?></p>
                <p><strong>خرابی:</strong> <?= nl2br(htmlspecialchars($ticket['reported_fault'])) ?></p>
                <p><strong>قطعات همراه:</strong> <?= nl2br(htmlspecialchars($ticket['accompanying_parts'])) ?></p>
                <p><strong>وضعیت ظاهری:</strong> <?= htmlspecialchars($ticket['physical_condition']) ?></p>
                <p><strong>بیعانه:</strong> <?= to_toman($ticket['deposit']) ?></p>
                <p><strong>اولویت:</strong> <?= ($ticket['priority'] == 'urgent') ? 'فوری' : 'عادی' ?></p>
                <?php if ($ticket['priority'] == 'urgent' && $ticket['urgent_deadline_sh']): ?>
                    <p><strong>تاریخ تحویل توافقی:</strong> <?= htmlspecialchars($ticket['urgent_deadline_sh']) ?></p>
                <?php elseif ($ticket['priority'] == 'normal' && $ticket['normal_days']): ?>
                    <p><strong>زمان تعمیر (روز):</strong> <?= $ticket['normal_days'] ?> روز</p>
                <?php endif; ?>
                <p><strong>وضعیت:</strong> <?= $status_map[$ticket['status']] ?? $ticket['status'] ?></p>
                <p><strong>جمع هزینه:</strong> <?= to_toman($ticket['total_cost']) ?></p>
                <p><strong>پرداختی:</strong> <?= to_toman($ticket['paid_amount']) ?></p>
                <p><strong>تاریخ پذیرش:</strong> <?= htmlspecialchars($ticket['received_date_sh']) ?></p>
                <a href="edit.php?id=<?= $ticket_id ?>" class="btn btn-warning btn-sm">ویرایش اطلاعات پذیرش</a>
                <a href="change_status.php?id=<?= $ticket_id ?>" class="btn btn-secondary btn-sm">تغییر وضعیت</a>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">افزودن اجرت یا قطعه</div>
            <div class="card-body">
                <form method="post" id="addItemForm">
                    <div class="mb-2">
                        <select name="item_type" class="form-select" id="itemType" required>
                            <option value="labor">اجرت تعمیر</option>
                            <option value="part">قطعه مصرفی</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label>توضیح</label>
                        <input type="text" name="description" id="descriptionField" class="form-control" placeholder="توضیح (مثل: تعمیر برد، موتور)" required>
                        <div id="productSuggestions" class="list-group position-absolute bg-white border" style="z-index:1000; width:100%; max-height:200px; overflow-y:auto; display:none;"></div>
                    </div>
                    <div class="row">
                        <div class="col"><input type="number" name="quantity" class="form-control" placeholder="تعداد" value="1" min="1" id="quantityField"></div>
                        <div class="col"><input type="number" name="unit_price" id="priceField" class="form-control" placeholder="قیمت واحد (تومان)" required></div>
                    </div>
                    <input type="hidden" name="product_id" id="selectedProductId" value="">
                    <button type="submit" name="add_item" class="btn btn-success mt-2">افزودن</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">لیست اجرت‌ها و قطعات مصرفی</div>
    <div class="card-body">
        <?php if (count($items) == 0): ?>
            <div class="alert alert-info">هیچ آیتمی ثبت نشده است.</div>
        <?php else: ?>
            <table class="table table-sm table-bordered">
                <thead>
                    <tr><th>نوع</th><th>توضیحات</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= $item['item_type'] == 'labor' ? 'اجرت' : 'قطعه' ?></td>
                            <td><?= htmlspecialchars($item['description']) ?></td>
                            <td><?= number_format($item['quantity']) ?></td>
                            <td><?= to_toman($item['unit_price']) ?></td>
                            <td><?= to_toman($item['total_price']) ?></td>
                            <td><a href="?id=<?= $ticket_id ?>&delete_item=<?= $item['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف شود؟')">حذف</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
                        html += '<a href="#" class="list-group-item list-group-item-action suggestion-item" data-id="'+p.id+'" data-name="'+escapeHtml(p.name)+'" data-price="'+p.sale_price+'">';
                        html += '<strong>'+escapeHtml(p.name)+'</strong> - موجودی: '+p.current_stock+' - قیمت فروش: '+formatNumber(p.sale_price)+' تومان';
                        html += '</a>';
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
            // غیر فعال کردن جستجوی انبار
            productSearchInput.off('keyup');
        } else {
            productSearchInput.attr('placeholder', 'جستجوی قطعه از انبار...');
            // دوباره فعال کردن جستجو
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