<?php
$page_title = 'ثبت فاکتور خرید';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_purchase'])) {
    $supplier_name = trim($_POST['supplier_name']);
    $invoice_date = $_POST['invoice_date'];
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $paid_amount = (int)$_POST['paid_amount'];
    $items = $_POST['items'];
    
    if (empty($supplier_name)) {
        $error = 'نام تأمین‌کننده الزامی است.';
    } elseif (empty($items) || !is_array($items)) {
        $error = 'حداقل یک قلم کالا باید وارد شود.';
    } else {
        $db->beginTransaction();
        try {
            $invoice_no = 'PUR-' . jdate('YmdHis') . rand(100, 999);
            $total_amount = 0;
            
            // محاسبه جمع کل
            foreach ($items as $item) {
                if (empty($item['product_id']) || $item['quantity'] <= 0) continue;
                $qty = (int)$item['quantity'];
                $unit_price = (int)$item['unit_price'];
                $total_amount += $qty * $unit_price;
            }
            
            // تعیین وضعیت پرداخت
            if ($paid_amount >= $total_amount) {
                $payment_status = 'paid';
                $paid_amount = $total_amount;
            } elseif ($paid_amount > 0) {
                $payment_status = 'partial';
            } else {
                $payment_status = 'unpaid';
            }
            
            // درج فاکتور
            $stmt = $db->prepare("INSERT INTO purchase_invoices 
                (invoice_no, supplier_name, invoice_date_sh, total_amount, paid_amount, payment_status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoice_no, $supplier_name, $invoice_date, $total_amount, $paid_amount, $payment_status, $_SESSION['user_id']]);
            $invoice_id = $db->lastInsertId();
            
            // ثبت آیتم‌ها و افزایش موجودی
            foreach ($items as $item) {
                if (empty($item['product_id']) || $item['quantity'] <= 0) continue;
                $product_id = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                $unit_price = (int)$item['unit_price'];
                $total_price = $qty * $unit_price;
                
                $insItem = $db->prepare("INSERT INTO purchase_items (purchase_invoice_id, product_id, quantity, unit_price, total_price) VALUES (?,?,?,?,?)");
                $insItem->execute([$invoice_id, $product_id, $qty, $unit_price, $total_price]);
                $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?")->execute([$qty, $product_id]);
                $db->prepare("UPDATE products SET purchase_price = ? WHERE id = ?")->execute([$unit_price, $product_id]);
                $mov = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id) VALUES (?, 'in', ?, ?, 'purchase_invoice', ?)");
                $mov->execute([$product_id, $qty, $unit_price, $invoice_id]);
            }
            
            // ثبت تراکنش حسابداری فقط در صورت پرداخت نقدی
            if ($paid_amount > 0 && $account_id) {
                $trans = $db->prepare("INSERT INTO transactions (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description) 
                                       VALUES (?, ?, ?, 'expense', 'purchase', ?, ?, 'خرید کالا - پرداخت نقدی')");
                $trans->execute([$invoice_date, $account_id, $paid_amount, $invoice_id, $_SESSION['user_id']]);
                $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$paid_amount, $account_id]);
            }
            
            $db->commit();
            $success = "✅ فاکتور خرید با شماره $invoice_no ثبت شد. مبلغ کل: " . number_format($total_amount) . " تومان، پرداختی: " . number_format($paid_amount) . " تومان";
            echo '<meta http-equiv="refresh" content="2;url=products.php">';
        } catch (Exception $e) {
            $db->rollBack();
            $error = "❌ خطا در ثبت: " . $e->getMessage();
        }
    }
}

$products = $db->query("SELECT id, name, current_stock, purchase_price FROM products ORDER BY name")->fetchAll();
$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
?>
<style>
    .suggestion-box { position: absolute; z-index: 1000; background: white; border: 1px solid #ccc; width: 100%; max-height: 200px; overflow-y: auto; display: none; }
    .suggestion-item { padding: 8px; cursor: pointer; border-bottom: 1px solid #eee; }
    .suggestion-item:hover { background-color: #f0f0f0; }
</style>
<div class="card">
    <div class="card-header">📦 ثبت فاکتور خرید</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        
        <form method="post" id="purchaseForm">
            <div class="row">
                <div class="col-md-4 mb-3 position-relative">
                    <label>نام تأمین‌کننده *</label>
                    <input type="text" id="supplierSearch" class="form-control" placeholder="جستجوی تأمین‌کننده..." autocomplete="off">
                    <input type="hidden" name="supplier_name" id="supplierName">
                    <div id="supplierSuggestions" class="suggestion-box"></div>
                    <small class="text-muted">نام تأمین‌کننده را جستجو کنید یا در صورت جدید بودن، مستقیم وارد کنید.</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label>تاریخ فاکتور (مثال 1402/10/15)</label>
                    <input type="text" name="invoice_date" class="form-control" value="<?= now_jalali() ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>مبلغ پرداختی (تومان) - در صورت نقدی</label>
                    <input type="number" name="paid_amount" id="paid_amount" class="form-control" value="0" min="0">
                    <small class="text-muted">اگر کل مبلغ نقدی پرداخت می‌شود، مبلغ را وارد کنید. در غیر این صورت 0 بگذارید (نسیه).</small>
                </div>
                <div class="col-md-12 mb-3" id="account_div" style="display:none;">
                    <label>حساب پرداخت وجه (برای مبلغ نقدی)</label>
                    <select name="account_id" class="form-select">
                        <option value="">--- انتخاب کنید (در صورت نسیه نیازی نیست) ---</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (موجودی: <?= number_format($a['current_balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <h5>اقلام خرید</h5>
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
            <button type="submit" name="submit_purchase" class="btn btn-primary mt-3">💾 ثبت فاکتور</button>
            <a href="products.php" class="btn btn-secondary mt-3">🔙 بازگشت</a>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    var rowIndex = 1;
    var searchTimeout = null;
    
    // ==================== جستجوی تأمین‌کننده ====================
    var supplierSearch = $('#supplierSearch');
    var supplierSuggestions = $('#supplierSuggestions');
    var supplierNameField = $('#supplierName');
    
    supplierSearch.on('keyup', function() {
        var query = $(this).val().trim();
        clearTimeout(searchTimeout);
        if (query.length < 2) {
            supplierSuggestions.hide();
            return;
        }
        searchTimeout = setTimeout(function() {
            $.ajax({
                url: '../../ajax_search.php',
                type: 'GET',
                data: { type: 'suppliers', query: query },
                dataType: 'json',
                success: function(data) {
                    if (data.length > 0) {
                        var html = '';
                        for (var i = 0; i < data.length; i++) {
                            html += '<div class="suggestion-item" data-name="'+escapeHtml(data[i].fullname)+'">';
                            html += '<strong>'+escapeHtml(data[i].fullname)+'</strong><br>';
                            html += '<small>موبایل: '+escapeHtml(data[i].mobile)+' | تلفن: '+escapeHtml(data[i].phone)+'</small>';
                            html += '</div>';
                        }
                        supplierSuggestions.html(html).show();
                    } else {
                        supplierSuggestions.hide();
                    }
                }
            });
        }, 300);
    });
    
    $(document).on('click', '#supplierSuggestions .suggestion-item', function(e) {
        e.preventDefault();
        var name = $(this).data('name');
        supplierSearch.val(name);
        supplierNameField.val(name);
        supplierSuggestions.hide();
    });
    
    // اگر کاربر نام جدید وارد کرد و از لیست انتخاب نکرد
    $('#purchaseForm').on('submit', function() {
        if (supplierNameField.val() === '' && supplierSearch.val() !== '') {
            $('#supplierName').val(supplierSearch.val());
        }
    });
    
    $(document).click(function(e) {
        if (!$(e.target).closest('#supplierSearch, #supplierSuggestions').length) {
            supplierSuggestions.hide();
        }
    });
    
    // ==================== مدیریت نمایش فیلد حساب بر اساس مبلغ پرداختی ====================
    $('#paid_amount').on('keyup change', function(){
        var paid = parseInt($(this).val()) || 0;
        if(paid > 0){
            $('#account_div').show();
            $('select[name="account_id"]').prop('required', true);
        } else {
            $('#account_div').hide();
            $('select[name="account_id"]').prop('required', false).val('');
        }
    }).trigger('keyup');
    
    // ==================== جستجوی کالا (همان کد قبلی) ====================
    function attachEvents() {
        $('.product-search').off('keyup').on('keyup', function() {
            var input = $(this);
            var box = input.siblings('.suggestion-box');
            var query = input.val().trim();
            if (query.length < 2) { box.hide(); return; }
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                $.ajax({
                    url: '../../ajax_search.php',
                    type: 'GET',
                    data: { type: 'products', query: query },
                    dataType: 'json',
                    success: function(data) {
                        if (data.length > 0) {
                            var html = '';
                            for (var i = 0; i < data.length; i++) {
                                html += '<div class="suggestion-item" data-id="'+data[i].id+'" data-name="'+escapeHtml(data[i].name)+'" data-price="'+data[i].purchase_price+'">';
                                html += escapeHtml(data[i].name) + ' (موجودی: '+data[i].current_stock+') - قیمت خرید: '+formatNumber(data[i].purchase_price)+' تومان';
                                html += '</div>';
                            }
                            box.html(html).show();
                        } else { box.hide(); }
                    }
                });
            }, 300);
        });
        
        $(document).off('click', '.suggestion-item').on('click', '.suggestion-item', function(e) {
            e.preventDefault();
            var row = $(this).closest('tr');
            row.find('.product-search').val($(this).data('name'));
            row.find('.product-id').val($(this).data('id'));
            row.find('.price').val($(this).data('price'));
            row.find('.suggestion-box').hide();
            updateRowTotal(row);
        });
        
        $('.qty, .price').off('keyup').on('keyup', function() {
            updateRowTotal($(this).closest('tr'));
        });
        
        $('.removeRow').off('click').on('click', function() {
            if ($('#itemsTable tbody tr').length > 1) {
                $(this).closest('tr').remove();
                calcGrandTotal();
            } else { alert('حداقل یک ردیف باید باقی بماند'); }
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
            sum += parseInt($(this).text().replace(/,/g, '')) || 0;
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
    
    $(document).click(function(e) {
        if (!$(e.target).closest('.product-search, .suggestion-box').length) {
            $('.suggestion-box').hide();
        }
    });
});
</script>
<?php require_once '../../includes/footer.php'; ?>