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

// دریافت تیکت‌های با وضعیت ready یا delivered
$stmt = $db->prepare("
    SELECT r.*, c.fullname, c.mobile 
    FROM repair_tickets r 
    JOIN customers c ON c.id = r.customer_id 
    WHERE r.status IN ('ready', 'delivered') 
    ORDER BY r.ready_date_sh DESC
");
$stmt->execute();
$tickets = $stmt->fetchAll();

// ========== محاسبه آمار ==========
$ready_count = 0;
$delivered_count = 0;
$total_penalty = 0;          // مجموع جریمه قابل اعمال برای تیکت‌های آماده با تاخیر
$total_delay_days = 0;
$delay_count = 0;

$today = now_jalali();
$today_ts = jalali_to_timestamp($today);

foreach ($tickets as $t) {
    if ($t['status'] == 'ready') $ready_count++;
    else $delivered_count++;
    
    $ready_date = $t['ready_date_sh'];
    $delivered_date = $t['delivered_date_sh'];
    $days_delay = 0;
    
    if ($t['status'] == 'ready' && $ready_date) {
        $ready_ts = jalali_to_timestamp($ready_date);
        $days_delay = floor(($today_ts - $ready_ts) / 86400);
        if ($days_delay < 0) $days_delay = 0;
        if ($days_delay > 0) {
            $total_penalty += $days_delay * 10000;
            $total_delay_days += $days_delay;
            $delay_count++;
        }
    } elseif ($t['status'] == 'delivered' && $ready_date && $delivered_date) {
        $ready_ts = jalali_to_timestamp($ready_date);
        $delivered_ts = jalali_to_timestamp($delivered_date);
        $days_delay = floor(($delivered_ts - $ready_ts) / 86400);
        if ($days_delay < 0) $days_delay = 0;
        // برای تحویل شده‌ها جریمه اعمال نمی‌شود (فقط اطلاع‌دهی)
    }
}

$avg_delay = ($delay_count > 0) ? round($total_delay_days / $delay_count, 1) : 0;

// به‌روزرسانی پیگیری‌ها (followup)
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
    /* ---------- کارت‌های آماری مدرن ---------- */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.2rem;
        margin-bottom: 2rem;
    }
    .stat-card {
        background: #fff;
        border-radius: 24px;
        padding: 1rem 0.5rem;
        text-align: center;
        border: 1px solid #eef2f6;
        transition: 0.25s;
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
        direction: ltr;
        color: #0f172a;
    }
    .stat-label {
        font-size: 0.75rem;
        color: #475569;
        margin-top: 0.3rem;
        font-weight: 500;
    }
    /* رنگ‌های آیکون‌ها */
    .stat-ready .stat-icon { color: #22c55e; }
    .stat-delivered .stat-icon { color: #6c757d; }
    .stat-penalty .stat-icon { color: #dc2626; }
    .stat-delay .stat-icon { color: #f59e0b; }

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
    /* سطر انتخاب شده */
    .modern-table tbody tr.selected-row {
        background: #e0f2fe !important;
        box-shadow: 0 4px 12px rgba(14, 165, 233, 0.2);
        transform: scale(1.01);
    }
    /* بج وضعیت */
    .badge-ready {
        background: #22c55e;
        color: white;
        padding: 5px 12px;
        border-radius: 40px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-block;
    }
    .badge-delivered {
        background: #6c757d;
        color: white;
        padding: 5px 12px;
        border-radius: 40px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-block;
    }
    .penalty-badge {
        background: #fef3c7;
        color: #b45309;
        padding: 4px 8px;
        border-radius: 20px;
        font-weight: 500;
        font-size: 0.8rem;
    }
    /* دکمه‌ها */
    .btn-modern-sm {
        background: #0ea5e9;
        border: none;
        padding: 5px 12px;
        border-radius: 30px;
        color: white;
        font-size: 0.75rem;
        transition: 0.2s;
    }
    .btn-modern-sm:hover {
        background: #0284c7;
        transform: scale(1.02);
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
    .action-buttons {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .action-buttons .btn {
        white-space: nowrap;
        font-size: 0.7rem;
        padding: 4px 8px;
        min-width: 85px;
    }
    @media (max-width: 768px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        .modern-table tbody td {
            white-space: normal;
            padding: 10px 12px;
        }
    }
</style>

<div class="modern-card">
    <!-- کارت‌های آماری -->
    <div class="stats-grid">
        <div class="stat-card stat-ready">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-value"><?= number_format($ready_count) ?></div>
            <div class="stat-label">آماده تحویل</div>
        </div>
        <div class="stat-card stat-delivered">
            <div class="stat-icon"><i class="fas fa-handshake"></i></div>
            <div class="stat-value"><?= number_format($delivered_count) ?></div>
            <div class="stat-label">تحویل شده</div>
        </div>
        <div class="stat-card stat-penalty">
            <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
            <div class="stat-value"><?= number_format($total_penalty) ?> تومان</div>
            <div class="stat-label">مجموع جریمه قابل اعمال</div>
        </div>
        <div class="stat-card stat-delay">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-value"><?= number_format($avg_delay) ?> روز</div>
            <div class="stat-label">میانگین تاخیر</div>
        </div>
    </div>

    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <span><i class="fas fa-clipboard-list"></i> دستگاه‌های آماده تحویل و تحویل شده</span>
        <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> بازگشت</a>
    </div>
    <div class="card-body" style="padding: 1rem 0;">
        <div class="table-responsive">
            <table class="modern-table" id="readyTable">
                <thead>
                    <tr>
                        <th>شماره تیکت</th><th>مشتری</th><th>موبایل</th><th>دستگاه</th><th>تاریخ آماده‌سازی</th><th>وضعیت</th><th>تاریخ تحویل</th><th>هزینه کل</th><th>مرحله 1</th><th>مرحله 2</th><th>مرحله 3</th><th>تاخیر (روز)</th><th>جریمه قابل اعمال</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($tickets) == 0): ?>
                        <tr><td colspan="14" class="text-center text-muted py-4">هیچ دستگاهی یافت نشد</td></tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $t):
                            $ready_date = $t['ready_date_sh'];
                            $delivered_date = $t['delivered_date_sh'];
                            
                            $days_delay = 0;
                            if ($t['status'] == 'ready' && $ready_date) {
                                $ready_ts = jalali_to_timestamp($ready_date);
                                $today_ts = jalali_to_timestamp($today);
                                $days_delay = floor(($today_ts - $ready_ts) / 86400);
                                if ($days_delay < 0) $days_delay = 0;
                            } elseif ($t['status'] == 'delivered' && $ready_date && $delivered_date) {
                                $ready_ts = jalali_to_timestamp($ready_date);
                                $delivered_ts = jalali_to_timestamp($delivered_date);
                                $days_delay = floor(($delivered_ts - $ready_ts) / 86400);
                                if ($days_delay < 0) $days_delay = 0;
                            }
                            $penalty = $days_delay * 10000;
                        ?>
                        <form method="post" class="ticket-row-form" data-id="<?= $t['id'] ?>">
                            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                            <tr class="ticket-row">
                                <td><strong><?= htmlspecialchars($t['ticket_no']) ?></strong></td>
                                <td><?= htmlspecialchars($t['fullname']) ?></td>
                                <td dir="ltr"><?= htmlspecialchars($t['mobile']) ?></td>
                                <td><?= htmlspecialchars($t['device_type'] . ' ' . $t['brand']) ?></td>
                                <td><?= $ready_date ?: '-' ?></td>
                                <td>
                                    <?php if ($t['status'] == 'ready'): ?>
                                        <span class="badge-ready">آماده تحویل</span>
                                    <?php else: ?>
                                        <span class="badge-delivered">تحویل شده</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $delivered_date ?: '-' ?></td>
                                <td class="fw-bold"><?= number_format($t['total_cost']) ?> تومان</td>
                                <td><input type="text" name="followup1" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup1']) ?>" placeholder="1402/10/15" autocomplete="off"></td>
                                <td><input type="text" name="followup2" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup2']) ?>" placeholder="1402/10/15" autocomplete="off"></td>
                                <td><input type="text" name="followup3" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup3']) ?>" placeholder="1402/10/15" autocomplete="off"></td>
                                <td class="text-center"><?= $days_delay > 0 ? '<span class="penalty-badge">'.$days_delay.' روز</span>' : '۰' ?></td>
                                <td class="text-center"><?= $penalty > 0 ? number_format($penalty) . ' تومان' : '۰' ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="submit" name="update_followup" class="btn btn-modern-sm"><i class="fas fa-save"></i> ذخیره</button>
                                        <?php if ($t['status'] == 'ready' && $days_delay > 0): ?>
                                            <button type="button" class="btn btn-penalty apply-penalty" data-id="<?= $t['id'] ?>" data-penalty="<?= $penalty ?>">
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
    // ========== هایلایت سطر انتخاب شده ==========
    $('.ticket-row').on('click', function(e) {
        // اگر روی دکمه‌های عملیات کلیک شده باشد، هایلایت نشود
        if ($(e.target).closest('.action-buttons, .btn-modern-sm, .btn-penalty, input, button').length) return;
        $('.ticket-row').removeClass('selected-row');
        $(this).addClass('selected-row');
    });

    // ========== اعمال جریمه از طریق AJAX ==========
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