<?php
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// پاک کردن بافر قبل از ارسال JSON
ob_clean();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']);
    exit;
}

$id = (int)$_GET['id'];

try {
    $stmt = $db->prepare("SELECT id, transaction_date_sh, account_id, amount, type, description, category_id FROM transactions WHERE id = ?");
    $stmt->execute([$id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($transaction) {
        echo json_encode(['success' => true] + $transaction);
    } else {
        echo json_encode(['success' => false, 'message' => 'تراکنش یافت نشد']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}