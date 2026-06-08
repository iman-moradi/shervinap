<?php
$page_title = 'ویرایش پذیرش دستگاه';
require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

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

$stmt = $db->prepare("SELECT r.*, c.id as customer_id, c.fullname, c.mobile, c.address 
                      FROM repair_tickets r 
                      JOIN customers c ON c.id = r.customer_id 
                      WHERE r.id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();
if (!$ticket) {
    echo '<div class="alert alert-danger">تیکت یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $received_date_sh = $_POST['received_date_sh'];
    $expected_delivery_date_sh = $_POST['expected_delivery_hidden'] ?? null;
    
    // اگر مقدار مخفی خالی بود، دوباره محاسبه کن
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
            // به‌روزرسانی اطلاعات مشتری
            $upd = $db->prepare("UPDATE customers SET fullname = ?, address = ? WHERE id = ?");
            $upd->execute([$customer_fullname, $customer_address, $ticket['customer_id']]);
            
            // به‌روزرسانی اطلاعات تیکت با فیلد expected_delivery_date_sh
            $sql = "UPDATE repair_tickets SET 
                        device_type = ?, brand = ?, model = ?, serial_no = ?, 
                        reported_fault = ?, accompanying_parts = ?, physical_condition = ?, 
                        deposit = ?, priority = ?, urgent_deadline_sh = ?, normal_days = ?, 
                        expected_delivery_date_sh = ?, received_date_sh = ?
                    WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                $device_type, $brand, $model, $serial_no,
                $reported_fault, $accompanying_parts, $physical_condition,
                $deposit, $priority, $urgent_deadline_sh, $normal_days,
                $expected_delivery_date_sh, $received_date_sh, $ticket_id
            ]);
            
            $db->commit();
            $success = "اطلاعات با موفقیت ویرایش شد.";
            
            // بارگذاری مجدد اطلاعات تیکت
            $stmt = $db->prepare("SELECT r.*, c.id as customer_id, c.fullname, c.mobile, c.address 
                                  FROM repair_tickets r JOIN customers c ON c.id = r.customer_id WHERE r.id = ?");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch();
        } catch (Exception $e) {
            $db->rollBack();
            $error = "خطا در ویرایش: " . $e->getMessage();
        }
    }
}

// محاسبه تاریخ تحویل تخمینی اولیه برای نمایش در فرم
$initial_delivery_date = '';
if (!empty($ticket['expected_delivery_date_sh'])) {
    $initial_delivery_date = $ticket['expected_delivery_date_sh'];
} elseif ($ticket['priority'] == 'normal' && !empty($ticket['normal_days'])) {
    $initial_delivery_date = jalali_add_days($ticket['received_date_sh'], $ticket['normal_days']);
} elseif ($ticket['priority'] == 'urgent' && !empty($ticket['urgent_deadline_sh'])) {
    $initial_delivery_date = $ticket['urgent_deadline_sh'];
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
        <i class="fas fa-edit"></i> ویرایش پذیرش
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger alert-glass"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success alert-glass"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        
        <form method="post" class="form-modern" id="editForm">
            <!-- فیلد مخفی برای ذخیره تاریخ تحویل محاسبه شده -->
            <input type="hidden" name="expected_delivery_hidden" id="expected_delivery_hidden" value="<?= htmlspecialchars($initial_delivery_date) ?>">
            
            <h5 class="mb-3"><i class="fas fa-user"></i> اطلاعات مشتری</h5>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label>نام کامل *</label>
                    <input type="text" name="customer_fullname" class="form-control" value="<?= htmlspecialchars($ticket['fullname']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>موبایل *</label>
                    <input type="text" name="customer_mobile" class="form-control" value="<?= htmlspecialchars($ticket['mobile']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>آدرس</label>
                    <input type="text" name="customer_address" class="form-control" value="<?= htmlspecialchars($ticket['address']) ?>">
                </div>
            </div>
            
            <h5 class="mb-3 mt-3"><i class="fas fa-microchip"></i> اطلاعات دستگاه</h5>
            <div class="row">
                <div class="col-md-3 mb-3"><label>نوع دستگاه *</label><input type="text" name="device_type" class="form-control" value="<?= htmlspecialchars($ticket['device_type']) ?>" required placeholder="ماشین لباسشویی"></div>
                <div class="col-md-3 mb-3"><label>برند *</label><input type="text" name="brand" class="form-control" value="<?= htmlspecialchars($ticket['brand']) ?>" required></div>
                <div class="col-md-3 mb-3"><label>مدل</label><input type="text" name="model" class="form-control" value="<?= htmlspecialchars($ticket['model']) ?>"></div>
                <div class="col-md-3 mb-3"><label>شماره سریال</label><input type="text" name="serial_no" class="form-control" value="<?= htmlspecialchars($ticket['serial_no']) ?>"></div>
                <div class="col-md-6 mb-3"><label>خرابی گزارش شده *</label><textarea name="reported_fault" class="form-control" rows="2" required><?= htmlspecialchars($ticket['reported_fault']) ?></textarea></div>
                <div class="col-md-6 mb-3"><label>قطعات همراه (توسط مشتری)</label><textarea name="accompanying_parts" class="form-control" rows="2"><?= htmlspecialchars($ticket['accompanying_parts']) ?></textarea></div>
                <div class="col-md-4 mb-3"><label>وضعیت ظاهری</label><input type="text" name="physical_condition" class="form-control" value="<?= htmlspecialchars($ticket['physical_condition']) ?>" placeholder="خش، خط و خش، سالم"></div>
                <div class="col-md-4 mb-3"><label>بیعانه (تومان)</label><input type="number" name="deposit" class="form-control" value="<?= $ticket['deposit'] ?>"></div>
                <div class="col-md-4 mb-3"><label>تاریخ پذیرش (مثال 1402/10/15)</label>
                    <input type="text" name="received_date_sh" id="received_date" class="form-control" required value="<?= htmlspecialchars($ticket['received_date_sh']) ?>">
                </div>
            </div>
            
            <h5 class="mb-3 mt-3"><i class="fas fa-clock"></i> اولویت و زمان تعمیر</h5>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label>اولویت</label>
                    <select name="priority" id="priority" class="form-select" required>
                        <option value="normal" <?= $ticket['priority'] == 'normal' ? 'selected' : '' ?>>عادی</option>
                        <option value="urgent" <?= $ticket['priority'] == 'urgent' ? 'selected' : '' ?>>فوری</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3" id="urgentDiv" style="display: <?= $ticket['priority'] == 'urgent' ? 'block' : 'none' ?>;">
                    <label>تاریخ تحویل توافقی (فوری)</label>
                    <input type="text" name="urgent_deadline_sh" id="urgent_deadline" class="form-control" placeholder="مثال 1402/10/20" value="<?= htmlspecialchars($ticket['urgent_deadline_sh']) ?>">
                </div>
                <div class="col-md-3 mb-3" id="normalDiv" style="display: <?= $ticket['priority'] == 'normal' ? 'block' : 'none' ?>;">
                    <label>زمان تعمیر (روز)</label>
                    <input type="number" name="normal_days" id="normal_days" class="form-control" value="<?= $ticket['normal_days'] ?: 3 ?>" min="1">
                </div>
                <div class="col-md-3 mb-3">
                    <label>تاریخ تحویل تخمینی</label>
                    <input type="text" id="expected_delivery_display" class="form-control" readonly style="background-color: #f8f9fa;" value="<?= htmlspecialchars($initial_delivery_date) ?>">
                    <small class="text-muted">به‌طور خودکار محاسبه می‌شود</small>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-modern"><i class="fas fa-save"></i> ذخیره تغییرات</button>
                <a href="view.php?id=<?= $ticket_id ?>" class="btn btn-secondary"><i class="fas fa-times"></i> بازگشت</a>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
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
                        $('#expected_delivery_display').val('خطا: ' + (response.error || 'محاسبه نشد'));
                        $('#expected_delivery_hidden').val('');
                    }
                },
                error: function(xhr, status, error) {
                    console.log('خطای AJAX:', status, error);
                    $('#expected_delivery_display').val('خطا در محاسبه');
                    $('#expected_delivery_hidden').val('');
                }
            });
        } else {
            $('#expected_delivery_display').val('');
            $('#expected_delivery_hidden').val('');
        }
    }
    
    // رویدادهای محاسبه تاریخ تحویل
    $('#priority').on('change', function(){
        if ($(this).val() === 'urgent') {
            $('#urgentDiv').show();
            $('#normalDiv').hide();
        } else {
            $('#urgentDiv').hide();
            $('#normalDiv').show();
        }
        calculateExpectedDelivery();
    });
    
    $('#received_date').on('change keyup', function(){
        calculateExpectedDelivery();
    });
    
    $('#normal_days').on('change keyup', function(){
        calculateExpectedDelivery();
    });
    
    $('#urgent_deadline').on('change keyup', function(){
        calculateExpectedDelivery();
    });
    
    // محاسبه اولیه اگر مقدار نداشت
    if (!$('#expected_delivery_display').val()) {
        calculateExpectedDelivery();
    }
});
</script>
<?php require_once '../../includes/footer.php'; ?>