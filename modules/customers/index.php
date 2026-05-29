<?php
$page_title = 'مدیریت مشتریان، تأمین‌کنندگان و همکاران';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'customers_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// دریافت مجوزهای جزئی
$can_view_supplier = has_permission($_SESSION['user_id'], 'view_supplier_info');

// پارامترهای فیلتر
$type_filter = $_GET['type'] ?? 'all';
$query = trim($_GET['query'] ?? '');

// اگر کاربر مجوز دیدن تأمین‌کنندگان را ندارد و فیلتر روی supplier است، آن را به all تغییر دهید
if (!$can_view_supplier && $type_filter == 'supplier') {
    $type_filter = 'all';
}

// ساخت کوئری
$sql = "SELECT * FROM customers WHERE 1=1";
$params = [];

// اعمال محدودیت نوع بر اساس فیلتر و مجوز
if ($type_filter != 'all') {
    $sql .= " AND type = ?";
    $params[] = $type_filter;
}
// اگر کاربر مجوز دیدن تأمین‌کنندگان را ندارد، آنها را حذف کن (حتی در حالت all)
if (!$can_view_supplier) {
    $sql .= " AND type != 'supplier'";
}

// جستجو
if (!empty($query)) {
    $sql .= " AND (fullname LIKE ? OR mobile LIKE ? OR email LIKE ?)";
    $like = "%$query%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= " ORDER BY type, fullname";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();
?>
<div class="card">
    <div class="card-header">
        <a href="add.php" class="btn btn-primary">➕ افزودن جدید</a>
        <div class="float-start w-25">
            <input type="text" id="liveSearch" class="form-control" placeholder="جستجوی نام، موبایل یا ایمیل..." autocomplete="off">
        </div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?= $type_filter=='all' ? 'active' : '' ?>" href="?type=all">همه</a></li>
            <li class="nav-item"><a class="nav-link <?= $type_filter=='customer' ? 'active' : '' ?>" href="?type=customer">مشتریان</a></li>
            <?php if ($can_view_supplier): ?>
                <li class="nav-item"><a class="nav-link <?= $type_filter=='supplier' ? 'active' : '' ?>" href="?type=supplier">تأمین‌کنندگان</a></li>
            <?php endif; ?>
            <li class="nav-item"><a class="nav-link <?= $type_filter=='partner' ? 'active' : '' ?>" href="?type=partner">همکاران</a></li>
        </ul>
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr><th>نوع</th><th>نام کامل</th><th>موبایل</th><th>تلفن</th><th>ایمیل</th><th>آدرس</th><th>فعال</th><th>عملیات</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><?= $c['type'] == 'customer' ? 'مشتری' : ($c['type'] == 'supplier' ? 'تأمین‌کننده' : 'همکار') ?></td>
                        <td><?= htmlspecialchars($c['fullname']) ?></td>
                        <td><?= htmlspecialchars($c['mobile']) ?></td>
                        <td><?= htmlspecialchars($c['phone']) ?></td>
                        <td><?= htmlspecialchars($c['email']) ?></td>
                        <td><?= htmlspecialchars($c['address']) ?></td>
                        <td><?= $c['is_active'] ? '✅' : '❌' ?></td>
                        <td>
                            <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-warning">✏️</a>
                            <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('حذف شود؟')">🗑️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#liveSearch').on('keyup', function(){
        var val = $(this).val().trim();
        window.location.href = 'index.php?type=<?= $type_filter ?>&query=' + encodeURIComponent(val);
    });
});
</script>
<?php require_once '../../includes/footer.php'; ?>