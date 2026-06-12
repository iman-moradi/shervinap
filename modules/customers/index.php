<?php
$page_title = 'مدیریت مشتریان، تأمین‌کنندگان و همکاران';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'customers_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// پارامترهای اولیه (برای نمایش اولیه)
$type_filter = $_GET['type'] ?? 'all';
$search_query = $_GET['query'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$allowed_per_page = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page)) $per_page = 20;

// آمار کارت‌ها
$total_customers = $db->query("SELECT COUNT(*) FROM customers WHERE type='customer'")->fetchColumn();
$total_suppliers = $db->query("SELECT COUNT(*) FROM customers WHERE type='supplier'")->fetchColumn();
$total_partners = $db->query("SELECT COUNT(*) FROM customers WHERE type='partner'")->fetchColumn();
$total_all = $total_customers + $total_suppliers + $total_partners;
?>

<style>
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
.per-page-selector {
    width: auto;
    display: inline-block;
    margin-left: 10px;
}
@media (max-width: 768px) {
    .table td, .table th { white-space: normal; }
    .search-box { width: 100%; margin-top: 10px; }
    .card-header-custom { flex-direction: column; align-items: stretch !important; }
}
</style>

<div class="row g-4 mb-4">
    <div class="col-md-3"><div class="modern-card text-center p-3"><i class="fas fa-users fa-2x text-primary"></i><h5>کل اشخاص</h5><h3><?= number_format($total_all) ?></h3></div></div>
    <div class="col-md-3"><div class="modern-card text-center p-3"><i class="fas fa-user-friends fa-2x text-success"></i><h5>مشتریان</h5><h3><?= number_format($total_customers) ?></h3></div></div>
    <div class="col-md-3"><div class="modern-card text-center p-3"><i class="fas fa-truck fa-2x text-warning"></i><h5>تأمین‌کنندگان</h5><h3><?= number_format($total_suppliers) ?></h3></div></div>
    <div class="col-md-3"><div class="modern-card text-center p-3"><i class="fas fa-handshake fa-2x text-info"></i><h5>همکاران</h5><h3><?= number_format($total_partners) ?></h3></div></div>
</div>

<div class="modern-card">
    <div class="card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="add.php" class="btn btn-modern"><i class="fas fa-plus"></i> افزودن شخص</a>
            <div class="per-page-selector">
                <label class="text-nowrap">نمایش به ازای هر صفحه:</label>
                <select id="perPageSelect" class="form-select form-select-sm d-inline-block w-auto ms-1">
                    <?php foreach ($allowed_per_page as $val): ?>
                        <option value="<?= $val ?>" <?= $per_page == $val ? 'selected' : '' ?>><?= $val ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="liveSearch" class="form-control" placeholder="جستجوی نام، موبایل، ایمیل..." value="<?= htmlspecialchars($search_query) ?>">
        </div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs filter-tabs mb-3" id="typeTabs">
            <li class="nav-item"><a class="nav-link <?= $type_filter=='all'?'active':'' ?>" data-type="all" href="#">همه</a></li>
            <li class="nav-item"><a class="nav-link <?= $type_filter=='customer'?'active':'' ?>" data-type="customer" href="#">مشتریان</a></li>
            <li class="nav-item"><a class="nav-link <?= $type_filter=='supplier'?'active':'' ?>" data-type="supplier" href="#">تأمین‌کنندگان</a></li>
            <li class="nav-item"><a class="nav-link <?= $type_filter=='partner'?'active':'' ?>" data-type="partner" href="#">همکاران</a></li>
        </ul>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr><th>نوع</th><th>نام کامل</th><th>موبایل</th><th>تلفن</th><th>ایمیل</th><th>آدرس</th><th>فعال</th><th>عملیات</th></tr>
                </thead>
                <tbody id="customersTableBody">
                    <tr><td colspan="8" class="text-center">در حال بارگذاری...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="paginationContainer"></div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){
    let currentType = '<?= $type_filter ?>';
    let currentQuery = '<?= addslashes($search_query) ?>';
    let currentPage = <?= $page ?>;
    let currentPerPage = <?= $per_page ?>;
    
    function loadData() {
        $.ajax({
            url: '../../ajax_search.php',
            type: 'GET',
            data: {
                type: 'customers_manage',
                type_filter: currentType,
                query: currentQuery,
                page: currentPage,
                per_page: currentPerPage
            },
            dataType: 'json',
            success: function(data) {
                $('#customersTableBody').html(data.html_table);
                $('#paginationContainer').html(data.html_pagination);
                attachPaginationEvents();
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', error);
                console.log('Response:', xhr.responseText);
            }
        });
    }
    
    function attachPaginationEvents() {
        $('#paginationContainer .page-link').off('click').on('click', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page && !isNaN(parseInt(page))) {
                currentPage = parseInt(page);
                loadData();
            }
        });
    }
    
    let searchTimer;
    $('#liveSearch').on('keyup', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            currentQuery = $(this).val();
            currentPage = 1;
            loadData();
        }, 400);
    });
    
    $('#typeTabs .nav-link').on('click', function(e) {
        e.preventDefault();
        currentType = $(this).data('type');
        currentPage = 1;
        $('#typeTabs .nav-link').removeClass('active');
        $(this).addClass('active');
        loadData();
    });
    
    $('#perPageSelect').on('change', function() {
        currentPerPage = parseInt($(this).val());
        currentPage = 1;
        loadData();
    });
    
    // بارگذاری اولیه
    loadData();
});
</script>

<?php require_once '../../includes/footer.php'; ?>