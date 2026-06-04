<?php
$page_title = 'لیست پذیرش دستگاه‌ها';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">شما دسترسی به این بخش ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// دریافت همه تیکت‌ها
$stmt = $db->query("
    SELECT r.*, c.fullname as customer_name, c.mobile as customer_mobile 
    FROM repair_tickets r 
    JOIN customers c ON c.id = r.customer_id 
    ORDER BY r.id DESC
");
$tickets = $stmt->fetchAll();
?>

<style>
    /* استایل اختصاصی این صفحه */
    .filter-search {
        margin-bottom: 1rem;
    }
    .filter-search .search-box {
        position: relative;
        min-width: 250px;
    }
    .filter-search .search-box i {
        position: absolute;
        right: 12px;
        top: 12px;
        color: #94a3b8;
        z-index: 2;
    }
    .filter-search .search-box input {
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
        .filter-search .search-box {
            width: 100%;
            margin-top: 10px;
        }
        .card-header-custom {
            flex-direction: column;
            align-items: stretch !important;
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
</style>

<div class="modern-card">
    <div class="card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <a href="create.php" class="btn btn-modern"><i class="fas fa-plus"></i> پذیرش جدید</a>
            <a href="customer_history.php" class="btn btn-info"><i class="fas fa-history"></i> تاریخچه مشتری</a>
            <a href="ready_list.php" class="btn btn-warning"><i class="fas fa-check-circle"></i> آماده تحویل</a>
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
                        <th>شماره پذیرش</th><th>مشتری</th><th>موبایل</th><th>دستگاه</th>
                        <th>وضعیت</th><th>هزینه کل</th><th>تاریخ پذیرش</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $ticket): 
                        // جایگزینی match expression با switch (برای PHP 7)
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
                            default:
                                $status_class = '';
                        }
                        
                        // استفاده از آرایه برای متن وضعیت (سازگار با PHP 7)
                        $status_texts = [
                            'pending' => 'در انتظار',
                            'in_progress' => 'در حال تعمیر',
                            'waiting_part' => 'انتظار قطعه',
                            'ready' => 'آماده تحویل',
                            'delivered' => 'تحویل شده'
                        ];
                        $status_text = isset($status_texts[$ticket['status']]) ? $status_texts[$ticket['status']] : $ticket['status'];
                    ?>
                    <tr class="ticket-row" data-mobile="<?= htmlspecialchars($ticket['customer_mobile']) ?>" data-ticket="<?= htmlspecialchars($ticket['ticket_no']) ?>">
                        <td><strong><?= htmlspecialchars($ticket['ticket_no']) ?></strong></td>
                        <td><?= htmlspecialchars($ticket['customer_name']) ?></td>
                        <td dir="ltr"><?= htmlspecialchars($ticket['customer_mobile']) ?></td>
                        <td><?= htmlspecialchars($ticket['device_type'] . ' ' . $ticket['brand'] . ' ' . $ticket['model']) ?></td>
                        <td><span class="badge-status <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td><?= to_toman($ticket['total_cost']) ?></td>
                        <td><?= display_persian_date($ticket['received_date_sh']) ?></td>
                        <td>
                            <a href="view.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-info" title="مشاهده"><i class="fas fa-eye"></i></a>
                            <a href="edit.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-warning" title="ویرایش"><i class="fas fa-edit"></i></a>
                            <a href="change_status.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-outline-secondary" title="تغییر وضعیت"><i class="fas fa-exchange-alt"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#liveSearchInput').on('keyup', function(){
        var searchValue = $(this).val().trim().toLowerCase();
        $('.ticket-row').each(function(){
            var mobile = $(this).data('mobile').toLowerCase();
            var ticketNo = $(this).data('ticket').toLowerCase();
            if (mobile.indexOf(searchValue) !== -1 || ticketNo.indexOf(searchValue) !== -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
});
</script>
<?php require_once '../../includes/footer.php'; ?>