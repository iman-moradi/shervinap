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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_purchase'])) {
    $supplier_name = trim($_POST['supplier_name']);
    $invoice_date = $_POST['invoice_date'];
    $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $paid_amount = (int)str_replace(',', '', $_POST['paid_amount']);
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
            
            if (empty($valid_items)) {
                throw new Exception('هیچ آیتم معتبری وجود ندارد.');
            }
            
            if ($paid_amount >= $total_amount) {
                $payment_status = 'paid';
                $paid_amount = $total_amount;
            } elseif ($paid_amount > 0) {
                $payment_status = 'partial';
            } else {
                $payment_status = 'unpaid';
            }
            
            $stmt = $db->prepare("INSERT INTO purchase_invoices 
                (invoice_no, supplier_name, invoice_date_sh, total_amount, paid_amount, payment_status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoice_no, $supplier_name, $invoice_date, $total_amount, $paid_amount, $payment_status, $_SESSION['user_id']]);
            $invoice_id = $db->lastInsertId();
            
            foreach ($valid_items as $item) {
                $insItem = $db->prepare("INSERT INTO purchase_items (purchase_invoice_id, product_id, quantity, unit_price, total_price) VALUES (?,?,?,?,?)");
                $insItem->execute([$invoice_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['total_price']]);
                $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
                $db->prepare("UPDATE products SET purchase_price = ? WHERE id = ?")->execute([$item['unit_price'], $item['product_id']]);
                $mov = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id) VALUES (?, 'in', ?, ?, 'purchase_invoice', ?)");
                $mov->execute([$item['product_id'], $item['quantity'], $item['unit_price'], $invoice_id]);
            }
            
            if ($paid_amount > 0 && $account_id) {
                $trans = $db->prepare("INSERT INTO transactions 
                    (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                    VALUES (?, ?, ?, 'expense', 'purchase', ?, ?, 'خرید کالا - پرداخت نقدی', NULL)");
                $trans->execute([$invoice_date, $account_id, $paid_amount, $invoice_id, $_SESSION['user_id']]);
                $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$paid_amount, $account_id]);
            }
            
            $credit_amount = $total_amount - $paid_amount;
            
            $db->commit();
            $success = "✅ فاکتور خرید با شماره $invoice_no ثبت شد. مبلغ کل: " . number_format($total_amount) . " تومان، پرداختی: " . number_format($paid_amount) . " تومان، مانده: " . number_format($credit_amount) . " تومان";
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
    .modern-card .card-body {
        padding: 1.5rem !important;
    }
    
    /* استایل جعبه جستجو - متصل به body */
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
        transition: all 0.2s ease !important;
        background: white !important;
    }
    .global-suggestion-item:hover {
        background-color: #f0f9ff !important;
    }
    .global-suggestion-item:last-child {
        border-bottom: none !important;
    }
    .global-suggestion-item strong {
        color: #1e293b !important;
        font-size: 0.95rem !important;
        display: block !important;
        margin-bottom: 4px !important;
    }
    .global-suggestion-item small {
        color: #64748b !important;
        font-size: 0.75rem !important;
        display: block !important;
    }
    
    /* جعبه جستجوی تأمین‌کننده - متصل به body */
    #supplierGlobalBox {
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
    
    /* استایل جدول - اطمینان از overflow visible */
    .table-responsive {
        overflow-x: auto !important;
        overflow-y: visible !important;
    }
    .table, tbody, tr, td {
        overflow: visible !important;
    }
    td {
        position: static !important;
    }
    
    /* استایل فیلدها */
    .form-control, .form-select {
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 10px 14px;
        transition: all 0.2s ease;
    }
    .form-control:focus, .form-select:focus {
        border-color: #0ea5e9;
        box-shadow: 0 0 0 3px rgba(14,165,233,0.1);
        outline: none;
    }
    
    .card-header-custom {
        background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%);
        color: white;
        padding: 16px 20px;
        font-size: 1.1rem;
        font-weight: 600;
    }
    
    .btn-modern {
        background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%);
        border: none;
        padding: 10px 24px;
        border-radius: 30px;
        color: white;
        transition: all 0.3s ease;
    }
    .btn-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(14,165,233,0.3);
        color: white;
    }
    
    .table th, .table td {
        vertical-align: middle;
        padding: 12px 10px;
    }
    .row-total {
        font-weight: 700;
        color: #0d6efd;
        background-color: #f8fafc;
        text-align: center;
    }
    .table thead th {
        background-color: #f1f5f9;
        font-weight: 600;
        border-bottom: 2px solid #e2e8f0;
    }
    .table tfoot td {
        background-color: #f8fafc;
        font-weight: 600;
        border-top: 2px solid #e2e8f0;
    }
    .alert-glass {
        background: rgba(255,255,255,0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        border-right: 5px solid;
    }
</style>

<div class="modern-card">
    <div class="card-header-custom">
        <i class="fas fa-shopping-cart"></i> ثبت فاکتور خرید
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger alert-glass"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success alert-glass"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        
        <form method="post" id="purchaseForm">
            <div class="row g-3">
                <div class="col-md-5 mb-3">
                    <label class="form-label fw-semibold">نام تأمین‌کننده *</label>
                    <input type="text" id="supplierSearch" class="form-control" placeholder="جستجوی تأمین‌کننده..." autocomplete="off">
                    <input type="hidden" name="supplier_name" id="supplierName">
                    <small class="text-muted">نام تأمین‌کننده را جستجو کنید یا مستقیم وارد کنید.</small>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-semibold">تاریخ فاکتور</label>
                    <input type="text" name="invoice_date" class="form-control" value="<?= now_jalali() ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-semibold">مبلغ پرداختی نقدی (تومان)</label>
                    <input type="text" name="paid_amount" id="paid_amount" class="form-control" value="0">
                    <small class="text-muted">مبلغ نقدی پرداختی - مابقی به عنوان بدهی ثبت می‌شود.</small>
                </div>
                <div class="col-md-12 mb-3" id="account_div" style="display:none;">
                    <label class="form-label fw-semibold">حساب پرداخت وجه</label>
                    <select name="account_id" class="form-select">
                        <option value="">--- انتخاب کنید ---</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (موجودی: <?= number_format($a['current_balance']) ?> تومان)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <h5 class="mt-4 mb-3"><i class="fas fa-boxes text-primary"></i> اقلام خرید</h5>
            <div class="table-responsive">
                <table class="table table-bordered" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width:45%">کالا</th>
                            <th style="width:15%">تعداد</th>
                            <th style="width:20%">قیمت واحد (تومان)</th>
                            <th style="width:15%">جمع ردیف</th>
                            <th style="width:5%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <input type="text" class="form-control product-search" placeholder="جستجوی کالا..." autocomplete="off" data-row="0">
                                <input type="hidden" name="items[0][product_id]" class="product-id">
                              </td>
                              <td><input type="number" name="items[0][quantity]" class="form-control qty" value="1" min="1" step="1"></td>
                              <td><input type="text" name="items[0][unit_price]" class="form-control price" value="0" style="text-align:left"></td>
                              <td class="row-total">0</td>
                              <td><button type="button" class="btn btn-outline-danger btn-sm removeRow"><i class="fas fa-trash-alt"></i></button></td>
                          </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end fw-bold">جمع کل: </td>
                            <td class="fw-bold text-primary fs-5" id="grandTotal">0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <button type="button" id="addRow" class="btn btn-outline-secondary btn-sm mb-3"><i class="fas fa-plus"></i> افزودن ردیف جدید</button>
            
            <div class="mt-4 d-flex gap-3">
                <button type="submit" name="submit_purchase" class="btn btn-modern"><i class="fas fa-save"></i> ثبت فاکتور</button>
                <a href="products.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-right"></i> بازگشت</a>
            </div>
        </form>
    </div>
</div>

<!-- جعبه جستجوی جهانی برای کالاها -->
<div id="productGlobalBox" class="global-suggestion-box"></div>

<!-- جعبه جستجوی جهانی برای تأمین‌کنندگان -->
<div id="supplierGlobalBox" class="global-suggestion-box"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){
    var rowIndex = 1;
    var searchTimeout = null;
    var currentSearchInput = null;
    
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
        $('.row-total').each(function() {
            sum += parseNumber($(this).text());
        });
        $('#grandTotal').text(formatNumber(sum));
        $('#paid_amount').attr('max', sum);
    }
    
    // ========================================
    // جستجوی تأمین‌کننده با جعبه جهانی
    // ========================================
    var supplierSearch = $('#supplierSearch');
    var supplierGlobalBox = $('#supplierGlobalBox');
    var supplierNameField = $('#supplierName');
    
    supplierSearch.on('keyup', function(e) {
        var query = $(this).val().trim();
        var inputPos = $(this).offset();
        
        clearTimeout(searchTimeout);
        if (query.length < 2) {
            supplierGlobalBox.fadeOut(150);
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
                            html += '<div class="global-suggestion-item" data-name="'+escapeHtml(data[i].fullname)+'">';
                            html += '<strong><i class="fas fa-truck"></i> '+escapeHtml(data[i].fullname)+'</strong>';
                            html += '<small><i class="fas fa-mobile-alt"></i> '+escapeHtml(data[i].mobile)+'</small>';
                            html += '</div>';
                        }
                        supplierGlobalBox.html(html).css({
                            'top': inputPos.top + supplierSearch.outerHeight() + 5,
                            'left': inputPos.left,
                            'width': supplierSearch.outerWidth()
                        }).fadeIn(200);
                    } else {
                        supplierGlobalBox.fadeOut(150);
                    }
                }
            });
        }, 300);
    });
    
    $(document).on('click', '#supplierGlobalBox .global-suggestion-item', function(e) {
        e.preventDefault();
        var name = $(this).data('name');
        supplierSearch.val(name);
        supplierNameField.val(name);
        supplierGlobalBox.fadeOut(150);
    });
    
    $('#purchaseForm').on('submit', function() {
        if (supplierNameField.val() === '' && supplierSearch.val() !== '') {
            supplierNameField.val(supplierSearch.val());
        }
        $('.price').each(function() {
            $(this).val(parseNumber($(this).val()));
        });
        $('#paid_amount').val(parseNumber($('#paid_amount').val()));
    });
    
    $(document).click(function(e) {
        if (!$(e.target).closest('#supplierSearch, #supplierGlobalBox').length) {
            supplierGlobalBox.fadeOut(150);
        }
        if (!$(e.target).closest('.product-search, #productGlobalBox').length) {
            $('#productGlobalBox').fadeOut(150);
            currentSearchInput = null;
        }
    });
    
    $('#paid_amount').on('keyup change', function(){
        var paid = parseNumber($(this).val());
        if(paid > 0){
            $('#account_div').slideDown(200);
            $('select[name="account_id"]').prop('required', true);
        } else {
            $('#account_div').slideUp(200);
            $('select[name="account_id"]').prop('required', false).val('');
        }
    }).trigger('keyup');
    
    // ========================================
    // جستجوی کالا با جعبه جهانی
    // ========================================
    var productGlobalBox = $('#productGlobalBox');
    
    function attachProductSearch(input) {
        input.off('keyup').on('keyup', function(e) {
            var query = $(this).val().trim();
            var inputPos = $(this).offset();
            var row = $(this).closest('tr');
            
            currentSearchInput = this;
            
            if (query.length < 2) { 
                productGlobalBox.fadeOut(150);
                return; 
            }
            
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
                                html += '<div class="global-suggestion-item" data-id="'+data[i].id+'" data-name="'+escapeHtml(data[i].name)+'" data-price="'+data[i].purchase_price+'">';
                                html += '<strong><i class="fas fa-cube"></i> '+escapeHtml(data[i].name)+'</strong>';
                                html += '<small><i class="fas fa-database"></i> موجودی: '+data[i].current_stock+' - <i class="fas fa-tag"></i> قیمت: '+formatNumber(data[i].purchase_price)+' تومان</small>';
                                html += '</div>';
                            }
                            productGlobalBox.html(html).css({
                                'top': inputPos.top + input.outerHeight() + 5,
                                'left': inputPos.left,
                                'width': input.outerWidth()
                            }).fadeIn(200);
                        } else { 
                            productGlobalBox.fadeOut(150);
                        }
                    }
                });
            }, 300);
        });
    }
    
    $(document).on('click', '#productGlobalBox .global-suggestion-item', function(e) {
        e.preventDefault();
        if (currentSearchInput) {
            var row = $(currentSearchInput).closest('tr');
            $(currentSearchInput).val($(this).data('name'));
            row.find('.product-id').val($(this).data('id'));
            row.find('.price').val(formatNumber($(this).data('price')));
            productGlobalBox.fadeOut(150);
            updateRowTotal(row);
        }
    });
    
    // ========================================
    // مدیریت ردیف‌های جدول
    // ========================================
    function attachEvents() {
        $('.product-search').each(function() {
            attachProductSearch($(this));
        });
        
        $('.qty').off('keyup change').on('keyup change', function() {
            updateRowTotal($(this).closest('tr'));
        });
        
        $('.price').off('keyup change').on('keyup change', function() {
            var val = $(this).val().replace(/[^0-9]/g, '');
            if (val && parseInt(val) > 0) {
                $(this).val(formatNumber(parseInt(val)));
            } else {
                $(this).val('0');
            }
            updateRowTotal($(this).closest('tr'));
        });
        
        $('.removeRow').off('click').on('click', function() {
            if ($('#itemsTable tbody tr').length > 1) {
                $(this).closest('tr').fadeOut(200, function() {
                    $(this).remove();
                    calcGrandTotal();
                    // ریست currentSearchInput اگر حذف شده باشد
                    if (currentSearchInput && !$(currentSearchInput).closest('tr').length) {
                        currentSearchInput = null;
                        productGlobalBox.fadeOut(150);
                    }
                });
            } else { 
                alert('حداقل یک ردیف باید باقی بماند'); 
            }
        });
    }
    
    $('#addRow').click(function() {
        var newRow = $('#itemsTable tbody tr:first').clone();
        newRow.find('.product-search').val('').attr('data-row', rowIndex);
        newRow.find('.product-id').val('');
        newRow.find('.qty').val(1);
        newRow.find('.price').val('0');
        newRow.find('.row-total').text('0');
        newRow.find('.product-id').attr('name', 'items['+rowIndex+'][product_id]');
        newRow.find('.qty').attr('name', 'items['+rowIndex+'][quantity]');
        newRow.find('.price').attr('name', 'items['+rowIndex+'][unit_price]');
        newRow.hide().appendTo('#itemsTable tbody').fadeIn(200);
        
        // attach events به فیلد جستجوی جدید
        attachProductSearch(newRow.find('.product-search'));
        newRow.find('.qty').on('keyup change', function() {
            updateRowTotal($(this).closest('tr'));
        });
        newRow.find('.price').on('keyup change', function() {
            var val = $(this).val().replace(/[^0-9]/g, '');
            if (val && parseInt(val) > 0) {
                $(this).val(formatNumber(parseInt(val)));
            } else {
                $(this).val('0');
            }
            updateRowTotal($(this).closest('tr'));
        });
        newRow.find('.removeRow').on('click', function() {
            if ($('#itemsTable tbody tr').length > 1) {
                $(this).closest('tr').fadeOut(200, function() {
                    $(this).remove();
                    calcGrandTotal();
                });
            } else { 
                alert('حداقل یک ردیف باید باقی بماند'); 
            }
        });
        
        rowIndex++;
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
    
    attachEvents();
    calcGrandTotal();
});
</script>

<?php require_once '../../includes/footer.php'; ?>