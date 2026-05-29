<?php
$page_title = 'افزودن دسته جدید';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
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
        $check = $db->prepare("SELECT id FROM product_categories WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) {
            $error = 'این دسته قبلاً ثبت شده است.';
        } else {
            $stmt = $db->prepare("INSERT INTO product_categories (name, parent_id) VALUES (?, ?)");
            $stmt->execute([$name, $parent_id]);
            header('Location: categories.php?msg=added');
            exit;
        }
    }
}
$parent_cats = $db->query("SELECT id, name FROM product_categories ORDER BY name")->fetchAll();
?>
<div class="card">
    <div class="card-header">افزودن دسته جدید</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label>نام دسته</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>دسته والد (اختیاری)</label>
                <select name="parent_id" class="form-select">
                    <option value="">بدون والد</option>
                    <?php foreach ($parent_cats as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">ذخیره</button>
            <a href="categories.php" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>