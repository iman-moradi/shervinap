<?php
$page_title = 'ویرایش شخص';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'customers_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$id = (int)$_GET['id'];
if (!$id) {
    header('Location: index.php');
    exit;
}
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    echo '<div class="alert alert-danger">شخص یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// بررسی مجوز دیدن تأمین‌کننده
if ($customer['type'] == 'supplier' && !has_permission($_SESSION['user_id'], 'view_supplier_info')) {
    echo '<div class="alert alert-danger">شما مجوز مشاهده اطلاعات تأمین‌کنندگان را ندارید.</div>';
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
    
    // جلوگیری از تغییر نوع به supplier اگر کاربر مجوز ندارد
    if ($type == 'supplier' && !has_permission($_SESSION['user_id'], 'view_supplier_info')) {
        $error = 'شما اجازه ثبت یا تغییر به تأمین‌کننده را ندارید.';
    } elseif (empty($fullname)) {
        $error = 'نام کامل الزامی است.';
    } else {
        $upd = $db->prepare("UPDATE customers SET type=?, fullname=?, mobile=?, phone=?, email=?, address=?, description=?, is_active=? WHERE id=?");
        if ($upd->execute([$type, $fullname, $mobile, $phone, $email, $address, $description, $is_active, $id])) {
            $success = '✅ اطلاعات با موفقیت به‌روز شد.';
            // به‌روزرسانی متغیر $customer با مقادیر جدید
            $customer = [
                'id' => $id,
                'type' => $type,
                'fullname' => $fullname,
                'mobile' => $mobile,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'description' => $description,
                'is_active' => $is_active,
                'created_at' => $customer['created_at']
            ];
        } else {
            $error = 'خطا در ویرایش.';
        }
    }
}
?>
<div class="card">
    <div class="card-header">ویرایش شخص</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <form method="post">
            <div class="row">
                <div class="col-md-4 mb-3"><label>نوع شخص</label>
                    <select name="type" class="form-select">
                        <option value="customer" <?= $customer['type'] == 'customer' ? 'selected' : '' ?>>مشتری</option>
                        <?php if (has_permission($_SESSION['user_id'], 'view_supplier_info')): ?>
                            <option value="supplier" <?= $customer['type'] == 'supplier' ? 'selected' : '' ?>>تأمین‌کننده</option>
                        <?php endif; ?>
                        <option value="partner" <?= $customer['type'] == 'partner' ? 'selected' : '' ?>>همکار</option>
                    </select>
                </div>
                <div class="col-md-8 mb-3"><label>نام کامل</label><input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($customer['fullname']) ?>" required></div>
                <div class="col-md-4 mb-3"><label>موبایل</label><input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($customer['mobile']) ?>"></div>
                <div class="col-md-4 mb-3"><label>تلفن ثابت</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone']) ?>"></div>
                <div class="col-md-4 mb-3"><label>ایمیل</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email']) ?>"></div>
                <div class="col-md-12 mb-3"><label>آدرس</label><textarea name="address" class="form-control"><?= htmlspecialchars($customer['address']) ?></textarea></div>
                <div class="col-md-12 mb-3"><label>توضیحات</label><textarea name="description" class="form-control"><?= htmlspecialchars($customer['description']) ?></textarea></div>
                <div class="col-md-6 mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="active" <?= $customer['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="active">فعال</label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">💾 ذخیره تغییرات</button>
            <a href="index.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>