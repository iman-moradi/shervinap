<?php
$page_title = 'مدیریت قالب‌های پیامکی';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'settings_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ایجاد جدول قالب‌ها در صورت عدم وجود
$db->exec("CREATE TABLE IF NOT EXISTS `sms_templates` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `title` varchar(200) NOT NULL,
    `content` text NOT NULL,
    `type` enum('reminder','promotion','info','other') DEFAULT 'other',
    `is_active` tinyint(1) DEFAULT 1,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// حذف
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->prepare("DELETE FROM sms_templates WHERE id = ?")->execute([$id]);
    header("Location: templates.php");
    exit;
}

// ویرایش یا افزودن
$edit = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM sms_templates WHERE id = ?");
    $stmt->execute([$id]);
    $edit = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $type = $_POST['type'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (isset($_POST['id']) && $_POST['id'] > 0) {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("UPDATE sms_templates SET name=?, title=?, content=?, type=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $title, $content, $type, $is_active, $id]);
    } else {
        $stmt = $db->prepare("INSERT INTO sms_templates (name, title, content, type, is_active) VALUES (?,?,?,?,?)");
        $stmt->execute([$name, $title, $content, $type, $is_active]);
    }
    header("Location: templates.php");
    exit;
}

$templates = $db->query("SELECT * FROM sms_templates ORDER BY id DESC");
?>
<div class="card">
    <div class="card-header d-flex justify-content-between">
        <span>📝 قالب‌های پیامکی</span>
        <a href="?edit=0" class="btn btn-sm btn-success">➕ افزودن قالب جدید</a>
    </div>
    <div class="card-body">
        <?php if ($edit): ?>
            <form method="post">
                <input type="hidden" name="id" value="<?= $edit['id'] ?>">
                <div class="mb-3"><label>نام داخلی</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($edit['name']) ?>" required></div>
                <div class="mb-3"><label>عنوان نمایشی</label><input type="text" name="title" class="form-control" value="<?= htmlspecialchars($edit['title']) ?>" required></div>
                <div class="mb-3"><label>نوع</label><select name="type" class="form-control"><option value="reminder" <?= $edit['type']=='reminder'?'selected':'' ?>>یادآوری</option><option value="promotion" <?= $edit['type']=='promotion'?'selected':'' ?>>تبلیغاتی</option><option value="info" <?= $edit['type']=='info'?'selected':'' ?>>اطلاع‌رسانی</option><option value="other" <?= $edit['type']=='other'?'selected':'' ?>>سایر</option></select></div>
                <div class="mb-3"><label>متن قالب</label><textarea name="content" class="form-control" rows="5" required><?= htmlspecialchars($edit['content']) ?></textarea></div>
                <div class="form-check mb-3"><input type="checkbox" name="is_active" class="form-check-input" id="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>><label for="is_active">فعال</label></div>
                <button type="submit" class="btn btn-primary">ذخیره</button>
                <a href="templates.php" class="btn btn-secondary">انصراف</a>
            </form>
            <hr>
        <?php endif; ?>
        
        <table class="table table-bordered">
            <thead><tr><th>نام</th><th>عنوان</th><th>نوع</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
                <?php while($tpl = $templates->fetch()): ?>
                <tr>
                    <td><?= htmlspecialchars($tpl['name']) ?></td>
                    <td><?= htmlspecialchars($tpl['title']) ?></td>
                    <td><?= $tpl['type'] ?></td>
                    <td><?= $tpl['is_active'] ? '<span class="badge bg-success">فعال</span>' : '<span class="badge bg-secondary">غیرفعال</span>' ?></td>
                    <td>
                        <a href="?edit=<?= $tpl['id'] ?>" class="btn btn-sm btn-warning">✏️</a>
                        <a href="?delete=<?= $tpl['id'] ?>" onclick="return confirm('حذف شود؟')" class="btn btn-sm btn-danger">🗑️</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>