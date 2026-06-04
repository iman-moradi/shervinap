<?php
// دریافت تعداد تیکت‌های فوری که deadline آنها گذشته یا امروز است
$today_shamsi = date('Y/m/d'); // تاریخ شمسی امروز
$stmt = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE priority='urgent' AND status!='delivered' AND urgent_deadline_sh <= ?");
$stmt->execute([$today_shamsi]);
$urgent_overdue = $stmt->fetchColumn();

// دریافت تعداد تیکت‌های انتظار قطعه
$stmt2 = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE status='waiting_part'");
$stmt2->execute();
$waiting_parts = $stmt2->fetchColumn();
?>

<?php if ($urgent_overdue > 0): ?>
<div class="alert alert-danger"><?= (int)$urgent_overdue ?> دستگاه با اولویت فوری نیاز به توجه فوری دارند.</div>
<?php endif; ?>

<?php if ($waiting_parts > 0): ?>
<div class="alert alert-warning"><?= (int)$waiting_parts ?> دستگاه در انتظار قطعه هستند.</div>
<?php endif; ?>