<?php
$page_title = 'ویرایش کالا';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$product_id = (int)$_GET['id'];
if (!$product_id) {
    header('Location: products.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();
if (!$product) {
    echo '<div class="alert alert-danger">کالا یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // اول اطلاعات پایه کالا را به‌روز می‌کنیم
    $sku = trim($_POST['sku']);
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $unit = trim($_POST['unit']);
    $purchase_price = (int)$_POST['purchase_price'];
    $sale_price = (int)$_POST['sale_price'];
    $min_stock_alert = (int)$_POST['min_stock_alert'];
    $storage_location = trim($_POST['storage_location'] ?? '');
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    
    // بررسی تکراری نبودن نام + نوع
    $check = $db->prepare("SELECT id FROM products WHERE name = ? AND type = ? AND id != ?");
    $check->execute([$name, $type, $product_id]);
    if ($check->fetch()) {
        $error = '❌ کالایی با همین نام و نوع قبلاً ثبت شده است.';
    } else {
        // به‌روزرسانی اطلاعات اصلی کالا
        $upd = $db->prepare("UPDATE products SET sku=?, name=?, category_id=?, type=?, unit=?, purchase_price=?, sale_price=?, min_stock_alert=?, storage_location=? WHERE id=?");
        if ($upd->execute([$sku, $name, $category_id, $type, $unit, $purchase_price, $sale_price, $min_stock_alert, $storage_location, $product_id])) {
            $success = '✅ اطلاعات کالا با موفقیت ویرایش شد.';
            // بارگذاری مجدد اطلاعات
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            
            // ------ بخش تعدیل موجودی ------
            $stock_adjustment = (int)($_POST['stock_adjustment'] ?? 0);
            $adjustment_reason = trim($_POST['adjustment_reason'] ?? '');
            if ($stock_adjustment != 0) {
                $new_stock = $product['current_stock'] + $stock_adjustment;
                if ($new_stock < 0) {
                    $error = '⚠️ موجودی نمی‌تواند منفی شود. تعدیل انجام نشد.';
                } else {
                    // بروزرسانی موجودی در جدول products
                    $upd_stock = $db->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
                    if ($upd_stock->execute([$new_stock, $product_id])) {
                        // ثبت در stock_movements برای پیگیری (بدون اثر حسابداری)
                        $movement_type = ($stock_adjustment > 0) ? 'in' : 'out';
                        $abs_qty = abs($stock_adjustment);
                        $ref_type = 'adjustment';
                        $ref_id = null; // یا می‌توانید یک آی‌دی از جدول تنظیمات داشته باشید
                        $ins_mov = $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        // قیمت را صفر در نظر می‌گیریم چون این تعدیل حسابداری ندارد
                        $ins_mov->execute([$product_id, $movement_type, $abs_qty, 0, $ref_type, $ref_id]);
                        
                        // در صورت وجود توضیح، می‌توان در جدول جداگانه‌ای ذخیره کرد، ولی به همان stock_movements اکتفا می‌کنیم
                        // (اختیاری: می‌توانید یک ستون description به stock_movements اضافه کنید)
                        
                        $success .= ' / موجودی با موفقیت تعدیل شد.';
                        // بارگذاری مجدد محصول
                        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
                        $stmt->execute([$product_id]);
                        $product = $stmt->fetch();
                    } else {
                        $error = '⚠️ خطا در بروزرسانی موجودی.';
                    }
                }
            }
        } else {
            $error = 'خطا در ویرایش کالا.';
        }
    }
}

$categories = $db->query("SELECT id, name FROM product_categories ORDER BY name")->fetchAll();
?>
<div class="card">
    <div class="card-header">✏️ ویرایش کالا</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <form method="post">
            <div class="row">
                <div class="col-md-3 mb-3"><label>کد کالا (SKU)</label><input type="text" name="sku" class="form-control" value="<?= htmlspecialchars($product['sku']) ?>" required></div>
                <div class="col-md-3 mb-3"><label>نام کالا</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($product['name']) ?>" required></div>
                <div class="col-md-3 mb-3"><label>نوع کالا</label>
                    <select name="type" class="form-select" required>
                        <option value="new_part" <?= $product['type']=='new_part' ? 'selected' : '' ?>>قطعه نو</option>
                        <option value="used_part" <?= $product['type']=='used_part' ? 'selected' : '' ?>>قطعه دست دوم</option>
                        <option value="new_appliance" <?= $product['type']=='new_appliance' ? 'selected' : '' ?>>لوازم خانگی نو</option>
                        <option value="used_appliance" <?= $product['type']=='used_appliance' ? 'selected' : '' ?>>لوازم خانگی دست دوم</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3"><label>دسته‌بندی</label>
                    <select name="category_id" class="form-select">
                        <option value="">بدون دسته</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($product['category_id'] == $cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3"><label>واحد</label><input type="text" name="unit" class="form-control" value="<?= htmlspecialchars($product['unit']) ?>"></div>
                
                <?php
                $can_edit_purchase = has_permission($_SESSION['user_id'], 'edit_purchase_price');
                $can_view_purchase = has_permission($_SESSION['user_id'], 'view_purchase_price'); // اگر بخواهید فقط نمایش بدید بدون ویرایش
                ?>

                <!-- فقط در صورت داشتن مجوز ویرایش، فیلد قیمت خرید نمایش داده شود -->
                <?php if ($can_edit_purchase): ?>
                <div class="col-md-2 mb-3">
                    <label>قیمت خرید (تومان)</label>
                    <input type="number" name="purchase_price" class="form-control" 
                        value="<?= htmlspecialchars($product['purchase_price']) ?>">
                </div>
                <?php endif; ?>

                <div class="col-md-2 mb-3"><label>قیمت فروش (تومان)</label><input type="number" name="sale_price" class="form-control" value="<?= $product['sale_price'] ?>"></div>
                <div class="col-md-2 mb-3"><label>هشدار کمبود</label><input type="number" name="min_stock_alert" class="form-control" value="<?= $product['min_stock_alert'] ?>"></div>
                <div class="col-md-3 mb-3"><label>محل نگهداری</label><input type="text" name="storage_location" class="form-control" value="<?= htmlspecialchars($product['storage_location']) ?>"></div>
                <div class="col-md-2 mb-3"><label>موجودی فعلی</label><input type="text" class="form-control" value="<?= $product['current_stock'] ?>" disabled></div>
            </div>

            <!-- بخش تعدیل موجودی (اختیاری و بدون اثر حسابداری) -->
            <hr>
            <h5>⚙️ تعدیل موجودی (اختیاری)</h5>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label>تغییر موجودی</label>
                    <input type="number" name="stock_adjustment" class="form-control" value="0" step="1" placeholder="مثبت = افزایش، منفی = کاهش">
                    <small class="text-muted">عدد مثبت: افزایش موجودی | عدد منفی: کاهش موجودی</small>
                </div>
                <div class="col-md-5 mb-3">
                    <label>توضیح تعدیل (دلیل)</label>
                    <input type="text" name="adjustment_reason" class="form-control" placeholder="مثال: تصحیح موجودی اولیه، شکستگی، اهدایی و ...">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">💾 ذخیره تغییرات</button>
            <a href="products.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>