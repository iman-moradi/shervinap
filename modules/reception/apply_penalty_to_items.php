<?php
// خاموش کردن نمایش خطاهای وارنینگ و ناتیس برای جلوگیری از خراب شدن JSON
error_reporting(E_ERROR);
ini_set('display_errors', 0);
ob_start();

require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'دسترسی ندارید']);
    exit;
}

$ticket_id = (int)($_POST['ticket_id'] ?? 0);
if (!$ticket_id) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'شناسه تیکت نامعتبر']);
    exit;
}

// دریافت اطلاعات تیکت
$stmt = $db->prepare("SELECT total_cost, ready_date_sh, status FROM repair_tickets WHERE id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();
if (!$ticket || $ticket['status'] != 'ready') {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'تیکت در وضعیت آماده تحویل نیست']);
    exit;
}

// محاسبه روزهای تاخیر
$today = now_jalali();
$ready_ts = jalali_to_timestamp($ticket['ready_date_sh']);
$today_ts = jalali_to_timestamp($today);
$days_delay = floor(($today_ts - $ready_ts) / (60 * 60 * 24));
if ($days_delay <= 0) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'هیچ تاخیری ثبت نشده است']);
    exit;
}

$penalty = $days_delay * 10000;

// بررسی عدم ثبت قبلی
$check = $db->prepare("SELECT id FROM repair_items WHERE ticket_id = ? AND item_type = 'labor' AND description LIKE '%هزینه انبارداری%'");
$check->execute([$ticket_id]);
if ($check->fetch()) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'هزینه انبارداری قبلاً برای این تیکت ثبت شده است']);
    exit;
}

$db->beginTransaction();
try {
    // افزودن آیتم جدید
    $ins = $db->prepare("INSERT INTO repair_items 
        (ticket_id, item_type, description, quantity, unit_price, total_price, product_id) 
        VALUES (?, 'labor', ?, 1, ?, ?, NULL)");
    $desc = "هزینه انبارداری (تاخیر {$days_delay} روز)";
    $ins->execute([$ticket_id, $desc, $penalty, $penalty]);

    // بروزرسانی total_cost
    $new_total = $ticket['total_cost'] + $penalty;
    $upd = $db->prepare("UPDATE repair_tickets SET total_cost = ? WHERE id = ?");
    $upd->execute([$new_total, $ticket_id]);

    $db->commit();

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "هزینه انبارداری به مبلغ " . number_format($penalty) . " تومان با موفقیت اضافه شد",
        'new_total' => $new_total
    ]);
} catch (Exception $e) {
    $db->rollBack();
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'خطا: ' . $e->getMessage()]);
}