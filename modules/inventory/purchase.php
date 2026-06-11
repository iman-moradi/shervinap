<?php
$page_title = 'ثبت فاکتور خرید';
require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';
$show_preview_modal = false;
$preview_data = [];

// پردازش پیش‌نمایش
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_purchase'])) {
    $preview_data['supplier_name'] = trim($_POST['supplier_name']);
    $preview_data['invoice_date'] = $_POST['invoice_date'];
    $preview_data['account_id'] = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $preview_data['paid_amount'] = (int)str_replace(',', '', $_POST['paid_amount']);
    $preview_data['items'] = $_POST['items'];
    $preview_data['total_amount'] = 0;
    $preview_data['items_detail'] = [];
    foreach ($preview_data['items'] as $idx => $item) {
        if (empty($item['product_id']) || $item['quantity'] <= 0) continue;
        $product_id = (int)$item['product_id'];
        $qty = (int)$item['quantity'];
        $unit_price = (int)str_replace(',', '', $item['unit_price']);
        $total_price = $qty * $unit_price;
        $preview_data['total_amount'] += $total_price;
        $pstmt = $db->prepare("SELECT name FROM products WHERE id = ?");
        $pstmt->execute([$product_id]);
        $product_name = $pstmt->fetchColumn();
        $preview_data['items_detail'][] = [
            'name' => $product_name,
            'qty' => $qty,
            'price' => $unit_price,
            'total' => $total_price
        ];
    }
    if (empty($preview_data['supplier_name'])) {
        $error = 'نام تأمین‌کننده الزامی است.';
    } elseif (empty($preview_data['items_detail'])) {
        $error = 'حداقل یک قلم کالا باید وارد شود.';
    } else {
        $show_preview_modal = true;
    }
}

// پردازش نهایی پس از تایید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_purchase'])) {
    $supplier_name = trim($_POST['supplier_name']);
    $invoice_date = $_POST['invoice_date'];
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $paid_amount = (int)str_replace(',', '', $_POST['paid_amount']);
    $items = json_decode($_POST['items_json'], true);
    
    if (empty($supplier_name) || empty($items)) {
        $error = 'اطلاعات ناقص است.';
    } else {
        $db->beginTransaction();
        try {
            $invoice_no = 'PUR-' . jdate('YmdHis') . rand(100, 999);
            $total_amount = 0;
            $valid_items = [];
            foreach ($items as $item) {
                if (empty($item['product_id']) || $item['quantity'] <= 0) continue;
                $product_id = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                $unit_price = (int)str_replace(',', '', $item['unit_price']);
                if ($qty > 0 && $unit_price > 0) {
                    $total_price = $qty * $unit_price;
                    $total_amount += $total_price;
                    $valid_items[] = [
                        'product_id' => $product_id,
                        'quantity' => $qty,
                        'unit_price' => $unit_price,
                        'total_price' => $total_price
                    ];
                }
            }
            if (empty($valid_items)) throw new Exception('هیچ آیتم معتبری وجود ندارد.');
            
            $payment_status = 'unpaid';
            $final_paid = 0;
            if ($paid_amount >= $total_amount) {
                $payment_status = 'paid';
                $final_paid = $total_amount;
            } elseif ($paid_amount > 0) {
                $payment_status = 'partial';
                $final_paid = $paid_amount;
            }
            
            $stmt = $db->prepare("INSERT INTO purchase_invoices 
                (invoice_no, supplier_name, invoice_date_sh, total_amount, paid_amount, payment_status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoice_no, $supplier_name, $invoice_date, $total_amount, $final_paid, $payment_status, $_SESSION['user_id']]);
            $invoice_id = $db->lastInsertId();
            
            foreach ($valid_items as $item) {
                $insItem = $db->prepare("INSERT INTO purchase_items (purchase_invoice_id, product_id, quantity, unit_price, total_price) VALUES (?,?,?,?,?)");
                $insItem->execute([$invoice_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total_price']]);
                $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
                $db->prepare("UPDATE products SET purchase_price = ? WHERE id = ?")->execute([$item['unit_price'], $item['product_id']]);
                $mov = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id) VALUES (?, 'in', ?, ?, 'purchase_invoice', ?)");
                $mov->execute([$item['product_id'], $item['quantity'], $item['unit_price'], $invoice_id]);
            }
            
            // ثبت تراکنش حسابداری فقط در صورت پرداخت نقدی (حداقل بخشی از آن)
            if ($final_paid > 0 && $account_id) {
                $trans = $db->prepare("INSERT INTO transactions 
                    (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                    VALUES (?, ?, ?, 'expense', 'purchase', ?, ?, 'خرید کالا - پرداخت نقدی', NULL)");
                $trans->execute([$invoice_date, $account_id, $final_paid, $invoice_id, $_SESSION['user_id']]);
                $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$final_paid, $account_id]);
            }
            
            $db->commit();
            $credit_amount = $total_amount - $final_paid;
            $success = "✅ فاکتور خرید با شماره $invoice_no ثبت شد. مبلغ کل: " . number_format($total_amount) . " تومان، پرداختی: " . number_format($final_paid) . " تومان، مانده: " . number_format($credit_amount) . " تومان";
            echo '<meta http-equiv="refresh" content="2;url=purchase_invoices.php">';
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
    .global-suggestion-box {
        position: fixed !important;
        z-index: 999999 !important;
        background: white !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 12px !important;
        min-width: 300px !important;
        max-width: 500px !important;
        max-height: 280px !important;
        overflow-y: auto !important;
        box-shadow: 0 20px 35px -10px rgba(0,0,0,0.3) !important;
        display: none;
    }
    .global-suggestion-item {
        padding: 12px 15px !important;
        cursor: pointer !important;
        border-bottom: 1px solid #f1f5f9 !important;
    }
    .global-suggestion-item:hover {
        background-color: #f0f9ff !important;
    }
</style>

<div class="card">
    <div class="card-header bg-primary text-white">💰 ثبت فاکتور خرید</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        
        <form method="post" id="purchaseForm">
            <div class="row">
                <div class="col-md-4 mb-3 position-relative">
                    <label>نام تأمین‌کننده *</label>
                    <input type="text" id="supplier_search" class="form-control" placeholder="جستجوی تأمین‌کننده..." autocomplete="off">
                    <input type="hidden" name="supplier_name" id="supplier_name">
                    <div id="supplier_suggestions" class="global-suggestion-box" style="position: absolute; z-index: 1050;"></div>
                    <small class="text-muted">نام تأمین‌کننده را جستجو کنید یا مستقیم وارد کنید.</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label>تاریخ فاکتور</label>
                    <input type="text" name="invoice_date" class="form-control" value="<?= now_jalali() ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>مبلغ پرداختی نقدی (تومان)</label>
                    <input type="number" name="paid_amount" id="paid_amount" class="form-control" value="0" min="0">
                    <small class="text-muted">مبلغ نقدی پرداختی - مابقی به عنوان بدهی ثبت می‌شود.</small>
                </div>
                <div class="col-md-12 mb-3" id="account_div" style="display:none;">
                    <label>حساب پرداخت وجه</label>
                    <select name="account_id" class="form-select">
                        <option value="">--- انتخاب کنید ---</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (موجودی: <?= number_format($a['current_balance']) ?> تومان)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <h5>اقلام خرید</h5>
            <table class="table table-bordered" id="itemsTable">
                <thead>
                    <tr><th style="width:45%">کالا</th><th style="width:15%">تعداد</th><th style="width:20%">قیمت واحد (تومان)</th><th style="width:15%">جمع</th><th style="width:5%"></th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <input type="text" class="form-control product-search" placeholder="جستجوی کالا..." autocomplete="off" data-row="0">
                            <input type="hidden" name="items[0][product_id]" class="product-id">
                          </div>
                        </td>
                        <td><input type="number" name="items[0][quantity]" class="form-control qty" value="1" min="1"></td>
                        <td><input type="number" name="items[0][unit_price]" class="form-control price" value="0"></div>
                        <td class="row-total">0</div>
                        <td><button type="button" class="btn btn-danger btn-sm removeRow">حذف</button></div>
                    </tr>
                </tbody>
                <tfoot>
                    <tr><td colspan="3" class="text-end fw-bold">جمع کل: </div><td class="fw-bold text-primary fs-5" id="grandTotal">0</div><td></div></tr>
                </tfoot>
            </table>
            <button type="button" id="addRow" class="btn btn-secondary btn-sm mb-3">➕ افزودن ردیف</button>
            <hr>
            <button type="submit" name="preview_purchase" class="btn btn-primary">👁️ پیش‌نمایش و تایید</button>
            <a href="products.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>

<!-- مودال پیش‌نمایش (بدون تغییر) -->
<?php if ($show_preview_modal): ?>
<div class="modal fade show" id="previewModal" tabindex="-1" style="display:block; background-color: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">📋 تایید نهایی فاکتور خرید</h5>
                <button type="button" class="btn-close btn-close-white" onclick="window.location.href=window.location.pathname"></button>
            </div>
            <div class="modal-body">
                <p>لطفاً اطلاعات زیر را بررسی کنید:</p>
                <table class="table table-bordered">
                    <tr><th>تأمین‌کننده</th><td><?= htmlspecialchars($preview_data['supplier_name']) ?> </div></tr>
                    <tr><th>تاریخ فاکتور</th><td><?= htmlspecialchars($preview_data['invoice_date']) ?> </div></tr>
                    <tr><th>مبلغ پرداختی نقدی</th><td><?= number_format($preview_data['paid_amount']) ?> تومان</div></tr>
                </table>
                <h6>اقلام فاکتور:</h6>
                <table class="table table-sm table-bordered">
                    <thead><tr><th>کالا</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead>
                    <tbody>
                        <?php foreach ($preview_data['items_detail'] as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars($it['name']) ?> </div>
                            <td><?= number_format($it['qty']) ?> </div>
                            <td><?= number_format($it['price']) ?> </div>
                            <td><?= number_format($it['total']) ?> </div>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th colspan="3">جمع کل</th><th><?= number_format($preview_data['total_amount']) ?> تومان</th></tr></tfoot>
                </table>
                <div class="alert alert-warning">
                    <strong>توجه:</strong> در صورت نسیه بودن (پرداختی کمتر از کل)، مابقی به عنوان بدهی ثبت می‌شود و تراکنش مالی در زمان پرداخت اقساط بعدی انجام خواهد شد.
                </div>
            </div>
            <div class="modal-footer">
                <form method="post">
                    <input type="hidden" name="supplier_name" value="<?= htmlspecialchars($preview_data['supplier_name']) ?>">
                    <input type="hidden" name="invoice_date" value="<?= htmlspecialchars($preview_data['invoice_date']) ?>">
                    <input type="hidden" name="paid_amount" value="<?= $preview_data['paid_amount'] ?>">
                    <input type="hidden" name="account_id" value="<?= $preview_data['account_id'] ?>">
                    <input type="hidden" name="items_json" value='<?= htmlspecialchars(json_encode($preview_data['items'])) ?>'>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href=window.location.pathname">✏️ بازگشت و ویرایش</button>
                    <button type="submit" name="confirm_purchase" class="btn btn-success">✅ تایید و ثبت نهایی</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){
    var rowIndex = 1;
    var productGlobalBox = $('<div class="global-suggestion-box"></div>').appendTo('body');
    var currentSearchInput = null;
    var searchTimeout = null;
    var supplierTimeout = null;
    
    // ==================== جستجوی زنده تأمین‌کننده ====================
    var supplierSearch = $('#supplier_search');
    var supplierHidden = $('#supplier_name');
    var supplierBox = $('#supplier_suggestions');
    
    supplierSearch.on('keyup', function() {
        var query = $(this).val().trim();
        var inputPos = $(this).offset();
        clearTimeout(supplierTimeout);
        if (query.length < 2) {
            supplierBox.fadeOut(150);
            return;
        }
        supplierTimeout = setTimeout(function() {
            $.ajax({
                url: '../../ajax_search.php',
                type: 'GET',
                data: { type: 'suppliers', query: query },
                dataType: 'json',
                success: function(data) {
                    if (data.length > 0) {
                        var html = '';
                        for (var i=0; i<data.length; i++) {
                            html += '<div class="global-suggestion-item" data-name="'+escapeHtml(data[i].fullname)+'">';
                            html += '<strong><i class="fas fa-truck"></i> '+escapeHtml(data[i].fullname)+'</strong>';
                            if (data[i].mobile) html += '<br><small>📱 '+escapeHtml(data[i].mobile)+'</small>';
                            if (data[i].phone) html += '<small> 📞 '+escapeHtml(data[i].phone)+'</small>';
                            html += '</div>';
                        }
                        supplierBox.html(html).css({
                            top: inputPos.top + supplierSearch.outerHeight() + 5,
                            left: inputPos.left,
                            width: supplierSearch.outerWidth()
                        }).fadeIn(200);
                    } else {
                        supplierBox.fadeOut(150);
                    }
                },
                error: function() {
                    supplierBox.fadeOut(150);
                }
            });
        }, 300);
    });
    
    $(document).on('click', '#supplier_suggestions .global-suggestion-item', function(e) {
        e.preventDefault();
        var name = $(this).data('name');
        supplierSearch.val(name);
        supplierHidden.val(name);
        supplierBox.fadeOut(150);
    });
    
    // اگر کاربر مستقیم وارد کرد و از جعبه انتخاب نکرد، موقع submit مقدار را منتقل کنیم
    $('#purchaseForm').on('submit', function() {
        if (supplierHidden.val() === '' && supplierSearch.val() !== '') {
            supplierHidden.val(supplierSearch.val());
        }
        $('.price').each(function() { $(this).val(parseNumber($(this).val())); });
        $('#paid_amount').val(parseNumber($('#paid_amount').val()));
    });
    
    $(document).click(function(e) {
        if (!$(e.target).closest('#supplier_search, #supplier_suggestions').length) {
            supplierBox.fadeOut(150);
        }
        if (!$(e.target).closest('.product-search, .global-suggestion-box').length) {
            productGlobalBox.fadeOut(150);
            currentSearchInput = null;
        }
    });
    
    // ==================== توابع کمکی ====================
    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }
    function parseNumber(str) {
        if (!str) return 0;
        return parseInt(String(str).replace(/,/g, '')) || 0;
    }
    function updateRowTotal(row) {
        var qty = parseInt(row.find('.qty').val()) || 0;
        var price = parseNumber(row.find('.price').val());
        var total = qty * price;
        row.find('.row-total').text(formatNumber(total));
        calcGrandTotal();
    }
    function calcGrandTotal() {
        var sum = 0;
        $('.row-total').each(function() { sum += parseNumber($(this).text()); });
        $('#grandTotal').text(formatNumber(sum));
        $('#paid_amount').attr('max', sum);
        var paid = parseNumber($('#paid_amount').val());
        if(paid > 0) $('#account_div').slideDown(200);
        else $('#account_div').slideUp(200);
    }
    
    // ==================== جستجوی محصولات ====================
    function attachProductSearch(input) {
        input.off('keyup').on('keyup', function(e) {
            var query = $(this).val().trim();
            var inputPos = $(this).offset();
            currentSearchInput = this;
            if(query.length < 2) { productGlobalBox.fadeOut(150); return; }
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                $.ajax({
                    url: '../../ajax_search.php',
                    type: 'GET',
                    data: { type: 'products', query: query },
                    dataType: 'json',
                    success: function(data) {
                        if(data.length > 0) {
                            var html = '';
                            for(var i=0; i<data.length; i++) {
                                html += '<div class="global-suggestion-item" data-id="'+data[i].id+'" data-name="'+escapeHtml(data[i].name)+'" data-price="'+data[i].purchase_price+'">';
                                html += '<strong>'+escapeHtml(data[i].name)+'</strong><br><small>موجودی: '+data[i].current_stock+' - قیمت خرید: '+formatNumber(data[i].purchase_price)+' تومان</small>';
                                html += '</div>';
                            }
                            productGlobalBox.html(html).css({
                                top: inputPos.top + input.outerHeight() + 5,
                                left: inputPos.left,
                                width: input.outerWidth()
                            }).fadeIn(200);
                        } else { productGlobalBox.fadeOut(150); }
                    }
                });
            }, 300);
        });
    }
    
    $(document).on('click', '.global-suggestion-item', function(e) {
        e.preventDefault();
        if(currentSearchInput) {
            var row = $(currentSearchInput).closest('tr');
            $(currentSearchInput).val($(this).data('name'));
            row.find('.product-id').val($(this).data('id'));
            row.find('.price').val($(this).data('price'));
            productGlobalBox.fadeOut(150);
            updateRowTotal(row);
        }
    });
    
    function attachEvents() {
        $('.product-search').each(function() { attachProductSearch($(this)); });
        $('.qty').off('keyup change').on('keyup change', function() { updateRowTotal($(this).closest('tr')); });
        $('.price').off('keyup change').on('keyup change', function() { updateRowTotal($(this).closest('tr')); });
        $('.removeRow').off('click').on('click', function() {
            if($('#itemsTable tbody tr').length > 1) $(this).closest('tr').remove();
            else alert('حداقل یک ردیف باید باقی بماند');
            calcGrandTotal();
        });
    }
    
    $('#addRow').click(function() {
        var newRow = $('#itemsTable tbody tr:first').clone();
        newRow.find('.product-search').val('');
        newRow.find('.product-id').val('');
        newRow.find('.qty').val(1);
        newRow.find('.price').val(0);
        newRow.find('.row-total').text('0');
        newRow.find('.product-id').attr('name', 'items['+rowIndex+'][product_id]');
        newRow.find('.qty').attr('name', 'items['+rowIndex+'][quantity]');
        newRow.find('.price').attr('name', 'items['+rowIndex+'][unit_price]');
        $('#itemsTable tbody').append(newRow);
        attachProductSearch(newRow.find('.product-search'));
        newRow.find('.qty').on('keyup change', function() { updateRowTotal($(this).closest('tr')); });
        newRow.find('.price').on('keyup change', function() { updateRowTotal($(this).closest('tr')); });
        newRow.find('.removeRow').click(function() { if($('#itemsTable tbody tr').length > 1) $(this).closest('tr').remove(); else alert('حداقل یک ردیف باید باقی بماند'); calcGrandTotal(); });
        rowIndex++;
    });
    
    $('#paid_amount').on('keyup change', calcGrandTotal);
    
    function escapeHtml(str) {
        if(!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if(m === '&') return '&amp;';
            if(m === '<') return '&lt;';
            if(m === '>') return '&gt;';
            return m;
        });
    }
    
    attachEvents();
    calcGrandTotal();
});
</script>

<?php require_once '../../includes/footer.php'; ?>