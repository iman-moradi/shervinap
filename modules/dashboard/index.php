// دریافت تعداد تیکت‌های فوری که deadline آنها گذشته یا امروز است
$urgent_overdue = $db->query("SELECT COUNT(*) FROM repair_tickets WHERE priority='urgent' AND status!='delivered' AND urgent_deadline_sh <= '".date('Y/m/d')."'")->fetchColumn();
// دریافت تعداد تیکت‌های انتظار قطعه
$waiting_parts = $db->query("SELECT COUNT(*) FROM repair_tickets WHERE status='waiting_part'")->fetchColumn();
?>
<?php if ($urgent_overdue > 0): ?>
<div class="alert alert-danger"><?= $urgent_overdue ?> دستگاه با اولویت فوری نیاز به توجه فوری دارند.</div>
<?php endif; ?>
<?php if ($waiting_parts > 0): ?>
<div class="alert alert-warning"><?= $waiting_parts ?> دستگاه در انتظار قطعه هستند.</div>
<?php endif; ?>