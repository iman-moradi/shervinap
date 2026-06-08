<?php
$page_title = 'گزارش کامل تمام تراکنش‌های مالی';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// دریافت پارامترهای فیلتر
$filter_type = $_GET['filter_type'] ?? 'all';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

// ساخت کوئری با فیلتر
$sql = "SELECT t.*, a.account_name, c.name as category_name,
        CASE 
            WHEN t.ref_type = 'sale' THEN 'فروش کالا'
            WHEN t.ref_type = 'purchase' THEN 'خرید کالا'
            WHEN t.ref_type = 'repair' THEN 'تعمیر (دریافت وجه)'
            WHEN t.ref_type = 'repair_refund' THEN 'برگشت وجه تعمیر'
            WHEN t.ref_type = 'loan' THEN 'دریافت وام'
            WHEN t.ref_type = 'loan_installment' THEN 'پرداخت قسط وام'
            WHEN t.ref_type = 'credit_sale' THEN 'وصول نسیه'
            WHEN t.ref_type = 'other' THEN 'سند دستی'
            ELSE t.ref_type
        END as ref_type_persian
        FROM transactions t
        LEFT JOIN accounts a ON t.account_id = a.id
        LEFT JOIN expense_categories c ON t.category_id = c.id
        WHERE 1=1";

$params = [];

if ($filter_type != 'all') {
    $sql .= " AND t.type = ?";
    $params[] = $filter_type;
}

if ($from_date && $to_date) {
    $sql .= " AND t.transaction_date_sh BETWEEN ? AND ?";
    $params[] = $from_date;
    $params[] = $to_date;
} elseif ($from_date) {
    $sql .= " AND t.transaction_date_sh >= ?";
    $params[] = $from_date;
} elseif ($to_date) {
    $sql .= " AND t.transaction_date_sh <= ?";
    $params[] = $to_date;
}

$sql .= " ORDER BY t.transaction_date_sh DESC, t.id DESC LIMIT 1000";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// محاسبه جمع‌ها
$total_income = 0;
$total_expense = 0;
foreach ($transactions as $t) {
    if ($t['type'] == 'income') $total_income += $t['amount'];
    else $total_expense += $t['amount'];
}
$net_profit = $total_income - $total_expense;
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        📊 گزارش کامل تمام تراکنش‌های مالی
    </div>
    <div class="card-body">
        <!-- فرم فیلتر -->
        <form method="get" class="row g-3 mb-4">
            <div class="col-md-3">
                <label>نوع تراکنش</label>
                <select name="filter_type" class="form-select">
                    <option value="all" <?= $filter_type == 'all' ? 'selected' : '' ?>>همه</option>
                    <option value="income" <?= $filter_type == 'income' ? 'selected' : '' ?>>درآمدها</option>
                    <option value="expense" <?= $filter_type == 'expense' ? 'selected' : '' ?>>هزینه‌ها</option>
                </select>
            </div>
            <div class="col-md-3">
                <label>از تاریخ</label>
                <input type="text" name="from_date" class="form-control" placeholder="1402/01/01" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-md-3">
                <label>تا تاریخ</label>
                <input type="text" name="to_date" class="form-control" placeholder="1402/12/29" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-md-3 align-self-end">
                <button type="submit" class="btn btn-primary">فیلتر</button>
                <a href="all_transactions.php" class="btn btn-secondary">پاک کردن</a>
            </div>
        </form>
        
        <!-- کارت‌های خلاصه -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="alert alert-success text-center">
                    <h5>💰 کل درآمدها</h5>
                    <h3><?= number_format($total_income) ?> تومان</h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="alert alert-danger text-center">
                    <h5>💸 کل هزینه‌ها</h5>
                    <h3><?= number_format($total_expense) ?> تومان</h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="alert <?= $net_profit >= 0 ? 'alert-info' : 'alert-warning' ?> text-center">
                    <h5>📈 سود خالص</h5>
                    <h3><?= number_format($net_profit) ?> تومان</h3>
                </div>
            </div>
        </div>
        
        <!-- جدول تراکنش‌ها -->
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover" id="transactionsTable">
                <thead class="table-dark">
                    <tr>
                        <th>ردیف</th>
                        <th>تاریخ</th>
                        <th>حساب</th>
                        <th>نوع</th>
                        <th>مبلغ</th>
                        <th>دسته هزینه</th>
                        <th>شرح</th>
                        <th>مرجع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; ?>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><?= $counter++ ?></td>
                            <td><?= htmlspecialchars($t['transaction_date_sh']) ?></td>
                            <td><?= htmlspecialchars($t['account_name'] ?? '---') ?></td>
                            <td><?= $t['type'] == 'income' ? '<span class="badge bg-success">واریز</span>' : '<span class="badge bg-danger">برداشت</span>' ?></td>
                            <td><?= number_format($t['amount']) ?> تومان</td
                            <td><?= htmlspecialchars($t['category_name'] ?? '---') ?></td
                            <td><?= htmlspecialchars($t['description']) ?></td
                            <td><?= htmlspecialchars($t['ref_type_persian']) ?> (ID: <?= $t['ref_id'] ?>)</td
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($transactions) == 0): ?>
                        <tr><td colspan="8" class="text-center">هیچ تراکنشی یافت نشد</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="alert alert-secondary mt-3">
            <i class="fas fa-info-circle"></i> توجه: این گزارش شامل تمام تراکنش‌های مالی از همه بخش‌ها (فروش، خرید، تعمیرات، وام‌ها و ...) می‌باشد.
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#transactionsTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/fa.json"
        },
        "order": [[1, "desc"]],
        "pageLength": 25,
        "responsive": true
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>