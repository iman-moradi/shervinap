<?php
$page_title = 'مدیریت کاربران';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'users_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// حذف کاربر (اختیاری)
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    // جلوگیری از حذف خود کاربر جاری
    if ($id != $_SESSION['user_id']) {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        echo '<div class="alert alert-success">کاربر حذف شد.</div>';
    } else {
        echo '<div class="alert alert-danger">نمی‌توانید خودتان را حذف کنید.</div>';
    }
}

// دریافت لیست کاربران
$users = $db->query("SELECT id, username, fullname, mobile, email, is_active FROM users ORDER BY id DESC")->fetchAll();
?>
<div class="card">
    <div class="card-header">
        <a href="user_add.php" class="btn btn-primary btn-sm">افزودن کاربر جدید</a>
    </div>
    <div class="card-body">
        <table class="table table-bordered table-striped">
            <thead>
                <tr><th>ID</th><th>نام کاربری</th><th>نام کامل</th><th>موبایل</th><th>فعال</th><th>عملیات</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= $user['id'] ?></td>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td><?= htmlspecialchars($user['fullname']) ?></td>
                    <td><?= htmlspecialchars($user['mobile']) ?></td>
                    <td><?= $user['is_active'] ? 'بله' : 'خیر' ?></td>
                    <td>
                        <a href="user_edit.php?id=<?= $user['id'] ?>" class="btn btn-warning btn-sm">ویرایش</a>
                        <a href="?delete=<?= $user['id'] ?>" onclick="return confirm('آیا مطمئنید؟')" class="btn btn-danger btn-sm">حذف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>