<?php
ob_start();
$page_title = 'تاریخچه ارسال پیامک';
require_once '../../includes/header.php';
require_once '../../includes/SMSManager.php';

if (!has_permission($_SESSION['user_id'], 'settings_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$smsManager = new SMSManager($db);
$message = '';
$error = '';

// ارسال مجدد
if (isset($_GET['resend']) && is_numeric($_GET['resend'])) {
    $logId = (int)$_GET['resend'];
    $result = $smsManager->resend($logId);
    if ($result['success']) {
        $message = "✅ پیامک با موفقیت ارسال مجدد شد.";
    } else {
        $error = "❌ ارسال مجدد ناموفق: " . htmlspecialchars($result['error']);
    }
}

// فیلتر و صفحه‌بندی
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 30;
$offset = ($page - 1) * $limit;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// شمارش کل رکوردها
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
foreach ($countParams as $idx => $param) {
    $countStmt->bindValue($idx + 1, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$totalCount = $countStmt->fetchColumn(); // تعریف متغیر totalCount

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
$listParams[] = (int)$limit;
$listParams[] = (int)$offset;

$stmt = $db->prepare($listSql);
foreach ($listParams as $idx => $param) {
    $type = is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($idx + 1, $param, $type);
}
$stmt->execute();
$logs = $stmt->fetchAll();
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>📜 تاریخچه ارسال پیامک</span>
        <div>
            <a href="manual_send.php" class="btn btn-sm btn-success">➕ ارسال پیامک جدید</a>
            <a href="../../modules/settings/general.php" class="btn btn-sm btn-info">⚙️ تنظیمات عمومی</a>
        </div>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        
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
                <a href="history.php" class="btn btn-secondary w-100">🗑️ پاک کردن</a>
            </div>
        </form>
        
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
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
                                    $displayNumbers = [];
                                    if (!empty($log['recipients'])) {
                                        $recipientsData = json_decode($log['recipients'], true);
                                        if (is_array($recipientsData)) {
                                            $displayNumbers = $recipientsData;
                                        } else {
                                            $displayNumbers = [$log['recipients']];
                                        }
                                    } elseif (!empty($log['mobile'])) {
                                        $displayNumbers = [$log['mobile']];
                                    }
                                    
                                    if (count($displayNumbers) > 1) {
                                        echo '<ul class="mb-0 ps-3">';
                                        foreach ($displayNumbers as $num) {
                                            echo '<li>' . htmlspecialchars($num) . '</li>';
                                        }
                                        echo '</ul>';
                                    } else {
                                        echo htmlspecialchars($displayNumbers[0] ?? '-');
                                    }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars(mb_substr($log['message'], 0, 50)) . (mb_strlen($log['message']) > 50 ? '...' : '') ?></td>
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