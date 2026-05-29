<?php
$page_title = 'صورت سود و زیان';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'reports_view')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
if (!$from_date) $from_date = now_jalali();
if (!$to_date) $to_date = now_jalali();

// جمع درآمدها (income)
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND transaction_date_sh BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$total_income = $stmt->fetchColumn();

// جمع هزینه‌ها (expense)
$stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND transaction_date_sh BETWEEN ? AND ?");
$stmt->execute([$from_date, $to_date]);
$total_expense = $stmt->fetchColumn();

$profit = $total_income - $total_expense;
?>
<div class="card">
    <div class="card-header">💰 صورت سود و زیان</div>
    <div class="card-body">
        <form method="get" class="row g-3 mb-4">
            <div class="col-md-3"><label>از تاریخ</label><input type="text" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>"></div>
            <div class="col-md-3"><label>تا تاریخ</label><input type="text" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>"></div>
            <div class="col-md-2 align-self-end"><button type="submit" class="btn btn-primary">محاسبه</button></div>
        </form>
        <div class="row">
            <div class="col-md-6">
                <div class="card text-white bg-success mb-3">
                    <div class="card-body"><h5>جمع درآمد (فروش + سایر)</h5><h2><?= number_format($total_income) ?> تومان</h2></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card text-white bg-danger mb-3">
                    <div class="card-body"><h5>جمع هزینه (خرید + سایر)</h5><h2><?= number_format($total_expense) ?> تومان</h2></div>
                </div>
            </div>
            <div class="col-md-12">
                <div class="card text-white bg-primary mb-3">
                    <div class="card-body"><h5>سود / زیان خالص</h5><h2><?= number_format($profit) ?> تومان</h2></div>
                </div>
            </div>
        </div>
        <hr>
        <h6>توضیح:</h6>
        <ul>
            <li>درآمد: مجموع تراکنش‌های نوع income (فروش کالا، دریافت وجه تعمیر، واریز دستی)</li>
            <li>هزینه: مجموع تراکنش‌های نوع expense (خرید کالا، پرداخت هزینه تعمیر، برداشت دستی)</li>
        </ul>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>