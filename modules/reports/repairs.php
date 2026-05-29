<?php
$page_title = 'گزارش تعمیرات';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reports_view')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$status = $_GET['status'] ?? '';

$sql = "SELECT r.*, c.fullname, c.mobile FROM repair_tickets r JOIN customers c ON c.id = r.customer_id WHERE 1=1";
$params = [];
if ($from_date) {
    $sql .= " AND r.received_date_sh >= ?";
    $params[] = $from_date;
}
if ($to_date) {
    $sql .= " AND r.received_date_sh <= ?";
    $params[] = $to_date;
}
if ($status) {
    $sql .= " AND r.status = ?";
    $params[] = $status;
}
$sql .= " ORDER BY r.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$repairs = $stmt->fetchAll();

$status_map = ['pending'=>'در انتظار','in_progress'=>'در حال تعمیر','waiting_part'=>'انتظار قطعه','ready'=>'آماده تحویل','delivered'=>'تحویل شده'];
?>
<div class="card">
    <div class="card-header">🔧 گزارش تعمیرات</div>
    <div class="card-body">
        <form method="get" class="row g-3 mb-4">
            <div class="col-md-2"><label>از تاریخ</label><input type="text" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>"></div>
            <div class="col-md-2"><label>تا تاریخ</label><input type="text" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>"></div>
            <div class="col-md-2"><label>وضعیت</label><select name="status" class="form-select"><option value="">همه</option><?php foreach($status_map as $k=>$v) echo "<option value=\"$k\" ".($status==$k?'selected':'').">$v</option>"; ?></select></div>
            <div class="col-md-2 align-self-end"><button type="submit" class="btn btn-primary">نمایش</button><a href="repairs.php" class="btn btn-secondary ms-2">پاک کردن</a></div>
        </form>
        <?php if (count($repairs) > 0): ?>
            <table class="table table-bordered table-striped">
                <thead><tr><th>شماره</th><th>تاریخ پذیرش</th><th>مشتری</th><th>دستگاه</th><th>وضعیت</th><th>هزینه کل</th><th>پرداختی</th></tr></thead>
                <tbody>
                    <?php foreach ($repairs as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['ticket_no']) ?></td>
                        <td><?= htmlspecialchars($r['received_date_sh']) ?></td>
                        <td><?= htmlspecialchars($r['fullname']) ?></td>
                        <td><?= htmlspecialchars($r['device_type'] . ' ' . $r['brand']) ?></td>
                        <td><?= $status_map[$r['status']] ?></td>
                        <td class="text-start"><?= number_format($r['total_cost']) ?> تومان</td>
                        <td class="text-start"><?= number_format($r['paid_amount']) ?> تومان</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-info">نتیجه‌ای یافت نشد.</div>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>