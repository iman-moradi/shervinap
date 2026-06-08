<?php
ob_start();
$page_title = 'ثبت هزینه/درآمد دسته‌بندی شده';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$error = '';
$success = '';
$show_preview_modal = false;
$preview_data = [];

// --- بخش 1: ذخیره نهایی پس از تایید پیش‌نمایش ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_transaction'])) {
    $account_id = (int)$_POST['account_id'];
    $amount = (int)$_POST['amount'];
    $type = $_POST['type'];
    $transaction_date = $_POST['transaction_date'];
    $description = trim($_POST['description']);
    $category_id = ($type == 'expense' && !empty($_POST['category_id'])) ? (int)$_POST['category_id'] : null;

    // اعتبارسنجی مجدد برای امنیت
    if ($amount <= 0) {
        $error = 'مبلغ باید بزرگتر از صفر باشد.';
    } else if ($type == 'expense' && !$category_id) {
        $error = 'لطفاً دسته هزینه را انتخاب کنید.';
    } else {
        $db->beginTransaction();
        try {
            // ثبت تراکنش در دیتابیس
            $stmt = $db->prepare("INSERT INTO transactions 
                (transaction_date_sh, account_id, amount, type, ref_type, ref_id, created_by, description, category_id) 
                VALUES (?, ?, ?, ?, 'other', 0, ?, ?, ?)");
            $stmt->execute([$transaction_date, $account_id, $amount, $type, $_SESSION['user_id'], $description, $category_id]);

            // بروزرسانی موجودی حساب
            if ($type == 'income') {
                $db->prepare("UPDATE accounts SET current_balance = current_balance + ? WHERE id = ?")->execute([$amount, $account_id]);
            } else {
                $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $account_id]);
            }
            $db->commit();
            
            // پاک کردن بافر و ریدایرکت به لیست تراکنش‌ها با پیام موفقیت
            ob_end_clean();
            header('Location: accounts.php?msg=transaction_added');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'خطا در ثبت نهایی: ' . $e->getMessage();
        }
    }
}

// --- بخش 2: پردازش درخواست پیش‌نمایش ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_transaction'])) {
    $preview_data['account_id'] = (int)$_POST['account_id'];
    $preview_data['amount'] = (int)$_POST['amount'];
    $preview_data['type'] = $_POST['type'];
    $preview_data['transaction_date'] = $_POST['transaction_date'];
    $preview_data['description'] = trim($_POST['description']);
    $preview_data['category_id'] = ($preview_data['type'] == 'expense' && !empty($_POST['category_id'])) ? (int)$_POST['category_id'] : null;

    // اعتبارسنجی اولیه
    if ($preview_data['amount'] <= 0) {
        $error = 'مبلغ باید بزرگتر از صفر باشد.';
    } else if ($preview_data['type'] == 'expense' && !$preview_data['category_id']) {
        $error = 'لطفاً دسته هزینه را انتخاب کنید.';
    } else {
        // دریافت نام حساب و نام دسته هزینه برای نمایش در پیش‌نمایش
        $stmt_acc = $db->prepare("SELECT account_name FROM accounts WHERE id = ?");
        $stmt_acc->execute([$preview_data['account_id']]);
        $preview_data['account_name'] = $stmt_acc->fetchColumn();

        if ($preview_data['category_id']) {
            $stmt_cat = $db->prepare("SELECT name FROM expense_categories WHERE id = ?");
            $stmt_cat->execute([$preview_data['category_id']]);
            $preview_data['category_name'] = $stmt_cat->fetchColumn();
        } else {
            $preview_data['category_name'] = '---';
        }
        $show_preview_modal = true;
    }
}

// دریافت لیست حساب‌ها و دسته‌بندی هزینه‌ها
$accounts = $db->query("SELECT id, account_name, current_balance FROM accounts ORDER BY account_name")->fetchAll();
$categories = $db->query("SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="card">
    <div class="card-header">💰 ثبت سند حسابداری (با پیش‌نمایش و تایید نهایی)</div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- فرم اصلی برای ورود اطلاعات -->
        <form method="post" id="mainForm">
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
            <button type="submit" name="preview_transaction" class="btn btn-primary">👁️ پیش‌نمایش و تایید</button>
            <a href="accounts.php" class="btn btn-secondary">🔙 بازگشت</a>
        </form>
    </div>
</div>

<!-- مودال پیش‌نمایش -->
<?php if ($show_preview_modal): ?>
<div class="modal fade show" id="previewModal" tabindex="-1" style="display:block; background-color: rgba(0,0,0,0.5);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">📋 تایید نهایی اطلاعات سند</h5>
                <button type="button" class="btn-close btn-close-white" onclick="window.location.href=window.location.pathname"></button>
            </div>
            <div class="modal-body">
                <p>لطفاً اطلاعات زیر را به دقت بررسی کنید:</p>
                <table class="table table-bordered">
                    <tr><th style="width:40%">تاریخ سند</th><td><?= htmlspecialchars($preview_data['transaction_date']) ?></td></tr>
                    <tr><th>حساب</th><td><?= htmlspecialchars($preview_data['account_name']) ?></td></tr>
                    <tr><th>نوع سند</th><td><?= $preview_data['type'] == 'income' ? '💰 درآمد (واریز)' : '💸 هزینه (برداشت)' ?></td></tr>
                    <?php if ($preview_data['type'] == 'expense'): ?>
                    <tr><th>دسته هزینه</th><td><?= htmlspecialchars($preview_data['category_name']) ?></td></tr>
                    <?php endif; ?>
                    <tr><th>مبلغ (تومان)</th><td class="fw-bold"><?= number_format($preview_data['amount']) ?></td></tr>
                    <tr><th>شرح</th><td><?= nl2br(htmlspecialchars($preview_data['description'])) ?></td></tr>
                </table>
                <div class="alert alert-warning text-center">
                    <strong>⚠️ توجه:</strong> با تایید این سند، عملیات مالی در سیستم ثبت شده و قابل بازگشت نخواهد بود.
                </div>
            </div>
            <div class="modal-footer">
                <form method="post">
                    <!-- انتقال تمام داده‌ها برای ثبت نهایی -->
                    <input type="hidden" name="account_id" value="<?= $preview_data['account_id'] ?>">
                    <input type="hidden" name="amount" value="<?= $preview_data['amount'] ?>">
                    <input type="hidden" name="type" value="<?= $preview_data['type'] ?>">
                    <input type="hidden" name="transaction_date" value="<?= htmlspecialchars($preview_data['transaction_date']) ?>">
                    <input type="hidden" name="description" value="<?= htmlspecialchars($preview_data['description']) ?>">
                    <?php if ($preview_data['category_id']): ?>
                    <input type="hidden" name="category_id" value="<?= $preview_data['category_id'] ?>">
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href=window.location.pathname">✏️ بازگشت و ویرایش</button>
                    <button type="submit" name="confirm_transaction" class="btn btn-success">✅ تایید و ثبت نهایی</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    // کنترل نمایش فیلد دسته‌بندی هزینه
    const typeSelect = document.getElementById('typeSelect');
    const categoryDiv = document.getElementById('categoryDiv');
    function toggleCategory() {
        if (typeSelect) {
            categoryDiv.style.display = typeSelect.value === 'expense' ? 'block' : 'none';
        }
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', toggleCategory);
        toggleCategory();
    }

    // برای بستن مودال با کلید ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === "Escape") {
            window.location.href = window.location.pathname;
        }
    });
</script>

<?php require_once '../../includes/footer.php'; ?>