<?php
// شروع بافر خروجی برای جلوگیری از خطاهای هدر
ob_start();

// بارگذاری فایل‌های مورد نیاز
require_once '../../config/database.php';
require_once '../../includes/functions.php'; // تابع has_permission
require_once '../../includes/date_helper.php';

session_start();

// تنظیم هدر JSON
header('Content-Type: application/json; charset=utf-8');

// بررسی دسترسی
if (!isset($_SESSION['user_id']) || !has_permission($_SESSION['user_id'], 'reception_access')) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'دسترسی غیرمجاز']);
    exit;
}

// دریافت پارامترها
$ticket_id = (int)($_POST['ticket_id'] ?? 0);
$service_id = (int)($_POST['service_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 1);
if ($quantity < 1) $quantity = 1;

if (!$ticket_id || !$service_id) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'اطلاعات ناقص']);
    exit;
}

try {
    // دریافت اطلاعات خدمت از دیتابیس
    $stmt = $db->prepare("SELECT * FROM repair_services WHERE id = ? AND is_active = 1");
    $stmt->execute([$service_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        throw new Exception('خدمت مورد نظر یافت نشد یا غیرفعال است');
    }
    
    $unit_price = (int)$service['price'];
    $description = $service['name'];
    $total_price = $quantity * $unit_price;
    
    // شروع تراکنش
    $db->beginTransaction();
    
    // درج در جدول repair_items با نوع labor
    $ins = $db->prepare("INSERT INTO repair_items (ticket_id, item_type, description, quantity, unit_price, total_price) VALUES (?, 'labor', ?, ?, ?, ?)");
    $ins->execute([$ticket_id, $description, $quantity, $unit_price, $total_price]);
    
    // به‌روزرسانی total_cost تیکت
    $update = $db->prepare("UPDATE repair_tickets SET total_cost = (SELECT COALESCE(SUM(total_price),0) FROM repair_items WHERE ticket_id = ?) WHERE id = ?");
    $update->execute([$ticket_id, $ticket_id]);
    
    $db->commit();
    
    ob_end_clean();
    echo json_encode(['success' => true, 'message' => 'خدمت با موفقیت اضافه شد']);
    
} catch (Exception $e) {
    $db->rollBack();
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>