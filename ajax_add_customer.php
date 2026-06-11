<?php
// حذف BOM و بافر خروجی
ob_start(function($buffer) {
    if (substr($buffer, 0, 3) == "\xEF\xBB\xBF") {
        $buffer = substr($buffer, 3);
    }
    return $buffer;
});

require_once __DIR__ . '/config/database.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

// بررسی لاگین
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

// دریافت داده‌ها
$fullname = trim($_POST['fullname'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$description = trim($_POST['description'] ?? '');

// اعتبارسنجی
if (empty($fullname) || empty($mobile)) {
    echo json_encode(['success' => false, 'message' => 'نام کامل و شماره موبایل الزامی است']);
    exit;
}

// بررسی تکراری نبودن موبایل
try {
    $check = $db->prepare("SELECT id FROM customers WHERE mobile = ?");
    $check->execute([$mobile]);
    if ($check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'این شماره موبایل قبلاً ثبت شده است']);
        exit;
    }

    // درج مشتری جدید
    $stmt = $db->prepare("INSERT INTO customers (fullname, mobile, phone, address, description, type, is_active) 
                           VALUES (?, ?, ?, ?, ?, 'customer', 1)");
    $result = $stmt->execute([$fullname, $mobile, $phone, $address, $description]);

    if ($result) {
        $newId = $db->lastInsertId();
        echo json_encode(['success' => true, 'customer_id' => $newId, 'fullname' => $fullname]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت اطلاعات در دیتابیس']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطای پایگاه داده: ' . $e->getMessage()]);
}