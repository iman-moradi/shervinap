<?php
$page_title = 'تغییر وضعیت تعمیر';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$ticket_id = $_GET['id'] ?? 0;
if (!$ticket_id) {
    header('Location: index.php');
    exit;
}

$stmt = $db->prepare("SELECT r.*, c.mobile, c.fullname FROM repair_tickets r JOIN customers c ON c.id = r.customer_id WHERE r.id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();
if (!$ticket) {
    echo '<div class="alert alert-danger">تیکت یافت نشد</div>';
    require_once '../../includes/footer.php';
    exit;
}

$status_map = [
    'pending' => 'در انتظار',
    'in_progress' => 'در حال تعمیر',
    'waiting_part' => 'انتظار قطعه',
    'ready' => 'آماده تحویل',
    'delivered' => 'تحویل شده'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = $_POST['status'];
    
    // اگر وضعیت جدید "آماده تحویل" است و قبلاً نبود، تاریخ آماده‌سازی را ثبت کن
    if ($new_status == 'ready' && $ticket['status'] != 'ready') {
        $ready_date = now_jalali(); // تاریخ شمسی جاری
        $update = $db->prepare("UPDATE repair_tickets SET status = ?, ready_date_sh = ? WHERE id = ?");
        $update->execute([$new_status, $ready_date, $ticket_id]);
    } else {
        $update = $db->prepare("UPDATE repair_tickets SET status = ? WHERE id = ?");
        $update->execute([$new_status, $ticket_id]);
    }
    
    $message = "خدمات فنی شروین: وضعیت تعمیر دستگاه شما به {$status_map[$new_status]} تغییر یافت. شماره پیگیری: {$ticket['ticket_no']}";
    send_sms($ticket['mobile'], $message);
    
    header("Location: view.php?id=$ticket_id");
    exit;
}
?>
<div class="card">
    <div class="card-header">تغییر وضعیت تعمیر</div>
    <div class="card-body">
        <p><strong>مشتری:</strong> <?= htmlspecialchars($ticket['fullname']) ?></p>
        <p><strong>دستگاه:</strong> <?= htmlspecialchars($ticket['device_type']) ?></p>
        <p><strong>وضعیت فعلی:</strong> <?= $status_map[$ticket['status']] ?></p>
        <form method="post">
            <div class="mb-3">
                <label>وضعیت جدید:</label>
                <select name="status" class="form-select">
                    <?php foreach ($status_map as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $ticket['status']==$key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">ذخیره و ارسال پیامک</button>
            <a href="view.php?id=<?= $ticket_id ?>" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>