<?php
$page_title = 'گزارش گردش حساب';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$transactions = [];
$account = null;

if ($account_id) {
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch();
    if ($account) {
        $sql = "SELECT * FROM transactions WHERE account_id = ?";
        $params = [$account_id];
        if ($from_date && $to_date) {
            $sql .= " AND transaction_date_sh BETWEEN ? AND ?";
            $params[] = $from_date;
            $params[] = $to_date;
        }
        $sql .= " ORDER BY transaction_date_sh DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll();
    }
}

$accounts = $db->query("SELECT id, account_name FROM accounts ORDER BY account_name")->fetchAll();
?>
<div class="card">
    <div class="card-header">📊 گزارش گردش حساب</div>
    <div class="card-body">
        <form method="get" class="row g-3 mb-4">
            <div class="col-md-3">
                <label>حساب</label>
                <select name="account_id" class="form-select" required>
                    <option value="">انتخاب کنید</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $account_id==$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['account_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label>از تاریخ (مثال 1402/01/01)</label>
                <input type="text" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-md-2">
                <label>تا تاریخ</label>
                <input type="text" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-md-2 align-self-end">
                <button type="submit" class="btn btn-primary">نمایش</button>
            </div>
        </form>
        
        <?php if ($account): ?>
            <h5>حساب: <?= htmlspecialchars($account['account_name']) ?></h5>
            <p>موجودی اولیه: <?= number_format($account['initial_balance']) ?> | موجودی فعلی: <?= number_format($account['current_balance']) ?></p>
            <?php if (count($transactions) > 0): ?>
                <table class="table table-bordered table-striped">
                    <thead>
                        <tr><th>تاریخ</th><th>نوع</th><th>مبلغ</th><th>شرح</th><th>مرجع</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['transaction_date_sh']) ?></td>
                            <td><?= $t['type'] == 'income' ? 'واریز' : 'برداشت' ?></td>
                            <td><?= number_format($t['amount']) ?> تومان</td>
                            <td><?= htmlspecialchars($t['description']) ?></td>
                            <td><?= $t['ref_type'] . ' #' . $t['ref_id'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="alert alert-info">هیچ تراکنشی یافت نشد.</div>
            <?php endif; ?>
        <?php elseif ($account_id): ?>
            <div class="alert alert-danger">حساب یافت نشد.</div>
        <?php endif; ?>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>