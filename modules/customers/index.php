<?php
$page_title = 'مدیریت مشتریان، تأمین‌کنندگان و همکاران';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'customers_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$type_filter = $_GET['type'] ?? 'all';
$search_query = $_GET['query'] ?? '';

// کوئری اصلی با فیلترها
$sql = "SELECT * FROM customers WHERE 1=1";
$params = [];

if ($type_filter != 'all') {
    $sql .= " AND type = ?";
    $params[] = $type_filter;
}
if (!empty($search_query)) {
    $sql .= " AND (fullname LIKE ? OR mobile LIKE ? OR email LIKE ?)";
    $like = "%$search_query%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= " ORDER BY type, fullname";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// آمار کارت‌ها
$total_customers = $db->query("SELECT COUNT(*) FROM customers WHERE type='customer'")->fetchColumn();
$total_suppliers = $db->query("SELECT COUNT(*) FROM customers WHERE type='supplier'")->fetchColumn();
$total_partners = $db->query("SELECT COUNT(*) FROM customers WHERE type='partner'")->fetchColumn();
$total_all = $total_customers + $total_suppliers + $total_partners;
?>

<style>
    /* استایل اختصاصی این صفحه – بدون تداخل با هدر */
    .filter-tabs .nav-link {
        border-radius: 30px;
        margin: 0 4px;
        padding: 6px 18px;
        transition: all 0.2s;
    }
    .filter-tabs .nav-link.active {
        background: #0ea5e9;
        color: white;
    }
    .search-box {
        min-width: 250px;
        position: relative;
    }
    .search-box i {
        position: absolute;
        right: 12px;
        top: 12px;
        color: #94a3b8;
        z-index: 2;
    }
    .search-box input {
        border-radius: 30px;
        padding-right: 35px;
    }
    .table th, .table td {
        vertical-align: middle;
        white-space: nowrap;
    }
    @media (max-width: 768px) {
        .table td, .table th {
            white-space: normal;
        }
        .search-box {
            width: 100%;
            margin-top: 10px;
        }
        .card-header-custom {
            flex-direction: column;
            align-items: stretch !important;
        }
    }
</style>

<!-- کارت‌های آماری -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="modern-card text-center p-3">
            <i class="fas fa-users fa-2x text-primary"></i>
            <h5>کل اشخاص</h5>
            <h3><?= number_format($total_all) ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="modern-card text-center p-3">
            <i class="fas fa-user-friends fa-2x text-success"></i>
            <h5>مشتریان</h5>
            <h3><?= number_format($total_customers) ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="modern-card text-center p-3">
            <i class="fas fa-truck fa-2x text-warning"></i>
            <h5>تأمین‌کنندگان</h5>
            <h3><?= number_format($total_suppliers) ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="modern-card text-center p-3">
            <i class="fas fa-handshake fa-2x text-info"></i>
            <h5>همکاران</h5>
            <h3><?= number_format($total_partners) ?></h3>
        </div>
    </div>
</div>

<!-- جدول اصلی -->
<div class="modern-card">
    <div class="card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <a href="add.php" class="btn btn-modern"><i class="fas fa-plus"></i> افزودن شخص</a>
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="liveSearch" class="form-control" placeholder="جستجوی نام، موبایل، ایمیل..." value="<?= htmlspecialchars($search_query) ?>">
        </div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs filter-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?= $type_filter=='all'?'active':'' ?>" href="?type=all&query=<?= urlencode($search_query) ?>">همه</a></li>
            <li class="nav-item"><a class="nav-link <?= $type_filter=='customer'?'active':'' ?>" href="?type=customer&query=<?= urlencode($search_query) ?>">مشتریان</a></li>
            <li class="nav-item"><a class="nav-link <?= $type_filter=='supplier'?'active':'' ?>" href="?type=supplier&query=<?= urlencode($search_query) ?>">تأمین‌کنندگان</a></li>
            <li class="nav-item"><a class="nav-link <?= $type_filter=='partner'?'active':'' ?>" href="?type=partner&query=<?= urlencode($search_query) ?>">همکاران</a></li>
        </ul>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>نوع</th><th>نام کامل</th><th>موبایل</th><th>تلفن</th><th>ایمیل</th><th>آدرس</th><th>فعال</th><th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($customers) == 0): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">هیچ شخصی یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): 
                            $type_label = match($c['type']) {
                                'customer' => 'مشتری',
                                'supplier' => 'تأمین‌کننده',
                                'partner' => 'همکار',
                                default => $c['type']
                            };
                            $active_icon = $c['is_active'] ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-danger"></i>';
                        ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= $type_label ?></span></td>
                                <td><?= htmlspecialchars($c['fullname']) ?></td>
                                <td dir="ltr"><?= htmlspecialchars($c['mobile']) ?></td>
                                <td dir="ltr"><?= htmlspecialchars($c['phone']) ?></td>
                                <td><?= htmlspecialchars($c['email']) ?></td>
                                <td class="text-truncate" style="max-width:180px;" title="<?= htmlspecialchars($c['address']) ?>"><?= htmlspecialchars($c['address']) ?></td>
                                <td><?= $active_icon ?></td>
                                <td>
                                    <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fas fa-edit"></i></a>
                                    <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف شود؟')"><i class="fas fa-trash-alt"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// جستجوی زنده با رفرش (با تاخیر 400 میلی‌ثانیه)
$(document).ready(function(){
    let timer;
    $('#liveSearch').on('keyup', function(){
        clearTimeout(timer);
        timer = setTimeout(() => {
            let query = $(this).val();
            let currentType = '<?= $type_filter ?>';
            window.location.href = '?type=' + currentType + '&query=' + encodeURIComponent(query);
        }, 400);
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>