<?php
$page_title = 'تحويل و تسويه تعمير';
require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسي نداريد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$ticket_id = (int)($_GET['id'] ?? 0);
if (!$ticket_id) {
    header('Location: index.php');
    exit;
}

// دريافت اطلاعات تيکت
$stmt = $db->prepare("SELECT r.*, c.fullname, c.mobile FROM repair_tickets r JOIN customers c ON c.id = r.customer_id WHERE r.id = ?");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();
if (!$ticket) {
    echo '<div class="alert alert-danger">تيکت يافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

if ($ticket['status'] == 'delivered') {
    echo '<div class="alert alert-warning">اين دستگاه قبلاً تحويل شده است.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$due_amount = $ticket['total_cost'] - $ticket['deposit'] - $ticket['paid_amount'];
$due_amount = max(0, $due_amount);

// دريافت ليست حساب‌هاي فعال (براي انتخاب روش پرداخت)
$accounts = $db->query("SELECT id, account_name FROM accounts WHERE account_type IN ('cash','bank_card') ORDER BY account_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_amount = (int)$_POST['payment_amount'];
    $account_id = (int)$_POST['account_id'];
    $delivered_date_sh = $_POST['delivered_date_sh'];
    $description = trim($_POST['description'] ?? 'تسويه تعمير');
    
    if ($payment_amount <= 0) {
        $error = "مبلغ پرداختي بايد بيشتر از صفر باشد.";
    } elseif ($payment_amount > $due_amount) {
        $error = "مبلغ پرداختي بيشتر از بدهي است.";
    } else {
        $db->beginTransaction();
        try {
            // ثبت تراکنش دريافت وجه
            $trans_sql = "INSERT INTO transactions (transaction_date_sh, account_id, amount, type, ref_type, ref_id, description, created_by)
                          VALUES (?, ?, ?, 'income', 'repair', ?, ?, ?)";
            $trans_stmt = $db->prepare($trans_sql);
            $trans_stmt->execute([$delivered_date_sh, $account_id, $payment_amount, $ticket_id, $description, $_SESSION['user_id']]);
            
            // به‌روزرساني موجودي حساب
            $upd_acc = $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?");
            $upd_acc->execute([$payment_amount, $account_id]);
            
            // به‌روزرساني جدول repair_tickets
            $new_paid = $ticket['paid_amount'] + $payment_amount;
            $new_status = ($new_paid >= $ticket['total_cost']) ? 'delivered' : $ticket['status'];
            $upd_ticket = $db->prepare("UPDATE repair_tickets SET paid_amount = ?, delivered_date_sh = ?, status = ? WHERE id = ?");
            $upd_ticket->execute([$new_paid, $delivered_date_sh, $new_status, $ticket_id]);
            
            $db->commit();
            $success = "تحويل با موفقيت ثبت شد. مبلغ پرداختي: " . number_format($payment_amount) . " تومان";
            // ريدايرکت به صفحه نمايش تيکت
            echo '<meta http-equiv="refresh" content="2;url=view.php?id='.$ticket_id.'">';
        } catch (Exception $e) {
            $db->rollBack();
            $error = "خطا در ثبت تسويه: " . $e->getMessage();
        }
    }
}
?>

<div class="modern-card">
    <div class="card-header-custom"><i class="fas fa-hand-holding-usd"></i> تسويه و تحويل دستگاه</div>
    <div class="card-body">
        <?php if(isset($error)): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if(isset($success)): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <table class="table table-bordered">
                    <tr><th>شماره تيکت</th><td><?= htmlspecialchars($ticket['ticket_no']) ?></td></tr>
                    <tr><th>مشتري</th><td><?= htmlspecialchars($ticket['fullname']) ?> - <?= htmlspecialchars($ticket['mobile']) ?></td></tr>
                    <tr><th>هزينه کل</th><td><?= number_format($ticket['total_cost']) ?> تومان</td></tr>
                    <tr><th>بيعانه پرداختي</th><td><?= number_format($ticket['deposit']) ?> تومان</td></tr>
                    <tr><th>پرداختي قبلي</th><td><?= number_format($ticket['paid_amount']) ?> تومان</td></tr>
                    <tr><th>مانده قابل پرداخت</th><td class="fw-bold text-primary"><?= number_format($due_amount) ?> تومان</td></tr>
                </table>
            </div>
        </div>
        
        <form method="post">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label>تاريخ تحويل</label>
                    <input type="text" name="delivered_date_sh" class="form-control" required value="<?= now_jalali() ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label>مبلغ پرداختي (تومان)</label>
                    <input type="number" name="payment_amount" class="form-control" required value="<?= $due_amount ?>" min="1" max="<?= $due_amount ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label>روش پرداخت</label>
                    <select name="account_id" class="form-select" required>
                        <?php foreach($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-12 mb-3">
                    <label>توضيحات (اختياري)</label>
                    <input type="text" name="description" class="form-control" placeholder="مثلاً: تسويه نقدي">
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-success"><i class="fas fa-check-circle"></i> ثبت تحويل و تسويه</button>
                <a href="view.php?id=<?= $ticket_id ?>" class="btn btn-secondary">بازگشت</a>
            </div>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>