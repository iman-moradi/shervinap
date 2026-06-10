<?php
ob_start();
$page_title = 'لیست دستگاه‌های آماده تحویل';
require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$stmt = $db->prepare("
    SELECT r.*, c.fullname, c.mobile 
    FROM repair_tickets r 
    JOIN customers c ON c.id = r.customer_id 
    WHERE r.status IN ('ready', 'delivered') 
    ORDER BY r.ready_date_sh DESC
");
$stmt->execute();
$tickets = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_followup'])) {
    $ticket_id = (int)$_POST['ticket_id'];
    $followup1 = $_POST['followup1'] ?? null;
    $followup2 = $_POST['followup2'] ?? null;
    $followup3 = $_POST['followup3'] ?? null;
    
    $upd = $db->prepare("UPDATE repair_tickets SET followup1 = ?, followup2 = ?, followup3 = ? WHERE id = ?");
    $upd->execute([$followup1, $followup2, $followup3, $ticket_id]);
    echo '<meta http-equiv="refresh" content="0;url=ready_list.php">';
    exit;
}
?>

<style>
    /* فاصله از کناره‌ها برای کل کارت و جدول */
    .modern-card .card-body {
        padding: 1.5rem;
    }
    .table-responsive {
        padding: 0 0.75rem;
    }
    .table th, .table td {
        vertical-align: middle;
        white-space: nowrap;
    }
    @media (max-width: 768px) {
        .table th, .table td {
            white-space: normal;
        }
        .table-responsive {
            padding: 0 0.25rem;
        }
    }
    .penalty-badge {
        background: #fef3c7;
        color: #b45309;
        padding: 4px 8px;
        border-radius: 20px;
        font-weight: 500;
    }
    .btn-penalty {
        background: #ffc107;
        color: #333;
        border: none;
    }
    .btn-penalty:hover {
        background: #e0a800;
        color: #fff;
    }
    .badge-ready {
        background-color: #28a745;
        color: white;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
    }
    .badge-delivered {
        background-color: #6c757d;
        color: white;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 12px;
    }
    /* بهبود ستون عملیات: دکمه‌های کوچک و منظم */
    .action-buttons {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .action-buttons .btn {
        white-space: nowrap;
        font-size: 0.75rem;
        padding: 4px 8px;
        width: auto;
        min-width: 85px;
    }
</style>

<div class="modern-card">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <span><i class="fas fa-check-circle"></i> دستگاه‌های آماده تحویل و تحویل شده</span>
        <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> بازگشت</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>شماره تیکت</th>
                        <th>مشتری</th>
                        <th>موبایل</th>
                        <th>دستگاه</th>
                        <th>تاریخ آماده‌سازی</th>
                        <th>وضعیت</th>
                        <th>تاریخ تحویل</th>
                        <th>هزینه کل</th>
                        <th>مرحله 1</th>
                        <th>مرحله 2</th>
                        <th>مرحله 3</th>
                        <th>تاخیر (روز)</th>
                        <th>جریمه قابل اعمال</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($tickets) == 0): ?>
                        <tr><td colspan="14" class="text-center">هیچ دستگاهی یافت نشد</td></tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t):
                            $today = now_jalali();
                            $ready_date = $t['ready_date_sh'];
                            $delivered_date = $t['delivered_date_sh'];
                            
                            $days_delay = 0;
                            if ($t['status'] == 'ready' && $ready_date) {
                                $ready_ts = jalali_to_timestamp($ready_date);
                                $today_ts = jalali_to_timestamp($today);
                                $days_delay = floor(($today_ts - $ready_ts) / (60 * 60 * 24));
                                if ($days_delay < 0) $days_delay = 0;
                            } elseif ($t['status'] == 'delivered' && $ready_date && $delivered_date) {
                                $ready_ts = jalali_to_timestamp($ready_date);
                                $delivered_ts = jalali_to_timestamp($delivered_date);
                                $days_delay = floor(($delivered_ts - $ready_ts) / (60 * 60 * 24));
                                if ($days_delay < 0) $days_delay = 0;
                            }
                            $penalty = $days_delay * 10000;
                        ?>
                        <form method="post" style="margin:0; padding:0; display:contents;">
                            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                            <tr>
                                <td><strong><?= htmlspecialchars($t['ticket_no']) ?></strong></td>
                                <td><?= htmlspecialchars($t['fullname']) ?></td>
                                <td dir="ltr"><?= htmlspecialchars($t['mobile']) ?></td>
                                <td><?= htmlspecialchars($t['device_type'] . ' ' . $t['brand']) ?></td>
                                <td><?= $t['ready_date_sh'] ?: '-' ?></td>
                                <td>
                                    <?php if ($t['status'] == 'ready'): ?>
                                        <span class="badge-ready">آماده تحویل</span>
                                    <?php else: ?>
                                        <span class="badge-delivered">تحویل شده</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $t['delivered_date_sh'] ?: '-' ?></td>
                                <td class="fw-bold"><?= number_format($t['total_cost']) ?> تومان</td>
                                <td><input type="text" name="followup1" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup1']) ?>" placeholder="1402/10/15"></td>
                                <td><input type="text" name="followup2" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup2']) ?>" placeholder="1402/10/15"></td>
                                <td><input type="text" name="followup3" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup3']) ?>" placeholder="1402/10/15"></td>
                                <td class="text-center"><?= $days_delay > 0 ? '<span class="penalty-badge">'.$days_delay.' روز</span>' : '۰' ?></td>
                                <td class="text-center"><?= $penalty > 0 ? number_format($penalty) . ' تومان' : '۰' ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="submit" name="update_followup" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> ذخیره</button>
                                        <?php if ($t['status'] == 'ready' && $days_delay > 0): ?>
                                            <button type="button" class="btn btn-sm btn-penalty apply-penalty" data-id="<?= $t['id'] ?>" data-penalty="<?= $penalty ?>">
                                                <i class="fas fa-money-bill-wave"></i> جریمه
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                 </td>
                            </tr>
                        </form>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    $('.apply-penalty').click(function(e) {
        e.preventDefault();
        var btn = $(this);
        var ticketId = btn.data('id');
        var penaltyAmount = btn.data('penalty');

        if (!confirm('آیا از اعمال جریمه به مبلغ ' + penaltyAmount.toLocaleString() + ' تومان اطمینان دارید؟')) {
            return;
        }

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> در حال اعمال...');

        $.ajax({
            url: 'apply_penalty_to_items.php',
            type: 'POST',
            data: { ticket_id: ticketId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('خطا: ' + response.message);
                    btn.prop('disabled', false).html('<i class="fas fa-money-bill-wave"></i> جریمه');
                }
            },
            error: function(xhr, status, error) {
                alert('خطا در ارتباط با سرور: ' + error);
                btn.prop('disabled', false).html('<i class="fas fa-money-bill-wave"></i> جریمه');
            }
        });
    });
});
</script>

<?php
ob_end_flush();
require_once '../../includes/footer.php';
?>