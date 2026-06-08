<?php
// ==================================================
// بخش 1: پردازش درخواست‌ها (قبل از هر خروجی)
// ==================================================
require_once '../../config/database.php';
require_once '../../includes/jdf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../includes/functions.php';

$error = '';
$success = '';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    header('Location: ../../index.php');
    exit;
}

// --- حذف تراکنش ---
if (isset($_GET['delete_transaction']) && is_numeric($_GET['delete_transaction'])) {
    $transaction_id = (int)$_GET['delete_transaction'];
    
    $stmt = $db->prepare("SELECT account_id, amount, type FROM transactions WHERE id = ?");
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch();
    
    if ($transaction) {
        $db->beginTransaction();
        try {
            if ($transaction['type'] == 'income') {
                $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")
                   ->execute([$transaction['amount'], $transaction['account_id']]);
            } else {
                $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")
                   ->execute([$transaction['amount'], $transaction['account_id']]);
            }
            $db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$transaction_id]);
            $db->commit();
            
            header("Location: balance_sheet.php?account_id=" . $transaction['account_id'] . "&success=deleted");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            header("Location: balance_sheet.php?account_id=" . $transaction['account_id'] . "&error=" . urlencode($e->getMessage()));
            exit;
        }
    } else {
        header("Location: balance_sheet.php?error=" . urlencode('تراکنش یافت نشد'));
        exit;
    }
}

// --- ویرایش تراکنش (ذخیره از فرم ویرایش) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_transaction'])) {
    $transaction_id = (int)$_POST['transaction_id'];
    $new_account_id = (int)$_POST['account_id'];
    $new_amount = (int)$_POST['amount'];
    $new_type = $_POST['type'];
    $new_date = $_POST['transaction_date'];
    $new_description = trim($_POST['description']);
    $new_category_id = ($new_type == 'expense' && !empty($_POST['category_id'])) ? (int)$_POST['category_id'] : null;
    
    $old = $db->prepare("SELECT account_id, amount, type FROM transactions WHERE id = ?");
    $old->execute([$transaction_id]);
    $old_transaction = $old->fetch();
    
    if (!$old_transaction) {
        header("Location: balance_sheet.php?error=" . urlencode('تراکنش یافت نشد'));
        exit;
    }
    
    if ($new_amount <= 0) {
        header("Location: balance_sheet.php?account_id=" . $new_account_id . "&error=" . urlencode('مبلغ باید بزرگتر از صفر باشد'));
        exit;
    }
    
    $db->beginTransaction();
    try {
        // بازگردانی حساب قدیم
        if ($old_transaction['type'] == 'income') {
            $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")
               ->execute([$old_transaction['amount'], $old_transaction['account_id']]);
        } else {
            $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")
               ->execute([$old_transaction['amount'], $old_transaction['account_id']]);
        }
        
        // بروزرسانی تراکنش
        $stmt = $db->prepare("UPDATE transactions SET 
            transaction_date_sh = ?, account_id = ?, amount = ?, type = ?, 
            description = ?, category_id = ? WHERE id = ?");
        $stmt->execute([$new_date, $new_account_id, $new_amount, $new_type, $new_description, $new_category_id, $transaction_id]);
        
        // اعمال روی حساب جدید
        if ($new_type == 'income') {
            $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")
               ->execute([$new_amount, $new_account_id]);
        } else {
            $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")
               ->execute([$new_amount, $new_account_id]);
        }
        
        $db->commit();
        header("Location: balance_sheet.php?account_id=" . $new_account_id . "&success=edited");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: balance_sheet.php?account_id=" . $new_account_id . "&error=" . urlencode($e->getMessage()));
        exit;
    }
}

// ==================================================
// بخش 2: بارگذاری هدر و نمایش صفحه
// ==================================================
$page_title = 'گزارش گردش حساب (همراه با مدیریت تراکنش‌ها)';
require_once '../../includes/header.php';

if (isset($_GET['success'])) {
    if ($_GET['success'] == 'deleted') $success = '✅ تراکنش با موفقیت حذف شد.';
    if ($_GET['success'] == 'edited') $success = '✅ تراکنش با موفقیت ویرایش شد.';
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

// دریافت ID تراکنش برای ویرایش (اگر edit_id وجود داشته باشد)
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_transaction = null;
if ($edit_id > 0) {
    $stmt = $db->prepare("SELECT t.*, c.name as category_name FROM transactions t LEFT JOIN expense_categories c ON t.category_id = c.id WHERE t.id = ?");
    $stmt->execute([$edit_id]);
    $edit_transaction = $stmt->fetch();
}

// دریافت لیست تراکنش‌ها
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$transactions = [];
$account = null;

if ($account_id) {
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch();
    
    if ($account) {
        $sql = "SELECT t.*, c.name as category_name,
                CASE 
                    WHEN t.ref_type = 'sale' THEN 'فروش کالا'
                    WHEN t.ref_type = 'purchase' THEN 'خرید کالا'
                    WHEN t.ref_type = 'repair' THEN 'تعمیر'
                    WHEN t.ref_type = 'repair_refund' THEN 'برگشت تعمیر'
                    WHEN t.ref_type = 'loan' THEN 'وام'
                    WHEN t.ref_type = 'loan_installment' THEN 'قسط وام'
                    WHEN t.ref_type = 'credit_sale' THEN 'وصول نسیه'
                    WHEN t.ref_type = 'other' THEN 'سند دستی'
                    ELSE t.ref_type
                END as ref_type_persian
                FROM transactions t
                LEFT JOIN expense_categories c ON t.category_id = c.id
                WHERE t.account_id = ?";
        $params = [$account_id];
        
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
        
        $sql .= " ORDER BY t.transaction_date_sh DESC, t.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll();
    }
}

$accounts_list = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
$categories = $db->query("SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY name")->fetchAll();

$total_income = 0;
$total_expense = 0;
foreach ($transactions as $t) {
    if ($t['type'] == 'income') $total_income += $t['amount'];
    else $total_expense += $t['amount'];
}
?>

<style>
    .balance-table th, .balance-table td {
        vertical-align: middle;
        text-align: center;
    }
    .balance-table .text-start {
        text-align: left !important;
    }
    .table-actions {
        display: flex;
        gap: 5px;
        justify-content: center;
    }
    .filter-box {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 20px;
    }
    .edit-form-box {
        background: #fff3cd;
        border: 1px solid #ffecb5;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
</style>

<div class="card">
    <div class="card-header bg-primary text-white">
        <i class="fas fa-chart-line"></i> 📊 گزارش گردش حساب با قابلیت مدیریت تراکنش‌ها
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>
        
        <!-- فرم ویرایش (در صورت وجود edit_id) -->
        <?php if ($edit_transaction): ?>
        <div class="edit-form-box">
            <h5 class="mb-3">✏️ ویرایش تراکنش #<?= $edit_transaction['id'] ?></h5>
            <form method="post">
                <input type="hidden" name="update_transaction" value="1">
                <input type="hidden" name="transaction_id" value="<?= $edit_transaction['id'] ?>">
                
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">تاریخ</label>
                        <input type="text" name="transaction_date" class="form-control" value="<?= htmlspecialchars($edit_transaction['transaction_date_sh']) ?>" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">حساب</label>
                        <select name="account_id" class="form-select" required>
                            <?php foreach ($accounts_list as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $edit_transaction['account_id'] == $a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">نوع</label>
                        <select name="type" id="edit_type_form" class="form-select" required>
                            <option value="income" <?= $edit_transaction['type'] == 'income' ? 'selected' : '' ?>>درآمد</option>
                            <option value="expense" <?= $edit_transaction['type'] == 'expense' ? 'selected' : '' ?>>هزینه</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3" id="edit_category_form_div" style="display: <?= $edit_transaction['type'] == 'expense' ? 'block' : 'none' ?>;">
                        <label class="form-label">دسته هزینه</label>
                        <select name="category_id" class="form-select">
                            <option value="">انتخاب کنید</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $edit_transaction['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">مبلغ</label>
                        <input type="number" name="amount" class="form-control" value="<?= $edit_transaction['amount'] ?>" required>
                    </div>
                    <div class="col-md-9 mb-3">
                        <label class="form-label">شرح</label>
                        <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($edit_transaction['description']) ?>" required>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">💾 ذخیره تغییرات</button>
                    <a href="?account_id=<?= $account_id ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" class="btn btn-secondary">❌ انصراف</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- فرم انتخاب حساب -->
        <form method="get" class="filter-box">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">🏦 حساب</label>
                    <select name="account_id" class="form-select" required>
                        <option value="">-- انتخاب حساب --</option>
                        <?php foreach ($accounts_list as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= $account_id == $a['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['account_name']) ?> (موجودی: <?= number_format($a['current_balance']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">📅 از تاریخ</label>
                    <input type="text" name="from_date" class="form-control" placeholder="1402/01/01" value="<?= htmlspecialchars($from_date) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">📅 تا تاریخ</label>
                    <input type="text" name="to_date" class="form-control" placeholder="1402/12/29" value="<?= htmlspecialchars($to_date) ?>">
                </div>
                <div class="col-md-2 align-self-end">
                    <button type="submit" class="btn btn-primary w-100">🔍 نمایش</button>
                    <a href="balance_sheet.php" class="btn btn-secondary w-100 mt-2">🗑️ پاک کردن</a>
                </div>
            </div>
        </form>
        
        <?php if ($account): ?>
            <div class="alert alert-info mb-4">
                <div class="row">
                    <div class="col-md-4"><strong>🏦 حساب:</strong> <?= htmlspecialchars($account['account_name']) ?></div>
                    <div class="col-md-4"><strong>💰 موجودی اولیه:</strong> <?= number_format($account['initial_balance']) ?> تومان</div>
                    <div class="col-md-4"><strong>💵 موجودی فعلی:</strong> <?= number_format($account['current_balance']) ?> تومان</div>
                </div>
            </div>
            
            <?php if (count($transactions) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover balance-table">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th><th>📅 تاریخ</th><th>💰 مبلغ</th>
                                <th>📊 دسته هزینه</th><th>📝 شرح</th><th>🔗 مرجع</th><th>⚙️ عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $counter = 1; ?>
                            <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td><?= $counter++ ?></td>
                                <td><?= htmlspecialchars($t['transaction_date_sh']) ?></td>
                                <td class="<?= $t['type'] == 'income' ? 'text-success fw-bold' : 'text-danger fw-bold' ?>">
                                    <?= $t['type'] == 'income' ? '+' : '-' ?> <?= number_format($t['amount']) ?> تومان
                                </td>
                                <td><?= htmlspecialchars($t['category_name'] ?? '---') ?></td>
                                <td class="text-start"><?= htmlspecialchars($t['description']) ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($t['ref_type_persian'] ?? $t['ref_type']) ?></span>
                                    <small class="text-muted">(ID: <?= $t['ref_id'] ?>)</small>
                                 </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?edit_id=<?= $t['id'] ?>&account_id=<?= $account_id ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" class="btn btn-sm btn-warning">✏️ ویرایش</a>
                                        <a href="?delete_transaction=<?= $t['id'] ?>&account_id=<?= $account_id ?>&from_date=<?= urlencode($from_date) ?>&to_date=<?= urlencode($to_date) ?>" 
                                           class="btn btn-sm btn-danger" onclick="return confirm('حذف شود؟')">🗑️ حذف</a>
                                    </div>
                                 </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-secondary">
                            <tr><th colspan="2">جمع کل درآمد</th><th class="text-success">+ <?= number_format($total_income) ?> تومان</th><th colspan="4"></th></tr>
                            <tr><th colspan="2">جمع کل هزینه</th><th class="text-danger">- <?= number_format($total_expense) ?> تومان</th><th colspan="4"></th></tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning text-center">هیچ تراکنشی یافت نشد.</div>
            <?php endif; ?>
        <?php elseif ($account_id): ?>
            <div class="alert alert-danger">حساب مورد نظر یافت نشد.</div>
        <?php else: ?>
            <div class="alert alert-info text-center">لطفاً یک حساب را انتخاب کنید.</div>
        <?php endif; ?>
    </div>
</div>

<script>
// کنترل نمایش فیلد دسته هزینه در فرم ویرایش
document.addEventListener('DOMContentLoaded', function() {
    var typeSelect = document.getElementById('edit_type_form');
    if (typeSelect) {
        typeSelect.addEventListener('change', function() {
            var categoryDiv = document.getElementById('edit_category_form_div');
            if (this.value === 'expense') {
                categoryDiv.style.display = 'block';
            } else {
                categoryDiv.style.display = 'none';
            }
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>