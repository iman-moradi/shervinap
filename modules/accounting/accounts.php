<?php
$page_title = 'مدیریت حساب‌ها و کارت‌های بانکی';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// حذف حساب (در صورتی که تراکنش مرتبط نداشته باشد)
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $check = $db->prepare("SELECT COUNT(*) FROM transactions WHERE account_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        $error = 'این حساب دارای تراکنش است و قابل حذف نیست.';
    } else {
        $db->prepare("DELETE FROM accounts WHERE id = ?")->execute([$id]);
        $success = 'حذف شد.';
    }
}

$accounts = $db->query("SELECT * FROM accounts ORDER BY id DESC")->fetchAll();
?>
<div class="card">
    <div class="card-header">
        <a href="account_add.php" class="btn btn-primary">➕ افزودن حساب/کارت جدید</a>
        <a href="add_transaction.php" class="btn btn-success">💰 ثبت سند دستی</a>
        <a href="balance_sheet.php" class="btn btn-info">📊 گزارش گردش حساب</a>
    </div>
    <div class="card-body">
        <?php if (isset($error)): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if (isset($success)): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>شناسه</th><th>نام حساب</th><th>نوع</th><th>بانک/کارت</th><th>شماره کارت</th>
                        <th>موجودی اولیه</th><th>موجودی فعلی</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $acc): ?>
                    <tr>
                        <td><?= $acc['id'] ?></td>
                        <td><?= htmlspecialchars($acc['account_name']) ?></td>
                        <td><?= $acc['account_type'] == 'cash' ? 'صندوق' : ($acc['account_type'] == 'bank_card' ? 'کارت بانکی' : 'سایر') ?></td>
                        <td><?= htmlspecialchars($acc['bank_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($acc['card_number'] ?? '-') ?></td>
                        <td class="text-start"><?= number_format($acc['initial_balance']) ?> تومان</td>
                        <td class="text-start <?= $acc['current_balance'] < 0 ? 'text-danger' : '' ?>"><?= number_format($acc['current_balance']) ?> تومان</td>
                        <td>
                            <a href="account_edit.php?id=<?= $acc['id'] ?>" class="btn btn-sm btn-warning">✏️</a>
                            <a href="?delete=<?= $acc['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف شود؟')">🗑️</a>
                         </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>