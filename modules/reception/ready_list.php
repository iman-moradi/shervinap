<?php
$page_title = 'لیست دستگاه‌های آماده تحویل';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// دریافت همه تیکت‌های با وضعیت "آماده تحویل" یا "تحویل شده"
$stmt = $db->prepare("
    SELECT r.*, c.fullname, c.mobile 
    FROM repair_tickets r 
    JOIN customers c ON c.id = r.customer_id 
    WHERE r.status IN ('ready', 'delivered') 
    ORDER BY r.ready_date_sh DESC
");
$stmt->execute();
$tickets = $stmt->fetchAll();

// به‌روزرسانی مراحل پیگیری
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
    .table th, .table td {
        vertical-align: middle;
        white-space: nowrap;
    }
    @media (max-width: 768px) {
        .table th, .table td {
            white-space: normal;
        }
    }
    .penalty-badge {
        background: #fef3c7;
        color: #b45309;
        padding: 4px 8px;
        border-radius: 20px;
        font-weight: 500;
    }
</style>

<div class="modern-card">
    <div class="card-header-custom d-flex justify-content-between align-items-center">
        <span><i class="fas fa-check-circle"></i> دستگاه‌های آماده تحویل و تحویل شده</span>
        <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> بازگشت</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>شماره تیکت</th><th>مشتری</th><th>موبایل</th><th>دستگاه</th>
                        <th>تاریخ آماده‌سازی</th><th>وضعیت</th><th>تاریخ تحویل</th>
                        <th>مرحله 1</th><th>مرحله 2</th><th>مرحله 3</th>
                        <th>تاخیر (روز)</th><th>جریمه</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t):
                        $today = now_jalali();
                        $ready_date = $t['ready_date_sh'];
                        $delivered_date = $t['delivered_date_sh'];
                        
                        $days_delay = 0;
                        if ($t['status'] == 'ready' && $ready_date) {
                            $ready_ts = jalali_to_gregorian_timestamp($ready_date);
                            $today_ts = time();
                            $days_delay = floor(($today_ts - $ready_ts) / (60*60*24));
                            if ($days_delay < 0) $days_delay = 0;
                        } elseif ($t['status'] == 'delivered' && $ready_date && $delivered_date) {
                            $ready_ts = jalali_to_gregorian_timestamp($ready_date);
                            $delivered_ts = jalali_to_gregorian_timestamp($delivered_date);
                            $days_delay = floor(($delivered_ts - $ready_ts) / (60*60*24));
                            if ($days_delay < 0) $days_delay = 0;
                        }
                        $penalty = $days_delay * 10000;
                    ?>
                        <form method="post">
                        <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                        <tr>
                            <td><strong><?= htmlspecialchars($t['ticket_no']) ?></strong></td>
                            <td><?= htmlspecialchars($t['fullname']) ?></td>
                            <td dir="ltr"><?= htmlspecialchars($t['mobile']) ?></td>
                            <td><?= htmlspecialchars($t['device_type'] . ' ' . $t['brand']) ?></td>
                            <td><?= $t['ready_date_sh'] ?: '-' ?></td>
                            <td><?= ($t['status'] == 'ready') ? '<span class="badge-status badge-ready">آماده تحویل</span>' : '<span class="badge-status badge-delivered">تحویل شده</span>' ?></td>
                            <td><?= $t['delivered_date_sh'] ?: '-' ?></td>
                            <td><input type="text" name="followup1" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup1']) ?>" placeholder="تاریخ تماس اول"></td>
                            <td><input type="text" name="followup2" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup2']) ?>" placeholder="تاریخ تماس دوم"></td>
                            <td><input type="text" name="followup3" class="form-control form-control-sm" value="<?= htmlspecialchars($t['followup3']) ?>" placeholder="تاریخ تماس سوم"></td>
                            <td class="text-center"><?= $days_delay > 0 ? '<span class="penalty-badge">'.$days_delay.' روز</span>' : '۰' ?></td>
                            <td class="text-center"><?= $penalty > 0 ? to_toman($penalty) : '۰' ?></td>
                            <td><button type="submit" name="update_followup" class="btn btn-sm btn-primary"><i class="fas fa-save"></i> ذخیره</button></td>
                        </tr>
                        </form>
                    <?php endforeach; ?>
                    <?php if (count($tickets) == 0): ?>
                        <tr><td colspan="13" class="text-center text-muted py-4">هیچ دستگاهی در وضعیت آماده تحویل یا تحویل شده یافت نشد.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>