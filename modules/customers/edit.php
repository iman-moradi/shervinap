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
        $upd = $db->prepare("UPDATE customers SET type=?, fullname=?, mobile=?, phone=?, email=?, address=?, description=?, is_active=? WHERE id=?");
        if ($upd->execute([$type, $fullname, $mobile, $phone, $email, $address, $description, $is_active, $id])) {
            $success = '✅ اطلاعات با موفقیت به‌روز شد.';
            // به‌روزرسانی متغیر $customer
            $customer = array_merge($customer, compact('type', 'fullname', 'mobile', 'phone', 'email', 'address', 'description', 'is_active'));
        } else {
            $error = 'خطا در ویرایش اطلاعات.';
        }
    }
}
?>

<style>
    /* اصلاحات یکسان با صفحه افزودن */
    .form-modern .form-control, 
    .form-modern .form-select,
    .form-modern textarea {
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 10px 15px;
        transition: all 0.2s;
        width: 100%;
        box-sizing: border-box;
    }
    .form-modern .form-control:focus, 
    .form-modern .form-select:focus,
    .form-modern textarea:focus {
        border-color: #0ea5e9;
        box-shadow: 0 0 0 3px rgba(14,165,233,0.1);
    }
    .form-modern label {
        font-weight: 500;
        margin-bottom: 8px;
        color: #334155;
        display: block;
    }
    .form-control[dir="ltr"] {
        text-align: left;
    }
    .modern-card .card-body {
        overflow-x: hidden;
        padding: 1.5rem;
    }
    .row {
        margin-left: 0;
        margin-right: 0;
    }
    .col-md-4, .col-md-8, .col-md-6, .col-12 {
        padding-left: 10px;
        padding-right: 10px;
    }
    .btn-modern, .btn-secondary {
        margin-bottom: 5px;
    }
    @media (max-width: 768px) {
        .modern-card .card-body {
            padding: 1rem;
        }
    }
</style>

<div class="modern-card">
    <div class="card-header-custom">
        <i class="fas fa-user-edit"></i> ویرایش شخص
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-glass"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-glass"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="post" class="form-modern">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label>نوع شخص <span class="text-danger">*</span></label>
                    <select name="type" class="form-select" required>
                        <option value="customer" <?= $customer['type'] == 'customer' ? 'selected' : '' ?>>مشتری</option>
                        <option value="supplier" <?= $customer['type'] == 'supplier' ? 'selected' : '' ?>>تأمین‌کننده</option>
                        <option value="partner" <?= $customer['type'] == 'partner' ? 'selected' : '' ?>>همکار</option>
                    </select>
                </div>
                <div class="col-md-8 mb-3">
                    <label>نام کامل <span class="text-danger">*</span></label>
                    <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($customer['fullname']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>موبایل</label>
                    <input type="text" name="mobile" class="form-control" dir="ltr" value="<?= htmlspecialchars($customer['mobile']) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label>تلفن ثابت</label>
                    <input type="text" name="phone" class="form-control" dir="ltr" value="<?= htmlspecialchars($customer['phone']) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label>ایمیل</label>
                    <input type="email" name="email" class="form-control" dir="ltr" value="<?= htmlspecialchars($customer['email']) ?>">
                </div>
                <div class="col-12 mb-3">
                    <label>آدرس</label>
                    <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($customer['address']) ?></textarea>
                </div>
                <div class="col-12 mb-3">
                    <label>توضیحات</label>
                    <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($customer['description']) ?></textarea>
                </div>
                <div class="col-md-6 mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="active" <?= $customer['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="active">فعال</label>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-modern">
                    <i class="fas fa-save"></i> ذخیره تغییرات
                </button>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> انصراف
                </a>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>