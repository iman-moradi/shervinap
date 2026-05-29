<?php
$page_title = 'افزودن شخص جدید';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'customers_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'];
    $fullname = trim($_POST['fullname']);
    $mobile = trim($_POST['mobile']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $description = trim($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($fullname)) {
        $error = 'نام کامل الزامی است.';
    } else {
        $stmt = $db->prepare("INSERT INTO customers (type, fullname, mobile, phone, email, address, description, is_active) VALUES (?,?,?,?,?,?,?,?)");
        if ($stmt->execute([$type, $fullname, $mobile, $phone, $email, $address, $description, $is_active])) {
            $success = '✅ شخص با موفقیت اضافه شد.';
            echo '<meta http-equiv="refresh" content="1;url=index.php">';
        } else {
            $error = 'خطا در ثبت.';
        }
    }
}
?>
<div class="card">
    <div class="card-header">افزودن شخص جدید</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <form method="post">
            <div class="row">
                <div class="col-md-4 mb-3"><label>نوع شخص *</label>
                    <select name="type" class="form-select" required>
                        <option value="customer">مشتری</option>
                        <option value="supplier">تأمین‌کننده</option>
                        <option value="partner">همکار</option>
                    </select>
                </div>
                <div class="col-md-8 mb-3"><label>نام کامل *</label><input type="text" name="fullname" class="form-control" required></div>
                <div class="col-md-4 mb-3"><label>موبایل</label><input type="text" name="mobile" class="form-control"></div>
                <div class="col-md-4 mb-3"><label>تلفن ثابت</label><input type="text" name="phone" class="form-control"></div>
                <div class="col-md-4 mb-3"><label>ایمیل</label><input type="email" name="email" class="form-control"></div>
                <div class="col-md-12 mb-3"><label>آدرس</label><textarea name="address" class="form-control"></textarea></div>
                <div class="col-md-12 mb-3"><label>توضیحات</label><textarea name="description" class="form-control"></textarea></div>
                <div class="col-md-6 mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="active" checked>
                    <label class="form-check-label" for="active">فعال</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">💾 ذخیره</button>
            <a href="index.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>