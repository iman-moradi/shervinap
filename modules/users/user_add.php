<?php
$page_title = 'افزودن کاربر جدید';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'users_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = hash('sha256', $_POST['password']);
    $fullname = $_POST['fullname'];
    $mobile = $_POST['mobile'];
    $email = $_POST['email'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // بررسی یکتایی نام کاربری
    $check = $db->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) {
        $error = 'نام کاربری تکراری است.';
    } else {
        $stmt = $db->prepare("INSERT INTO users (username, password, fullname, mobile, email, is_active) VALUES (?,?,?,?,?,?)");
        if ($stmt->execute([$username, $password, $fullname, $mobile, $email, $is_active])) {
            $user_id = $db->lastInsertId();
            // تنظیم مجوزها
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                foreach ($_POST['permissions'] as $perm_id) {
                    $ins = $db->prepare("INSERT INTO user_permissions (user_id, permission_id, granted) VALUES (?,?,1)");
                    $ins->execute([$user_id, $perm_id]);
                }
            }
            $success = 'کاربر با موفقیت اضافه شد.';
            
            // ========== شروع بخش اضافه شده برای ارسال پیامک ==========
            // فقط در صورتی که شماره موبایل وارد شده باشد و وضعیت فعال باشد، پیامک ارسال کن
            if (!empty($mobile) && $is_active == 1) {
                // فراخوانی فایل کلاس SMSManager
                require_once '../../includes/SMSManager.php';
                
                // ایجاد یک شی از کلاس SMSManager
                $sms = new SMSManager($db);
                
                // بررسی اینکه آیا سرویس پیامک فعال است یا خیر
                if ($sms->isAvailable()) {
                    // متن پیام خوش‌آمدگویی برای کاربر
                    $welcomeMessage = "{$fullname} عزیز، حساب کاربری شما با موفقیت در سیستم ایجاد شد.";
                    // ارسال پیامک
                    $smsResult = $sms->send($mobile, $welcomeMessage);
                    
                    // (اختیاری) در صورت بروز خطا، آن را لاگ کنید
                    if (!$smsResult['success']) {
                        error_log("خطا در ارسال پیامک خوش‌آمدگویی به {$mobile}: " . $smsResult['error']);
                    }
                }
            }
            // ========== پایان بخش اضافه شده ==========
            
            // ... (ادامه کدها، مانند ریدایرکت به لیست کاربران)
        } else {
            $error = 'خطا در درج کاربر.';
        }
    }
}

// دریافت لیست تمام مجوزها برای نمایش چک‌باکس‌ها
$permissions = $db->query("SELECT * FROM permissions ORDER BY module, id")->fetchAll();
?>
<div class="card">
    <div class="card-header">افزودن کاربر</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <form method="post">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label>نام کاربری *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label>رمز عبور *</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label>نام کامل</label>
                    <input type="text" name="fullname" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                    <label>موبایل</label>
                    <input type="text" name="mobile" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                    <label>ایمیل</label>
                    <input type="email" name="email" class="form-control">
                </div>
                <div class="col-md-6 mb-3 form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="active" checked>
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
                ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $perm['id'] ?>" id="perm_<?= $perm['id'] ?>">
                        <label class="form-check-label" for="perm_<?= $perm['id'] ?>"><?= $perm['permission_name'] ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary">ذخیره</button>
            <a href="users.php" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>