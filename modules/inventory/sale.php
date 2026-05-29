<?php
$page_title = 'ثبت فاکتور فروش';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

// پردازش فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_sale'])) {
    $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $invoice_date = $_POST['invoice_date'];
    $account_id = (int)$_POST['account_id'];
    $paid_amount = (int)$_POST['paid_amount'];
    $items = $_POST['items']; // آرایه‌ای از product_id, quantity, unit_price
    
    if (empty($items) || !is_array($items)) {
        $error = 'حداقل یک قلم کالا باید وارد شود.';
    } else {
        $db->beginTransaction();
        try {
            // ایجاد فاکتور فروش
            $invoice_no = 'SAL-' . jdate('YmdHis') . rand(100, 999);
            $stmt = $db->prepare("INSERT INTO sales_invoices (invoice_no, customer_id, invoice_date_sh, total_amount, paid_amount, account_id, created_by) 
                                   VALUES (?, ?, ?, 0, ?, ?, ?)");
            $stmt->execute([$invoice_no, $customer_id, $invoice_date, $paid_amount, $account_id, $_SESSION['user_id']]);
            $invoice_id = $db->lastInsertId();
            
            $total_amount = 0;
            foreach ($items as $item) {
                if (empty($item['product_id']) || $item['quantity'] <= 0) continue;
                $product_id = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                $unit_price = (int)$item['unit_price'];
                $total_price = $qty * $unit_price;
                $total_amount += $total_price;
                
                // بررسی موجودی کافی
                $stockCheck = $db->prepare("SELECT current_stock FROM products WHERE id = ?");
                $stockCheck->execute([$product_id]);
                $current_stock = $stockCheck->fetchColumn();
                if ($current_stock < $qty) {
                    throw new Exception("موجودی کالا (شناسه $product_id) کافی نیست. موجودی: $current_stock");
                }
                
                // ثبت آیتم فروش
                $insItem = $db->prepare("INSERT INTO sales_items (sales_invoice_id, product_id, quantity, unit_price, total_price) VALUES (?,?,?,?,?)");
                $insItem->execute([$invoice_id, $product_id, $qty, $unit_price, $total_price]);
                
                // کاهش موجودی
                $db->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?")->execute([$qty, $product_id]);
                
                // ثبت گردش موجودی
                $mov = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id) VALUES (?, 'out', ?, ?, 'sales_invoice', ?)");
                $mov->execute([$product_id, $qty, $unit_price, $invoice_id]);
            }
            
            // بروزرسانی مبلغ کل فاکتور
            $db->prepare("UPDATE sales_invoices SET total_amount = ? WHERE id = ?")->execute([$total_amount, $invoice_id]);
            
            // ثبت سند حسابداری (درآمد)
            $trans = $db->prepare("INSERT INTO transactions (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description) 
                                   VALUES (?, ?, ?, 'income', 'sale', ?, ?, 'فروش کالا')");
            $trans->execute([$invoice_date, $account_id, $total_amount, $invoice_id, $_SESSION['user_id']]);
            
            // به‌روزرسانی موجودی حساب
            $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$total_amount, $account_id]);
            
            $db->commit();
            $success = "✅ فاکتور فروش با شماره $invoice_no ثبت شد. مبلغ کل: " . number_format($total_amount) . " تومان";
            // پاک کردن فرم (می‌توان redirect کرد)
            echo '<meta http-equiv="refresh" content="2;url=products.php">';
        } catch (Exception $e) {
            $db->rollBack();
            $error = "❌ خطا در ثبت: " . $e->getMessage();
        }
    }
}

// دریافت لیست محصولات، حساب‌ها و مشتریان
$products = $db->query("SELECT id, name, current_stock, sale_price FROM products WHERE current_stock > 0 ORDER BY name")->fetchAll();
$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
$customers = $db->query("SELECT id, fullname, mobile FROM customers ORDER BY fullname")->fetchAll();
?>
<style>
    .suggestion-box {
        position: absolute;
        z-index: 1000;
        background: white;
        border: 1px solid #ccc;
        width: 100%;
        max-height: 200px;
        overflow-y: auto;
        display: none;
    }
    .suggestion-item {
        padding: 8px;
        cursor: pointer;
        border-bottom: 1px solid #eee;
    }
    .suggestion-item:hover {
        background-color: #f0f0f0;
    }
</style>
<div class="card">
    <div class="card-header">💰 ثبت فاکتور فروش</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        
        <form method="post" id="saleForm">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label>مشتری (اختیاری)</label>
                    <select name="customer_id" class="form-select">
                        <option value="">بدون مشتری (فروش عمومی)</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['fullname']) ?> (<?= $c['mobile'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label>تاریخ فاکتور (مثال 1402/10/15)</label>
                    <input type="text" name="invoice_date" class="form-control" value="<?= now_jalali() ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>حساب دریافت وجه *</label>
                    <select name="account_id" class="form-select" required>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (موجودی: <?= number_format($a['current_balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label>مبلغ پرداختی (تومان)</label>
                    <input type="number" name="paid_amount" class="form-control" value="0">
                    <small class="text-muted">در صورت پرداخت نقدی یا کارت خوان</small>
                </div>
            </div>
            
            <h5>اقلام فروش</h5>
            <table class="table table-bordered table-sm" id="itemsTable">
                <thead>
                    <tr><th>کالا</th><th>تعداد</th><th>قیمت واحد (تومان)</th><th>جمع</th><th></th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="position-relative">
                            <input type="text" class="form-control product-search" placeholder="جستجوی کالا..." autocomplete="off">
                            <input type="hidden" name="items[0][product_id]" class="product-id">
                            <div class="suggestion-box"></div>
                        </td>
                        <td><input type="number" name="items[0][quantity]" class="form-control qty" value="1" min="1" required></td>
                        <td><input type="number" name="items[0][unit_price]" class="form-control price" value="0" min="0" required></td>
                        <td class="row-total">0</td>
                        <td><button type="button" class="btn btn-danger btn-sm removeRow">حذف</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" id="addRow" class="btn btn-secondary btn-sm mb-3">➕ افزودن ردیف</button>
            <hr>
            <h4>جمع کل: <span id="grandTotal">0</span> تومان</h4>
            <button type="submit" name="submit_sale" class="btn btn-primary mt-3">💾 ثبت فاکتور</button>
            <a href="products.php" class="btn btn-secondary mt-3">🔙 بازگشت</a>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    var rowIndex = 1;
    
    function attachEvents() {
        // جستجوی زنده برای هر ردیف
        $('.product-search').off('keyup').on('keyup', function() {
            var input = $(this);
            var box = input.siblings('.suggestion-box');
            var query = input.val().trim();
            if (query.length < 2) {
                box.hide();
                return;
            }
            $.ajax({
                url: '../../ajax_search.php',
                type: 'GET',
                data: { type: 'products', query: query },
                dataType: 'json',
                success: function(data) {
                    if (data.length > 0) {
                        var html = '';
                        for (var i=0; i<data.length; i++) {
                            html += '<div class="suggestion-item" data-id="'+data[i].id+'" data-name="'+escapeHtml(data[i].name)+'" data-price="'+data[i].sale_price+'">';
                            html += escapeHtml(data[i].name) + ' (موجودی: '+data[i].current_stock+') - قیمت: '+formatNumber(data[i].sale_price)+' تومان';
                            html += '</div>';
                        }
                        box.html(html).show();
                    } else {
                        box.hide();
                    }
                }
            });
        });
        
        // انتخاب محصول از جعبه پیشنهاد
        $(document).off('click', '.suggestion-item').on('click', '.suggestion-item', function(e) {
            e.preventDefault();
            var row = $(this).closest('tr');
            var id = $(this).data('id');
            var name = $(this).data('name');
            var price = $(this).data('price');
            row.find('.product-search').val(name);
            row.find('.product-id').val(id);
            row.find('.price').val(price);
            row.find('.suggestion-box').hide();
            updateRowTotal(row);
        });
        
        // محاسبه جمع هر ردیف
        $('.qty, .price').off('keyup').on('keyup', function() {
            updateRowTotal($(this).closest('tr'));
        });
        
        // حذف ردیف
        $('.removeRow').off('click').on('click', function() {
            if ($('#itemsTable tbody tr').length > 1) {
                $(this).closest('tr').remove();
                calcGrandTotal();
            } else {
                alert('حداقل یک ردیف باید باقی بماند');
            }
        });
    }
    
    function updateRowTotal(row) {
        var qty = parseInt(row.find('.qty').val()) || 0;
        var price = parseInt(row.find('.price').val()) || 0;
        var total = qty * price;
        row.find('.row-total').text(total.toLocaleString());
        calcGrandTotal();
    }
    
    function calcGrandTotal() {
        var sum = 0;
        $('.row-total').each(function() {
            var val = $(this).text().replace(/,/g, '');
            sum += parseInt(val) || 0;
        });
        $('#grandTotal').text(sum.toLocaleString());
    }
    
    $('#addRow').click(function() {
        var newRow = $('#itemsTable tbody tr:first').clone();
        newRow.find('.product-search').val('');
        newRow.find('.product-id').val('');
        newRow.find('.qty').val(1);
        newRow.find('.price').val(0);
        newRow.find('.row-total').text('0');
        newRow.find('.suggestion-box').hide();
        // تغییر name attributes برای آرایه
        newRow.find('.product-id').attr('name', 'items['+rowIndex+'][product_id]');
        newRow.find('.qty').attr('name', 'items['+rowIndex+'][quantity]');
        newRow.find('.price').attr('name', 'items['+rowIndex+'][unit_price]');
        $('#itemsTable tbody').append(newRow);
        rowIndex++;
        attachEvents();
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
    
    attachEvents();
    
    // کلیک خارج از باکس پیشنهاد، آن را ببندد
    $(document).click(function(e) {
        if (!$(e.target).closest('.product-search, .suggestion-box').length) {
            $('.suggestion-box').hide();
        }
    });
});
</script>
<?php require_once '../../includes/footer.php'; ?>