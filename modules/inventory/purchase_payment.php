<?php
$page_title = 'ثبت پرداخت برای فاکتور خرید';
require_once '../../includes/header.php';
if (!has_permission($_SESSION['user_id'], 'accounting_access')) { echo '<div class="alert alert-danger">دسترسی ندارید.</div>'; require_once '../../includes/footer.php'; exit; }

$invoice_id = (int)$_GET['id'];
if(!$invoice_id) { header('Location: products.php'); exit; }

$invoice = $db->prepare("SELECT * FROM purchase_invoices WHERE id = ?")->execute([$invoice_id]);
$invoice = $db->prepare("SELECT * FROM purchase_invoices WHERE id = ?")->execute([$invoice_id]);
$stmt = $db->prepare("SELECT * FROM purchase_invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();
if(!$invoice){ echo '<div class="alert alert-danger">فاکتور یافت نشد.</div>'; require_once '../../includes/footer.php'; exit; }

$remaining = $invoice['total_amount'] - $invoice['paid_amount'];
if($remaining <= 0){ echo '<div class="alert alert-info">این فاکتور قبلاً تسویه شده است.</div>'; require_once '../../includes/footer.php'; exit; }

$error = '';
$success = '';
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $payment = (int)$_POST['payment'];
    $account_id = (int)$_POST['account_id'];
    $payment_date = $_POST['payment_date'];
    if($payment <= 0 || $payment > $remaining) $error = 'مبلغ پرداختی نامعتبر است.';
    else{
        $db->beginTransaction();
        try{
            $new_paid = $invoice['paid_amount'] + $payment;
            $status = ($new_paid >= $invoice['total_amount']) ? 'paid' : 'partial';
            $db->prepare("UPDATE purchase_invoices SET paid_amount = ?, payment_status = ? WHERE id = ?")->execute([$new_paid, $status, $invoice_id]);
            $trans = $db->prepare("INSERT INTO transactions (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description) VALUES (?, ?, ?, 'expense', 'purchase', ?, ?, 'پرداخت قسط خرید')");
            $trans->execute([$payment_date, $account_id, $payment, $invoice_id, $_SESSION['user_id']]);
            $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$payment, $account_id]);
            $db->commit();
            $success = "✅ مبلغ ".number_format($payment)." تومان پرداخت شد. مانده بدهی: ".number_format($invoice['total_amount'] - $new_paid)." تومان";
            echo '<meta http-equiv="refresh" content="2;url=products.php">';
        } catch(Exception $e){ $db->rollBack(); $error = "خطا: ".$e->getMessage(); }
    }
}

$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
?>
<div class="card">
    <div class="card-header">پرداخت قسط فاکتور خرید #<?= htmlspecialchars($invoice['invoice_no']) ?></div>
    <div class="card-body">
        <?php if($error):?><div class="alert alert-danger"><?=$error?></div><?php endif; ?>
        <?php if($success):?><div class="alert alert-success"><?=$success?></div><?php endif; ?>
        <p><strong>تأمین‌کننده:</strong> <?= htmlspecialchars($invoice['supplier_name']) ?></p>
        <p><strong>مبلغ کل:</strong> <?= number_format($invoice['total_amount']) ?> تومان</p>
        <p><strong>پرداخت شده:</strong> <?= number_format($invoice['paid_amount']) ?> تومان</p>
        <p><strong>مانده بدهی:</strong> <?= number_format($remaining) ?> تومان</p>
        <form method="post">
            <div class="row">
                <div class="col-md-4 mb-3"><label>تاریخ پرداخت</label><input type="text" name="payment_date" class="form-control" value="<?= now_jalali() ?>" required></div>
                <div class="col-md-4 mb-3"><label>مبلغ پرداختی (تومان)</label><input type="number" name="payment" class="form-control" required></div>
                <div class="col-md-4 mb-3"><label>حساب پرداخت کننده</label><select name="account_id" class="form-select" required>
                    <?php foreach($accounts as $a):?><option value="<?=$a['id']?>"><?= htmlspecialchars($a['account_name']) ?> (<?= number_format($a['current_balance']) ?>)</option><?php endforeach; ?>
                </select></div>
            </div>
            <button type="submit" class="btn btn-primary">ثبت پرداخت</button>
            <a href="products.php" class="btn btn-secondary">بازگشت</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>