<?php
ob_start();
$page_title = 'افزودن حساب / کارت بانکی';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_name = trim($_POST['account_name']);
    $account_type = $_POST['account_type'];
    $bank_name = !empty($_POST['bank_name']) ? trim($_POST['bank_name']) : null;
    $card_number = !empty($_POST['card_number']) ? trim($_POST['card_number']) : null;
    $initial_balance = (int)$_POST['initial_balance'];
    
    if (empty($account_name)) {
        $error = 'نام حساب الزامی است.';
    } else {
        $stmt = $db->prepare("INSERT INTO accounts (account_name, account_type, bank_name, card_number, initial_balance, current_balance) VALUES (?,?,?,?,?,?)");
        if ($stmt->execute([$account_name, $account_type, $bank_name, $card_number, $initial_balance, $initial_balance])) {
            ob_end_clean();
            header('Location: accounts.php?msg=added');
            exit;
        } else {
            $error = 'خطا در ثبت.';
        }
    }
}
?>
<div class="card">
    <div class="card-header">➕ افزودن حساب جدید</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <form method="post">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label>نام حساب *</label>
                    <input type="text" name="account_name" class="form-control" required>
                    <small class="text-muted">مثال: صندوق اصلی، بانک ملت، کارت اعتباری ...</small>
                </div>
                <div class="col-md-6 mb-3">
                    <label>نوع حساب</label>
                    <select name="account_type" class="form-select">
                        <option value="cash">صندوق (نقدی)</option>
                        <option value="bank_card">کارت بانکی</option>
                        <option value="other">سایر</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label>نام بانک (برای کارت بانکی)</label>
                    <input type="text" name="bank_name" class="form-control">
                </div>
                <div class="col-md-4 mb-3">
                    <label>شماره کارت</label>
                    <input type="text" name="card_number" class="form-control" maxlength="16" placeholder="۴ رقمی-۴ رقمی-...">
                </div>
                <div class="col-md-4 mb-3">
                    <label>موجودی اولیه (تومان)</label>
                    <input type="number" name="initial_balance" class="form-control" value="0">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">💾 ذخیره</button>
            <a href="accounts.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>