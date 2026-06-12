<?php
// ==================== پردازش فرم و ریدایرکت (قبل از هر گونه خروجی) ====================
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// بررسی دسترسی
if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    $access_denied = true;
} else {
    $access_denied = false;
}

$error = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// اگر آیدی معتبر نبود، به صفحه لیست هدایت کن
if (!$id && !$access_denied) {
    header('Location: categories.php');
    exit;
}

// دریافت اطلاعات دسته (برای استفاده در فرم)
$category = null;
if (!$access_denied && $id) {
    $stmt = $db->prepare("SELECT * FROM product_categories WHERE id = ?");
    $stmt->execute([$id]);
    $category = $stmt->fetch();
    if (!$category) {
        // دسته یافت نشد - می‌توانیم بعداً خطا نمایش دهیم
        $category_not_found = true;
    } else {
        $category_not_found = false;
    }
}

// پردازش فرم
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$access_denied && !$category_not_found) {
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

// ==================== شروع صفحه (بعد از پردازش) ====================
$page_title = 'ویرایش دسته';
require_once '../../includes/header.php';

if ($access_denied) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

if ($category_not_found) {
    echo '<div class="alert alert-danger">دسته یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// دریافت لیست دسته‌های والد (به جز خود این دسته)
$parent_cats = $db->prepare("SELECT id, name FROM product_categories WHERE id != ? ORDER BY name");
$parent_cats->execute([$id]);
$parent_cats = $parent_cats->fetchAll();
?>

<div class="card">
    <div class="card-header">ویرایش دسته</div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
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
                        <option value="<?= $p['id'] ?>" <?= ($category['parent_id'] == $p['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">ذخیره</button>
            <a href="categories.php" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>