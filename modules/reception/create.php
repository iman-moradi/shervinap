<?php
$page_title = 'پذیرش دستگاه جدید';
require_once '../../includes/header.php';

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
    
    $db->beginTransaction();
    try {
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
        
        $ticket_no = 'R-' . str_replace('/', '', $received_date_sh) . '-' . rand(100, 999);
        $sql = "INSERT INTO repair_tickets 
                (ticket_no, customer_id, device_type, brand, model, serial_no, reported_fault, 
                 accompanying_parts, physical_condition, deposit, priority, urgent_deadline_sh, 
                 normal_days, status, received_date_sh, created_by, total_cost, paid_amount) 
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $ticket_no, $customer_id, $device_type, $brand, $model, $serial_no, $reported_fault,
            $accompanying_parts, $physical_condition, $deposit, $priority, $urgent_deadline_sh,
            $normal_days, $status, $received_date_sh, $_SESSION['user_id']
        ]);
        $ticket_id = $db->lastInsertId();
        $db->commit();
        $success = "پذیرش با موفقیت ثبت شد. شماره پیگیری: $ticket_no";
        echo '<meta http-equiv="refresh" content="2;url=view.php?id='.$ticket_id.'">';
    } catch (Exception $e) {
        $db->rollBack();
        $error = "خطا در ثبت: " . $e->getMessage();
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
            <h5 class="mb-3"><i class="fas fa-user"></i> اطلاعات مشتری</h5>
            <div class="row">
                <div class="col-md-4 mb-3 position-relative">
                    <label>موبایل *</label>
                    <input type="text" id="customerMobile" class="form-control" autocomplete="off" required>
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
                <div class="col-md-4 mb-3"><label>تاریخ پذیرش (مثال 1402/10/15)</label><input type="text" name="received_date_sh" class="form-control" required value="<?= now_jalali() ?>"></div>
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
                    <input type="text" name="urgent_deadline_sh" class="form-control" placeholder="مثال 1402/10/20">
                </div>
                <div class="col-md-3 mb-3" id="normalDiv">
                    <label>زمان تعمیر (روز)</label>
                    <input type="number" name="normal_days" class="form-control" value="3" min="1">
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
    
    $('#priority').on('change', function() {
        if ($(this).val() === 'urgent') {
            $('#urgentDiv').show();
            $('#normalDiv').hide();
        } else {
            $('#urgentDiv').hide();
            $('#normalDiv').show();
        }
    });
});
</script>
<?php require_once '../../includes/footer.php'; ?>