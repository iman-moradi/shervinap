<?php
// شروع بافر خروجی برای جلوگیری از خطای "headers already sent"
ob_start();

$page_title = 'مدیریت اجرت‌های عمومی';

// بارگذاری فایل‌های مورد نیاز (قبل از هرگونه خروجی)
require_once '../../config/database.php';
require_once '../../includes/functions.php';   // تابع has_permission در اینجا تعریف شده
require_once '../../includes/date_helper.php';

// بررسی دسترسی (بعد از بارگذاری توابع)
if (!isset($_SESSION)) session_start();
if (!has_permission($_SESSION['user_id'] ?? 0, 'reception_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ========== پردازش درخواست‌های POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = (int)($_POST['price'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($action === 'add' && !empty($name)) {
        $stmt = $db->prepare("INSERT INTO repair_services (name, price, description, is_active) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $price, $description, $is_active]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'خدمت با موفقیت اضافه شد.'];
    } 
    elseif ($action === 'edit' && $id > 0 && !empty($name)) {
        $stmt = $db->prepare("UPDATE repair_services SET name = ?, price = ?, description = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $price, $description, $is_active, $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'خدمت ویرایش شد.'];
    }
    elseif ($action === 'delete' && $id > 0) {
        $stmt = $db->prepare("DELETE FROM repair_services WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'خدمت حذف شد.'];
    }
    // پاک کردن بافر و ریدایرکت
    ob_end_clean();
    header('Location: standard_services.php');
    exit;
}

// حالا که POST پردازش شد، هدر اصلی را بارگذاری می‌کنیم
require_once '../../includes/header.php';

// دریافت کلیه خدمات برای نمایش
$services = $db->query("SELECT * FROM repair_services ORDER BY id DESC")->fetchAll();

$total_services = count($services);
$active_services = count(array_filter($services, function($s) { return $s['is_active'] == 1; }));
$inactive_services = $total_services - $active_services;

$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);
?>

<style>
    /* استایل‌های مدرن (همانند قبل) */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.2rem;
        margin-bottom: 2rem;
    }
    .stat-card {
        background: #fff;
        border-radius: 24px;
        padding: 1rem 0.5rem;
        text-align: center;
        border: 1px solid #eef2f6;
        transition: 0.25s;
        box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 20px rgba(0,0,0,0.08);
        border-color: #cbd5e1;
    }
    .stat-icon {
        font-size: 2rem;
        margin-bottom: 0.5rem;
    }
    .stat-value {
        font-size: 1.8rem;
        font-weight: 800;
        direction: ltr;
        color: #0f172a;
    }
    .stat-label {
        font-size: 0.75rem;
        color: #475569;
        margin-top: 0.3rem;
    }

    .search-box {
        position: relative;
        margin-bottom: 1rem;
        width: 300px;
    }
    .search-box i {
        position: absolute;
        right: 12px;
        top: 12px;
        color: #94a3b8;
    }
    .search-box input {
        border-radius: 30px;
        padding-right: 35px;
        border: 1px solid #e2e8f0;
    }

    .modern-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 8px;
    }
    .modern-table thead th {
        border: none;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        color: #64748b;
        padding: 12px 16px;
        background: #f8fafc;
        white-space: nowrap;
    }
    .modern-table tbody tr {
        background: #ffffff;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .modern-table tbody tr:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.05);
    }
    .modern-table tbody td {
        border: none;
        padding: 14px 16px;
        font-size: 0.9rem;
        color: #1e293b;
        vertical-align: middle;
    }
    .modern-table tbody tr td:first-child {
        border-top-right-radius: 16px;
        border-bottom-right-radius: 16px;
    }
    .modern-table tbody tr td:last-child {
        border-top-left-radius: 16px;
        border-bottom-left-radius: 16px;
    }
    .badge-active {
        background: #22c55e;
        color: white;
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 0.7rem;
    }
    .badge-inactive {
        background: #ef4444;
        color: white;
        padding: 4px 12px;
        border-radius: 30px;
        font-size: 0.7rem;
    }
    .btn-icon {
        background: transparent;
        border: none;
        color: #94a3b8;
        margin: 0 4px;
        transition: 0.2s;
        font-size: 1.1rem;
    }
    .btn-icon:hover {
        color: #0ea5e9;
        transform: scale(1.1);
    }
    .btn-icon-danger:hover {
        color: #dc2626;
    }

    /* پاپ‌آپ سفارشی */
    .custom-popup-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(4px);
        z-index: 10000;
        display: none;
        justify-content: center;
        align-items: center;
    }
    .custom-popup-container {
        background: #fff;
        border-radius: 28px;
        width: 90%;
        max-width: 550px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        animation: fadeInScale 0.2s ease;
    }
    .custom-popup-header {
        padding: 1.2rem 1.5rem;
        border-bottom: 2px solid #0ea5e9;
        background: #f8fafc;
        border-radius: 28px 28px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .custom-popup-header h5 {
        margin: 0;
        font-weight: 600;
        color: #0f172a;
    }
    .custom-popup-close {
        background: none;
        border: none;
        font-size: 1.8rem;
        cursor: pointer;
        color: #94a3b8;
        transition: color 0.2s;
    }
    .custom-popup-close:hover {
        color: #ef4444;
    }
    .custom-popup-body {
        padding: 1.5rem;
    }
    .custom-popup-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    @keyframes fadeInScale {
        from { opacity: 0; transform: scale(0.95); }
        to { opacity: 1; transform: scale(1); }
    }
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2,1fr); }
        .search-box { width: 100%; }
    }
</style>

<div class="modern-card">
    <div class="card-header-custom d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fas fa-tools"></i> لیست اجرت‌های عمومی</span>
        <div>
            <button id="showAddPopupBtn" class="btn btn-modern btn-sm"><i class="fas fa-plus"></i> افزودن خدمت جدید</button>
        </div>
    </div>
    <div class="card-body">
        <?php if ($message): ?>
            <div class="alert alert-<?= $message['type'] ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message['text']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- کارت‌های آماری -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-list-ul"></i></div><div class="stat-value"><?= $total_services ?></div><div class="stat-label">کل خدمات</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-check-circle" style="color:#22c55e;"></i></div><div class="stat-value"><?= $active_services ?></div><div class="stat-label">فعال</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-ban" style="color:#ef4444;"></i></div><div class="stat-value"><?= $inactive_services ?></div><div class="stat-label">غیرفعال</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-dollar-sign"></i></div><div class="stat-value"><?= number_format(array_sum(array_column($services, 'price'))) ?></div><div class="stat-label">مجموع قیمت پایه (تومان)</div></div>
        </div>

        <!-- جستجو -->
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" class="form-control" placeholder="جستجو بر اساس نام خدمت...">
        </div>

        <div class="table-responsive">
            <table class="modern-table" id="servicesTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نام خدمت</th>
                        <th>قیمت (تومان)</th>
                        <th>توضیحات</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $s): ?>
                    <tr data-name="<?= htmlspecialchars($s['name']) ?>">
                        <td><?= $s['id'] ?></td>
                        <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                        <td><?= number_format($s['price']) ?></td>
                        <td><?= htmlspecialchars($s['description']) ?></td>
                        <td><?= $s['is_active'] ? '<span class="badge-active">فعال</span>' : '<span class="badge-inactive">غیرفعال</span>' ?></td>
                        <td>
                            <button class="btn-icon edit-service" data-id="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['name']) ?>" data-price="<?= $s['price'] ?>" data-desc="<?= htmlspecialchars($s['description']) ?>" data-active="<?= $s['is_active'] ?>" title="ویرایش"><i class="fas fa-edit"></i></button>
                            <button class="btn-icon btn-icon-danger delete-service" data-id="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['name']) ?>" title="حذف"><i class="fas fa-trash-alt"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- پاپ‌آپ سفارشی برای افزودن/ویرایش -->
<div id="servicePopup" class="custom-popup-overlay">
    <div class="custom-popup-container">
        <div class="custom-popup-header">
            <h5 id="popupTitle">افزودن خدمت جدید</h5>
            <button type="button" class="custom-popup-close" id="closePopupBtn">&times;</button>
        </div>
        <form method="post" id="serviceForm">
            <div class="custom-popup-body">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="serviceId" value="0">
                <div class="mb-3">
                    <label class="form-label">نام خدمت <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="serviceName" class="form-control" required autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label">قیمت (تومان) <span class="text-danger">*</span></label>
                    <input type="number" name="price" id="servicePrice" class="form-control" required value="0">
                </div>
                <div class="mb-3">
                    <label class="form-label">توضیحات</label>
                    <textarea name="description" id="serviceDesc" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" id="serviceActive" checked>
                    <label class="form-check-label">فعال</label>
                </div>
            </div>
            <div class="custom-popup-footer">
                <button type="button" class="btn btn-secondary" id="cancelPopupBtn">انصراف</button>
                <button type="submit" class="btn btn-modern">ذخیره</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // جستجوی زنده
    $('#searchInput').on('keyup', function() {
        var val = $(this).val().toLowerCase().trim();
        $('#servicesTable tbody tr').each(function() {
            var name = $(this).data('name').toLowerCase();
            $(this).toggle(name.indexOf(val) > -1);
        });
    });

    // مدیریت پاپ‌آپ
    var popup = $('#servicePopup');
    var formAction = $('#formAction');
    var serviceId = $('#serviceId');
    var serviceName = $('#serviceName');
    var servicePrice = $('#servicePrice');
    var serviceDesc = $('#serviceDesc');
    var serviceActive = $('#serviceActive');
    var popupTitle = $('#popupTitle');

    function showPopup(title) {
        popupTitle.text(title);
        popup.fadeIn(200);
    }
    function hidePopup() {
        popup.fadeOut(200);
        // ریست فرم
        formAction.val('add');
        serviceId.val('');
        serviceName.val('');
        servicePrice.val(0);
        serviceDesc.val('');
        serviceActive.prop('checked', true);
    }

    $('#showAddPopupBtn').click(function() {
        showPopup('افزودن خدمت جدید');
    });
    $('#closePopupBtn, #cancelPopupBtn').click(hidePopup);
    $(popup).click(function(e) {
        if (e.target === this) hidePopup();
    });

    // ویرایش
    $('.edit-service').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var price = $(this).data('price');
        var desc = $(this).data('desc');
        var active = $(this).data('active');
        formAction.val('edit');
        serviceId.val(id);
        serviceName.val(name);
        servicePrice.val(price);
        serviceDesc.val(desc);
        serviceActive.prop('checked', active == 1);
        showPopup('ویرایش خدمت');
    });

    // حذف با تأیید (ارسال فرم POST)
    $('.delete-service').click(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        if (confirm(`آیا از حذف "${name}" مطمئن هستید؟`)) {
            $('<form method="post">')
                .append('<input type="hidden" name="action" value="delete">')
                .append('<input type="hidden" name="id" value="' + id + '">')
                .appendTo('body')
                .submit();
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; 
// پایان بافر خروجی
ob_end_flush();
?>