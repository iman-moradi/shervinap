<?php
$page_title = 'انتقال وجه بین حساب‌ها';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'transfers_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from_account = (int)$_POST['from_account'];
    $to_account = (int)$_POST['to_account'];
    $amount = (int)$_POST['amount'];
    $transfer_date = $_POST['transfer_date'];
    $description = trim($_POST['description']);
    
    if ($from_account == $to_account) {
        $error = 'حساب مبدا و مقصد نمی‌تواند یکسان باشد.';
    } elseif ($amount <= 0) {
        $error = 'مبلغ باید بزرگتر از صفر باشد.';
    } else {
        $db->beginTransaction();
        try {
            // بررسی موجودی کافی در حساب مبدا
            $stmt = $db->prepare("SELECT current_balance FROM accounts WHERE id = ?");
            $stmt->execute([$from_account]);
            $balance = $stmt->fetchColumn();
            if ($balance < $amount) {
                throw new Exception("موجودی حساب مبدا کافی نیست. موجودی فعلی: " . number_format($balance) . " تومان");
            }
            
            // ثبت انتقال
            $ins = $db->prepare("INSERT INTO internal_transfers (transfer_date_sh, from_account_id, to_account_id, amount, description, created_by) VALUES (?,?,?,?,?,?)");
            $ins->execute([$transfer_date, $from_account, $to_account, $amount, $description, $_SESSION['user_id']]);
            
            // کاهش موجودی مبدا
            $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $from_account]);
            // افزایش موجودی مقصد
            $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $to_account]);
            
            $db->commit();
            $success = '✅ انتقال وجه با موفقیت انجام شد.';
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}

$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
$transfers = $db->query("SELECT t.*, a1.account_name as from_name, a2.account_name as to_name FROM internal_transfers t JOIN accounts a1 ON a1.id = t.from_account_id JOIN accounts a2 ON a2.id = t.to_account_id ORDER BY t.id DESC LIMIT 50")->fetchAll();
?>
<div class="row">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header">انتقال وجه جدید</div>
            <div class="card-body">
                <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label>تاریخ انتقال</label>
                        <input type="text" name="transfer_date" class="form-control" value="<?= now_jalali() ?>" required>
                    </div>
                    <div class="mb-3">
                        <label>از حساب</label>
                        <select name="from_account" class="form-select" required>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (<?= number_format($a['current_balance']) ?> تومان)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>به حساب</label>
                        <select name="to_account" class="form-select" required>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (<?= number_format($a['current_balance']) ?> تومان)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>مبلغ (تومان)</label>
                        <input type="number" name="amount" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>توضیحات</label>
                        <textarea name="description" class="form-control"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">انتقال وجه</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">تاریخچه انتقالات</div>
            <div class="card-body">
                <table class="table table-sm table-bordered">
                    <thead><tr><th>تاریخ</th><th>از حساب</th><th>به حساب</th><th>مبلغ</th><th>توضیحات</th></tr></thead>
                    <tbody>
                        <?php foreach ($transfers as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['transfer_date_sh']) ?></td>
                            <td><?= htmlspecialchars($t['from_name']) ?></td>
                            <td><?= htmlspecialchars($t['to_name']) ?></td>
                            <td><?= number_format($t['amount']) ?> تومان</td
                            <td><?= htmlspecialchars($t['description']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($transfers)): ?>
                            <td><td colspan="5" class="text-center">هیچ انتقالی ثبت نشده است</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>