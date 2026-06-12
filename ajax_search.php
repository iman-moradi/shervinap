<?php
// حذف BOM از خروجی (قبل از هر چیز)
ob_start(function($buffer) {
    if (substr($buffer, 0, 3) == "\xEF\xBB\xBF") {
        $buffer = substr($buffer, 3);
    }
    return $buffer;
});

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/jdf.php';
session_start();

// تنظیم هدرها برای JSON
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'دسترسی غیرمجاز']);
    exit;
}

$type = $_GET['type'] ?? '';
$query = trim($_GET['query'] ?? '');

// پارامترهای صفحه‌بندی برای نوع customers_manage
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$allowed_per_page = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page)) $per_page = 20;
$offset = ($page - 1) * $per_page;

try {
    switch ($type) {
        case 'customers_manage':
            $type_filter = $_GET['type_filter'] ?? 'all';
            $search_query = trim($_GET['query'] ?? '');
            
            $base_sql = "FROM customers WHERE 1=1";
            $params = [];
            
            if ($type_filter != 'all') {
                $base_sql .= " AND type = ?";
                $params[] = $type_filter;
            }
            if (!empty($search_query)) {
                $base_sql .= " AND (fullname LIKE ? OR mobile LIKE ? OR email LIKE ?)";
                $like = "%$search_query%";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            
            // شمارش کل رکوردها
            $count_sql = "SELECT COUNT(*) " . $base_sql;
            $stmt = $db->prepare($count_sql);
            $stmt->execute($params);
            $total_records = $stmt->fetchColumn();
            $total_pages = ceil($total_records / $per_page);
            
            // اصلاح صفحه اگر از تعداد صفحات بیشتر باشد
            if ($page > $total_pages && $total_pages > 0) {
                $page = $total_pages;
                $offset = ($page - 1) * $per_page;
            }
            
            // دریافت رکوردهای صفحه جاری
            $select_sql = "SELECT * " . $base_sql . " ORDER BY type, fullname LIMIT ? OFFSET ?";
            $stmt = $db->prepare($select_sql);
            $idx = 1;
            foreach ($params as $p) $stmt->bindValue($idx++, $p);
            $stmt->bindValue($idx++, $per_page, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $customers_data = $stmt->fetchAll();
            
            // تولید HTML جدول و صفحه‌بندی
            $html_table = render_customers_table($customers_data);
            $html_pagination = render_pagination($total_pages, $page, $per_page, $type_filter, $search_query, $total_records);
            
            echo json_encode([
                'html_table' => $html_table,
                'html_pagination' => $html_pagination,
                'total_records' => $total_records,
                'page' => $page,
                'total_pages' => $total_pages
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
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
            $results = [];
            foreach ($customers as $c) {
                $tickets_sql = "SELECT id, ticket_no, device_type, status, received_date_sh, total_cost FROM repair_tickets WHERE customer_id = ? ORDER BY received_date_sh DESC";
                $t_stmt = $db->prepare($tickets_sql);
                $t_stmt->execute([$c['id']]);
                $c['tickets'] = $t_stmt->fetchAll();
                $results[] = $c;
            }
            break;
            
        case 'standard_services':
            $stmt = $db->query("SELECT id, name, price, description FROM repair_services WHERE is_active = 1 ORDER BY name");
            $results = $stmt->fetchAll();
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
            } else {
                $results = [];
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
            $base_sql = "FROM customers WHERE (fullname LIKE :q OR mobile LIKE :q OR email LIKE :q)";
            $like = "%$query%";
            $count_sql = "SELECT COUNT(*) " . $base_sql;
            $stmt = $db->prepare($count_sql);
            $stmt->execute([':q' => $like]);
            $total_records = $stmt->fetchColumn();
            
            $select_sql = "SELECT * " . $base_sql . " ORDER BY type, fullname LIMIT ? OFFSET ?";
            $stmt = $db->prepare($select_sql);
            $stmt->bindValue(1, $like);
            $stmt->bindValue(2, $like);
            $stmt->bindValue(3, $like);
            $stmt->bindValue(4, $per_page, PDO::PARAM_INT);
            $stmt->bindValue(5, $offset, PDO::PARAM_INT);
            $stmt->execute();
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
                echo json_encode(['success' => false, 'error' => 'خطا در محاسبه تاریخ']);
            }
            exit;
            
        case 'customer_history_advanced':
            $name = $_GET['name'] ?? '';
            $mobile = $_GET['mobile'] ?? '';
            $serial = $_GET['serial'] ?? '';
            $ticket_no = $_GET['ticket_no'] ?? '';
            $status = $_GET['status'] ?? '';
            $date_from = $_GET['date_from'] ?? '';
            $date_to = $_GET['date_to'] ?? '';
            
            // بررسی نام جدول صحیح - reception_tickets یا repair_tickets؟
            // ابتدا بررسی کنید کدام جدول وجود دارد
            $table_check = $db->query("SHOW TABLES LIKE 'reception_tickets'");
            $use_reception = ($table_check->rowCount() > 0);
            $tickets_table = $use_reception ? 'reception_tickets' : 'repair_tickets';
            
            $query = "SELECT DISTINCT c.id, c.fullname, c.mobile, c.email 
                      FROM customers c 
                      LEFT JOIN {$tickets_table} rt ON c.id = rt.customer_id 
                      WHERE 1=1";
            $params = [];
            
            if (!empty($name)) {
                $query .= " AND c.fullname LIKE ?";
                $params[] = "%$name%";
            }
            if (!empty($mobile)) {
                $query .= " AND c.mobile LIKE ?";
                $params[] = "%$mobile%";
            }
            if (!empty($serial)) {
                $query .= " AND rt.serial_no LIKE ?";
                $params[] = "%$serial%";
            }
            if (!empty($ticket_no)) {
                $query .= " AND rt.ticket_no LIKE ?";
                $params[] = "%$ticket_no%";
            }
            if (!empty($status)) {
                $query .= " AND rt.status = ?";
                $params[] = $status;
            }
            if (!empty($date_from)) {
                $query .= " AND rt.received_date_sh >= ?";
                $params[] = $date_from;
            }
            if (!empty($date_to)) {
                $query .= " AND rt.received_date_sh <= ?";
                $params[] = $date_to;
            }
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $result = [];
            foreach ($customers as $customer) {
                $ticketQuery = "SELECT * FROM {$tickets_table} WHERE customer_id = ?";
                $ticketParams = [$customer['id']];
                
                if (!empty($serial)) {
                    $ticketQuery .= " AND serial_no LIKE ?";
                    $ticketParams[] = "%$serial%";
                }
                if (!empty($ticket_no)) {
                    $ticketQuery .= " AND ticket_no LIKE ?";
                    $ticketParams[] = "%$ticket_no%";
                }
                if (!empty($status)) {
                    $ticketQuery .= " AND status = ?";
                    $ticketParams[] = $status;
                }
                if (!empty($date_from)) {
                    $ticketQuery .= " AND received_date_sh >= ?";
                    $ticketParams[] = $date_from;
                }
                if (!empty($date_to)) {
                    $ticketQuery .= " AND received_date_sh <= ?";
                    $ticketParams[] = $date_to;
                }
                
                $ticketQuery .= " ORDER BY received_date_sh DESC";
                
                $ticketStmt = $db->prepare($ticketQuery);
                $ticketStmt->execute($ticketParams);
                $tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $result[] = [
                    'id' => $customer['id'],
                    'fullname' => $customer['fullname'],
                    'mobile' => $customer['mobile'],
                    'email' => $customer['email'],
                    'tickets' => $tickets
                ];
            }
            
            echo json_encode(['customers' => $result], JSON_UNESCAPED_UNICODE);
            exit;
            
        case 'ticket_detail_advanced':
            $ticket_id = (int)($_GET['id'] ?? 0);
            
            if ($ticket_id <= 0) {
                echo json_encode(['error' => 'شناسه تیکت نامعتبر'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // بررسی نام جدول صحیح
            $table_check = $db->query("SHOW TABLES LIKE 'reception_tickets'");
            $use_reception = ($table_check->rowCount() > 0);
            $tickets_table = $use_reception ? 'reception_tickets' : 'repair_tickets';
            
            // دریافت اطلاعات تیکت
            $stmt = $db->prepare("
                SELECT rt.*, c.fullname as customer_name, c.mobile as customer_mobile, c.email as customer_email
                FROM {$tickets_table} rt
                JOIN customers c ON rt.customer_id = c.id
                WHERE rt.id = ?
            ");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ticket) {
                echo json_encode(['error' => 'تیکت مورد نظر یافت نشد'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // دریافت تعمیرات (اگر جدول repairs وجود دارد)
            $repairs = [];
            $repairs_table_check = $db->query("SHOW TABLES LIKE 'repairs'");
            if ($repairs_table_check->rowCount() > 0) {
                $repairStmt = $db->prepare("
                    SELECT r.*, u.fullname as technician_name
                    FROM repairs r
                    LEFT JOIN users u ON r.technician_id = u.id
                    WHERE r.ticket_id = ?
                    ORDER BY r.repair_date DESC
                ");
                $repairStmt->execute([$ticket_id]);
                $repairs = $repairStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // دریافت قطعات (اگر جدول ticket_parts وجود دارد)
            $parts = [];
            $parts_table_check = $db->query("SHOW TABLES LIKE 'ticket_parts'");
            if ($parts_table_check->rowCount() > 0) {
                $partsStmt = $db->prepare("
                    SELECT tp.*, p.name as product_name
                    FROM ticket_parts tp
                    JOIN products p ON tp.product_id = p.id
                    WHERE tp.ticket_id = ?
                ");
                $partsStmt->execute([$ticket_id]);
                $parts = $partsStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'ticket' => $ticket,
                'repairs' => $repairs,
                'parts' => $parts
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        default:
            $results = [];
            echo json_encode($results, JSON_UNESCAPED_UNICODE);
            exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای پایگاه داده: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطای عمومی: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// برای سایر انواع (غیر از مواردی که قبلاً exit کردند)
if (!in_array($type, ['customers_manage', 'calculate_delivery', 'customer_history_advanced', 'ticket_detail_advanced'])) {
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== توابع کمکی ==========
function render_customers_table($customers) {
    if (empty($customers)) {
        return '<tr><td colspan="8" class="text-center text-muted py-4">هیچ شخصی یافت نشد.</td></tr>';
    }
    $html = '';
    foreach ($customers as $c) {
        switch($c['type']) {
            case 'customer': $type_label = 'مشتری'; break;
            case 'supplier': $type_label = 'تأمین‌کننده'; break;
            case 'partner': $type_label = 'همکار'; break;
            default: $type_label = $c['type'];
        }
        $active_icon = $c['is_active'] ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-danger"></i>';
        $html .= '<tr>
            <td><span class="badge bg-secondary">' . htmlspecialchars($type_label) . '</span></td>
            <td>' . htmlspecialchars($c['fullname']) . '</td>
            <td dir="ltr">' . htmlspecialchars($c['mobile']) . '</td>
            <td dir="ltr">' . htmlspecialchars($c['phone']) . '</td>
            <td>' . htmlspecialchars($c['email']) . '</td>
            <td class="text-truncate" style="max-width:180px;" title="' . htmlspecialchars($c['address']) . '">' . htmlspecialchars($c['address']) . '</td>
            <td>' . $active_icon . '</td>
            <td>
                <a href="edit.php?id=' . $c['id'] . '" class="btn btn-sm btn-outline-warning"><i class="fas fa-edit"></i></a>
                <a href="delete.php?id=' . $c['id'] . '" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'حذف شود؟\')"><i class="fas fa-trash-alt"></i></a>
              </td>
         </tr>';
    }
    return $html;
}

function render_pagination($total_pages, $page, $per_page, $type_filter, $search_query, $total_records) {
    if ($total_pages <= 1) return '';
    $html = '<nav aria-label="Page navigation" class="mt-3"><ul class="pagination justify-content-center flex-wrap">';
    if ($page > 1) {
        $html .= '<li class="page-item"><a class="page-link" data-page="1" href="#">اول</a></li>';
        $html .= '<li class="page-item"><a class="page-link" data-page="' . ($page-1) . '" href="#">قبلی</a></li>';
    }
    $start = max(1, $page-2);
    $end = min($total_pages, $page+2);
    for ($i=$start; $i<=$end; $i++) {
        $active = ($i == $page) ? 'active' : '';
        $html .= '<li class="page-item ' . $active . '"><a class="page-link" data-page="' . $i . '" href="#">' . $i . '</a></li>';
    }
    if ($page < $total_pages) {
        $html .= '<li class="page-item"><a class="page-link" data-page="' . ($page+1) . '" href="#">بعدی</a></li>';
        $html .= '<li class="page-item"><a class="page-link" data-page="' . $total_pages . '" href="#">آخر</a></li>';
    }
    $html .= '</ul><div class="text-center text-muted small">نمایش ' . number_format($total_records) . ' رکورد</div></nav>';
    return $html;
}
?>