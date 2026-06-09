<?php
$page_title = 'ثبت پرداخت برای فاکتور فروش';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$invoice_id = (int)$_GET['id'];
if (!$invoice_id) {
    header('Location: sales_invoices.php');
    exit;
}

// دریافت اطلاعات فاکتور فروش با اطلاعات مشتری
$stmt = $db->prepare("SELECT s.*, c.fullname as customer_name, c.mobile 
                      FROM sales_invoices s 
                      LEFT JOIN customers c ON c.id = s.customer_id 
                      WHERE s.id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    echo '<div class="alert alert-danger">فاکتور یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$remaining = $invoice['total_amount'] - $invoice['paid_amount'];
if ($remaining <= 0) {
    echo '<div class="alert alert-info">این فاکتور قبلاً تسویه شده است.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment = (int)$_POST['payment'];
    $account_id = (int)$_POST['account_id'];
    $payment_date = $_POST['payment_date'];
    $description = trim($_POST['description'] ?? 'پرداخت قسط فروش');
    
    if ($payment <= 0 || $payment > $remaining) {
        $error = 'مبلغ پرداختی نامعتبر است.';
    } else {
        $db->beginTransaction();
        try {
            $new_paid = $invoice['paid_amount'] + $payment;
            $status = ($new_paid >= $invoice['total_amount']) ? 'paid' : 'partial';
            
            // بروزرسانی فاکتور فروش
            $db->prepare("UPDATE sales_invoices SET paid_amount = ? WHERE id = ?")
               ->execute([$new_paid, $invoice_id]);
            
            // اگر مشتری دارد و پرداخت کامل نشده، وضعیت در credit_sales هم بروزرسانی شود
            if ($invoice['customer_id']) {
                // بررسی وجود فاکتور نسیه
                $cs = $db->prepare("SELECT id, paid_amount FROM credit_sales WHERE invoice_no = ?");
                $cs->execute([$invoice['invoice_no']]);
                $credit_sale = $cs->fetch();
                if ($credit_sale) {
                    $new_cs_paid = $credit_sale['paid_amount'] + $payment;
                    $cs_status = ($new_cs_paid >= $invoice['total_amount']) ? 'paid' : 'partial';
                    $db->prepare("UPDATE credit_sales SET paid_amount = ?, status = ? WHERE id = ?")
                       ->execute([$new_cs_paid, $cs_status, $credit_sale['id']]);
                }
            }
            
            // ثبت تراکنش حسابداری (درآمد)
            $trans = $db->prepare("INSERT INTO transactions 
                (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                VALUES (?, ?, ?, 'income', 'sale', ?, ?, ?, NULL)");
            $trans->execute([$payment_date, $account_id, $payment, $invoice_id, $_SESSION['user_id'], $description]);
            
            // افزایش موجودی حساب
            $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")
               ->execute([$payment, $account_id]);
            
            $db->commit();
            $success = "✅ مبلغ " . number_format($payment) . " تومان پرداخت شد. مانده بدهی: " . number_format($invoice['total_amount'] - $new_paid) . " تومان";
            echo '<meta http-equiv="refresh" content="2;url=sales_invoices.php">';
        } catch (Exception $e) { 
            $db->rollBack(); 
            $error = "خطا: " . $e->getMessage(); 
        }
    }
}

$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
?>

<div class="card">
    <div class="card-header bg-warning">
        💰 پرداخت قسط فاکتور فروش #<?= htmlspecialchars($invoice['invoice_no']) ?>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <table class="table table-bordered">
                    <tr><th>مشتری</th><td><?= htmlspecialchars($invoice['customer_name'] ?? 'مشتری عمومی') ?></td></tr>
                    <tr><th>مبلغ کل</th><td><?= number_format($invoice['total_amount']) ?> تومان</td></tr>
                    <tr><th>پرداخت شده</th><td><?= number_format($invoice['paid_amount']) ?> تومان</td></tr>
                    <tr><th class="text-danger">مانده بدهی</th><td class="text-danger fw-bold"><?= number_format($remaining) ?> تومان</td></tr>
                </table>
            </div>
        </div>
        
        <form method="post">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label>تاریخ پرداخت</label>
                    <input type="text" name="payment_date" class="form-control" value="<?= now_jalali() ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>مبلغ پرداختی (تومان)</label>
                    <input type="number" name="payment" class="form-control" value="<?= $remaining ?>" min="1" max="<?= $remaining ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label>حساب دریافت وجه</label>
                    <select name="account_id" class="form-select" required>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (موجودی: <?= number_format($a['current_balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-12 mb-3">
                    <label>توضیحات (اختیاری)</label>
                    <input type="text" name="description" class="form-control" placeholder="مثلاً: پرداخت قسط دوم">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">💰 ثبت پرداخت</button>
            <a href="sales_invoices.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>