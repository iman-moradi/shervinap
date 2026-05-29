<?php
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'customers_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$id = (int)$_GET['id'];
if ($id) {
    // بررسی استفاده در جداول دیگر (اختیاری)
    $check = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE customer_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        echo '<div class="alert alert-danger">این شخص در تعمیرات استفاده شده است، قابل حذف نیست.</div>';
        echo '<a href="index.php" class="btn btn-secondary">بازگشت</a>';
        require_once '../../includes/footer.php';
        exit;
    }
    $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
}
header('Location: index.php');
exit;