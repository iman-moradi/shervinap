<?php
require_once '../../config/database.php';
require_once '../../includes/SMSManager.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'دسترسی غیرمجاز']);
    exit;
}

$type = $_POST['type'] ?? 'customer';
$fullname = trim($_POST['fullname'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$description = trim($_POST['description'] ?? '');
$is_active = isset($_POST['is_active']) ? 1 : 0;

if (empty($fullname)) {
    echo json_encode(['success' => false, 'error' => 'نام کامل الزامی است']);
    exit;
}
if (empty($mobile)) {
    echo json_encode(['success' => false, 'error' => 'شماره موبایل الزامی است']);
    exit;
}

try {
    // بررسی عدم تکراری بودن موبایل
    $check = $db->prepare("SELECT id FROM customers WHERE mobile = ?");
    $check->execute([$mobile]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'این شماره موبایل قبلاً ثبت شده است']);
        exit;
    }
    
    $stmt = $db->prepare("INSERT INTO customers (type, fullname, mobile, phone, email, address, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$type, $fullname, $mobile, $phone, $email, $address, $description, $is_active]);
    $newId = $db->lastInsertId();
    
    // ارسال پیامک خوش‌آمدگویی (اختیاری)
    if (!empty($mobile) && $is_active == 1) {
        require_once '../../includes/SMSManager.php';
        $sms = new SMSManager($db);
        if ($sms->isAvailable()) {
            $welcomeMessage = "{$fullname} گرامی، به خدمات فنی شروین خوش آمدید.";
            $sms->send($mobile, $welcomeMessage, 'auto_welcome');
        }
    }
    
    echo json_encode([
        'success' => true,
        'customer_id' => $newId,
        'fullname' => $fullname,
        'mobile' => $mobile,
        'address' => $address
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'خطا در ثبت: ' . $e->getMessage()]);
}
?>