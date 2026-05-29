<?php
session_start();
require_once 'config/database.php';
require_once 'config/app.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $hashed = hash('sha256', $password);
    $stmt = $db->prepare("SELECT id, fullname, is_active FROM users WHERE username = :user AND password = :pass");
    $stmt->execute([':user' => $username, ':pass' => $hashed]);
    $user = $stmt->fetch();
    if ($user && $user['is_active'] == 1) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['fullname'] = $user['fullname'];
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    } else {
        $error = 'نام کاربری یا رمز عبور اشتباه است یا حساب کاربری غیرفعال می‌باشد.';
    }
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <title>ورود به سیستم - شروین</title>
    <link href="<?= BASE_URL ?>assets/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-header bg-primary text-white text-center">
                    <h4>مدیریت خدمات فنی شروین</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="mb-3">
                            <label>نام کاربری</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>رمز عبور</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">ورود</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= BASE_URL ?>assets/js/jquery-3.6.0.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>