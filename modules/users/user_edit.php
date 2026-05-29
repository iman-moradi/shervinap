<?php
$page_title = 'ویرایش کاربر';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'users_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$user_id = $_GET['id'] ?? 0;
if (!$user_id) {
    header('Location: users.php');
    exit;
}

$error = '';
$success = '';

// دریافت اطلاعات کاربر
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
if (!$user) {
    echo '<div class="alert alert-danger">کاربر یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// پردازش فرم ویرایش
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $fullname = $_POST['fullname'];
    $mobile = $_POST['mobile'];
    $email = $_POST['email'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // بررسی یکتایی نام کاربری (به جز خود کاربر)
    $check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check->execute([$username, $user_id]);
    if ($check->fetch()) {
        $error = 'نام کاربری تکراری است.';
    } else {
        // به‌روزرسانی اطلاعات پایه
        $sql = "UPDATE users SET username = ?, fullname = ?, mobile = ?, email = ?, is_active = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        if ($stmt->execute([$username, $fullname, $mobile, $email, $is_active, $user_id])) {
            // در صورت تمایل به تغییر رمز عبور
            if (!empty($_POST['new_password'])) {
                $new_pass = hash('sha256', $_POST['new_password']);
                $pass_stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $pass_stmt->execute([$new_pass, $user_id]);
            }
            
            // به‌روزرسانی دسترسی‌ها: ابتدا دسترسی‌های قبلی را پاک می‌کنیم
            $del = $db->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $del->execute([$user_id]);
            
            // درج مجوزهای جدید (از آرایه ارسالی)
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                foreach ($_POST['permissions'] as $perm_id) {
                    $ins = $db->prepare("INSERT INTO user_permissions (user_id, permission_id, granted) VALUES (?,?,1)");
                    $ins->execute([$user_id, $perm_id]);
                }
            }
            $success = 'اطلاعات کاربر با موفقیت به‌روزرسانی شد.';
            // بارگذاری مجدد اطلاعات کاربر
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        } else {
            $error = 'خطا در به‌روزرسانی.';
        }
    }
}

// دریافت لیست تمام مجوزها
$permissions = $db->query("SELECT * FROM permissions ORDER BY module, id")->fetchAll();

// دریافت مجوزهای فعلی کاربر
$user_perms = [];
$perm_stmt = $db->prepare("SELECT permission_id FROM user_permissions WHERE user_id = ? AND granted = 1");
$perm_stmt->execute([$user_id]);
while ($row = $perm_stmt->fetch()) {
    $user_perms[] = $row['permission_id'];
}
?>

<div class="card">
    <div class="card-header">ویرایش کاربر: <?= htmlspecialchars($user['fullname']) ?></div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <form method="post">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label>نام کاربری *</label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label>رمز عبور جدید (در صورت تمایل به تغییر)</label>
                    <input type="password" name="new_password" class="form-control" placeholder="در صورت عدم تغییر خالی بگذارید">
                </div>
                <div class="col-md-6 mb-3">
                    <label>نام کامل</label>
                    <input type="text" name="fullname" class="form-control" value="<?= htmlspecialchars($user['fullname']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label>موبایل</label>
                    <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($user['mobile']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label>ایمیل</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>">
                </div>
                <div class="col-md-6 mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="active" <?= $user['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="active">فعال</label>
                </div>
            </div>
            <div class="mb-3">
                <label>دسترسی‌ها</label><br>
                <?php 
                $current_module = '';
                foreach ($permissions as $perm):
                    if ($current_module != $perm['module']):
                        $current_module = $perm['module'];
                        echo "<strong>{$current_module}</strong><br>";
                    endif;
                    $checked = in_array($perm['id'], $user_perms) ? 'checked' : '';
                ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $perm['id'] ?>" id="perm_<?= $perm['id'] ?>" <?= $checked ?>>
                        <label class="form-check-label" for="perm_<?= $perm['id'] ?>"><?= $perm['permission_name'] ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            <a href="users.php" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>