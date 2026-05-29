<?php
$page_title = 'مدیریت وام‌های بانکی';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'loans_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ثبت وام جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_loan'])) {
    $bank_name = trim($_POST['bank_name']);
    $loan_amount = (int)$_POST['loan_amount'];
    $total_installments = (int)$_POST['total_installments'];
    $installment_amount = (int)$_POST['installment_amount'];
    $start_date = $_POST['start_date'];
    $account_id = (int)$_POST['account_id'];
    
    // محاسبه تاریخ پایان (حدوداً)
    $end_date = jdate('Y/m/d', strtotime("+" . ($total_installments - 1) . " months", strtotime(jalali_to_gregorian_timestamp($start_date))));
    
    $stmt = $db->prepare("INSERT INTO loans (bank_name, loan_amount, total_installments, installment_amount, start_date_sh, end_date_sh, remaining_amount, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $remaining = $loan_amount;
    if ($stmt->execute([$bank_name, $loan_amount, $total_installments, $installment_amount, $start_date, $end_date, $remaining, $_SESSION['user_id']])) {
        // ثبت تراکنش دریافت وام (به عنوان درآمد موقت؟ بهتر است به حساب بانکی اضافه شود)
        $loan_id = $db->lastInsertId();
        // افزایش موجودی حساب بانکی
        $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$loan_amount, $account_id]);
        // ثبت تراکنش برای شفافیت
        $trans = $db->prepare("INSERT INTO transactions (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description) VALUES (?, ?, ?, 'income', 'loan', ?, ?, 'دریافت وام')");
        $trans->execute([$start_date, $account_id, $loan_amount, $loan_id, $_SESSION['user_id']]);
        header('Location: loans.php');
        exit;
    }
}

// ثبت پرداخت قسط
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_installment'])) {
    $loan_id = (int)$_POST['loan_id'];
    $amount = (int)$_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $account_id = (int)$_POST['account_id'];
    $description = trim($_POST['description']);
    
    $db->beginTransaction();
    try {
        // دریافت اطلاعات وام
        $stmt = $db->prepare("SELECT * FROM loans WHERE id = ?");
        $stmt->execute([$loan_id]);
        $loan = $stmt->fetch();
        if (!$loan) throw new Exception("وام یافت نشد");
        
        $new_paid = $loan['paid_installments'] + 1;
        $new_remaining = $loan['remaining_amount'] - $amount;
        
        if ($new_remaining < 0) throw new Exception("مبلغ پرداختی بیشتر از مانده بدهی است");
        
        // درج قسط
        $ins = $db->prepare("INSERT INTO loan_installments (loan_id, installment_no, amount, payment_date_sh, account_id, description, created_by) VALUES (?,?,?,?,?,?,?)");
        $ins->execute([$loan_id, $new_paid, $amount, $payment_date, $account_id, $description, $_SESSION['user_id']]);
        
        // به‌روزرسانی وام
        $status = ($new_remaining <= 0) ? 'completed' : 'active';
        $upd = $db->prepare("UPDATE loans SET paid_installments = ?, remaining_amount = ?, status = ? WHERE id = ?");
        $upd->execute([$new_paid, $new_remaining, $status, $loan_id]);
        
        // ثبت تراکنش هزینه
        $trans = $db->prepare("INSERT INTO transactions (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description) VALUES (?, ?, ?, 'expense', 'loan_installment', ?, ?, 'پرداخت قسط وام')");
        $trans->execute([$payment_date, $account_id, $amount, $loan_id, $_SESSION['user_id']]);
        // کاهش موجودی حساب
        $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $account_id]);
        
        $db->commit();
        header('Location: loans.php');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

$loans = $db->query("SELECT * FROM loans ORDER BY id DESC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
?>
<div class="card">
    <div class="card-header">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLoanModal">➕ ثبت وام جدید</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr><th>بانک</th><th>مبلغ کل</th><th>تعداد اقساط</th><th>مبلغ هر قسط</th><th>تاریخ شروع</th><th>پرداخت شده</th><th>مانده</th><th>وضعیت</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($loans as $l): ?>
                        <tr>
                            <td><?= htmlspecialchars($l['bank_name']) ?></td>
                            <td><?= number_format($l['loan_amount']) ?> تومان</td
                            <td><?= $l['total_installments'] ?> قسط</td
                            <td><?= number_format($l['installment_amount']) ?> تومان</td
                            <td><?= htmlspecialchars($l['start_date_sh']) ?></td>
                            <td><?= $l['paid_installments'] ?> قسط</td
                            <td><?= number_format($l['remaining_amount']) ?> تومان</td
                            <td><?= $l['status'] == 'active' ? 'فعال' : ($l['status'] == 'completed' ? 'تسویه شده' : 'معوق') ?></td>
                            <td>
                                <?php if ($l['remaining_amount'] > 0): ?>
                                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#payModal" data-id="<?= $l['id'] ?>" data-remaining="<?= $l['remaining_amount'] ?>" data-amount="<?= $l['installment_amount'] ?>">پرداخت قسط</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال ثبت وام جدید -->
<div class="modal fade" id="addLoanModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header"><h5>ثبت وام جدید</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-2"><label>نام بانک</label><input type="text" name="bank_name" class="form-control" required></div>
                    <div class="mb-2"><label>مبلغ کل وام (تومان)</label><input type="number" name="loan_amount" class="form-control" required></div>
                    <div class="mb-2"><label>تعداد اقساط</label><input type="number" name="total_installments" class="form-control" required></div>
                    <div class="mb-2"><label>مبلغ هر قسط (تومان)</label><input type="number" name="installment_amount" class="form-control" required></div>
                    <div class="mb-2"><label>تاریخ شروع (مثال 1402/01/01)</label><input type="text" name="start_date" class="form-control" required></div>
                    <div class="mb-2"><label>حساب دریافت وام</label><select name="account_id" class="form-select" required><?php foreach($accounts as $a){ echo "<option value='{$a['id']}'>{$a['account_name']}</option>"; } ?></select></div>
                </div>
                <div class="modal-footer"><button type="submit" name="add_loan" class="btn btn-primary">ثبت</button></div>
            </form>
        </div>
    </div>
</div>

<!-- مودال پرداخت قسط -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="loan_id" id="loan_id">
                <div class="modal-header"><h5>پرداخت قسط وام</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-2"><label>مبلغ پرداختی (تومان)</label><input type="number" name="amount" id="pay_amount" class="form-control" required></div>
                    <div class="mb-2"><label>تاریخ پرداخت</label><input type="text" name="payment_date" class="form-control" value="<?= now_jalali() ?>" required></div>
                    <div class="mb-2"><label>حساب پرداخت کننده</label><select name="account_id" class="form-select" required><?php foreach($accounts as $a){ echo "<option value='{$a['id']}'>{$a['account_name']}</option>"; } ?></select></div>
                    <div class="mb-2"><label>توضیحات</label><textarea name="description" class="form-control"></textarea></div>
                </div>
                <div class="modal-footer"><button type="submit" name="pay_installment" class="btn btn-primary">پرداخت</button></div>
            </form>
        </div>
    </div>
</div>

<script>
var payModal = document.getElementById('payModal');
payModal.addEventListener('show.bs.modal', function(event) {
    var button = event.relatedTarget;
    var loanId = button.getAttribute('data-id');
    var remaining = button.getAttribute('data-remaining');
    var amount = button.getAttribute('data-amount');
    document.getElementById('loan_id').value = loanId;
    document.getElementById('pay_amount').value = amount;
});
</script>
<?php require_once '../../includes/footer.php'; ?>