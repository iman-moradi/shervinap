<?php
$page_title = 'تنظیم بودجه ماهانه دسته‌بندی هزینه‌ها';
require_once '../../includes/header.php';
if (!has_permission($_SESSION['user_id'], 'accounting_access')) exit('دسترسی ندارید');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['budgets'])) {
    foreach ($_POST['budgets'] as $id => $amount) {
        $db->prepare("UPDATE expense_categories SET monthly_budget = ? WHERE id = ?")->execute([(int)$amount, (int)$id]);
    }
    echo '<div class="alert alert-success">بودجه‌ها ذخیره شد.</div>';
}
$categories = $db->query("SELECT id, name, monthly_budget FROM expense_categories ORDER BY name")->fetchAll();
?>
<div class="card">
    <div class="card-header">💰 تنظیم بودجه ماهانه (تومان)</div>
    <div class="card-body">
        <form method="post">
            <table class="table table-bordered">
                <thead><tr><th>دسته</th><th>بودجه ماهانه</th></tr></thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><?= htmlspecialchars($cat['name']) ?></td>
                        <td><input type="number" name="budgets[<?= $cat['id'] ?>]" class="form-control" value="<?= $cat['monthly_budget'] ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary">ذخیره بودجه‌ها</button>
            <a href="smart_advice.php" class="btn btn-secondary">بازگشت به هوش مالی</a>
        </form>
    </div>
</div>
<?php require_once '../../includes/footer.php'; ?>