<?php
$page_title = 'ثبت هزینه/درآمد دسته‌بندی شده';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';

// دریافت لیست حساب‌ها و دسته‌بندی هزینه‌ها
$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
$categories = $db->query("SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $account_id = (int)$_POST['account_id'];
    $amount = (int)$_POST['amount'];
    $type = $_POST['type']; // income یا expense
    $transaction_date = $_POST['transaction_date'];
    $description = trim($_POST['description']);
    $category_id = ($type == 'expense' && !empty($_POST['category_id'])) ? (int)$_POST['category_id'] : null;

    if ($amount <= 0) {
        $error = 'مبلغ باید بزرگتر از صفر باشد.';
    } else if ($type == 'expense' && !$category_id) {
        $error = 'لطفاً دسته هزینه را انتخاب کنید.';
    } else {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO transactions 
                (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                VALUES (?,?,?,?, 'other', 0, ?, ?, ?)");
            $stmt->execute([$transaction_date, $account_id, $amount, $type, $_SESSION['user_id'], $description, $category_id]);

            // به‌روزرسانی موجودی حساب
            if ($type == 'income') {
                $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $account_id]);
            } else {
                $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $account_id]);
            }
            $db->commit();
            $success = '✅ سند با موفقیت ثبت شد.';
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'خطا: ' . $e->getMessage();
        }
    }
}
?>
<div class="card">
    <div class="card-header">💰 ثبت سند حسابداری (با دسته‌بندی هزینه)</div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        <form method="post">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label>تاریخ سند</label>
                    <input type="text" name="transaction_date" class="form-control" value="<?= now_jalali() ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label>حساب</label>
                    <select name="account_id" class="form-select" required>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?> (<?= number_format($acc['current_balance']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label>نوع سند</label>
                    <select name="type" id="typeSelect" class="form-select" required>
                        <option value="income">💰 درآمد (واریز)</option>
                        <option value="expense">💸 هزینه (برداشت)</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3" id="categoryDiv" style="display:none;">
                    <label>دسته هزینه</label>
                    <select name="category_id" class="form-select">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label>مبلغ (تومان)</label>
                    <input type="number" name="amount" class="form-control" required>
                </div>
                <div class="col-md-8 mb-3">
                    <label>شرح کامل</label>
                    <input type="text" name="description" class="form-control" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">💾 ثبت سند</button>
            <a href="accounts.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>
<script>
    const typeSelect = document.getElementById('typeSelect');
    const categoryDiv = document.getElementById('categoryDiv');
    function toggleCategory() {
        categoryDiv.style.display = typeSelect.value === 'expense' ? 'block' : 'none';
    }
    typeSelect.addEventListener('change', toggleCategory);
    toggleCategory();
</script>
<?php require_once '../../includes/footer.php'; ?>