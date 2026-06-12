<?php
// ==================================================
// ابتدا بررسی درخواست AJAX (قبل از هر گونه خروجی)
// ==================================================
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($is_ajax) {
    // فقط برای AJAX: فایل‌های ضروری را بدون هدر/فوتر فراخوانی کن
    require_once '../../config/database.php';
    require_once '../../includes/date_helper.php';
    require_once '../../includes/functions.php';
    
    // شروع سشن برای بررسی دسترسی
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!has_permission($_SESSION['user_id'], 'reception_access')) {
        echo '<div class="alert alert-danger">شما دسترسی به این بخش ندارید.</div>';
        exit;
    }
    
    // ========== دریافت پارامترها ==========
    $search = trim($_GET['search'] ?? '');
    $showDelivered = isset($_GET['showDelivered']) && $_GET['showDelivered'] == '1';
    $showCanceled = isset($_GET['showCanceled']) && $_GET['showCanceled'] == '1';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
    $allowed_per_page = [10, 15, 20, 25, 50, 100];
    if (!in_array($per_page, $allowed_per_page)) $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    // ========== ساخت کوئری WHERE ==========
    $where = "WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $where .= " AND (c.mobile LIKE :search OR r.ticket_no LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if (!$showDelivered) {
        $where .= " AND r.status != 'delivered'";
    }
    if (!$showCanceled) {
        $where .= " AND r.status != 'canceled'";
    }
    
    // ========== شمارش کل رکوردها ==========
    $countSql = "SELECT COUNT(*) FROM repair_tickets r JOIN customers c ON c.id = r.customer_id $where";
    $stmt = $db->prepare($countSql);
    foreach ($params as $key => $val) $stmt->bindValue($key, $val);
    $stmt->execute();
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
    
    if ($page > $total_pages && $total_pages > 0) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }
    
    // ========== دریافت رکوردهای صفحه جاری ==========
    $sql = "SELECT r.*, c.fullname as customer_name, c.mobile as customer_mobile 
            FROM repair_tickets r 
            JOIN customers c ON c.id = r.customer_id 
            $where
            ORDER BY 
                CASE 
                    WHEN r.status IN ('delivered', 'canceled') THEN 1 
                    ELSE 0 
                END,
                CASE 
                    WHEN r.expected_delivery_date_sh IS NULL THEN 1 
                    ELSE 0 
                END,
                r.expected_delivery_date_sh ASC,
                r.id DESC
            LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $val) $stmt->bindValue($key, $val);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $tickets = $stmt->fetchAll();
    
    $today = now_jalali();
    $today_ts = jalali_to_timestamp($today);
    
    // تابع ساخت لینک صفحه‌بندی (برای استفاده در AJAX)
    function pagination_link_ajax($page_num, $per_page, $search, $showDelivered, $showCanceled, $label = null) {
        $label = $label ?? $page_num;
        $active = ($page == $page_num) ? 'active' : '';
        $url = "?page=$page_num&per_page=$per_page&search=" . urlencode($search) . 
               "&showDelivered=" . ($showDelivered ? '1' : '0') . 
               "&showCanceled=" . ($showCanceled ? '1' : '0');
        return "<li class='page-item $active'><a class='page-link' href='$url'>$label</a></li>";
    }
    
    // تولید خروجی HTML (جدول + صفحه‌بندی)
    ob_start();
    ?>
    <div class="table-responsive">
        <table class="modern-table" id="ticketsTable">
            <thead>
                <tr>
                    <th>شماره پذیرش</th><th>مشتری</th><th>موبایل</th><th>دستگاه</th><th>وضعیت</th><th>هزینه کل</th><th>تاریخ پذیرش</th><th>تاریخ تحویل انتظار</th><th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($tickets) == 0): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">هیچ تیکتی یافت نشد.</td></tr>
                <?php else: ?>
                    <?php foreach ($tickets as $ticket):
                        // جایگزینی match با switch برای PHP 7
                        $status_class = '';
                        switch($ticket['status']) {
                            case 'pending':
                                $status_class = 'badge-pending';
                                break;
                            case 'in_progress':
                                $status_class = 'badge-in_progress';
                                break;
                            case 'waiting_part':
                                $status_class = 'badge-waiting_part';
                                break;
                            case 'ready':
                                $status_class = 'badge-ready';
                                break;
                            case 'delivered':
                                $status_class = 'badge-delivered';
                                break;
                            case 'canceled':
                                $status_class = 'badge-canceled';
                                break;
                            default:
                                $status_class = '';
                        }
                        
                        $status_text = '';
                        switch($ticket['status']) {
                            case 'pending':
                                $status_text = 'در انتظار';
                                break;
                            case 'in_progress':
                                $status_text = 'در حال تعمیر';
                                break;
                            case 'waiting_part':
                                $status_text = 'انتظار قطعه';
                                break;
                            case 'ready':
                                $status_text = 'آماده تحویل';
                                break;
                            case 'delivered':
                                $status_text = 'تحویل شده';
                                break;
                            case 'canceled':
                                $status_text = 'لغو شده';
                                break;
                            default:
                                $status_text = $ticket['status'];
                        }
                        
                        $row_class = '';
                        $delivery_badge = '';
                        if (!empty($ticket['expected_delivery_date_sh']) && !in_array($ticket['status'], ['delivered', 'canceled'])) {
                            $expected_ts = jalali_to_timestamp($ticket['expected_delivery_date_sh']);
                            if ($expected_ts > 0) {
                                $days_left = floor(($expected_ts - $today_ts) / 86400);
                                if ($days_left <= 2) $row_class = ($days_left <= 0) ? 'table-danger-row' : 'table-warning-row';
                                if ($days_left <= 2 && $days_left >= 0) $delivery_badge = '<span class="delivery-badge">⚠️ ' . $days_left . ' روز تا موعد</span>';
                                elseif ($days_left < 0) $delivery_badge = '<span class="delivery-badge delivery-urgent">⛔ تأخیر ' . abs($days_left) . ' روز</span>';
                                elseif ($days_left > 2) $delivery_badge = '<span class="delivery-badge">' . $days_left . ' روز</span>';
                            }
                        }
                    ?>
                    <tr class="ticket-row <?= $row_class ?>" data-id="<?= $ticket['id'] ?>">
                        <td>
                            <strong><?= htmlspecialchars($ticket['ticket_no']) ?></strong>
                            <?= $delivery_badge ?>
                        </td>
                        <td><?= htmlspecialchars($ticket['customer_name']) ?></td>
                        <td dir="ltr"><?= htmlspecialchars($ticket['customer_mobile']) ?></td>
                        <td><?= htmlspecialchars($ticket['device_type'] . ' ' . $ticket['brand'] . ' ' . $ticket['model']) ?></td>
                        <td><span class="badge-status <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td><?= to_toman($ticket['total_cost']) ?></td>
                        <td><?= display_persian_date($ticket['received_date_sh']) ?></td>
                        <td><?= !empty($ticket['expected_delivery_date_sh']) ? htmlspecialchars($ticket['expected_delivery_date_sh']) : '—' ?></td>
                        <td>
                            <a href="view.php?id=<?= $ticket['id'] ?>" class="btn-table-action" title="مشاهده"><i class="fas fa-eye"></i></a>
                            <a href="edit.php?id=<?= $ticket['id'] ?>" class="btn-table-action" title="ویرایش"><i class="fas fa-edit"></i></a>
                            <a href="change_status.php?id=<?= $ticket['id'] ?>" class="btn-table-action" title="تغییر وضعیت"><i class="fas fa-exchange-alt"></i></a>
                          </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Page navigation" class="mt-4">
        <ul class="pagination justify-content-center flex-wrap">
            <?php
            if ($page > 1) {
                echo pagination_link_ajax(1, $per_page, $search, $showDelivered, $showCanceled, 'اول');
                echo pagination_link_ajax($page-1, $per_page, $search, $showDelivered, $showCanceled, 'قبلی');
            }
            $start = max(1, $page-2);
            $end = min($total_pages, $page+2);
            for ($i=$start; $i<=$end; $i++) {
                echo pagination_link_ajax($i, $per_page, $search, $showDelivered, $showCanceled);
            }
            if ($page < $total_pages) {
                echo pagination_link_ajax($page+1, $per_page, $search, $showDelivered, $showCanceled, 'بعدی');
                echo pagination_link_ajax($total_pages, $per_page, $search, $showDelivered, $showCanceled, 'آخر');
            }
            ?>
        </ul>
        <div class="text-center text-muted small">
            نمایش <?= count($tickets) ?> از <?= number_format($total_records) ?> رکورد
        </div>
    </nav>
    <?php endif; ?>
    <?php
    $output = ob_get_clean();
    echo $output;
    exit;
}

// ==================================================
// درخواست عادی (غیر AJAX) – نمایش کامل صفحه
// ==================================================
$page_title = 'لیست پذیرش دستگاه‌ها';
require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">شما دسترسی به این بخش ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ========== دریافت پارامترها ==========
$search = trim($_GET['search'] ?? '');
$showDelivered = isset($_GET['showDelivered']) && $_GET['showDelivered'] == '1';
$showCanceled = isset($_GET['showCanceled']) && $_GET['showCanceled'] == '1';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$allowed_per_page = [10, 15, 20, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page)) $per_page = 20;
$offset = ($page - 1) * $per_page;

// ========== آمار کلی (بدون اعمال فیلترهای جستجو و نمایش) ==========
$stats_sql = "SELECT 
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
    SUM(CASE WHEN status = 'waiting_part' THEN 1 ELSE 0 END) AS waiting_part_count,
    SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS ready_count,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
    SUM(CASE WHEN status = 'canceled' THEN 1 ELSE 0 END) AS canceled_count,
    COUNT(*) AS total_count
FROM repair_tickets";
$stats_stmt = $db->query($stats_sql);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$today = now_jalali();
$today_ts = jalali_to_timestamp($today);

$today_stats_sql = "SELECT COUNT(*) AS today_count FROM repair_tickets WHERE received_date_sh = :today";
$today_stmt = $db->prepare($today_stats_sql);
$today_stmt->execute([':today' => $today]);
$today_count = $today_stmt->fetchColumn();

// ========== ساخت کوئری WHERE بر اساس فیلترها ==========
$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (c.mobile LIKE :search OR r.ticket_no LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!$showDelivered) {
    $where .= " AND r.status != 'delivered'";
}
if (!$showCanceled) {
    $where .= " AND r.status != 'canceled'";
}

// ========== شمارش کل رکوردها ==========
$countSql = "SELECT COUNT(*) FROM repair_tickets r JOIN customers c ON c.id = r.customer_id $where";
$stmt = $db->prepare($countSql);
foreach ($params as $key => $val) $stmt->bindValue($key, $val);
$stmt->execute();
$total_records = $stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

// ========== دریافت رکوردهای صفحه جاری ==========
$sql = "SELECT r.*, c.fullname as customer_name, c.mobile as customer_mobile 
        FROM repair_tickets r 
        JOIN customers c ON c.id = r.customer_id 
        $where
        ORDER BY 
            CASE 
                WHEN r.status IN ('delivered', 'canceled') THEN 1 
                ELSE 0 
            END,
            CASE 
                WHEN r.expected_delivery_date_sh IS NULL THEN 1 
                ELSE 0 
            END,
            r.expected_delivery_date_sh ASC,
            r.id DESC
        LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($sql);
foreach ($params as $key => $val) $stmt->bindValue($key, $val);
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$tickets = $stmt->fetchAll();

// تابع ساخت لینک صفحه‌بندی (برای حالت عادی)
function pagination_link($page_num, $per_page, $search, $showDelivered, $showCanceled, $label = null) {
    $label = $label ?? $page_num;
    $active = ($_GET['page'] ?? 1) == $page_num ? 'active' : '';
    $url = "?page=$page_num&per_page=$per_page&search=" . urlencode($search) . 
           "&showDelivered=" . ($showDelivered ? '1' : '0') . 
           "&showCanceled=" . ($showCanceled ? '1' : '0');
    return "<li class='page-item $active'><a class='page-link' href='$url'>$label</a></li>";
}
?>

<style>
    /* ---------- استایل اصلی کادر و فاصله‌گذاری ---------- */
    .modern-card {
        background: #fff;
        border-radius: 28px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.05);
        padding: 1.8rem;
        transition: all 0.2s;
    }
    .card-header-custom {
        margin-bottom: 1.5rem;
    }
    .card-body {
        padding: 0;
    }

    /* ---------- کارت‌های آماری (دو ردیف ۴ تایی) ---------- */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.2rem;
        margin-bottom: 2rem;
    }
    .stat-card {
        background: #ffffff;
        border-radius: 24px;
        padding: 1rem 0.5rem;
        text-align: center;
        transition: all 0.25s ease;
        border: 1px solid #eef2f6;
        box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 20px rgba(0,0,0,0.08);
        border-color: #cbd5e1;
    }
    .stat-icon {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }
    .stat-value {
        font-size: 1.8rem;
        font-weight: 800;
        line-height: 1.2;
        direction: ltr;
        color: #0f172a;
    }
    .stat-label {
        font-size: 0.75rem;
        color: #475569;
        margin-top: 0.3rem;
        font-weight: 500;
        letter-spacing: 0.3px;
    }
    /* رنگ آیکون‌ها */
    .stat-pending .stat-icon { color: #f59e0b; }
    .stat-in_progress .stat-icon { color: #0ea5e9; }
    .stat-waiting_part .stat-icon { color: #f97316; }
    .stat-ready .stat-icon { color: #22c55e; }
    .stat-delivered .stat-icon { color: #6c757d; }
    .stat-canceled .stat-icon { color: #dc2626; }
    .stat-total .stat-icon { color: #8b5cf6; }
    .stat-today .stat-icon { color: #ec489a; }

    /* ---------- فیلترها و جستجو ---------- */
    .filter-checkboxes {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    .filter-checkboxes label {
        margin: 0;
        font-weight: normal;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .search-box {
        position: relative;
        min-width: 250px;
    }
    .search-box i {
        position: absolute;
        right: 12px;
        top: 12px;
        color: #94a3b8;
        z-index: 2;
    }
    .search-box input {
        border-radius: 30px;
        padding-right: 35px;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }
    .search-box input:focus {
        border-color: #0ea5e9;
        box-shadow: 0 0 0 3px rgba(14,165,233,0.1);
    }
    .per-page-selector select {
        border-radius: 30px;
        border-color: #e2e8f0;
    }

    /* ---------- جدول مدرن ---------- */
    .modern-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 8px;
        margin: 0;
    }
    .modern-table thead th {
        border: none;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        padding: 12px 16px;
        background: #f8fafc;
        white-space: nowrap;
    }
    .modern-table tbody tr {
        background: #ffffff;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .modern-table tbody tr:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.05);
    }
    .modern-table tbody td {
        border: none;
        padding: 14px 16px;
        font-size: 0.9rem;
        color: #1e293b;
        vertical-align: middle;
        white-space: nowrap;
    }
    /* گوشه‌های گرد سطر */
    .modern-table tbody tr td:first-child {
        border-top-right-radius: 16px;
        border-bottom-right-radius: 16px;
    }
    .modern-table tbody tr td:last-child {
        border-top-left-radius: 16px;
        border-bottom-left-radius: 16px;
    }
    /* وضعیت‌های هشدار (موعد تحویل) */
    .modern-table tbody tr.table-warning-row {
        background: #fffbeb;
        border-right: 3px solid #f59e0b;
    }
    .modern-table tbody tr.table-danger-row {
        background: #fef2f2;
        border-right: 3px solid #ef4444;
    }
    /* سطر انتخاب شده */
    .modern-table tbody tr.selected-row {
        background: #e0f2fe !important;
        box-shadow: 0 4px 12px rgba(14, 165, 233, 0.2);
        transform: scale(1.01);
    }
    .modern-table tbody tr.selected-row td:first-child {
        border-right: 3px solid #0ea5e9;
    }

    /* بج وضعیت */
    .badge-status {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 40px;
        font-size: 0.7rem;
        font-weight: 600;
        text-align: center;
        min-width: 80px;
    }
    .badge-pending { background: #fef3c7; color: #b45309; }
    .badge-in_progress { background: #dbeafe; color: #1e40af; }
    .badge-waiting_part { background: #ffedd5; color: #c2410c; }
    .badge-ready { background: #dcfce7; color: #15803d; }
    .badge-delivered { background: #f1f5f9; color: #475569; }
    .badge-canceled { background: #fee2e2; color: #b91c1c; }

    /* دکمه‌های عملیات */
    .btn-table-action {
        background: transparent;
        border: none;
        color: #94a3b8;
        transition: all 0.2s;
        margin: 0 4px;
        font-size: 1.1rem;
        display: inline-block;
        text-decoration: none;
    }
    .btn-table-action:hover {
        color: #0f172a;
        transform: scale(1.1);
    }

    /* بج اخطار موعد */
    .delivery-badge {
        font-size: 0.7rem;
        padding: 2px 8px;
        border-radius: 20px;
        background: #ffc107;
        color: #000;
        margin-right: 5px;
        display: inline-block;
    }
    .delivery-urgent {
        background: #dc3545;
        color: #fff;
    }

    /* صفحه‌بندی مدرن */
    .pagination .page-link {
        border-radius: 40px;
        margin: 0 3px;
        color: #334155;
        border: 1px solid #e2e8f0;
    }
    .pagination .page-item.active .page-link {
        background-color: #0ea5e9;
        border-color: #0ea5e9;
        color: white;
    }

    /* واکنش‌گرایی */
    @media (max-width: 768px) {
        .modern-card {
            padding: 1rem;
        }
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.8rem;
        }
        .stat-value {
            font-size: 1.4rem;
        }
        .modern-table tbody td {
            white-space: normal;
            padding: 10px 12px;
        }
        .card-header-custom {
            flex-direction: column;
            align-items: stretch !important;
        }
        .filter-checkboxes {
            justify-content: flex-start;
        }
        .search-box {
            width: 100%;
        }
    }
    /* لودینگ */
    .loading-overlay {
        position: relative;
        min-height: 200px;
    }
    .loading-spinner {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        z-index: 10;
    }
</style>

<div class="modern-card">
    <!-- کارت‌های آماری دو ردیف چهارتایی -->
    <div class="stats-grid">
        <div class="stat-card stat-pending">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-value"><?= number_format($stats['pending_count']) ?></div>
            <div class="stat-label">در انتظار تعمیر</div>
        </div>
        <div class="stat-card stat-in_progress">
            <div class="stat-icon"><i class="fas fa-tools"></i></div>
            <div class="stat-value"><?= number_format($stats['in_progress_count']) ?></div>
            <div class="stat-label">در حال تعمیر</div>
        </div>
        <div class="stat-card stat-waiting_part">
            <div class="stat-icon"><i class="fas fa-microchip"></i></div>
            <div class="stat-value"><?= number_format($stats['waiting_part_count']) ?></div>
            <div class="stat-label">انتظار قطعه</div>
        </div>
        <div class="stat-card stat-ready">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-value"><?= number_format($stats['ready_count']) ?></div>
            <div class="stat-label">آماده تحویل</div>
        </div>
        <div class="stat-card stat-delivered">
            <div class="stat-icon"><i class="fas fa-handshake"></i></div>
            <div class="stat-value"><?= number_format($stats['delivered_count']) ?></div>
            <div class="stat-label">تحویل شده</div>
        </div>
        <div class="stat-card stat-canceled">
            <div class="stat-icon"><i class="fas fa-ban"></i></div>
            <div class="stat-value"><?= number_format($stats['canceled_count']) ?></div>
            <div class="stat-label">لغو شده</div>
        </div>
        <div class="stat-card stat-total">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-value"><?= number_format($stats['total_count']) ?></div>
            <div class="stat-label">کل تیکت‌ها</div>
        </div>
        <div class="stat-card stat-today">
            <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-value"><?= number_format($today_count) ?></div>
            <div class="stat-label">پذیرش امروز</div>
        </div>
    </div>

    <!-- هدر فیلترها و دکمه‌ها -->
    <div class="card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex gap-2 flex-wrap">
            <a href="create.php" class="btn btn-modern"><i class="fas fa-plus"></i> پذیرش جدید</a>
            <a href="customer_history.php" class="btn btn-info"><i class="fas fa-history"></i> تاریخچه مشتری</a>
            <a href="ready_list.php" class="btn btn-warning"><i class="fas fa-check-circle"></i> آماده تحویل</a>
        </div>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <div class="filter-checkboxes">
                <label><input type="checkbox" id="showDelivered" <?= $showDelivered ? 'checked' : '' ?>> نمایش تحویل شده</label>
                <label><input type="checkbox" id="showCanceled" <?= $showCanceled ? 'checked' : '' ?>> نمایش لغو شده</label>
            </div>
            <div class="per-page-selector">
                <label class="text-nowrap">نمایش به ازای هر صفحه:</label>
                <select id="perPageSelect" class="form-select form-select-sm d-inline-block w-auto ms-1">
                    <?php foreach ($allowed_per_page as $val): ?>
                        <option value="<?= $val ?>" <?= $per_page == $val ? 'selected' : '' ?>><?= $val ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" class="form-control" placeholder="جستجوی شماره تلفن یا شماره تیکت..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
    </div>

    <!-- محتوای داینامیک جدول و صفحه‌بندی -->
    <div id="ajax-content">
        <div class="table-responsive">
            <table class="modern-table" id="ticketsTable">
                <thead>
                    <tr>
                        <th>شماره پذیرش</th><th>مشتری</th><th>موبایل</th><th>دستگاه</th><th>وضعیت</th><th>هزینه کل</th><th>تاریخ پذیرش</th><th>تاریخ تحویل انتظار</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($tickets) == 0): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">هیچ تیکتی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $ticket):
                            // جایگزینی match با switch برای PHP 7
                            $status_class = '';
                            switch($ticket['status']) {
                                case 'pending':
                                    $status_class = 'badge-pending';
                                    break;
                                case 'in_progress':
                                    $status_class = 'badge-in_progress';
                                    break;
                                case 'waiting_part':
                                    $status_class = 'badge-waiting_part';
                                    break;
                                case 'ready':
                                    $status_class = 'badge-ready';
                                    break;
                                case 'delivered':
                                    $status_class = 'badge-delivered';
                                    break;
                                case 'canceled':
                                    $status_class = 'badge-canceled';
                                    break;
                                default:
                                    $status_class = '';
                            }
                            
                            $status_text = '';
                            switch($ticket['status']) {
                                case 'pending':
                                    $status_text = 'در انتظار';
                                    break;
                                case 'in_progress':
                                    $status_text = 'در حال تعمیر';
                                    break;
                                case 'waiting_part':
                                    $status_text = 'انتظار قطعه';
                                    break;
                                case 'ready':
                                    $status_text = 'آماده تحویل';
                                    break;
                                case 'delivered':
                                    $status_text = 'تحویل شده';
                                    break;
                                case 'canceled':
                                    $status_text = 'لغو شده';
                                    break;
                                default:
                                    $status_text = $ticket['status'];
                            }

                            $row_class = '';
                            $delivery_badge = '';
                            if (!empty($ticket['expected_delivery_date_sh']) && !in_array($ticket['status'], ['delivered', 'canceled'])) {
                                $expected_ts = jalali_to_timestamp($ticket['expected_delivery_date_sh']);
                                if ($expected_ts > 0) {
                                    $days_left = floor(($expected_ts - $today_ts) / 86400);
                                    if ($days_left <= 2) $row_class = ($days_left <= 0) ? 'table-danger-row' : 'table-warning-row';
                                    if ($days_left <= 2 && $days_left >= 0) $delivery_badge = '<span class="delivery-badge">⚠️ ' . $days_left . ' روز تا موعد</span>';
                                    elseif ($days_left < 0) $delivery_badge = '<span class="delivery-badge delivery-urgent">⛔ تأخیر ' . abs($days_left) . ' روز</span>';
                                    elseif ($days_left > 2) $delivery_badge = '<span class="delivery-badge">' . $days_left . ' روز</span>';
                                }
                            }
                        ?>
                        <tr class="ticket-row <?= $row_class ?>" data-id="<?= $ticket['id'] ?>">
                            <td>
                                <strong><?= htmlspecialchars($ticket['ticket_no']) ?></strong>
                                <?= $delivery_badge ?>
                            </td>
                            <td><?= htmlspecialchars($ticket['customer_name']) ?></td>
                            <td dir="ltr"><?= htmlspecialchars($ticket['customer_mobile']) ?></td>
                            <td><?= htmlspecialchars($ticket['device_type'] . ' ' . $ticket['brand'] . ' ' . $ticket['model']) ?></td>
                            <td><span class="badge-status <?= $status_class ?>"><?= $status_text ?></span></td>
                            <td><?= to_toman($ticket['total_cost']) ?></td>
                            <td><?= display_persian_date($ticket['received_date_sh']) ?></td>
                            <td><?= !empty($ticket['expected_delivery_date_sh']) ? htmlspecialchars($ticket['expected_delivery_date_sh']) : '—' ?></td>
                            <td>
                                <a href="view.php?id=<?= $ticket['id'] ?>" class="btn-table-action" title="مشاهده"><i class="fas fa-eye"></i></a>
                                <a href="edit.php?id=<?= $ticket['id'] ?>" class="btn-table-action" title="ویرایش"><i class="fas fa-edit"></i></a>
                                <a href="change_status.php?id=<?= $ticket['id'] ?>" class="btn-table-action" title="تغییر وضعیت"><i class="fas fa-exchange-alt"></i></a>
                             </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation" class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <?php
                if ($page > 1) {
                    echo pagination_link(1, $per_page, $search, $showDelivered, $showCanceled, 'اول');
                    echo pagination_link($page-1, $per_page, $search, $showDelivered, $showCanceled, 'قبلی');
                }
                $start = max(1, $page-2);
                $end = min($total_pages, $page+2);
                for ($i=$start; $i<=$end; $i++) {
                    echo pagination_link($i, $per_page, $search, $showDelivered, $showCanceled);
                }
                if ($page < $total_pages) {
                    echo pagination_link($page+1, $per_page, $search, $showDelivered, $showCanceled, 'بعدی');
                    echo pagination_link($total_pages, $per_page, $search, $showDelivered, $showCanceled, 'آخر');
                }
                ?>
            </ul>
            <div class="text-center text-muted small">
                نمایش <?= count($tickets) ?> از <?= number_format($total_records) ?> رکورد
            </div>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function(){
    // تابع بارگذاری AJAX
    function loadTickets() {
        var searchVal = $('#searchInput').val();
        var showDelivered = $('#showDelivered').is(':checked') ? '1' : '0';
        var showCanceled = $('#showCanceled').is(':checked') ? '1' : '0';
        var perPage = $('#perPageSelect').val();
        // صفحه جاری را از pagination active دریافت کنید
        var activePage = $('.pagination .page-item.active .page-link').text();
        var page = (activePage && $.isNumeric(activePage)) ? parseInt(activePage) : 1;
        
        $.ajax({
            url: window.location.href,
            type: 'GET',
            data: {
                search: searchVal,
                showDelivered: showDelivered,
                showCanceled: showCanceled,
                per_page: perPage,
                page: page
            },
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                $('#ajax-content').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i><p>در حال بارگذاری...</p></div>');
            },
            success: function(data) {
                $('#ajax-content').html(data);
                attachRowClick();
                // پس از بارگذاری مجدد، رویداد کلیک روی لینک‌های صفحه‌بندی را متصل می‌کنیم
                $(document).off('click', '.pagination .page-link').on('click', '.pagination .page-link', function(e) {
                    e.preventDefault();
                    var pageNum = $(this).text();
                    if (!$.isNumeric(pageNum)) {
                        if ($(this).text() === 'اول') pageNum = 1;
                        else if ($(this).text() === 'قبلی') pageNum = parseInt($('.pagination .page-item.active .page-link').text()) - 1;
                        else if ($(this).text() === 'بعدی') pageNum = parseInt($('.pagination .page-item.active .page-link').text()) + 1;
                        else if ($(this).text() === 'آخر') pageNum = $('.pagination .page-item:not(.active) .page-link').last().text();
                        else return;
                    }
                    var url = new URL(window.location.href);
                    url.searchParams.set('page', pageNum);
                    window.history.pushState({}, '', url);
                    loadTickets();
                });
            },
            error: function() {
                $('#ajax-content').html('<div class="alert alert-danger">خطا در بارگذاری اطلاعات</div>');
            }
        });
    }

    function attachRowClick() {
        $('#ticketsTable tbody').off('click', '.ticket-row').on('click', '.ticket-row', function(e) {
            if ($(e.target).closest('.btn-table-action').length) return;
            $('.ticket-row').removeClass('selected-row');
            $(this).addClass('selected-row');
        });
    }

    var timer;
    function applyFiltersAndLoad() {
        clearTimeout(timer);
        timer = setTimeout(function() {
            var url = new URL(window.location.href);
            url.searchParams.set('page', 1);
            window.history.pushState({}, '', url);
            loadTickets();
        }, 500);
    }

    $('#searchInput').on('keyup', applyFiltersAndLoad);
    $('#showDelivered, #showCanceled').on('change', applyFiltersAndLoad);
    $('#perPageSelect').on('change', applyFiltersAndLoad);

    attachRowClick();
    
    $(document).on('click', '.pagination .page-link', function(e) {
        e.preventDefault();
        var pageNum = $(this).text();
        if (!$.isNumeric(pageNum)) {
            if ($(this).text() === 'اول') pageNum = 1;
            else if ($(this).text() === 'قبلی') pageNum = parseInt($('.pagination .page-item.active .page-link').text()) - 1;
            else if ($(this).text() === 'بعدی') pageNum = parseInt($('.pagination .page-item.active .page-link').text()) + 1;
            else if ($(this).text() === 'آخر') pageNum = $('.pagination .page-item:not(.active) .page-link').last().text();
            else return;
        }
        var url = new URL(window.location.href);
        url.searchParams.set('page', pageNum);
        window.history.pushState({}, '', url);
        loadTickets();
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>