<?php
$page_title = 'ویرایش حساب';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$id = (int)$_GET['id'];
if (!$id) {
    header('Location: accounts.php');
    exit;
}
$stmt = $db->prepare("SELECT * FROM accounts WHERE id = ?");
$stmt->execute([$id]);
$account = $stmt->fetch();
if (!$account) {
    echo '<div class="alert alert-danger">حساب یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_name = trim($_POST['account_name']);
    $account_type = $_POST['account_type'];
    $bank_name = !empty($_POST['bank_name']) ? trim($_POST['bank_name']) : null;
    $card_number = !empty($_POST['card_number']) ? trim($_POST['card_number']) : null;
    $initial_balance = (int)$_POST['initial_balance']; // فقط برای به‌روزرسانی مبلغ اولیه (اما موجودی فعلی نباید تغییر کند)
    
    if (empty($account_name)) {
        $error = 'نام حساب الزامی است.';
    } else {
        // بروزرسانی اطلاعات (موجودی فعلی را تغییر نمیدهیم، فقط موجودی اولیه را به‌روز می‌کنیم)
        $update = $db->prepare("UPDATE accounts SET account_name=?, account_type=?, bank_name=?, card_number=?, initial_balance=? WHERE id=?");
        if ($update->execute([$account_name, $account_type, $bank_name, $card_number, $initial_balance, $id])) {
            header('Location: accounts.php?msg=updated');
            exit;
        } else {
            $error = 'خطا در ویرایش.';
        }
    }
}
?>
<div class="card">
    <div class="card-header">✏️ ویرایش حساب</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <form method="post">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label>نام حساب</label>
                    <input type="text" name="account_name" class="form-control" value="<?= htmlspecialchars($account['account_name']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label>نوع حساب</label>
                    <select name="account_type" class="form-select">
                        <option value="cash" <?= $account['account_type']=='cash' ? 'selected' : '' ?>>صندوق</option>
                        <option value="bank_card" <?= $account['account_type']=='bank_card' ? 'selected' : '' ?>>کارت بانکی</option>
                        <option value="other" <?= $account['account_type']=='other' ? 'selected' : '' ?>>سایر</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label>نام بانک</label>
                    <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($account['bank_name']) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label>شماره کارت</label>
                    <input type="text" name="card_number" class="form-control" value="<?= htmlspecialchars($account['card_number']) ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label>موجودی اولیه (تومان)</label>
                    <input type="number" name="initial_balance" class="form-control" value="<?= $account['initial_balance'] ?>">
                    <small class="text-muted">موجودی فعلی: <?= number_format($account['current_balance']) ?> تومان (تغییر نمی‌کند)</small>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">💾 ذخیره</button>
            <a href="accounts.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>