<?php
$page_title = 'مرجوعی و تعویض کالا';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';
$sale_invoice = null;
$sale_items = [];
$mode = 'return';

// --- جستجوی فاکتور فروش ---
if (isset($_GET['invoice_no']) && !empty($_GET['invoice_no'])) {
    $invoice_no = $_GET['invoice_no'];
    $stmt = $db->prepare("SELECT s.*, c.fullname as customer_name 
                          FROM sales_invoices s 
                          LEFT JOIN customers c ON c.id = s.customer_id 
                          WHERE s.invoice_no = ?");
    $stmt->execute([$invoice_no]);
    $sale_invoice = $stmt->fetch();
    
    if ($sale_invoice) {
        $stmt_items = $db->prepare("SELECT si.*, p.name as product_name, p.current_stock 
                                     FROM sales_items si 
                                     JOIN products p ON p.id = si.product_id 
                                     WHERE si.sales_invoice_id = ?");
        $stmt_items->execute([$sale_invoice['id']]);
        $sale_items = $stmt_items->fetchAll();
    } else {
        $error = "فاکتور فروش با شماره '$invoice_no' یافت نشد.";
    }
}

// --- پردازش فرم مرجوعی ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_id = (int)$_POST['invoice_id'];
    $return_reason = trim($_POST['return_reason']);
    $refund_account_id = (int)$_POST['refund_account_id'];
    $refund_amount = 0;
    $mode = $_POST['mode'];
    
    $stmt = $db->prepare("SELECT * FROM sales_invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $original_invoice = $stmt->fetch();
    
    if (!$original_invoice) {
        $error = "فاکتور اصلی یافت نشد.";
    } else {
        $db->beginTransaction();
        try {
            // ========== 1. برگشت کالا به انبار ==========
            $returned_items = [];
            foreach ($_POST['return_qty'] as $item_id => $qty_return) {
                $qty_return = (int)$qty_return;
                if ($qty_return <= 0) continue;
                
                $stmt_item = $db->prepare("SELECT si.*, p.purchase_price, p.name 
                                           FROM sales_items si 
                                           JOIN products p ON p.id = si.product_id 
                                           WHERE si.id = ?");
                $stmt_item->execute([$item_id]);
                $sale_item = $stmt_item->fetch();
                
                if ($sale_item && $qty_return <= $sale_item['quantity']) {
                    $return_amount = $qty_return * $sale_item['unit_price'];
                    $refund_amount += $return_amount;
                    
                    $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?")
                       ->execute([$qty_return, $sale_item['product_id']]);
                    
                    $db->prepare("INSERT INTO stock_movements 
                        (product_id, movement_type, quantity, price, ref_type, ref_id) 
                        VALUES (?, 'in', ?, ?, 'sales_return', ?)")
                       ->execute([$sale_item['product_id'], $qty_return, $sale_item['unit_price'], $original_invoice['id']]);
                    
                    $returned_items[] = [
                        'product_id' => $sale_item['product_id'],
                        'quantity' => $qty_return,
                        'unit_price' => $sale_item['unit_price'],
                        'return_amount' => $return_amount
                    ];
                }
            }
            
            if (empty($returned_items)) {
                throw new Exception("هیچ کالایی برای برگشت انتخاب نشده است.");
            }
            
            // ========== 2. ثبت تراکنش مالی برگشت وجه ==========
            $refund_date = now_jalali();
            $trans = $db->prepare("INSERT INTO transactions 
                (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                VALUES (?, ?, ?, 'expense', 'refund', ?, ?, 'برگشت وجه بابت مرجوعی کالا', NULL)");
            $trans->execute([$refund_date, $refund_account_id, $refund_amount, $original_invoice['id'], $_SESSION['user_id']]);
            
            $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")
               ->execute([$refund_amount, $refund_account_id]);
            
            // ========== 3. تعویض کالا ==========
            $exchange_total = 0;
            $exchange_invoice_id = null;
            
            if ($mode == 'exchange' && isset($_POST['exchange_product']) && is_array($_POST['exchange_product'])) {
                $exchange_invoice_no = 'EXC-' . jdate('YmdHis') . rand(100, 999);
                $stmt_exc = $db->prepare("INSERT INTO sales_invoices 
                    (invoice_no, customer_id, invoice_date_sh, total_amount, paid_amount, account_id, created_by) 
                    VALUES (?, ?, ?, 0, 0, ?, ?)");
                $stmt_exc->execute([$exchange_invoice_no, $original_invoice['customer_id'], $refund_date, $refund_account_id, $_SESSION['user_id']]);
                $exchange_invoice_id = $db->lastInsertId();
                
                foreach ($_POST['exchange_product'] as $exc_data) {
                    $product_id = (int)$exc_data['product_id'];
                    $qty = (int)$exc_data['quantity'];
                    $unit_price = (int)$exc_data['unit_price'];
                    
                    if ($qty <= 0 || $unit_price <= 0 || $product_id <= 0) continue;
                    
                    // بررسی موجودی کافی (با خطای دقیق)
                    $stockCheck = $db->prepare("SELECT current_stock, name FROM products WHERE id = ?");
                    $stockCheck->execute([$product_id]);
                    $product_info = $stockCheck->fetch();
                    
                    if (!$product_info) {
                        throw new Exception("کالای جایگزین یافت نشد.");
                    }
                    if ($product_info['current_stock'] < $qty) {
                        throw new Exception("موجودی کالای '{$product_info['name']}' کافی نیست. موجودی فعلی: {$product_info['current_stock']} عدد");
                    }
                    
                    $total_price = $qty * $unit_price;
                    $exchange_total += $total_price;
                    
                    $insItem = $db->prepare("INSERT INTO sales_items 
                        (sales_invoice_id, product_id, quantity, unit_price, total_price) 
                        VALUES (?,?,?,?,?)");
                    $insItem->execute([$exchange_invoice_id, $product_id, $qty, $unit_price, $total_price]);
                    
                    $db->prepare("UPDATE products SET current_stock = current_stock - ? WHERE id = ?")
                       ->execute([$qty, $product_id]);
                    
                    $db->prepare("INSERT INTO stock_movements 
                        (product_id, movement_type, quantity, price, ref_type, ref_id) 
                        VALUES (?, 'out', ?, ?, 'sales_invoice', ?)")
                       ->execute([$product_id, $qty, $unit_price, $exchange_invoice_id]);
                }
                
                if ($exchange_total > 0) {
                    $db->prepare("UPDATE sales_invoices SET total_amount = ? WHERE id = ?")
                       ->execute([$exchange_total, $exchange_invoice_id]);
                    
                    $trans_exc = $db->prepare("INSERT INTO transactions 
                        (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                        VALUES (?, ?, ?, 'income', 'sale', ?, ?, 'فروش کالا - تعویض', NULL)");
                    $trans_exc->execute([$refund_date, $refund_account_id, $exchange_total, $exchange_invoice_id, $_SESSION['user_id']]);
                    
                    $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")
                       ->execute([$exchange_total, $refund_account_id]);
                }
            }
            
            // ========== 4. محاسبه و ثبت مابه‌تفاوت نهایی ==========
            $difference = $exchange_total - $refund_amount;
            if ($mode == 'exchange' && $difference != 0) {
                if ($difference > 0) {
                    // مشتری باید مبلغ اضافی پرداخت کند → ثبت تراکنش درآمد اضافی
                    $trans_diff = $db->prepare("INSERT INTO transactions 
                        (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                        VALUES (?, ?, ?, 'income', 'exchange_settlement', ?, ?, 'تسویه مابه‌تفاوت تعویض (پرداخت مشتری)', NULL)");
                    $trans_diff->execute([$refund_date, $refund_account_id, $difference, $original_invoice['id'], $_SESSION['user_id']]);
                    $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")
                       ->execute([$difference, $refund_account_id]);
                } else {
                    // باید به مشتری وجه برگردانده شود (مبلغ برگشتی بیشتر است) → ثبت تراکنش هزینه اضافی
                    $diff_amount = abs($difference);
                    $trans_diff = $db->prepare("INSERT INTO transactions 
                        (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                        VALUES (?, ?, ?, 'expense', 'exchange_settlement', ?, ?, 'تسویه مابه‌تفاوت تعویض (برگشت به مشتری)', NULL)");
                    $trans_diff->execute([$refund_date, $refund_account_id, $diff_amount, $original_invoice['id'], $_SESSION['user_id']]);
                    $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")
                       ->execute([$diff_amount, $refund_account_id]);
                }
            }
            
            $db->commit();
            
            $message = "✅ مرجوعی با موفقیت ثبت شد. مبلغ برگشتی: " . number_format($refund_amount) . " تومان";
            if ($mode == 'exchange') {
                $message .= "<br>✅ فاکتور تعویض با شماره $exchange_invoice_no ثبت شد. مبلغ کل: " . number_format($exchange_total) . " تومان";
                if ($difference > 0) {
                    $message .= "<br>💰 مبلغ قابل پرداخت توسط مشتری: " . number_format($difference) . " تومان";
                } elseif ($difference < 0) {
                    $message .= "<br>💰 مبلغ قابل برگشت به مشتری: " . number_format(abs($difference)) . " تومان";
                } else {
                    $message .= "<br>🎯 تسویه نهایی: مبلغ دقیقاً برابر است.";
                }
            }
            $success = $message;
            $sale_invoice = null;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "❌ خطا در ثبت مرجوعی: " . $e->getMessage();
        }
    }
}

$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
$products = $db->query("SELECT id, name, current_stock, sale_price FROM products WHERE current_stock > 0 ORDER BY name")->fetchAll();
?>

<style>
    .return-item-row {
        background-color: #fff3cd;
        border-left: 3px solid #ffc107;
        margin-bottom: 10px;
        padding: 10px;
        border-radius: 5px;
    }
    .exchange-item-row {
        background-color: #d1ecf1;
        border-left: 3px solid #17a2b8;
        margin-bottom: 10px;
        padding: 10px;
        border-radius: 5px;
    }
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
    <div class="card-header bg-warning text-dark">
        <i class="fas fa-undo-alt"></i> مرجوعی و تعویض کالا
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        
        <!-- فرم جستجوی فاکتور -->
        <?php if (!$sale_invoice): ?>
        <form method="get" class="row g-3 mb-4">
            <div class="col-md-8">
                <label class="form-label">شماره فاکتور فروش</label>
                <input type="text" name="invoice_no" class="form-control" placeholder="مثال: SAL-14020315123456789" required>
            </div>
            <div class="col-md-4 align-self-end">
                <button type="submit" class="btn btn-primary">🔍 جستجوی فاکتور</button>
            </div>
        </form>
        <?php endif; ?>
        
        <!-- نمایش اطلاعات فاکتور و فرم مرجوعی -->
        <?php if ($sale_invoice): ?>
        <div class="alert alert-info">
            <div class="row">
                <div class="col-md-4"><strong>شماره فاکتور:</strong> <?= htmlspecialchars($sale_invoice['invoice_no']) ?></div>
                <div class="col-md-4"><strong>مشتری:</strong> <?= htmlspecialchars($sale_invoice['customer_name'] ?: 'مشتری عمومی') ?></div>
                <div class="col-md-4"><strong>تاریخ:</strong> <?= htmlspecialchars($sale_invoice['invoice_date_sh']) ?></div>
                <div class="col-md-4"><strong>مبلغ کل:</strong> <?= number_format($sale_invoice['total_amount']) ?> تومان</div>
                <div class="col-md-4"><strong>مبلغ پرداختی:</strong> <?= number_format($sale_invoice['paid_amount']) ?> تومان</div>
                <div class="col-md-4"><strong>مانده:</strong> <?= number_format($sale_invoice['total_amount'] - $sale_invoice['paid_amount']) ?> تومان</div>
            </div>
        </div>
        
        <form method="post" id="returnForm">
            <input type="hidden" name="invoice_id" value="<?= $sale_invoice['id'] ?>">
            <input type="hidden" name="mode" id="mode" value="return">
            
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold">نوع عملیات</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="operation_mode" id="modeReturn" value="return" checked>
                            <label class="form-check-label" for="modeReturn">
                                🗑️ مرجوعی ساده (بازگشت وجه)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="operation_mode" id="modeExchange" value="exchange">
                            <label class="form-check-label" for="modeExchange">
                                🔄 تعویض کالا
                            </label>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">حساب برگشت وجه</label>
                    <select name="refund_account_id" class="form-select" required>
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (موجودی: <?= number_format($a['current_balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- اقلام قابل برگشت -->
            <h5 class="mt-3">📦 اقلام قابل برگشت</h5>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr><th>کالا</th><th>تعداد فروخته شده</th><th>قیمت واحد</th><th>تعداد برگشتی</th><th>جمع برگشتی</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sale_items as $item): ?>
                        <tr class="return-item-row">
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= number_format($item['unit_price']) ?> تومان</td>
                            <td>
                                <input type="number" name="return_qty[<?= $item['id'] ?>]" class="form-control return-qty" 
                                       data-price="<?= $item['unit_price'] ?>" data-max="<?= $item['quantity'] ?>" 
                                       value="0" min="0" max="<?= $item['quantity'] ?>" style="width: 100px;">
                            </td>
                            <td class="return-total">0 تومان</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- بخش تعویض کالا (مخفی در ابتدا) -->
            <div id="exchangeSection" style="display:none;">
                <h5 class="mt-4">🔄 کالاهای جایگزین</h5>
                <div id="exchangeItemsContainer">
                    <div class="exchange-item-row">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label">کالای جایگزین</label>
                                <div class="position-relative">
                                    <input type="text" class="form-control product-search-exchange" placeholder="جستجوی کالا..." autocomplete="off">
                                    <input type="hidden" name="exchange_product[0][product_id]" class="exchange-product-id">
                                    <div class="suggestion-box-exchange"></div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">تعداد</label>
                                <input type="number" name="exchange_product[0][quantity]" class="form-control exchange-qty" value="1" min="1">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">قیمت واحد (تومان)</label>
                                <input type="number" name="exchange_product[0][unit_price]" class="form-control exchange-price" value="0" min="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="button" class="btn btn-danger btn-sm remove-exchange-row form-control">حذف</button>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" id="addExchangeRow" class="btn btn-secondary btn-sm mt-2">➕ افزودن کالای دیگر</button>
            </div>
            
            <div class="mb-3 mt-3">
                <label class="form-label">علت مرجوعی</label>
                <textarea name="return_reason" class="form-control" rows="2" placeholder="مثال: کیفیت نامناسب، اشتباه در سفارش، غیره"></textarea>
            </div>
            
            <hr>
            <h4>💰 جمع مبلغ قابل برگشت: <span id="totalRefundAmount">0</span> تومان</h4>
            
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-warning" id="submitBtn">✅ ثبت مرجوعی</button>
                <a href="return_sale.php" class="btn btn-secondary">🔙 فاکتور جدید</a>
                <a href="products.php" class="btn btn-outline-secondary">🏠 بازگشت به انبار</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    var exchangeRowIndex = 1;
    
    // نمایش/مخفی کردن بخش تعویض
    $('input[name="operation_mode"]').on('change', function() {
        if ($(this).val() === 'exchange') {
            $('#exchangeSection').show();
            $('#mode').val('exchange');
            $('#submitBtn').html('✅ ثبت تعویض');
        } else {
            $('#exchangeSection').hide();
            $('#mode').val('return');
            $('#submitBtn').html('✅ ثبت مرجوعی');
        }
    });
    
    // محاسبه جمع مبلغ برگشتی
    function calcTotalRefund() {
        var total = 0;
        $('.return-item-row').each(function() {
            var row = $(this);
            var qty = parseInt(row.find('.return-qty').val()) || 0;
            var price = parseInt(row.find('.return-qty').data('price')) || 0;
            var rowTotal = qty * price;
            row.find('.return-total').text(rowTotal.toLocaleString() + ' تومان');
            total += rowTotal;
        });
        $('#totalRefundAmount').text(total.toLocaleString());
    }
    
    $('.return-qty').on('keyup change', function() {
        var max = parseInt($(this).data('max')) || 0;
        var val = parseInt($(this).val()) || 0;
        if (val > max) {
            $(this).val(max);
        }
        calcTotalRefund();
    });
    
    // ==================== جستجوی کالا برای تعویض ====================
    function attachExchangeSearch() {
        $('.product-search-exchange').off('keyup').on('keyup', function() {
            var input = $(this);
            var box = input.siblings('.suggestion-box-exchange');
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
                        for (var i = 0; i < data.length; i++) {
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
        
        $(document).off('click', '.suggestion-item').on('click', '.suggestion-item', function(e) {
            e.preventDefault();
            var row = $(this).closest('.exchange-item-row');
            var id = $(this).data('id');
            var name = $(this).data('name');
            var price = $(this).data('price');
            row.find('.product-search-exchange').val(name);
            row.find('.exchange-product-id').val(id);
            row.find('.exchange-price').val(price);
            row.find('.suggestion-box-exchange').hide();
        });
    }
    
    $('#addExchangeRow').click(function() {
        var newRow = $('#exchangeItemsContainer .exchange-item-row:first').clone();
        newRow.find('.product-search-exchange').val('');
        newRow.find('.exchange-product-id').val('');
        newRow.find('.exchange-qty').val(1);
        newRow.find('.exchange-price').val(0);
        newRow.find('.suggestion-box-exchange').hide();
        newRow.find('.remove-exchange-row').show();
        $('#exchangeItemsContainer').append(newRow);
        attachExchangeSearch();
    });
    
    $(document).on('click', '.remove-exchange-row', function() {
        if ($('#exchangeItemsContainer .exchange-item-row').length > 1) {
            $(this).closest('.exchange-item-row').remove();
        } else {
            alert('حداقل یک ردیف باید باقی بماند');
        }
    });
    
    attachExchangeSearch();
    
    $(document).click(function(e) {
        if (!$(e.target).closest('.product-search-exchange, .suggestion-box-exchange').length) {
            $('.suggestion-box-exchange').hide();
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
    
    calcTotalRefund();
});
</script>

<?php require_once '../../includes/footer.php'; ?>