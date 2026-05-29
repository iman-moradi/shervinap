<?php
ob_start();
$page_title = 'تغییر وضعیت تعمیر';
require_once '../../includes/header.php';
require_once '../../includes/SMSManager.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$ticket_id = $_GET['id'] ?? 0;
if (!$ticket_id) {
    ob_start();
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

// تابع کمکی برای اختلاف روز بین دو تاریخ شمسی (ساده)
function diffJalaliDays($date1, $date2) {
    $d1 = preg_replace('/[^0-9]/', '', $date1);
    $d2 = preg_replace('/[^0-9]/', '', $date2);
    if (strlen($d1) != 8 || strlen($d2) != 8) return 0;
    $y1 = substr($d1,0,4); $m1 = substr($d1,4,2); $d1d = substr($d1,6,2);
    $y2 = substr($d2,0,4); $m2 = substr($d2,4,2); $d2d = substr($d2,6,2);
    $g_y1 = $y1 + 621; $g_y2 = $y2 + 621;
    $ts1 = mktime(0,0,0, $m1, $d1d, $g_y1);
    $ts2 = mktime(0,0,0, $m2, $d2d, $g_y2);
    return floor(abs($ts2 - $ts1) / 86400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = $_POST['status'];
    $old_status = $ticket['status'];
    $customer_mobile = $ticket['mobile'];
    $error = '';
    
    $db->beginTransaction();
    try {
        if ($new_status == 'ready' && $old_status != 'ready') {
            $ready_date_sh = date('Y/m/d'); // تاریخ امروز به فرمت شمسی (در اینجا میلادی برای سادگی)
            // محاسبه روزهای انبارداری از تاریخ پذیرش تا امروز
            $days_storage = diffJalaliDays($ticket['received_date_sh'], $ready_date_sh);
            $storage_fee = $days_storage * 10000; // هر روز ۱۰ هزار تومان
            
            // هزینه تعمیر (فرض کنید در total_cost ذخیره شده است - اگر نه، می‌توانید فیلد جداگانه اضافه کنید)
            $repair_cost = $ticket['total_cost'] ?? 0;
            $total_cost = $repair_cost + $storage_fee;
            
            $update = $db->prepare("UPDATE repair_tickets SET status = ?, ready_date_sh = ?, total_cost = ? WHERE id = ?");
            $update->execute([$new_status, $ready_date_sh, $total_cost, $ticket_id]);
        } else {
            $update = $db->prepare("UPDATE repair_tickets SET status = ? WHERE id = ?");
            $update->execute([$new_status, $ticket_id]);
        }
        $db->commit();
        
        // ========== ارسال پیامک وضعیت ==========
        if (!empty($customer_mobile)) {
            $sms = new SMSManager($db);
            if ($sms->isAvailable()) {
                $status_text = $status_map[$new_status];
                $message = "خدمات فنی شروین: وضعیت تعمیر دستگاه {$ticket['device_type']} به '{$status_text}' تغییر یافت. شماره پیگیری: {$ticket['ticket_no']}";
                
                if ($new_status == 'ready') {
                    // محاسبه مجدد هزینه کل (اگر در بالا محاسبه نشده باشد)
                    if (!isset($total_cost)) {
                        $days_storage = diffJalaliDays($ticket['received_date_sh'], date('Y/m/d'));
                        $storage_fee = $days_storage * 10000;
                        $total_cost = ($ticket['total_cost'] ?? 0) + $storage_fee;
                    }
                    $deposit = $ticket['deposit'] ?? 0;
                    $payable = max(0, $total_cost - $deposit);
                    $message .= "\nمبلغ نهایی تعمیر: " . number_format($total_cost) . " تومان";
                    $message .= "\nبیعانه پرداختی: " . number_format($deposit) . " تومان";
                    $message .= "\nمبلغ قابل پرداخت: " . number_format($payable) . " تومان";
                    $message .= "\nهزینه انبارداری (هر روز ۱۰,۰۰۰ تومان) نیز محاسبه شده است.";
                }
                $smsResult = $sms->send($customer_mobile, $message);
                if (!$smsResult['success']) {
                    error_log("خطا در ارسال پیامک وضعیت به {$customer_mobile}: " . $smsResult['error']);
                }
            }
        }
        // ===================================
        
        header("Location: view.php?id=$ticket_id");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $error = "خطا: " . $e->getMessage();
    }
}
?>

<div class="modern-card">
    <div class="card-header-custom">
        <i class="fas fa-exchange-alt"></i> تغییر وضعیت تعمیر
    </div>
    <div class="card-body">
        <?php if (isset($error) && $error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div class="info-grid mb-4">
            <div class="info-item"><span class="info-label">مشتری:</span><span class="info-value"><?= htmlspecialchars($ticket['fullname']) ?></span></div>
            <div class="info-item"><span class="info-label">دستگاه:</span><span class="info-value"><?= htmlspecialchars($ticket['device_type']) ?></span></div>
            <div class="info-item"><span class="info-label">وضعیت فعلی:</span><span class="info-value">
                <?php 
                $status_class = '';
                switch($ticket['status']) {
                    case 'pending': $status_class = 'badge-pending'; break;
                    case 'in_progress': $status_class = 'badge-in_progress'; break;
                    case 'waiting_part': $status_class = 'badge-waiting_part'; break;
                    case 'ready': $status_class = 'badge-ready'; break;
                    case 'delivered': $status_class = 'badge-delivered'; break;
                }
                ?>
                <span class="badge-status <?= $status_class ?>"><?= $status_map[$ticket['status']] ?></span>
            </span></div>
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
    .badge-status {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-in_progress { background: #dbeafe; color: #1e40af; }
    .badge-waiting_part { background: #fed7aa; color: #9a3412; }
    .badge-ready { background: #dcfce7; color: #166534; }
    .badge-delivered { background: #e0e7ff; color: #3730a3; }
</style>
<?php require_once '../../includes/footer.php'; ?>