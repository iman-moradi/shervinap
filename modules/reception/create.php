<?php
$page_title = 'پذیرش دستگاه جدید';
require_once '../../includes/header.php';
require_once '../../includes/SMSManager.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = isset($_POST['customer_id']) && is_numeric($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $customer_fullname = trim($_POST['customer_fullname']);
    $customer_mobile = trim($_POST['customer_mobile']);
    $customer_address = trim($_POST['customer_address'] ?? '');
    $device_type = $_POST['device_type'];
    $brand = $_POST['brand'];
    $model = $_POST['model'];
    $serial_no = $_POST['serial_no'] ?? '';
    $reported_fault = $_POST['reported_fault'];
    $accompanying_parts = $_POST['accompanying_parts'] ?? '';
    $physical_condition = $_POST['physical_condition'] ?? '';
    $deposit = (int)($_POST['deposit'] ?? 0);
    $priority = $_POST['priority'];
    $urgent_deadline_sh = ($priority == 'urgent') ? $_POST['urgent_deadline_sh'] : null;
    $normal_days = ($priority == 'normal') ? (int)$_POST['normal_days'] : null;
    $status = 'pending';
    $received_date_sh = $_POST['received_date_sh'];
    
    // دریافت تاریخ تحویل از فیلد مخفی (اگر AJAX محاسبه کرده باشد)
    $expected_delivery_date_sh = $_POST['expected_delivery_hidden'] ?? null;
    
    // اگر فیلد مخفی خالی بود، خودمان محاسبه کنیم
    if (empty($expected_delivery_date_sh)) {
        if ($priority == 'urgent' && !empty($urgent_deadline_sh)) {
            $expected_delivery_date_sh = $urgent_deadline_sh;
        } elseif ($priority == 'normal' && !empty($normal_days) && $normal_days > 0) {
            $received_ts = jalali_to_timestamp($received_date_sh);
            if ($received_ts > 0) {
                $delivery_ts = $received_ts + ($normal_days * 86400);
                $delivery_gregorian = date('Y-m-d', $delivery_ts);
                list($gy, $gm, $gd) = explode('-', $delivery_gregorian);
                list($jy, $jm, $jd) = gregorian_to_jalali($gy, $gm, $gd);
                $expected_delivery_date_sh = sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
            }
        }
    }

    if (empty($received_date_sh)) {
        $error = "تاریخ پذیرش باید پر شود";
    } else {
        $db->beginTransaction();
        try {
            // مدیریت مشتری
            if ($customer_id) {
                $customer = $db->prepare("SELECT id, fullname, mobile, address FROM customers WHERE id = ?");
                $customer->execute([$customer_id]);
                $cust = $customer->fetch();
                if (!$cust) throw new Exception('مشتری نامعتبر');
                $customer_id = $cust['id'];
                $update = $db->prepare("UPDATE customers SET fullname = ?, address = ? WHERE id = ?");
                $update->execute([$customer_fullname, $customer_address, $customer_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO customers (fullname, mobile, address) VALUES (?, ?, ?)");
                $stmt->execute([$customer_fullname, $customer_mobile, $customer_address]);
                $customer_id = $db->lastInsertId();
            }

            // تولید شماره تیکت
            $ticket_no = 'R-' . str_replace('/', '', $received_date_sh) . '-' . rand(100, 999);
            
            $sql = "INSERT INTO repair_tickets 
                    (ticket_no, customer_id, device_type, brand, model, serial_no, reported_fault, 
                     accompanying_parts, physical_condition, deposit, priority, urgent_deadline_sh, 
                     normal_days, expected_delivery_date_sh, status, received_date_sh, created_by, total_cost, paid_amount) 
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $ticket_no, $customer_id, $device_type, $brand, $model, $serial_no, $reported_fault,
                $accompanying_parts, $physical_condition, $deposit, $priority, $urgent_deadline_sh,
                $normal_days, $expected_delivery_date_sh, $status, $received_date_sh, $_SESSION['user_id']
            ]);
            $ticket_id = $db->lastInsertId();
            
            // ثبت تراکنش بیعانه در صورت وجود
            if ($deposit > 0) {
                $default_cash_account_id = 1;
                $trans_sql = "INSERT INTO transactions 
                            (transaction_date_sh, account_id, amount, type, ref_type, ref_id, description, created_by) 
                            VALUES (?, ?, ?, 'income', 'repair', ?, 'بیعانه تعمیر', ?)";
                $trans_stmt = $db->prepare($trans_sql);
                $trans_stmt->execute([$received_date_sh, $default_cash_account_id, $deposit, $ticket_id, $_SESSION['user_id']]);
                
                $upd_acc = $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?");
                $upd_acc->execute([$deposit, $default_cash_account_id]);
            }
            $db->commit();

            // ارسال پیامک
            $finalMobile = $customer_mobile;
            if (empty($finalMobile) && $customer_id) {
                $mStmt = $db->prepare("SELECT mobile FROM customers WHERE id = ?");
                $mStmt->execute([$customer_id]);
                $finalMobile = $mStmt->fetchColumn();
            }
            if (!empty($finalMobile)) {
                $sms = new SMSManager($db);
                if ($sms->isAvailable()) {
                    $deviceInfo = "{$device_type} برند {$brand}";
                    $faultInfo = mb_substr($reported_fault, 0, 100);
                    $deadlineText = '';
                    if ($priority == 'urgent' && !empty($urgent_deadline_sh)) {
                        $deadlineText = " - تحویل فوری تا تاریخ {$urgent_deadline_sh}";
                    } elseif ($priority == 'normal' && !empty($normal_days)) {
                        $deadlineText = " - زمان تقریبی تعمیر {$normal_days} روز کاری";
                    }
                    $message = "خدمات فنی شروین: دستگاه {$deviceInfo} با عیب \"{$faultInfo}\" ثبت شد. شماره پیگیری: {$ticket_no}{$deadlineText}";
                    $smsResult = $sms->send($finalMobile, $message, 'auto_welcome', $ticket_no);
                    if (!$smsResult['success']) {
                        error_log("خطا در ارسال پیامک پذیرش به {$finalMobile}: " . $smsResult['error']);
                    }
                }
            }

            $success = "پذیرش با موفقیت ثبت شد. شماره پیگیری: $ticket_no";
            echo '<meta http-equiv="refresh" content="2;url=view.php?id='.$ticket_id.'">';
        } catch (Exception $e) {
            $db->rollBack();
            $error = "خطا در ثبت: " . $e->getMessage();
        }
    }
}
?>

<style>
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
    .modern-card .card-body {
        padding: 2rem !important;
    }
    .suggestion-item {
        padding: 8px 12px;
        cursor: pointer;
        transition: background 0.2s;
    }
    .suggestion-item:hover {
        background-color: #f1f5f9;
    }
    .form-modern .form-control, .form-modern .form-select {
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 10px 15px;
        width: 100%;
        box-sizing: border-box;
    }
    .form-modern label {
        font-weight: 500;
        margin-bottom: 8px;
        color: #334155;
        display: block;
    }
    .row {
        margin-left: 0;
        margin-right: 0;
    }
    [class^="col-"] {
        padding-left: 10px;
        padding-right: 10px;
    }
</style>

<div class="modern-card">
    <div class="card-header-custom">
        <i class="fas fa-clipboard-list"></i> فرم پذیرش دستگاه
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger alert-glass"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success alert-glass"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        
        <form method="post" id="receptionForm" class="form-modern">
            <!-- فیلد مخفی برای ذخیره تاریخ تحویل تخمینی -->
            <input type="hidden" name="expected_delivery_hidden" id="expected_delivery_hidden" value="">
            
            <h5 class="mb-3"><i class="fas fa-user"></i> اطلاعات مشتری</h5>
            <div class="row">
                <div class="col-md-4 mb-3 position-relative">
                    <label>موبایل *</label>
                    <input type="text" name="customer_mobile" id="customerMobile" class="form-control" autocomplete="off" required>
                    <input type="hidden" name="customer_id" id="customerId" value="">
                    <div id="mobileSuggestions" class="suggestions-box"></div>
                    <small class="text-muted">شماره موبایل مشتری را وارد کنید، اگر وجود داشت انتخاب کنید، در غیر این صورت اطلاعات را کامل کنید.</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label>نام کامل</label>
                    <input type="text" name="customer_fullname" id="customerFullname" class="form-control" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>آدرس</label>
                    <input type="text" name="customer_address" id="customerAddress" class="form-control">
                </div>
            </div>

            <h5 class="mb-3 mt-3"><i class="fas fa-microchip"></i> اطلاعات دستگاه</h5>
            <div class="row">
                <div class="col-md-3 mb-3"><label>نوع دستگاه *</label><input type="text" name="device_type" class="form-control" required placeholder="ماشین لباسشویی"></div>
                <div class="col-md-3 mb-3"><label>برند *</label><input type="text" name="brand" class="form-control" required></div>
                <div class="col-md-3 mb-3"><label>مدل</label><input type="text" name="model" class="form-control"></div>
                <div class="col-md-3 mb-3"><label>شماره سریال</label><input type="text" name="serial_no" class="form-control"></div>
                <div class="col-md-6 mb-3"><label>خرابی گزارش شده *</label><textarea name="reported_fault" class="form-control" rows="2" required></textarea></div>
                <div class="col-md-6 mb-3"><label>قطعات همراه (توسط مشتری)</label><textarea name="accompanying_parts" class="form-control" rows="2"></textarea></div>
                <div class="col-md-4 mb-3"><label>وضعیت ظاهری</label><input type="text" name="physical_condition" class="form-control" placeholder="خش، خط و خش، سالم"></div>
                <div class="col-md-4 mb-3"><label>بیعانه (تومان)</label><input type="number" name="deposit" class="form-control" value="0"></div>
                <div class="col-md-4 mb-3"><label>تاریخ پذیرش (مثال 1402/10/15)</label>
                    <input type="text" name="received_date_sh" id="received_date" class="form-control" required value="<?= now_jalali() ?>">
                </div>
            </div>

            <h5 class="mb-3 mt-3"><i class="fas fa-clock"></i> اولویت و زمان تعمیر</h5>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label>اولویت</label>
                    <select name="priority" id="priority" class="form-select" required>
                        <option value="normal">عادی</option>
                        <option value="urgent">فوری</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3" id="urgentDiv" style="display:none;">
                    <label>تاریخ تحویل توافقی (فوری)</label>
                    <input type="text" name="urgent_deadline_sh" id="urgent_deadline" class="form-control" placeholder="مثال 1402/10/20">
                </div>
                <div class="col-md-3 mb-3" id="normalDiv">
                    <label>زمان تعمیر (روز)</label>
                    <input type="number" name="normal_days" id="normal_days" class="form-control" value="3" min="1">
                </div>
                <div class="col-md-3 mb-3">
                    <label>تاریخ تحویل تخمینی</label>
                    <input type="text" id="expected_delivery_display" class="form-control" readonly style="background-color: #f8f9fa;">
                    <small class="text-muted">به‌طور خودکار محاسبه می‌شود</small>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-modern"><i class="fas fa-save"></i> ثبت پذیرش</button>
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-times"></i> بازگشت</a>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    var searchTimeout = null;
    
    // تابع محاسبه تاریخ تحویل تخمینی با AJAX
    function calculateExpectedDelivery() {
        var priority = $('#priority').val();
        var receivedDate = $('#received_date').val();
        var normalDays = $('#normal_days').val();
        var urgentDeadline = $('#urgent_deadline').val();
        
        if (!receivedDate) {
            $('#expected_delivery_display').val('');
            $('#expected_delivery_hidden').val('');
            return;
        }
        
        if (priority === 'urgent' && urgentDeadline) {
            $('#expected_delivery_display').val(urgentDeadline);
            $('#expected_delivery_hidden').val(urgentDeadline);
            return;
        }
        
        if (priority === 'normal' && normalDays && normalDays > 0) {
            $('#expected_delivery_display').val('در حال محاسبه...');
            
            $.ajax({
                url: '../../ajax_search.php',
                type: 'GET',
                data: {
                    type: 'calculate_delivery',
                    received_date: receivedDate,
                    days: normalDays
                },
                dataType: 'json',
                timeout: 5000,
                success: function(response) {
                    if (response.success) {
                        $('#expected_delivery_display').val(response.delivery_date);
                        $('#expected_delivery_hidden').val(response.delivery_date);
                    } else {
                        console.log('خطای سرور:', response.error);
                        $('#expected_delivery_display').val('خطا: ' + (response.error || 'محاسبه نشد'));
                        $('#expected_delivery_hidden').val('');
                    }
                },
                error: function(xhr, status, error) {
                    console.log('خطای AJAX:', status, error);
                    console.log('پاسخ سرور:', xhr.responseText);
                    $('#expected_delivery_display').val('خطا در ارتباط با سرور');
                    $('#expected_delivery_hidden').val('');
                }
            });
        } else {
            $('#expected_delivery_display').val('');
            $('#expected_delivery_hidden').val('');
        }
    }
    
    // رویدادهای محاسبه تاریخ تحویل
    $('#priority').on('change', function() {
        if ($(this).val() === 'urgent') {
            $('#urgentDiv').show();
            $('#normalDiv').hide();
        } else {
            $('#urgentDiv').hide();
            $('#normalDiv').show();
        }
        calculateExpectedDelivery();
    });
    
    $('#received_date').on('change keyup', function() {
        calculateExpectedDelivery();
    });
    
    $('#normal_days').on('change keyup', function() {
        calculateExpectedDelivery();
    });
    
    $('#urgent_deadline').on('change keyup', function() {
        calculateExpectedDelivery();
    });
    
    // محاسبه اولیه
    calculateExpectedDelivery();
    
    // جستجوی مشتری
    $('#customerMobile').on('keyup', function() {
        var query = $(this).val().trim();
        clearTimeout(searchTimeout);
        if (query.length < 2) {
            $('#mobileSuggestions').hide();
            return;
        }
        searchTimeout = setTimeout(function() {
            $.ajax({
                url: '../../ajax_search.php',
                type: 'GET',
                data: { type: 'customer_search', query: query },
                dataType: 'json',
                success: function(data) {
                    if (data.length > 0) {
                        var html = '';
                        for (var i=0; i<data.length; i++) {
                            html += '<div class="suggestion-item" data-id="'+data[i].id+'" data-name="'+escapeHtml(data[i].fullname)+'" data-mobile="'+escapeHtml(data[i].mobile)+'" data-address="'+escapeHtml(data[i].address)+'">';
                            html += '<strong>'+escapeHtml(data[i].fullname)+'</strong> - '+escapeHtml(data[i].mobile)+'<br>';
                            html += '<small>'+escapeHtml(data[i].address)+'</small>';
                            html += '</div>';
                        }
                        $('#mobileSuggestions').html(html).show();
                    } else {
                        $('#mobileSuggestions').hide();
                    }
                }
            });
        }, 300);
    });
    
    $(document).on('click', '.suggestion-item', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var mobile = $(this).data('mobile');
        var address = $(this).data('address');
        $('#customerId').val(id);
        $('#customerMobile').val(mobile);
        $('#customerFullname').val(name);
        $('#customerAddress').val(address);
        $('#mobileSuggestions').hide();
    });
    
    $(document).click(function(e) {
        if (!$(e.target).closest('#customerMobile, #mobileSuggestions').length) {
            $('#mobileSuggestions').hide();
        }
    });
    
    $('#receptionForm').on('submit', function() {
        var customerId = $('#customerId').val();
        if (!customerId) {
            if ($('#customerFullname').val().trim() === '' || $('#customerMobile').val().trim() === '') {
                alert('لطفاً نام کامل و موبایل مشتری را وارد کنید.');
                return false;
            }
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
});
</script>
<?php require_once '../../includes/footer.php'; ?>