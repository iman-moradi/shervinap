<?php
// shervinap/includes/db.php
require_once dirname(__DIR__) . '/config/database.php';
// حالا $db از آن فایل در دسترس است
if (!isset($db)) {
    die('خطا در اتصال به دیتابیس');
}
?>