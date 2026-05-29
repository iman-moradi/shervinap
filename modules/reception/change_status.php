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
    
    if ($new_status == 'ready' && $ticket['status'] != 'ready') {
        $ready_date = now_jalali();
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

<div class="modern-card">
    <div class="card-header-custom">
        <i class="fas fa-exchange-alt"></i> تغییر وضعیت تعمیر
    </div>
    <div class="card-body">
        <div class="info-grid mb-4">
            <div class="info-item"><span class="info-label">مشتری:</span><span class="info-value"><?= htmlspecialchars($ticket['fullname']) ?></span></div>
            <div class="info-item"><span class="info-label">دستگاه:</span><span class="info-value"><?= htmlspecialchars($ticket['device_type']) ?></span></div>
            <div class="info-item"><span class="info-label">وضعیت فعلی:</span><span class="info-value"><span class="badge-status <?= match($ticket['status']){'pending'=>'badge-pending','in_progress'=>'badge-in_progress','waiting_part'=>'badge-waiting_part','ready'=>'badge-ready','delivered'=>'badge-delivered'} ?>"><?= $status_map[$ticket['status']] ?></span></span></div>
        </div>
        
        <form method="post">
            <div class="mb-3">
                <label>وضعیت جدید:</label>
                <select name="status" class="form-select">
                    <?php foreach ($status_map as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $ticket['status']==$key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-modern"><i class="fas fa-save"></i> ذخیره و ارسال پیامک</button>
                <a href="view.php?id=<?= $ticket_id ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> بازگشت</a>
            </div>
        </form>
    </div>
</div>

<style>
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 12px;
        background: #f8fafc;
        padding: 1rem;
        border-radius: 16px;
    }
    .info-item {
        display: flex;
        justify-content: space-between;
        border-bottom: 1px dashed #cbd5e1;
        padding: 6px 0;
    }
    .info-label {
        font-weight: 600;
        color: #334155;
    }
    .info-value {
        color: #1e293b;
    }
</style>
<?php require_once '../../includes/footer.php'; ?>