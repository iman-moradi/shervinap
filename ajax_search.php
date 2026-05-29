<?php
// حذف هرگونه خروجی قبلی و BOM
if (ob_get_level()) ob_end_clean();
ob_start();

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

if (strlen($query) < 1) {
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

        case 'customer_search':
            $sql = "SELECT id, fullname, mobile, address FROM customers WHERE mobile LIKE :q OR fullname LIKE :q LIMIT 10";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $results = $stmt->fetchAll();
            break;


        case 'suppliers':
            $sql = "SELECT id, fullname, mobile, phone, address FROM customers WHERE type = 'supplier' AND (fullname LIKE :q OR mobile LIKE :q) LIMIT 10";
            $stmt = $db->prepare($sql);
            $stmt->execute([':q' => "%$query%"]);
            $results = $stmt->fetchAll();
            break;
            
        default:
            $results = [];
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای پایگاه داده: ' . $e->getMessage()]);
    exit;
}

// پاک کردن بافر و ارسال JSON خالص
ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($results);
exit;
?>