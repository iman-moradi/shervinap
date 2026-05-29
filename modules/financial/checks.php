<?php
$page_title = 'مدیریت چک‌ها';
require_once '../../includes/header.php';

// تابع تبدیل تاریخ شمسی به تایم‌استمپ (در صورت نبود در functions.php)
if (!function_exists('jalali_to_gregorian_timestamp')) {
    function jalali_to_gregorian_timestamp($shamsi_date) {
        $parts = explode('/', $shamsi_date);
        if (count($parts) != 3) return time();
        list($y, $m, $d) = $parts;
        $g = jalali_to_gregorian($y, $m, $d);
        return mktime(0, 0, 0, $g[1], $g[2], $g[0]);
    }
}

if (!has_permission($_SESSION['user_id'], 'checks_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ثبت چک جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_check'])) {
    $type = $_POST['type'];
    $check_number = trim($_POST['check_number']);
    $bank_name = trim($_POST['bank_name']);
    $amount = (int)$_POST['amount'];
    $due_date = $_POST['due_date'];
    $receiver_name = trim($_POST['receiver_name']);
    $description = trim($_POST['description']);
    
    $stmt = $db->prepare("INSERT INTO checks (type, check_number, bank_name, amount, due_date_sh, receiver_name, status, description, created_by) VALUES (?,?,?,?,?,?, 'pending', ?, ?)");
    if ($stmt->execute([$type, $check_number, $bank_name, $amount, $due_date, $receiver_name, $description, $_SESSION['user_id']])) {
        $success = '✅ چک با موفقیت ثبت شد.';
    } else {
        $error = 'خطا در ثبت چک.';
    }
}

// تغییر وضعیت چک
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    $allowed = ['cleared', 'returned', 'canceled'];
    if (in_array($action, $allowed)) {
        $db->prepare("UPDATE checks SET status = ? WHERE id = ?")->execute([$action, $id]);
        header('Location: checks.php');
        exit;
    }
}

// حذف چک
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM checks WHERE id = ?")->execute([$id]);
    header('Location: checks.php');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$sql = "SELECT * FROM checks ORDER BY due_date_sh ASC";
if ($filter == 'issued') $sql = "SELECT * FROM checks WHERE type='issued' ORDER BY due_date_sh ASC";
if ($filter == 'received') $sql = "SELECT * FROM checks WHERE type='received' ORDER BY due_date_sh ASC";
if ($filter == 'pending') $sql = "SELECT * FROM checks WHERE status='pending' ORDER BY due_date_sh ASC";
$checks = $db->query($sql)->fetchAll();
?>
<div class="card">
    <div class="card-header">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCheckModal">➕ ثبت چک جدید</button>
        <div class="btn-group float-start">
            <a href="?filter=all" class="btn btn-sm btn-secondary">همه</a>
            <a href="?filter=issued" class="btn btn-sm btn-secondary">چک‌های پرداختی</a>
            <a href="?filter=received" class="btn btn-sm btn-secondary">چک‌های دریافتی</a>
            <a href="?filter=pending" class="btn btn-sm btn-secondary">در انتظار</a>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr><th>نوع</th><th>شماره چک</th><th>بانک</th><th>مبلغ</th><th>سررسید</th><th>ذی‌نفع/صادرکننده</th><th>وضعیت</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($checks)): ?>
                        <tr><td colspan="8" class="text-center">هیچ چکی ثبت نشده است.</td></tr>
                    <?php else: ?>
                        <?php foreach ($checks as $c): 
                            $status_label = '';
                            $status_class = '';
                            switch ($c['status']) {
                                case 'pending': $status_label = 'در انتظار'; $status_class = 'warning'; break;
                                case 'cleared': $status_label = 'وصول شده'; $status_class = 'success'; break;
                                case 'returned': $status_label = 'برگشتی'; $status_class = 'danger'; break;
                                case 'canceled': $status_label = 'ابطال شده'; $status_class = 'secondary'; break;
                            }
                        ?>
                            <tr class="table-<?= $status_class ?>">
                                <td><?= $c['type'] == 'issued' ? 'پرداختی' : 'دریافتی' ?></td>
                                <td><?= htmlspecialchars($c['check_number']) ?></td>
                                <td><?= htmlspecialchars($c['bank_name']) ?></td>
                                <td><?= number_format($c['amount']) ?> تومان</td
                                <td><?= htmlspecialchars($c['due_date_sh']) ?></td>
                                <td><?= htmlspecialchars($c['receiver_name']) ?></td>
                                <td><?= $status_label ?></td>
                                <td>
                                    <?php if ($c['status'] == 'pending'): ?>
                                        <a href="?action=cleared&id=<?= $c['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('وصول شد؟')">✔️ وصول</a>
                                        <a href="?action=returned&id=<?= $c['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('برگشت خورده؟')">❌ برگشتی</a>
                                        <a href="?action=canceled&id=<?= $c['id'] ?>" class="btn btn-sm btn-secondary" onclick="return confirm('ابطال شود؟')">🚫 ابطال</a>
                                    <?php endif; ?>
                                    <a href="?delete=<?= $c['id'] ?>" class="btn btn-sm btn-dark" onclick="return confirm('حذف شود؟')">🗑️ حذف</a>
                                </td>
                            </td>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال ثبت چک جدید -->
<div class="modal fade" id="addCheckModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header"><h5>ثبت چک جدید</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-2"><label>نوع چک</label><select name="type" class="form-select" required><option value="issued">پرداختی (چکی که ما دادیم)</option><option value="received">دریافتی (چکی که از دیگران گرفتیم)</option></select></div>
                    <div class="mb-2"><label>شماره چک</label><input type="text" name="check_number" class="form-control" required></div>
                    <div class="mb-2"><label>نام بانک</label><input type="text" name="bank_name" class="form-control" required></div>
                    <div class="mb-2"><label>مبلغ (تومان)</label><input type="number" name="amount" class="form-control" required></div>
                    <div class="mb-2"><label>تاریخ سررسید (مثال 1402/01/01)</label><input type="text" name="due_date" class="form-control" required></div>
                    <div class="mb-2"><label>ذی‌نفع (برای چک پرداختی) / صادرکننده (برای چک دریافتی)</label><input type="text" name="receiver_name" class="form-control"></div>
                    <div class="mb-2"><label>توضیحات</label><textarea name="description" class="form-control"></textarea></div>
                </div>
                <div class="modal-footer"><button type="submit" name="add_check" class="btn btn-primary">ثبت چک</button></div>
            </form>
        </div>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>