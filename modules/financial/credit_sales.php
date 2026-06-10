<?php
ob_start(); // جلوگیری از خروجی ناخواسته

$page_title = 'مدیریت فروش نسیه (اقساطی)';
require_once '../../includes/header.php';

// حذف تابع تکراری - اگر در جای دیگر وجود دارد، این خط را حذف کنید
if (!function_exists('jalali_to_gregorian_timestamp')) {
    function jalali_to_gregorian_timestamp($shamsi_date) {
        $parts = explode('/', $shamsi_date);
        if (count($parts) != 3) return time();
        list($y, $m, $d) = $parts;
        if (function_exists('jalali_to_gregorian')) {
            $g = jalali_to_gregorian($y, $m, $d);
            return mktime(0, 0, 0, $g[1], $g[2], $g[0]);
        }
        return time();
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

<style>
    .table-credit th, .table-credit td {
        vertical-align: middle;
        text-align: center;
    }
    .badge-paid {
        background-color: #28a745;
    }
    .badge-partial {
        background-color: #ffc107;
        color: #333;
    }
    .badge-unpaid {
        background-color: #dc3545;
    }
</style>

<div class="card">
    <div class="card-header bg-primary text-white">
        <i class="fas fa-credit-card"></i> مدیریت فروش نسیه (اقساطی)
    </div>
    <div class="card-body">
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (isset($error_pay)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_pay) ?></div>
        <?php endif; ?>
        <?php if (isset($success_pay)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_pay) ?></div>
        <?php endif; ?>
        
        <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addCreditModal">
            <i class="fas fa-plus"></i> ثبت فاکتور نسیه جدید
        </button>
        
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover table-credit">
                <thead class="table-dark">
                    <tr>
                        <th>شماره فاکتور</th>
                        <th>مشتری</th>
                        <th>تاریخ فروش</th>
                        <th>مبلغ کل</th>
                        <th>پرداخت شده</th>
                        <th>مانده</th>
                        <th>سررسید</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($credit_sales) == 0): ?>
                        <tr><td colspan="9" class="text-center">هیچ فاکتور نسیه‌ای ثبت نشده است</td></tr>
                    <?php else: ?>
                        <?php foreach ($credit_sales as $s): 
                            $remaining = $s['total_amount'] - $s['paid_amount'];
                            if ($s['status'] == 'paid') {
                                $status_label = 'تسویه شده';
                                $status_class = 'badge-paid';
                            } elseif ($s['status'] == 'partial') {
                                $status_label = 'بدهی جزیی';
                                $status_class = 'badge-partial';
                            } else {
                                $status_label = 'پرداخت نشده';
                                $status_class = 'badge-unpaid';
                            }
                            $row_class = ($remaining > 0 && $s['due_date_sh'] < now_jalali()) ? 'table-danger' : '';
                        ?>
                            <tr class="<?= $row_class ?>">
                                <td class="fw-bold"><?= htmlspecialchars($s['invoice_no']) ?></td>
                                <td><?= htmlspecialchars($s['customer_name']) ?></td>
                                <td><?= htmlspecialchars($s['sale_date_sh']) ?></td>
                                <td><?= number_format($s['total_amount']) ?> تومان</td>
                                <td><?= number_format($s['paid_amount']) ?> تومان</td>
                                <td class="<?= $remaining > 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= number_format($remaining) ?> تومان</td>
                                <td><?= htmlspecialchars($s['due_date_sh']) ?></td>
                                <td><span class="badge <?= $status_class ?>"><?= $status_label ?></span></td>
                                <td>
                                    <?php if ($remaining > 0): ?>
                                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#payCreditModal" 
                                                data-id="<?= $s['id'] ?>" data-remaining="<?= $remaining ?>">
                                            <i class="fas fa-hand-holding-usd"></i> ثبت پرداخت
                                        </button>
                                    <?php else: ?>
                                        <span class="text-success"><i class="fas fa-check-circle"></i> تسویه شده</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- مودال ثبت فاکتور نسیه جدید -->
<div class="modal fade" id="addCreditModal" tabindex="-1" aria-labelledby="addCreditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addCreditModalLabel"><i class="fas fa-plus-circle"></i> ثبت فاکتور فروش نسیه</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">مشتری</label>
                        <select name="customer_id" class="form-select" required>
                            <?php foreach($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['fullname']) ?> (<?= $c['mobile'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاریخ فروش</label>
                        <input type="text" name="sale_date" class="form-control" value="<?= now_jalali() ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">مبلغ کل (تومان)</label>
                        <input type="number" name="total_amount" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاریخ سررسید</label>
                        <input type="text" name="due_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">توضیحات</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="submit" name="add_credit_sale" class="btn btn-primary">ثبت فاکتور</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- مودال ثبت پرداخت برای فاکتور نسیه -->
<div class="modal fade" id="payCreditModal" tabindex="-1" aria-labelledby="payCreditModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="credit_id" id="credit_id">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="payCreditModalLabel"><i class="fas fa-money-bill-wave"></i> ثبت پرداخت نسیه</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">مبلغ پرداختی (تومان)</label>
                        <input type="number" name="amount" id="pay_amount" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاریخ پرداخت</label>
                        <input type="text" name="payment_date" class="form-control" value="<?= now_jalali() ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">حساب دریافت وجه</label>
                        <select name="account_id" class="form-select" required>
                            <?php foreach($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (موجودی: <?= number_format($a['current_balance']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">توضیحات</label>
                        <input type="text" name="payment_desc" class="form-control" placeholder="اختیاری">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="submit" name="add_payment" class="btn btn-success">ثبت پرداخت</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// اطمینان از بارگذاری کامل DOM قبل از اجرای رویدادها
document.addEventListener('DOMContentLoaded', function() {
    // مقداردهی مودال پرداخت
    var payModal = document.getElementById('payCreditModal');
    if (payModal) {
        payModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            var creditId = button.getAttribute('data-id');
            var remaining = button.getAttribute('data-remaining');
            document.getElementById('credit_id').value = creditId;
            document.getElementById('pay_amount').value = remaining;
        });
    }
});
</script>

<?php 
ob_end_flush();
require_once '../../includes/footer.php'; 
?>