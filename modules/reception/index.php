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
<div class="card">
    <div class="card-header">
        <a href="create.php" class="btn btn-primary">پذیرش جدید</a>
        <a href="customer_history.php" class="btn btn-info">تاریخچه مشتری</a>
        <a href="ready_list.php" class="btn btn-warning">📋 آماده تحویل</a>
        <div class="float-start w-25">
            <input type="text" id="liveSearchInput" class="form-control" placeholder="جستجوی شماره تلفن یا شماره تیکت..." autocomplete="off">
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="ticketsTable">
                <thead>
                    <tr>
                        <th>شماره پذیرش</th><th>مشتری</th><th>موبایل</th><th>دستگاه</th>
                        <th>وضعیت</th><th>هزینه کل</th><th>تاریخ پذیرش</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $ticket): ?>
                    <tr class="ticket-row" data-mobile="<?= htmlspecialchars($ticket['customer_mobile']) ?>" data-ticket="<?= htmlspecialchars($ticket['ticket_no']) ?>">
                        <td><?= htmlspecialchars($ticket['ticket_no']) ?></td>
                        <td><?= htmlspecialchars($ticket['customer_name']) ?></td>
                        <td><?= htmlspecialchars($ticket['customer_mobile']) ?></td>
                        <td><?= htmlspecialchars($ticket['device_type'] . ' ' . $ticket['brand'] . ' ' . $ticket['model']) ?></td>
                        <td>
                            <?php
                            $status_text = [
                                'pending' => 'در انتظار',
                                'in_progress' => 'در حال تعمیر',
                                'waiting_part' => 'انتظار قطعه',
                                'ready' => 'آماده تحویل',
                                'delivered' => 'تحویل شده'
                            ];
                            echo $status_text[$ticket['status']] ?? $ticket['status'];
                            ?>
                        </td>
                        <td><?= to_toman($ticket['total_cost']) ?></td>
                        <td><?= display_persian_date($ticket['received_date_sh']) ?> <!-- اصلاح شده -->
                        <td>
                            <a href="view.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-info">مشاهده</a>
                            <a href="edit.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-warning">ویرایش</a>
                            <a href="change_status.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-secondary">تغییر وضعیت</a>
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