<?php
$page_title = 'مدیریت فروش نسیه (اقساطی)';
require_once '../../includes/header.php';

if (!function_exists('jalali_to_gregorian_timestamp')) {
    function jalali_to_gregorian_timestamp($shamsi_date) {
        $parts = explode('/', $shamsi_date);
        if (count($parts) != 3) return time();
        list($y, $m, $d) = $parts;
        $g = jalali_to_gregorian($y, $m, $d);
        return mktime(0, 0, 0, $g[1], $g[2], $g[0]);
    }
}

if (!has_permission($_SESSION['user_id'], 'credit_sales_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ثبت فاکتور فروش نسیه جدید
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_credit_sale'])) {
    $customer_id = (int)$_POST['customer_id'];
    $sale_date = $_POST['sale_date'];
    $total_amount = (int)$_POST['total_amount'];
    $due_date = $_POST['due_date'];
    $description = trim($_POST['description']);
    
    $invoice_no = 'N-' . jdate('YmdHis') . rand(100, 999);
    $stmt = $db->prepare("INSERT INTO credit_sales (customer_id, invoice_no, sale_date_sh, total_amount, paid_amount, due_date_sh, status, description, created_by) VALUES (?,?,?,?,0,?, 'unpaid', ?, ?)");
    if ($stmt->execute([$customer_id, $invoice_no, $sale_date, $total_amount, $due_date, $description, $_SESSION['user_id']])) {
        $success = '✅ فاکتور نسیه با شماره ' . $invoice_no . ' ثبت شد.';
    } else {
        $error = 'خطا در ثبت.';
    }
}

// ثبت پرداخت جدید برای فاکتور نسیه
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $credit_id = (int)$_POST['credit_id'];
    $amount = (int)$_POST['amount'];
    $payment_date = $_POST['payment_date'];
    $account_id = (int)$_POST['account_id'];
    $description = trim($_POST['payment_desc']);
    
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM credit_sales WHERE id = ?");
        $stmt->execute([$credit_id]);
        $sale = $stmt->fetch();
        if (!$sale) throw new Exception("فاکتور یافت نشد");
        
        $new_paid = $sale['paid_amount'] + $amount;
        if ($new_paid > $sale['total_amount']) throw new Exception("مبلغ پرداختی بیشتر از کل فاکتور است");
        $status = ($new_paid >= $sale['total_amount']) ? 'paid' : 'partial';
        
        // درج پرداخت
        $ins = $db->prepare("INSERT INTO credit_payments (credit_sale_id, payment_date_sh, amount, account_id, description, created_by) VALUES (?,?,?,?,?,?)");
        $ins->execute([$credit_id, $payment_date, $amount, $account_id, $description, $_SESSION['user_id']]);
        
        // به‌روزرسانی فاکتور
        $upd = $db->prepare("UPDATE credit_sales SET paid_amount = ?, status = ? WHERE id = ?");
        $upd->execute([$new_paid, $status, $credit_id]);
        
        // ثبت تراکنش حسابداری (درآمد)
        $trans = $db->prepare("INSERT INTO transactions (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description) VALUES (?, ?, ?, 'income', 'credit_sale', ?, ?, 'پرداخت نسیه')");
        $trans->execute([$payment_date, $account_id, $amount, $credit_id, $_SESSION['user_id'], $description]);
        // افزایش موجودی حساب
        $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $account_id]);
        
        $db->commit();
        $success_pay = "✅ پرداخت به مبلغ " . number_format($amount) . " تومان ثبت شد.";
    } catch (Exception $e) {
        $db->rollBack();
        $error_pay = $e->getMessage();
    }
}

$credit_sales = $db->query("SELECT cs.*, c.fullname as customer_name FROM credit_sales cs JOIN customers c ON c.id = cs.customer_id ORDER BY cs.id DESC")->fetchAll();
$customers = $db->query("SELECT id, fullname, mobile FROM customers WHERE type='customer' ORDER BY fullname")->fetchAll();
$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
?>
<div class="card">
    <div class="card-header">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCreditModal">➕ ثبت فاکتور نسیه جدید</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr><th>شماره فاکتور</th><th>مشتری</th><th>تاریخ فروش</th><th>مبلغ کل</th><th>پرداخت شده</th><th>مانده</th><th>سررسید</th><th>وضعیت</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($credit_sales as $s): 
                        $remaining = $s['total_amount'] - $s['paid_amount'];
                        $status_label = ($s['status']=='paid') ? 'تسویه' : (($s['status']=='partial') ? 'بدهی جزیی' : 'پرداخت نشده');
                        $row_class = ($remaining > 0 && $s['due_date_sh'] < now_jalali()) ? 'table-danger' : '';
                    ?>
                        <tr class="<?= $row_class ?>">
                            <td><?= htmlspecialchars($s['invoice_no']) ?></td>
                            <td><?= htmlspecialchars($s['customer_name']) ?></td>
                            <td><?= htmlspecialchars($s['sale_date_sh']) ?></td>
                            <td><?= number_format($s['total_amount']) ?> تومان</td
                            <td><?= number_format($s['paid_amount']) ?> تومان</td
                            <td><strong><?= number_format($remaining) ?> تومان</strong></td
                            <td><?= htmlspecialchars($s['due_date_sh']) ?></td>
                            <td><?= $status_label ?></td>
                            <td>
                                <?php if ($remaining > 0): ?>
                                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#payCreditModal" data-id="<?= $s['id'] ?>" data-remaining="<?= $remaining ?>">💰 ثبت پرداخت</button>
                                <?php else: ?>
                                    <span class="text-success">تسویه شده</span>
                                <?php endif; ?>
                             </td
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال ثبت فاکتور نسیه جدید -->
<div class="modal fade" id="addCreditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header"><h5>ثبت فاکتور فروش نسیه</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-2"><label>مشتری</label><select name="customer_id" class="form-select" required><?php foreach($customers as $c){ echo "<option value='{$c['id']}'>{$c['fullname']} ({$c['mobile']})</option>"; } ?></select></div>
                    <div class="mb-2"><label>تاریخ فروش</label><input type="text" name="sale_date" class="form-control" value="<?= now_jalali() ?>" required></div>
                    <div class="mb-2"><label>مبلغ کل (تومان)</label><input type="number" name="total_amount" class="form-control" required></div>
                    <div class="mb-2"><label>تاریخ سررسید</label><input type="text" name="due_date" class="form-control" required></div>
                    <div class="mb-2"><label>توضیحات</label><textarea name="description" class="form-control"></textarea></div>
                </div>
                <div class="modal-footer"><button type="submit" name="add_credit_sale" class="btn btn-primary">ثبت فاکتور</button></div>
            </form>
        </div>
    </div>
</div>

<!-- مودال ثبت پرداخت برای فاکتور نسیه -->
<div class="modal fade" id="payCreditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="credit_id" id="credit_id">
                <div class="modal-header"><h5>ثبت پرداخت نسیه</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-2"><label>مبلغ پرداختی (تومان)</label><input type="number" name="amount" id="pay_amount" class="form-control" required></div>
                    <div class="mb-2"><label>تاریخ پرداخت</label><input type="text" name="payment_date" class="form-control" value="<?= now_jalali() ?>" required></div>
                    <div class="mb-2"><label>حساب دریافت وجه</label><select name="account_id" class="form-select" required><?php foreach($accounts as $a){ echo "<option value='{$a['id']}'>{$a['account_name']} (موجودی: ".number_format($a['current_balance']).")</option>"; } ?></select></div>
                    <div class="mb-2"><label>توضیحات</label><input type="text" name="payment_desc" class="form-control"></div>
                </div>
                <div class="modal-footer"><button type="submit" name="add_payment" class="btn btn-primary">ثبت پرداخت</button></div>
            </form>
        </div>
    </div>
</div>

<script>
var payModal = document.getElementById('payCreditModal');
payModal.addEventListener('show.bs.modal', function(event) {
    var button = event.relatedTarget;
    var creditId = button.getAttribute('data-id');
    var remaining = button.getAttribute('data-remaining');
    document.getElementById('credit_id').value = creditId;
    document.getElementById('pay_amount').value = remaining;
});
</script>
<?php require_once '../../includes/footer.php'; ?>