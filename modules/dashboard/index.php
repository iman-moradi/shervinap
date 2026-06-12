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