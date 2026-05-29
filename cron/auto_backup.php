<?php
require_once '../config/database.php';
$auto = $db->query("SELECT setting_value FROM settings WHERE setting_key='auto_backup'")->fetchColumn();
if ($auto == 1) {
    $hour = $db->query("SELECT setting_value FROM settings WHERE setting_key='auto_backup_hour'")->fetchColumn();
    if (date('H') == $hour) {
        $backupDir = __DIR__ . '/../backups/';
        if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);
        $backupFile = $backupDir . 'auto_backup_' . date('Ymd_His') . '.sql';
        $cmd = "mysqldump --user=" . DB_USER . " --password=" . DB_PASS . " --host=" . DB_HOST . " " . DB_NAME . " > " . $backupFile;
        exec($cmd);
    }
}