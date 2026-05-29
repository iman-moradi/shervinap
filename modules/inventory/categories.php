<?php
$page_title = 'مدیریت دسته‌بندی کالاها';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// حذف دسته
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // بررسی عدم استفاده در محصولات
    $check = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        $error = 'این دسته در محصولات استفاده شده است، قابل حذف نیست.';
    } else {
        $db->prepare("DELETE FROM product_categories WHERE id = ?")->execute([$id]);
        $success = 'دسته با موفقیت حذف شد.';
    }
}

$categories = $db->query("SELECT * FROM product_categories ORDER BY id DESC")->fetchAll();
?>
<div class="card">
    <div class="card-header">
        <a href="category_add.php" class="btn btn-primary">➕ افزودن دسته جدید</a>
    </div>
    <div class="card-body">
        <?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if (isset($success)): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <table class="table table-bordered table-striped">
            <thead>
                <tr><th>شناسه</th><th>نام دسته</th><th>دسته والد</th><th>عملیات</th></tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><?= $cat['id'] ?></td>
                    <td><?= htmlspecialchars($cat['name']) ?></td>
                    <td><?= $cat['parent_id'] ? $cat['parent_id'] : '-' ?></td>
                    <td>
                        <a href="category_edit.php?id=<?= $cat['id'] ?>" class="btn btn-sm btn-warning">✏️ ویرایش</a>
                        <a href="?delete=<?= $cat['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف شود؟')">🗑️ حذف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>