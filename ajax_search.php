<?php
// حذف BOM از خروجی
ob_start(function($buffer) {
    if (substr($buffer, 0, 3) == "\xEF\xBB\xBF") {
        $buffer = substr($buffer, 3);
    }
    return $buffer;
});

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/jdf.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'دسترسی غیرمجاز']);
    exit;
}

$type = $_GET['type'] ?? '';
$query = trim($_GET['query'] ?? '');

// برای calculate_delivery نیازی به حداقل طول نیست
if (strlen($query) < 1 && $type != 'calculate_delivery' && $type != 'get_product_by_id') {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$results = [];

try {
    switch ($type) {
        case 'reception':
            $sql = "SELECT r.id, r.ticket_no, c.fullname, c.mobile, r.device_type, r.status, r.total_cost, r.received_date_sh 
                    FROM repair_tickets r 
                    JOIN customers c ON c.id = r.customer_id 
                    WHERE c.mobile LIKE :q OR r.ticket_no LIKE :q 
                    ORDER BY r.id DESC LIMIT 50";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $results = $stmt->fetchAll();
            break;
            
        case 'customer_history':
            $sql = "SELECT id, fullname, mobile FROM customers WHERE mobile LIKE :q LIMIT 10";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $customers = $stmt->fetchAll();
            foreach ($customers as $c) {
                $tickets_sql = "SELECT id, ticket_no, device_type, status, received_date_sh, total_cost FROM repair_tickets WHERE customer_id = ? ORDER BY received_date_sh DESC";
                $t_stmt = $db->prepare($tickets_sql);
                $t_stmt->execute([$c['id']]);
                $c['tickets'] = $t_stmt->fetchAll();
                $results[] = $c;
            }
            break;
            
        case 'products':
            $sql = "SELECT id, sku, name, type, unit, current_stock, purchase_price, sale_price, min_stock_alert 
                    FROM products 
                    WHERE name LIKE :q OR sku LIKE :q 
                    ORDER BY name LIMIT 50";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $results = $stmt->fetchAll();
            break;

        case 'get_product_by_id':
            $product_id = (int)$query;
            if ($product_id > 0) {
                $stmt = $db->prepare("SELECT id, name, current_stock, sale_price, purchase_price FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $results = $stmt->fetchAll();
            }
            break;

        case 'customer_search':
            $sql = "SELECT id, fullname, mobile, address FROM customers WHERE mobile LIKE :q OR fullname LIKE :q LIMIT 10";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $results = $stmt->fetchAll();
            break;

        case 'suppliers':
            $sql = "SELECT id, fullname, mobile, phone, address 
                    FROM customers 
                    WHERE type IN ('supplier', 'partner') 
                    AND (fullname LIKE :q OR mobile LIKE :q) 
                    LIMIT 10";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $results = $stmt->fetchAll();
            break;
            
        case 'all_customers':
            $sql = "SELECT id, fullname, mobile, phone, address 
                    FROM customers 
                    WHERE (fullname LIKE :q OR mobile LIKE :q) 
                    LIMIT 15";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $results = $stmt->fetchAll();
            break;

        // ========== نوع جدید برای جستجوی مشتری با موبایل (فروش) ==========
        case 'customers_by_mobile':
            $sql = "SELECT id, fullname, mobile, phone, address 
                    FROM customers 
                    WHERE type = 'customer' AND mobile LIKE :q 
                    ORDER BY fullname LIMIT 20";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $results = $stmt->fetchAll();
            break;
            
        case 'calculate_delivery':
            $received_date = $_GET['received_date'] ?? '';
            $days = (int)($_GET['days'] ?? 0);
            
            if (empty($received_date) || $days <= 0) {
                echo json_encode(['success' => false, 'error' => 'پارامترهای نامعتبر']);
                exit;
            }
            
            $persian_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
            $received_date = str_replace($persian_numbers, $english_numbers, $received_date);
            
            require_once __DIR__ . '/includes/date_helper.php';
            $delivery_date = jalali_add_days($received_date, $days);
            
            if ($delivery_date && $delivery_date != '') {
                echo json_encode(['success' => true, 'delivery_date' => $delivery_date]);
            } else {
                echo json_encode(['success' => false, 'error' => 'خطا در محاسبه تاریخ - ورودی: ' . $received_date . ', روز: ' . $days]);
            }
            exit;
            
        default:
            $results = [];
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای پایگاه داده: ' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای عمومی: ' . $e->getMessage()]);
    exit;
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($results, JSON_UNESCAPED_UNICODE);
exit;