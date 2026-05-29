<?php
// admin/sms/get_template_content.php
header('Content-Type: application/json');
error_reporting(0); // جلوگیری از نمایش خطاهای PHP در خروجی

// مسیر صحیح به فایل db.php (5 پوشه بالا: admin/sms/ -> ../ + ../ + ../ + ../ + includes/db.php)
require_once '../../../includes/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode(['content' => '']);
    exit;
}

try {
    $stmt = $db->prepare("SELECT content FROM sms_templates WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['content' => $row['content'] ?? '']);
} catch (Exception $e) {
    // خطا لاگ شود اما خروجی JSON معتبر بدهد
    error_log("get_template_content.php error: " . $e->getMessage());
    echo json_encode(['content' => '', 'error' => 'خطای داخلی سرور']);
}
?>