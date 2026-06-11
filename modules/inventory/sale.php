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
$show_preview_modal = false;
$preview_data = [];

// پردازش پیش‌نمایش
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_sale'])) {
    $preview_data['customer_id'] = (int)$_POST['customer_id'];
    $preview_data['invoice_date'] = $_POST['invoice_date'];
    $preview_data['account_id'] = (int)$_POST['account_id'];
    $preview_data['paid_amount'] = (int)$_POST['paid_amount'];
    $preview_data['items'] = $_POST['items'];
    $preview_data['due_date'] = $_POST['due_date'] ?? '';
    $preview_data['customer_name'] = '';
    if ($preview_data['customer_id']) {
        $stmt = $db->prepare("SELECT fullname FROM customers WHERE id = ?");
        $stmt->execute([$preview_data['customer_id']]);
        $preview_data['customer_name'] = $stmt->fetchColumn();
    }
    $preview_data['total_amount'] = 0;
    $preview_data['items_detail'] = [];
    foreach ($preview_data['items'] as $idx => $item) {
        if (empty($item['product_id']) || $item['quantity'] <= 0) continue;
        $product_id = (int)$item['product_id'];
        $qty = (int)$item['quantity'];
        $unit_price = (int)$item['unit_price'];
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
    if (empty($preview_data['customer_id'])) {
        $error = 'لطفاً مشتری را انتخاب کنید.';
    } elseif (empty($preview_data['items_detail'])) {
        $error = 'حداقل یک قلم کالا باید وارد شود.';
    } else {
        $show_preview_modal = true;
    }
}

// پردازش نهایی پس از تایید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_sale'])) {
    $customer_id = (int)$_POST['customer_id'];
    $invoice_date = $_POST['invoice_date'];
    $account_id = (int)$_POST['account_id'];
    $paid_amount = (int)$_POST['paid_amount'];
    $items = json_decode($_POST['items_json'], true);
    $due_date = $_POST['due_date'] ?? '';
    
    if (!$customer_id) {
        $error = 'لطفاً مشتری را انتخاب کنید.';
    } elseif (empty($items) || !is_array($items)) {
        $error = 'حداقل یک قلم کالا باید وارد شود.';
    } else {
        $db->beginTransaction();
        try {
            $invoice_no = 'SAL-' . jdate('YmdHis') . rand(100, 999);
            $stmt = $db->prepare("INSERT INTO sales_invoices 
                (invoice_no, customer_id, invoice_date_sh, total_amount, paid_amount, account_id, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$invoice_no, $customer_id, $invoice_date, 0, $paid_amount, $account_id, $_SESSION['user_id']]);
            $invoice_id = $db->lastInsertId();
            
            $total_amount = 0;
            foreach ($items as $item) {
                $product_id = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                $unit_price = (int)$item['unit_price'];
                $total_price = $qty * $unit_price;
                $total_amount += $total_price;
                
                $stockCheck = $db->prepare("SELECT current_stock FROM products WHERE id = ?");
                $stockCheck->execute([$product_id]);
                $current_stock = $stockCheck->fetchColumn();
                if ($current_stock < $qty) {
                    throw new Exception("موجودی کالا (شناسه $product_id) کافی نیست. موجودی: $current_stock");
                }
                
                $insItem = $db->prepare("INSERT INTO sales_items (sales_invoice_id, product_id, quantity, unit_price, total_price) VALUES (?,?,?,?,?)");
                $insItem->execute([$invoice_id, $product_id, $qty, $unit_price, $total_price]);
                $db->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?")->execute([$qty, $product_id]);
                $mov = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id) VALUES (?, 'out', ?, ?, 'sales_invoice', ?)");
                $mov->execute([$product_id, $qty, $unit_price, $invoice_id]);
            }
            
            $db->prepare("UPDATE sales_invoices SET total_amount = ? WHERE id = ?")->execute([$total_amount, $invoice_id]);
            
            $is_cash_sale = ($paid_amount >= $total_amount);
            if ($is_cash_sale) {
                $trans = $db->prepare("INSERT INTO transactions 
                    (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                    VALUES (?, ?, ?, 'income', 'sale', ?, ?, 'فروش کالا - نقدی', NULL)");
                $trans->execute([$invoice_date, $account_id, $total_amount, $invoice_id, $_SESSION['user_id']]);
                $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$total_amount, $account_id]);
            } else {
                $due_date_sh = !empty($due_date) ? $due_date : jdate('Y/m/d', strtotime('+30 days'));
                $credit_stmt = $db->prepare("INSERT INTO credit_sales 
                    (customer_id, invoice_no, sale_date_sh, total_amount, paid_amount, due_date_sh, status, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, 'partial', ?)");
                $credit_stmt->execute([$customer_id, $invoice_no, $invoice_date, $total_amount, $paid_amount, $due_date_sh, $_SESSION['user_id']]);
            }
            
            $db->commit();
            $success = "✅ فاکتور فروش با شماره $invoice_no ثبت شد. مبلغ کل: " . number_format($total_amount) . " تومان";
            echo '<meta http-equiv="refresh" content="2;url=products.php">';
        } catch (Exception $e) {
            $db->rollBack();
            $error = "❌ خطا در ثبت: " . $e->getMessage();
        }
    }
}

$products = $db->query("SELECT id, name, current_stock, sale_price FROM products WHERE current_stock > 0 ORDER BY name")->fetchAll();
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
    .customer-info {
        background-color: #e9ecef;
        border: 1px solid #ced4da;
        border-radius: 6px;
        padding: 8px 12px;
        color: #212529;
        font-size: 0.9rem;
        display: inline-block;
        width: 100%;
    }
    .customer-info strong,
    #selected_customer_name {
        color: #212529;
    }
    /* استایل dialog ثبت مشتری جدید */
    dialog {
        border: none;
        border-radius: 16px;
        padding: 0;
        width: 500px;
        max-width: 90%;
        box-shadow: 0 20px 35px -10px rgba(0,0,0,0.3);
    }
    dialog::backdrop {
        background-color: rgba(0,0,0,0.5);
    }
    .dialog-header {
        background: #0d6efd;
        color: white;
        padding: 15px 20px;
        border-radius: 16px 16px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .dialog-header h5 {
        margin: 0;
    }
    .dialog-header button {
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
    }
    .dialog-body {
        padding: 20px;
    }
    .dialog-footer {
        padding: 15px 20px;
        border-top: 1px solid #e2e8f0;
        text-align: left;
        direction: ltr;
    }
    .btn-close-dialog {
        background-color: #6c757d;
        color: white;
        border: none;
        padding: 6px 15px;
        border-radius: 8px;
        margin-left: 10px;
    }
    .btn-save-dialog {
        background-color: #0d6efd;
        color: white;
        border: none;
        padding: 6px 15px;
        border-radius: 8px;
    }
</style>

<div class="card">
    <div class="card-header bg-success text-white">💰 ثبت فاکتور فروش</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        
        <form method="post" id="saleForm">
            <div class="row">
                <div class="col-md-4 mb-3 position-relative">
                    <label>شماره موبایل مشتری *</label>
                    <input type="text" id="mobile_search" class="form-control" placeholder="جستجوی مشتری با شماره موبایل..." autocomplete="off" required>
                    <input type="hidden" name="customer_id" id="customer_id" value="">
                    <div id="customer_suggestions" class="global-suggestion-box"></div>
                    <!-- بلوک نمایش اطلاعات مشتری -->
                    <div id="customer_info_div" style="display:none; margin-top: 10px;">
                        <div class="customer-info">
                            <strong>مشتری انتخاب شده:</strong> <span id="selected_customer_name"></span>
                        </div>
                    </div>
                    <small class="text-muted">شماره موبایل مشتری را وارد کنید، اگر یافت نشد گزینه ثبت مشتری جدید نمایش داده می‌شود.</small>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label>تاریخ فاکتور</label>
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
                    <input type="number" name="paid_amount" id="paid_amount" class="form-control" value="0" min="0">
                    <small class="text-muted">در صورت پرداخت نقدی یا کارت خوان</small>
                </div>
                <div class="col-md-3 mb-3" id="dueDateDiv" style="display:none;">
                    <label>تاریخ سررسید (فروش نسیه)</label>
                    <input type="text" name="due_date" class="form-control" value="<?= jdate('Y/m/d', strtotime('+30 days')) ?>">
                </div>
            </div>
            
            <h5>اقلام فروش</h5>
            <table class="table table-bordered table-sm" id="itemsTable">
                <thead class="table-light">
                    <tr><th>کالا</th><th>تعداد</th><th>قیمت واحد (تومان)</th><th>جمع</th><th></th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="position-relative">
                            <input type="text" class="form-control product-search" placeholder="جستجوی کالا..." autocomplete="off">
                            <input type="hidden" name="items[0][product_id]" class="product-id">
                            <div class="suggestion-box"></div>
                         </div>
                        <td><input type="number" name="items[0][quantity]" class="form-control qty" value="1" min="1" required> </div>
                        <td><input type="number" name="items[0][unit_price]" class="form-control price" value="0" min="0" required> </div>
                        <td class="row-total">0</div>
                        <td><button type="button" class="btn btn-danger btn-sm removeRow">حذف</button></div>
                    </tr>
                </tbody>
            </table>
            <button type="button" id="addRow" class="btn btn-secondary btn-sm mb-3">➕ افزودن ردیف</button>
            <hr>
            <h4>جمع کل: <span id="grandTotal">0</span> تومان</h4>
            <button type="submit" name="preview_sale" class="btn btn-primary mt-3" id="previewBtn">👁️ پیش‌نمایش و تایید</button>
            <a href="products.php" class="btn btn-secondary mt-3">🔙 بازگشت</a>
        </form>
    </div>
</div>

<!-- dialog ثبت مشتری جدید (به جای مودال بوت‌استرپ) -->
<dialog id="newCustomerDialog">
    <div class="dialog-header">
        <h5>➕ ثبت مشتری جدید</h5>
        <button id="closeDialogBtn" class="btn-close-custom">&times;</button>
    </div>
    <div class="dialog-body">
        <form id="newCustomerForm">
            <div class="mb-2">
                <label>نام کامل *</label>
                <input type="text" id="new_fullname" class="form-control" required>
            </div>
            <div class="mb-2">
                <label>شماره موبایل *</label>
                <input type="text" id="new_mobile" class="form-control" readonly>
            </div>
            <div class="mb-2">
                <label>تلفن ثابت</label>
                <input type="text" id="new_phone" class="form-control">
            </div>
            <div class="mb-2">
                <label>آدرس</label>
                <textarea id="new_address" class="form-control" rows="2"></textarea>
            </div>
            <div class="mb-2">
                <label>توضیحات</label>
                <textarea id="new_description" class="form-control" rows="2"></textarea>
            </div>
        </form>
    </div>
    <div class="dialog-footer">
        <button type="button" class="btn-close-dialog" id="cancelDialogBtn">انصراف</button>
        <button type="button" class="btn-save-dialog" id="saveNewCustomerBtn">ذخیره و انتخاب</button>
    </div>
</dialog>

<!-- مودال پیش‌نمایش (همان مودال قبلی) -->
<?php if ($show_preview_modal): ?>
<div class="modal fade show" id="previewModal" tabindex="-1" style="display:block; background-color: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">📋 تایید نهایی فاکتور فروش</h5>
                <button type="button" class="btn-close btn-close-white" onclick="window.location.href=window.location.pathname"></button>
            </div>
            <div class="modal-body">
                <p>لطفاً اطلاعات زیر را به دقت بررسی کنید:</p>
                <table class="table table-bordered">
                    <tr><th style="width:30%">مشتری</th><td><?= htmlspecialchars($preview_data['customer_name'] ?: 'عمومی') ?> </div></tr>
                    <tr><th>تاریخ فاکتور</th><td><?= htmlspecialchars($preview_data['invoice_date']) ?> </div></tr>
                    <tr><th>حساب دریافت</th><td><?= htmlspecialchars($accounts[array_search($preview_data['account_id'], array_column($accounts, 'id'))]['account_name'] ?? 'نامشخص') ?> </div></tr>
                    <tr><th>مبلغ پرداختی</th><td><?= number_format($preview_data['paid_amount']) ?> تومان</div></tr>
                    <?php if ($preview_data['paid_amount'] < $preview_data['total_amount'] && $preview_data['customer_id']): ?>
                    <tr><th>تاریخ سررسید (نسیه)</th><td><?= htmlspecialchars($preview_data['due_date']) ?> </div></tr>
                    <?php endif; ?>
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
                <div class="alert alert-warning text-center">
                    <strong>⚠️ توجه:</strong> با تایید این فاکتور، عملیات در سیستم ثبت شده و در صورت نسیه بودن، تراکنش مالی فقط در زمان دریافت اقساط ثبت خواهد شد.
                </div>
            </div>
            <div class="modal-footer">
                <form method="post">
                    <input type="hidden" name="customer_id" value="<?= $preview_data['customer_id'] ?>">
                    <input type="hidden" name="invoice_date" value="<?= htmlspecialchars($preview_data['invoice_date']) ?>">
                    <input type="hidden" name="account_id" value="<?= $preview_data['account_id'] ?>">
                    <input type="hidden" name="paid_amount" value="<?= $preview_data['paid_amount'] ?>">
                    <input type="hidden" name="due_date" value="<?= htmlspecialchars($preview_data['due_date']) ?>">
                    <input type="hidden" name="items_json" value='<?= htmlspecialchars(json_encode($preview_data['items'])) ?>'>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href=window.location.pathname">✏️ بازگشت و ویرایش</button>
                    <button type="submit" name="confirm_sale" class="btn btn-success">✅ تایید و ثبت نهایی</button>
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
    var searchTimeout = null;
    var currentSearchInput = null;
    var productGlobalBox = $('<div class="global-suggestion-box"></div>').appendTo('body');
    var customerSearchTimeout = null;
    
    // ========== المان‌های dialog ==========
    var dialog = document.getElementById('newCustomerDialog');
    var closeDialogBtn = document.getElementById('closeDialogBtn');
    var cancelDialogBtn = document.getElementById('cancelDialogBtn');
    var saveNewCustomerBtn = document.getElementById('saveNewCustomerBtn');
    
    function openDialog() {
        dialog.showModal();
    }
    function closeDialog() {
        dialog.close();
    }
    if (closeDialogBtn) closeDialogBtn.onclick = closeDialog;
    if (cancelDialogBtn) cancelDialogBtn.onclick = closeDialog;
    // کلیک روی backdrop بسته شود
    dialog.addEventListener('click', function(e) {
        if (e.target === dialog) closeDialog();
    });
    
    // ========== جستجوی مشتری با موبایل ==========
    var mobileSearch = $('#mobile_search');
    var customerSuggestions = $('#customer_suggestions');
    var customerIdField = $('#customer_id');
    var customerInfoDiv = $('#customer_info_div');
    var selectedCustomerNameSpan = $('#selected_customer_name');
    
    mobileSearch.on('keyup', function() {
        var query = $(this).val().trim();
        var inputPos = $(this).offset();
        clearTimeout(customerSearchTimeout);
        if (query.length < 2) {
            customerSuggestions.fadeOut(150);
            return;
        }
        customerSearchTimeout = setTimeout(function() {
            $.ajax({
                url: '../../ajax_search.php',
                type: 'GET',
                data: { type: 'customers_by_mobile', query: query },
                dataType: 'json',
                success: function(data) {
                    var html = '';
                    if (data.length > 0) {
                        for (var i=0; i<data.length; i++) {
                            html += '<div class="global-suggestion-item" data-id="'+data[i].id+'" data-name="'+escapeHtml(data[i].fullname)+'" data-mobile="'+escapeHtml(data[i].mobile)+'">';
                            html += '<strong>'+escapeHtml(data[i].fullname)+'</strong><br><small>📱 '+escapeHtml(data[i].mobile)+'</small>';
                            if (data[i].phone) html += ' - 📞 '+escapeHtml(data[i].phone);
                            html += '</div>';
                        }
                    }
                    // همیشه گزینه ثبت مشتری جدید را اضافه کن
                    html += '<div class="global-suggestion-item" id="newCustomerOption" style="background-color:#e9ecef; text-align:center;">';
                    html += '<strong><i class="fas fa-plus-circle"></i> مشتری جدید با شماره '+escapeHtml(query)+' ثبت کنید</strong>';
                    html += '</div>';
                    customerSuggestions.html(html).css({
                        top: inputPos.top + mobileSearch.outerHeight() + 5,
                        left: inputPos.left,
                        width: mobileSearch.outerWidth()
                    }).fadeIn(200);
                },
                error: function() {
                    customerSuggestions.fadeOut(150);
                }
            });
        }, 400);
    });
    
    // انتخاب مشتری موجود یا کلیک روی گزینه جدید
    $(document).on('click', '#customer_suggestions .global-suggestion-item', function(e) {
        var id = $(this).data('id');
        if (id) { // مشتری موجود
            var name = $(this).data('name');
            var mobile = $(this).data('mobile');
            customerIdField.val(id);
            mobileSearch.val(mobile);
            selectedCustomerNameSpan.text(name);
            customerInfoDiv.fadeIn();
            customerSuggestions.fadeOut();
        } else { // گزینه "مشتری جدید"
            var newMobile = mobileSearch.val().trim();
            if (newMobile.length === 0) {
                alert('لطفاً شماره موبایل را وارد کنید.');
                return;
            }
            $('#new_mobile').val(newMobile);
            $('#new_fullname').val('');
            $('#new_phone').val('');
            $('#new_address').val('');
            $('#new_description').val('');
            openDialog();
            customerSuggestions.fadeOut();
        }
    });
    
    // ذخیره مشتری جدید
    saveNewCustomerBtn.onclick = function() {
        var fullname = $('#new_fullname').val().trim();
        var mobile = $('#new_mobile').val().trim();
        var phone = $('#new_phone').val().trim();
        var address = $('#new_address').val().trim();
        var description = $('#new_description').val().trim();
        if (!fullname || !mobile) {
            alert('نام کامل و شماره موبایل الزامی است.');
            return;
        }
        $.ajax({
            url: '../../ajax_add_customer.php',
            type: 'POST',
            data: { fullname: fullname, mobile: mobile, phone: phone, address: address, description: description },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    customerIdField.val(res.customer_id);
                    mobileSearch.val(mobile);
                    selectedCustomerNameSpan.text(fullname);
                    customerInfoDiv.fadeIn();
                    closeDialog();
                    alert('مشتری با موفقیت ثبت شد.');
                } else {
                    alert('خطا در ثبت مشتری: ' + (res.message || 'نامشخص'));
                }
            },
            error: function(xhr, status, error) {
                // نمایش جزئیات خطا
                var errorMsg = 'خطا در ارتباط با سرور';
                if (xhr.responseText) {
                    try {
                        var jsonResp = JSON.parse(xhr.responseText);
                        errorMsg = jsonResp.message || errorMsg;
                    } catch(e) {
                        errorMsg = xhr.responseText.substring(0, 200);
                    }
                }
                alert('خطا: ' + errorMsg);
            }
        });
    };
    
    // ========== محصولات ==========
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
        var paidAmount = parseNumber($('#paid_amount').val());
        var hasCustomer = (customerIdField.val() != '');
        if (hasCustomer && paidAmount < sum) {
            $('#dueDateDiv').show();
        } else {
            $('#dueDateDiv').hide();
        }
    }
    
    function attachProductSearch(input) {
        input.off('keyup').on('keyup', function(e) {
            var query = $(this).val().trim();
            var inputPos = $(this).offset();
            currentSearchInput = this;
            if (query.length < 2) { productGlobalBox.fadeOut(150); return; }
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
                            for (var i=0; i<data.length; i++) {
                                html += '<div class="global-suggestion-item" data-id="'+data[i].id+'" data-name="'+escapeHtml(data[i].name)+'" data-price="'+data[i].sale_price+'">';
                                html += '<strong>'+escapeHtml(data[i].name)+'</strong><br><small>موجودی: '+data[i].current_stock+' - قیمت: '+formatNumber(data[i].sale_price)+' تومان</small>';
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
        if ($(this).closest('#customer_suggestions').length) return;
        e.preventDefault();
        if (currentSearchInput) {
            var row = $(currentSearchInput).closest('tr');
            $(currentSearchInput).val($(this).data('name'));
            row.find('.product-id').val($(this).data('id'));
            row.find('.price').val($(this).data('price'));
            productGlobalBox.fadeOut(150);
            updateRowTotal(row);
        }
    });
    
    $(document).click(function(e) {
        if (!$(e.target).closest('#mobile_search, #customer_suggestions').length) {
            customerSuggestions.fadeOut(150);
        }
        if (!$(e.target).closest('.product-search, .global-suggestion-box').length) {
            productGlobalBox.fadeOut(150);
            currentSearchInput = null;
        }
    });
    
    function attachEvents() {
        $('.product-search').each(function() { attachProductSearch($(this)); });
        $('.qty').off('keyup change').on('keyup change', function() { updateRowTotal($(this).closest('tr')); });
        $('.price').off('keyup change').on('keyup change', function() { updateRowTotal($(this).closest('tr')); });
        $('.removeRow').off('click').on('click', function() {
            if ($('#itemsTable tbody tr').length > 1) $(this).closest('tr').remove();
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
        newRow.find('.removeRow').click(function() { if ($('#itemsTable tbody tr').length > 1) $(this).closest('tr').remove(); else alert('حداقل یک ردیف باید باقی بماند'); calcGrandTotal(); });
        rowIndex++;
    });
    
    $('#paid_amount').on('keyup change', calcGrandTotal);
    
    $('#previewBtn').click(function(e) {
        if (!customerIdField.val()) {
            e.preventDefault();
            alert('لطفاً ابتدا مشتری را با جستجوی شماره موبایل انتخاب کنید.');
            return false;
        }
        return true;
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