<?php
$page_title = 'ویرایش پذیرش دستگاه';
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
    
    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE customers SET fullname = ?, address = ? WHERE id = ?");
        $upd->execute([$customer_fullname, $customer_address, $ticket['customer_id']]);
        
        $sql = "UPDATE repair_tickets SET 
                    device_type = ?, brand = ?, model = ?, serial_no = ?, 
                    reported_fault = ?, accompanying_parts = ?, physical_condition = ?, 
                    deposit = ?, priority = ?, urgent_deadline_sh = ?, normal_days = ?, 
                    received_date_sh = ?
                WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $device_type, $brand, $model, $serial_no,
            $reported_fault, $accompanying_parts, $physical_condition,
            $deposit, $priority, $urgent_deadline_sh, $normal_days,
            $received_date_sh, $ticket_id
        ]);
        
        $db->commit();
        $success = "اطلاعات با موفقیت ویرایش شد.";
        // reload
        $stmt = $db->prepare("SELECT r.*, c.id as customer_id, c.fullname, c.mobile, c.address 
                              FROM repair_tickets r JOIN customers c ON c.id = r.customer_id WHERE r.id = ?");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch();
    } catch (Exception $e) {
        $db->rollBack();
        $error = "خطا در ویرایش: " . $e->getMessage();
    }
}
?>
<div class="card">
    <div class="card-header">ویرایش پذیرش</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <form method="post">
            <h5>اطلاعات مشتری</h5>
            <div class="row">
                <div class="col-md-4 mb-3"><label>نام کامل</label><input type="text" name="customer_fullname" class="form-control" value="<?= htmlspecialchars($ticket['fullname']) ?>" required></div>
                <div class="col-md-4 mb-3"><label>موبایل</label><input type="text" name="customer_mobile" class="form-control" value="<?= htmlspecialchars($ticket['mobile']) ?>" required></div>
                <div class="col-md-4 mb-3"><label>آدرس</label><input type="text" name="customer_address" class="form-control" value="<?= htmlspecialchars($ticket['address']) ?>"></div>
            </div>
            <h5>اطلاعات دستگاه</h5>
            <div class="row">
                <div class="col-md-3 mb-3"><label>نوع دستگاه</label><input type="text" name="device_type" class="form-control" value="<?= htmlspecialchars($ticket['device_type']) ?>" required></div>
                <div class="col-md-3 mb-3"><label>برند</label><input type="text" name="brand" class="form-control" value="<?= htmlspecialchars($ticket['brand']) ?>"></div>
                <div class="col-md-3 mb-3"><label>مدل</label><input type="text" name="model" class="form-control" value="<?= htmlspecialchars($ticket['model']) ?>"></div>
                <div class="col-md-3 mb-3"><label>شماره سریال</label><input type="text" name="serial_no" class="form-control" value="<?= htmlspecialchars($ticket['serial_no']) ?>"></div>
                <div class="col-md-6 mb-3"><label>خرابی گزارش شده</label><textarea name="reported_fault" class="form-control" rows="2" required><?= htmlspecialchars($ticket['reported_fault']) ?></textarea></div>
                <div class="col-md-6 mb-3"><label>قطعات همراه</label><textarea name="accompanying_parts" class="form-control" rows="2"><?= htmlspecialchars($ticket['accompanying_parts']) ?></textarea></div>
                <div class="col-md-4 mb-3"><label>وضعیت ظاهری</label><input type="text" name="physical_condition" class="form-control" value="<?= htmlspecialchars($ticket['physical_condition']) ?>"></div>
                <div class="col-md-4 mb-3"><label>بیعانه (تومان)</label><input type="number" name="deposit" class="form-control" value="<?= $ticket['deposit'] ?>"></div>
                <div class="col-md-4 mb-3"><label>تاریخ پذیرش</label><input type="text" name="received_date_sh" class="form-control" required value="<?= htmlspecialchars($ticket['received_date_sh']) ?>"></div>
            </div>
            <h5>اولویت و زمان تعمیر</h5>
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
                    <input type="text" name="urgent_deadline_sh" class="form-control" value="<?= htmlspecialchars($ticket['urgent_deadline_sh']) ?>">
                </div>
                <div class="col-md-3 mb-3" id="normalDiv" style="display: <?= $ticket['priority'] == 'normal' ? 'block' : 'none' ?>;">
                    <label>زمان تعمیر (روز)</label>
                    <input type="number" name="normal_days" class="form-control" value="<?= $ticket['normal_days'] ?: 3 ?>" min="1">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            <a href="view.php?id=<?= $ticket_id ?>" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>
</div>
<script>
document.getElementById('priority').addEventListener('change', function(){
    if(this.value === 'urgent'){
        document.getElementById('urgentDiv').style.display = 'block';
        document.getElementById('normalDiv').style.display = 'none';
    } else {
        document.getElementById('urgentDiv').style.display = 'none';
        document.getElementById('normalDiv').style.display = 'block';
    }
});
</script>
<?php require_once '../../includes/footer.php'; ?>