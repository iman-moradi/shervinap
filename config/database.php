<?php
/**
 * تنظیمات اتصال به پایگاه داده MySQL
 * نام دیتابیس: shervin_db
 * کاراکتر ست: utf8mb4 برای پشتیبانی از فارسی
 */

$db_host = 'localhost';      // معمولاً localhost
$db_name = 'shervin_db';
$db_user = 'root';           // در صورتی که رمز دارید، تغییر دهید
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("خطا در اتصال به دیتابیس: " . $e->getMessage());
}

// متغیر global برای استفاده در فایل‌های دیگر
$db = $pdo;
?>