<?php
$page_title = 'لیست پذیرش دستگاه‌ها';
require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">شما دسترسی به این بخش ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// دریافت تیکت‌ها با مرتب‌سازی بر اساس تاریخ تحویل مورد انتظار (نزدیک‌ترین اول)
$stmt = $db->query("
    SELECT r.*, c.fullname as customer_name, c.mobile as customer_mobile 
    FROM repair_tickets r 
    JOIN customers c ON c.id = r.customer_id 
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
");
$tickets = $stmt->fetchAll();
?>

<style>
    .table-warning-row {
        background-color: #fff3cd !important;
        border-right: 4px solid #ffc107;
    }
    .table-danger-row {
        background-color: #f8d7da !important;
        border-right: 4px solid #dc3545;
    }
    .delivery-badge {
        font-size: 0.7rem;
        padding: 2px 6px;
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
    }
    .table th, .table td {
        vertical-align: middle;
        white-space: nowrap;
    }
    @media (max-width: 768px) {
        .table th, .table td {
            white-space: normal;
        }
        .card-header-custom {
            flex-direction: column;
            align-items: stretch !important;
        }
        .filter-checkboxes {
            justify-content: flex-start;
            margin-top: 10px;
        }
        .search-box {
            width: 100%;
            margin-top: 10px;
        }
    }
    .badge-status {
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .badge-pending { background: #ffc107; color: #000; }
    .badge-in_progress { background: #0ea5e9; color: #fff; }
    .badge-waiting_part { background: #f97316; color: #fff; }
    .badge-ready { background: #22c55e; color: #fff; }
    .badge-delivered { background: #6c757d; color: #fff; }
    .badge-canceled { background: #dc2626; color: #fff; }
</style>

<div class="modern-card">
    <div class="card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <a href="create.php" class="btn btn-modern"><i class="fas fa-plus"></i> پذیرش جدید</a>
            <a href="customer_history.php" class="btn btn-info"><i class="fas fa-history"></i> تاریخچه مشتری</a>
            <a href="ready_list.php" class="btn btn-warning"><i class="fas fa-check-circle"></i> آماده تحویل</a>
        </div>
        <div class="filter-checkboxes">
            <label>
                <input type="checkbox" id="showDelivered" value="delivered"> نمایش تحویل شده
            </label>
            <label>
                <input type="checkbox" id="showCanceled" value="canceled"> نمایش لغو شده
            </label>
        </div>
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="liveSearchInput" class="form-control" placeholder="جستجوی شماره تلفن یا شماره تیکت...">
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="ticketsTable">
                <thead>
                    <tr>
                        <th>شماره پذیرش</th>
                        <th>مشتری</th>
                        <th>موبایل</th>
                        <th>دستگاه</th>
                        <th>وضعیت</th>
                        <th>هزینه کل</th>
                        <th>تاریخ پذیرش</th>
                        <th>تاریخ تحویل انتظار</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $today = now_jalali();
                    $today_ts = jalali_to_timestamp($today);
                    
                    foreach ($tickets as $ticket):
                        $status_class = '';
                        switch($ticket['status']) {
                            case 'pending': $status_class = 'badge-pending'; break;
                            case 'in_progress': $status_class = 'badge-in_progress'; break;
                            case 'waiting_part': $status_class = 'badge-waiting_part'; break;
                            case 'ready': $status_class = 'badge-ready'; break;
                            case 'delivered': $status_class = 'badge-delivered'; break;
                            case 'canceled': $status_class = 'badge-canceled'; break;
                        }
                        
                        $status_texts = [
                            'pending' => 'در انتظار',
                            'in_progress' => 'در حال تعمیر',
                            'waiting_part' => 'انتظار قطعه',
                            'ready' => 'آماده تحویل',
                            'delivered' => 'تحویل شده',
                            'canceled' => 'لغو شده'
                        ];
                        $status_text = $status_texts[$ticket['status']] ?? $ticket['status'];

                        // محاسبه روزهای باقی‌مانده تا موعد تحویل (فقط برای تیکت‌های فعال)
                        $days_left = null;
                        $row_class = '';
                        $delivery_badge = '';
                        
                        if (!empty($ticket['expected_delivery_date_sh']) && !in_array($ticket['status'], ['delivered', 'canceled'])) {
                            $expected_ts = jalali_to_timestamp($ticket['expected_delivery_date_sh']);
                            if ($expected_ts > 0) {
                                $days_left = floor(($expected_ts - $today_ts) / 86400);
                                
                                if ($days_left <= 2) {
                                    $row_class = ($days_left <= 0) ? 'table-danger-row' : 'table-warning-row';
                                }
                                
                                if ($days_left <= 2 && $days_left >= 0) {
                                    $delivery_badge = '<span class="delivery-badge">⚠️ ' . $days_left . ' روز تا موعد</span>';
                                } elseif ($days_left < 0) {
                                    $delivery_badge = '<span class="delivery-badge delivery-urgent">⛔ تأخیر ' . abs($days_left) . ' روز</span>';
                                } elseif ($days_left > 2) {
                                    $delivery_badge = '<span class="delivery-badge">' . $days_left . ' روز</span>';
                                }
                            }
                        }
                    ?>
                    <tr class="ticket-row <?= $row_class ?>" 
                        data-status="<?= htmlspecialchars($ticket['status']) ?>" 
                        data-mobile="<?= htmlspecialchars($ticket['customer_mobile']) ?>" 
                        data-ticket="<?= htmlspecialchars($ticket['ticket_no']) ?>">
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
                        <td>
                            <?php if (!empty($ticket['expected_delivery_date_sh'])): ?>
                                <?= htmlspecialchars($ticket['expected_delivery_date_sh']) ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="view.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-info" title="مشاهده"><i class="fas fa-eye"></i></a>
                            <a href="edit.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-warning" title="ویرایش"><i class="fas fa-edit"></i></a>
                            <a href="change_status.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-secondary" title="تغییر وضعیت"><i class="fas fa-exchange-alt"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (count($tickets) == 0): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">هیچ تیکتی یافت نشد.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    // فیلتر جستجو
    $('#liveSearchInput').on('keyup', function(){
        applyFilters();
    });
    
    // فیلتر وضعیت‌ها
    $('#showDelivered, #showCanceled').on('change', function(){
        applyFilters();
    });
    
    function applyFilters() {
        var searchValue = $('#liveSearchInput').val().trim().toLowerCase();
        var showDelivered = $('#showDelivered').is(':checked');
        var showCanceled = $('#showCanceled').is(':checked');
        
        $('.ticket-row').each(function(){
            var status = $(this).data('status');
            var mobile = $(this).data('mobile').toLowerCase();
            var ticketNo = $(this).data('ticket').toLowerCase();
            
            // شرط فیلتر وضعیت
            var statusOk = true;
            if (status === 'delivered' && !showDelivered) statusOk = false;
            if (status === 'canceled' && !showCanceled) statusOk = false;
            
            // شرط جستجو
            var searchOk = (searchValue === '' || mobile.indexOf(searchValue) !== -1 || ticketNo.indexOf(searchValue) !== -1);
            
            $(this).toggle(statusOk && searchOk);
        });
    }
    
    // اجرای اولیه (مخفی کردن تحویل شده و لغو شده پیش‌فرض)
    applyFilters();
});
</script>
<?php require_once '../../includes/footer.php'; ?>