<?php
$page_title = '📜 گزارش ارسال پیامک‌ها';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access') && !has_permission($_SESSION['user_id'], 'settings_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ارسال مجدد
if (isset($_GET['resend']) && is_numeric($_GET['resend'])) {
    // نیاز به کلاس SMSManager دارید
    require_once '../../includes/SMSManager.php';
    $smsManager = new SMSManager($db);
    $result = $smsManager->resend((int)$_GET['resend']);
    if ($result['success']) {
        echo '<div class="alert alert-success">✅ پیامک با موفقیت ارسال مجدد شد.</div>';
    } else {
        echo '<div class="alert alert-danger">❌ ارسال مجدد ناموفق: ' . htmlspecialchars($result['error']) . '</div>';
    }
}

// فیلتر و صفحه‌بندی
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 30;
$offset = ($page - 1) * $limit;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// شمارش کل
$countSql = "SELECT COUNT(*) FROM sms_logs WHERE 1=1";
$countParams = [];
if ($statusFilter !== 'all') {
    $countSql .= " AND status = ?";
    $countParams[] = $statusFilter;
}
if (!empty($search)) {
    $countSql .= " AND (recipients LIKE ? OR mobile LIKE ? OR message LIKE ? OR ticket_no LIKE ?)";
    $searchTerm = "%$search%";
    $countParams = array_merge($countParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}
$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$totalCount = $countStmt->fetchColumn();

// دریافت داده‌ها
$listSql = "SELECT * FROM sms_logs WHERE 1=1";
$listParams = [];
if ($statusFilter !== 'all') {
    $listSql .= " AND status = ?";
    $listParams[] = $statusFilter;
}
if (!empty($search)) {
    $listSql .= " AND (recipients LIKE ? OR mobile LIKE ? OR message LIKE ? OR ticket_no LIKE ?)";
    $searchTerm = "%$search%";
    $listParams = array_merge($listParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}
$listSql .= " ORDER BY sent_at DESC LIMIT ? OFFSET ?";
$listParams[] = $limit;
$listParams[] = $offset;

$stmt = $db->prepare($listSql);
$stmt->execute($listParams);
$logs = $stmt->fetchAll();
?>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h4 class="mb-0">📜 تاریخچه ارسال پیامک‌ها</h4>
        <a href="manual_send.php" class="btn btn-sm btn-light">➕ ارسال پیامک جدید</a>
    </div>
    <div class="card-body">
        <!-- فیلتر و جستجو -->
        <form method="get" class="row g-3 mb-4">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="جستجو در شماره، متن یا شماره پیگیری..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="all" <?= $statusFilter == 'all' ? 'selected' : '' ?>>همه وضعیت‌ها</option>
                    <option value="sent" <?= $statusFilter == 'sent' ? 'selected' : '' ?>>ارسال شده</option>
                    <option value="failed" <?= $statusFilter == 'failed' ? 'selected' : '' ?>>ناموفق</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">🔍 فیلتر</button>
            </div>
            <div class="col-md-2">
                <a href="?page=1" class="btn btn-secondary w-100">🗑️ پاک کردن</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th>زمان ارسال</th>
                        <th>گیرنده(ها)</th>
                        <th>متن پیامک</th>
                        <th>نوع</th>
                        <th>شماره پیگیری</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="7" class="text-center">هیچ پیامکی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= date('Y/m/d H:i', strtotime($log['sent_at'])) ?></td>
                            <td>
                                <?php
                                $numbers = [];
                                if (!empty($log['recipients'])) {
                                    $recipientsData = json_decode($log['recipients'], true);
                                    if (is_array($recipientsData)) {
                                        $numbers = $recipientsData;
                                    } else {
                                        $numbers = [$log['recipients']];
                                    }
                                } elseif (!empty($log['mobile'])) {
                                    $numbers = [$log['mobile']];
                                }
                                if (count($numbers) > 1) {
                                    echo '<ul class="mb-0 ps-3">';
                                    foreach ($numbers as $num) echo '<li>' . htmlspecialchars($num) . '</li>';
                                    echo '</ul>';
                                } else {
                                    echo htmlspecialchars($numbers[0] ?? '-');
                                }
                                ?>
                            </td>
                            <td><?= htmlspecialchars(mb_substr($log['message'], 0, 80)) . (mb_strlen($log['message']) > 80 ? '...' : '') ?></td>
                            <td>
                                <?php
                                $typeLabels = [
                                    'manual' => 'دستی',
                                    'auto_welcome' => 'خوش‌آمدگویی',
                                    'auto_status' => 'تغییر وضعیت',
                                    'auto_reminder' => 'یادآوری'
                                ];
                                echo $typeLabels[$log['type']] ?? $log['type'];
                                ?>
                            </td>
                            <td><?= htmlspecialchars($log['ticket_no'] ?? '-') ?></td>
                            <td>
                                <?php if ($log['status'] == 'sent'): ?>
                                    <span class="badge bg-success">ارسال شد</span>
                                <?php else: ?>
                                    <span class="badge bg-danger" title="<?= htmlspecialchars($log['error_message'] ?? 'خطای ناشناخته') ?>">ناموفق</span>
                                    <?php if ($log['retry_count'] > 0): ?>
                                        <div class="small text-muted mt-1">تلاش: <?= $log['retry_count'] ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['status'] != 'sent'): ?>
                                    <a href="?resend=<?= $log['id'] ?>&page=<?= $page ?>&status=<?= $statusFilter ?>&search=<?= urlencode($search) ?>" 
                                       class="btn btn-sm btn-warning" 
                                       onclick="return confirm('آیا از ارسال مجدد این پیامک اطمینان دارید؟');">
                                        🔄 ارسال مجدد
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- صفحه‌بندی -->
        <?php if ($totalCount > $limit): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <?php for ($i = 1; $i <= ceil($totalCount / $limit); $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&status=<?= $statusFilter ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>