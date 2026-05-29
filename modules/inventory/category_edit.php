<?php
$page_title = 'ویرایش دسته';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$id = (int)$_GET['id'];
if (!$id) {
    header('Location: categories.php');
    exit;
}
$stmt = $db->prepare("SELECT * FROM product_categories WHERE id = ?");
$stmt->execute([$id]);
$category = $stmt->fetch();
if (!$category) {
    echo '<div class="alert alert-danger">دسته یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    if (empty($name)) {
        $error = 'نام دسته الزامی است.';
    } else {
        $check = $db->prepare("SELECT id FROM product_categories WHERE name = ? AND id != ?");
        $check->execute([$name, $id]);
        if ($check->fetch()) {
            $error = 'این دسته قبلاً ثبت شده است.';
        } else {
            $upd = $db->prepare("UPDATE product_categories SET name = ?, parent_id = ? WHERE id = ?");
            $upd->execute([$name, $parent_id, $id]);
            header('Location: categories.php?msg=updated');
            exit;
        }
    }
}
$parent_cats = $db->query("SELECT id, name FROM product_categories WHERE id != $id ORDER BY name")->fetchAll();
?>
<div class="card">
    <div class="card-header">ویرایش دسته</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label>نام دسته</label>
                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($category['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label>دسته والد</label>
                <select name="parent_id" class="form-select">
                    <option value="">بدون والد</option>
                    <?php foreach ($parent_cats as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($category['parent_id'] == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">ذخیره</button>
            <a href="categories.php" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>