<?php
$page_title = 'تاریخچه ارسال پیامک';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'settings_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ایجاد جدول لاگ در صورت نیاز
$db->exec("CREATE TABLE IF NOT EXISTS `sms_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `mobile` varchar(20) NOT NULL,
    `message` text NOT NULL,
    `sent_at` datetime NOT NULL,
    `status` varchar(50) DEFAULT 'pending',
    `provider_ref_id` varchar(100) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `mobile` (`mobile`),
    KEY `sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 30;
$offset = ($page - 1) * $limit;

$total = $db->query("SELECT COUNT(*) FROM sms_logs")->fetchColumn();
$stmt = $db->prepare("SELECT * FROM sms_logs ORDER BY sent_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();
?>
<div class="card">
    <div class="card-header">📜 تاریخچه ارسال پیامک</div>
    <div class="card-body">
        <table class="table table-bordered">
            <thead><tr><th>زمان ارسال</th><th>شماره</th><th>متن</th><th>وضعیت</th></tr></thead>
            <tbody>
                <?php foreach($logs as $log): ?>
                <tr>
                    <td><?= date('Y/m/d H:i', strtotime($log['sent_at'])) ?></td>
                    <td><?= htmlspecialchars($log['mobile']) ?></td>
                    <td><?= htmlspecialchars(mb_substr($log['message'], 0, 50)) . (mb_strlen($log['message'])>50 ? '...' : '') ?></td>
                    <td><?= $log['status'] == 'sent' ? '<span class="badge bg-success">ارسال شد</span>' : '<span class="badge bg-warning">'.$log['status'].'</span>' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($logs)): ?>发展<td colspan="4" class="text-center">هیچ پیامکی ارسال نشده است.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if($total > $limit): ?>
        <nav><ul class="pagination"><?php for($i=1; $i<=ceil($total/$limit); $i++): ?><li class="page-item <?= $i==$page?'active':'' ?>"><a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>