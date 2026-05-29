<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_fullname = $_SESSION['fullname'] ?? 'کاربر';

load_appearance_settings();

// ساختار منوهای گروه‌بندی شده
$menu_groups = [
    'dashboard' => [
        'type' => 'link',
        'title' => '🏠 داشبورد',
        'url' => BASE_URL . 'index.php',
        'permission' => 'dashboard_view',
        'icon' => 'fas fa-tachometer-alt'
    ],
    'customers' => [
        'type' => 'link',
        'title' => '👥 مشتریان و اشخاص',
        'url' => BASE_URL . 'modules/customers/index.php',
        'permission' => 'customers_manage',
        'icon' => 'fas fa-users'
    ],
    'reception' => [
        'type' => 'group',
        'title' => '🔧 پذیرش',
        'permission' => 'reception_access',
        'icon' => 'fas fa-clipboard-list',
        'items' => [
            ['title' => 'پذیرش دستگاه', 'url' => BASE_URL . 'modules/reception/index.php', 'icon' => 'fas fa-plus-circle'],
            ['title' => 'آماده تحویل', 'url' => BASE_URL . 'modules/reception/ready_list.php', 'icon' => 'fas fa-check-circle'],
            ['title' => 'تاریخچه مشتری', 'url' => BASE_URL . 'modules/reception/customer_history.php', 'icon' => 'fas fa-history']
        ]
    ],
    'inventory' => [
        'type' => 'group',
        'title' => '📦 انبار',
        'permission' => 'inventory_access',
        'icon' => 'fas fa-boxes',
        'items' => [
            ['title' => 'مدیریت کالاها', 'url' => BASE_URL . 'modules/inventory/products.php', 'icon' => 'fas fa-cubes'],
            ['title' => 'ثبت فاکتور خرید', 'url' => BASE_URL . 'modules/inventory/purchase.php', 'icon' => 'fas fa-shopping-cart'],
            ['title' => 'ثبت فاکتور فروش', 'url' => BASE_URL . 'modules/inventory/sale.php', 'icon' => 'fas fa-money-bill-wave'],
            ['title' => 'فاکتورهای خرید', 'url' => BASE_URL . 'modules/inventory/purchase_invoices.php', 'icon' => 'fas fa-file-invoice'],
            ['title' => 'گزارش گردش موجودی', 'url' => BASE_URL . 'modules/inventory/stock_movements.php', 'icon' => 'fas fa-chart-line']
        ]
    ],
    'financial' => [
        'type' => 'group',
        'title' => '💰 مالی و اعتبارات',
        'permission' => 'transfers_manage', // مجوز پایه برای نمایش گروه
        'icon' => 'fas fa-money-bill-wave',
        'items' => []
    ],
    'accounting' => [
        'type' => 'group',
        'title' => '💰 حسابداری',
        'permission' => 'accounting_access',
        'icon' => 'fas fa-calculator',
        'items' => [
            ['title' => 'مدیریت حساب‌ها', 'url' => BASE_URL . 'modules/accounting/accounts.php', 'icon' => 'fas fa-university'],
            ['title' => 'ثبت سند دستی', 'url' => BASE_URL . 'modules/accounting/transactions.php', 'icon' => 'fas fa-pen-alt'],
            ['title' => 'گزارش گردش حساب', 'url' => BASE_URL . 'modules/accounting/balance_sheet.php', 'icon' => 'fas fa-chart-pie']
        ]
    ],
    'users' => [
        'type' => 'link',
        'title' => '👤 مدیریت کاربران',
        'url' => BASE_URL . 'modules/users/users.php',
        'permission' => 'users_manage',
        'icon' => 'fas fa-user-cog'
    ],
    'settings' => [
        'type' => 'group',
        'title' => '⚙️ تنظیمات',
        'permission' => 'settings_manage',
        'icon' => 'fas fa-sliders-h',
        'items' => [
            ['title' => 'تنظیمات ظاهری', 'url' => BASE_URL . 'modules/settings/appearance.php', 'icon' => 'fas fa-palette'],
            ['title' => 'تنظیمات عمومی', 'url' => BASE_URL . 'modules/settings/general.php', 'icon' => 'fas fa-globe']
        ]
    ],
    'reports' => [
        'type' => 'link',
        'title' => '📊 گزارشات',
        'url' => BASE_URL . 'modules/reports/index.php',
        'permission' => 'reports_view',
        'icon' => 'fas fa-chart-bar'
    ]
];

// اضافه کردن آیتم‌های منوی مالی بر اساس دسترسی‌ها
if (has_permission($user_id, 'transfers_manage')) {
    $menu_groups['financial']['items'][] = ['title' => 'انتقال وجه', 'url' => BASE_URL . 'modules/financial/transfer.php', 'icon' => 'fas fa-exchange-alt'];
}
if (has_permission($user_id, 'loans_manage')) {
    $menu_groups['financial']['items'][] = ['title' => 'مدیریت وام‌ها', 'url' => BASE_URL . 'modules/financial/loans.php', 'icon' => 'fas fa-hand-holding-usd'];
}
if (has_permission($user_id, 'checks_manage')) {
    $menu_groups['financial']['items'][] = ['title' => 'مدیریت چک‌ها', 'url' => BASE_URL . 'modules/financial/checks.php', 'icon' => 'fas fa-money-check'];
}
if (has_permission($user_id, 'credit_sales_manage')) {
    $menu_groups['financial']['items'][] = ['title' => 'فروش نسیه', 'url' => BASE_URL . 'modules/financial/credit_sales.php', 'icon' => 'fas fa-credit-card'];
}
// اگر هیچ آیتمی به گروه مالی اضافه نشد، گروه را حذف می‌کنیم
if (empty($menu_groups['financial']['items'])) {
    unset($menu_groups['financial']);
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت خدمات فنی شروین</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/theme.css">
    <script src="<?= BASE_URL ?>assets/js/jquery-3.6.0.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --sidebar-bg: linear-gradient(135deg, #1e2a3a 0%, #0f172a 100%);
            --sidebar-hover: rgba(255,255,255,0.1);
            --sidebar-active: #0ea5e9;
        }
        .sidebar {
            min-height: 100vh;
            background: var(--sidebar-bg);
            color: #e2e8f0;
            box-shadow: 4px 0 15px rgba(0,0,0,0.1);
        }
        .sidebar .nav-link {
            color: #cbd5e1;
            padding: 12px 16px;
            border-radius: 8px;
            margin: 4px 8px;
            transition: all 0.2s;
            font-weight: 500;
        }
        .sidebar .nav-link:hover {
            background: var(--sidebar-hover);
            color: white;
            transform: translateX(5px);
        }
        .sidebar .nav-link.active {
            background: var(--sidebar-active);
            color: white;
        }
        .sidebar .nav-link i {
            width: 24px;
            margin-left: 8px;
            text-align: center;
        }
        .sidebar .dropdown-toggle::after {
            float: left;
            margin-top: 8px;
        }
        .sidebar .dropdown-menu {
            background: #1e293b;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            margin-right: 8px;
        }
        .sidebar .dropdown-item {
            color: #cbd5e1;
            padding: 8px 20px;
            border-radius: 6px;
        }
        .sidebar .dropdown-item:hover {
            background: var(--sidebar-hover);
            color: white;
        }
        .content {
            background: #f1f5f9;
            min-height: 100vh;
            padding: 20px;
        }
        .card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 2px solid #0ea5e9;
        }
        .navbar-brand-custom {
            font-size: 1.4rem;
            font-weight: bold;
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 15px;
        }
        .user-info {
            padding: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
            margin-top: auto;
            font-size: 0.9rem;
        }
        .sidebar-wrapper {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <nav class="col-md-2 sidebar">
            <div class="sidebar-wrapper">
                <div class="navbar-brand-custom">
                    <i class="fas fa-tools"></i> شروین
                </div>
                <ul class="nav flex-column">
                    <?php
                    foreach ($menu_groups as $key => $menu) {
                        if (!has_permission($user_id, $menu['permission'])) continue;
                        
                        if ($menu['type'] == 'link') {
                            echo '<li class="nav-item">';
                            echo '<a class="nav-link" href="' . $menu['url'] . '">';
                            echo '<i class="' . $menu['icon'] . '"></i> ' . $menu['title'];
                            echo '</a>';
                            echo '</li>';
                        } elseif ($menu['type'] == 'group') {
                            echo '<li class="nav-item dropdown">';
                            echo '<a class="nav-link dropdown-toggle" href="#" id="dropdown' . $key . '" role="button" data-bs-toggle="dropdown" aria-expanded="false">';
                            echo '<i class="' . $menu['icon'] . '"></i> ' . $menu['title'];
                            echo '</a>';
                            echo '<ul class="dropdown-menu" aria-labelledby="dropdown' . $key . '">';
                            foreach ($menu['items'] as $sub) {
                                echo '<li><a class="dropdown-item" href="' . $sub['url'] . '">';
                                if (isset($sub['icon'])) echo '<i class="' . $sub['icon'] . ' me-2"></i> ';
                                echo $sub['title'];
                                echo '</a></li>';
                            }
                            echo '</ul></li>';
                        }
                    }
                    ?>
                    <li class="nav-item mt-auto">
                        <a class="nav-link text-danger" href="<?= BASE_URL ?>logout.php">
                            <i class="fas fa-sign-out-alt"></i> خروج
                        </a>
                    </li>
                </ul>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i> <?= htmlspecialchars($user_fullname) ?>
                </div>
            </div>
        </nav>

        <!-- Main content -->
        <main class="col-md-10 content">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2"><?= $page_title ?? 'داشبورد' ?></h1>
                <div class="text-muted">
                    <i class="fas fa-calendar-alt"></i> <?= now_jalali() ?>
                </div>
            </div>

            <?php
            // ==================== هشدارهای سیستمی ====================
            
            // 1. هشدار فاکتورهای خرید نسیه
            if (function_exists('get_unpaid_purchase_invoices_count')) {
                $unpaid_count = get_unpaid_purchase_invoices_count($db);
                if ($unpaid_count > 0) {
                    echo '<div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                            <i class="fas fa-exclamation-triangle"></i> <strong>توجه!</strong> ' . $unpaid_count . ' فاکتور خرید نسیه تسویه نشده وجود دارد. 
                            <a href="' . BASE_URL . 'modules/inventory/purchase_invoices.php?filter=unpaid" class="alert-link">مشاهده و تسویه</a>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                          </div>';
                }
            }

            // 2. هشدار اقساط وام (یک هفته مانده به سررسید)
            if (has_permission($user_id, 'loans_manage')) {
                $upcoming_loans = $db->query("SELECT * FROM loans WHERE status='active' AND remaining_amount > 0")->fetchAll();
                foreach ($upcoming_loans as $l) {
                    // محاسبه تاریخ قسط بعدی (ساده شده: هر ماه یک قسط)
                    $start_timestamp = jalali_to_gregorian_timestamp($l['start_date_sh']);
                    $next_date_timestamp = strtotime("+" . $l['paid_installments'] . " months", $start_timestamp);
                    $next_date = jdate('Y/m/d', $next_date_timestamp);
                    $days_left = ($next_date_timestamp - time()) / (60*60*24);
                    if ($days_left <= 7 && $days_left >= 0 && (!$l['last_reminder_date'] || strtotime($l['last_reminder_date']) < strtotime('-1 day'))) {
                        echo '<div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
                                <i class="fas fa-bell"></i> <strong>یادآوری وام</strong> قسط وام ' . htmlspecialchars($l['bank_name']) . ' در تاریخ ' . $next_date . ' سررسید می‌شود.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                              </div>';
                        $db->prepare("UPDATE loans SET last_reminder_date = NOW() WHERE id = ?")->execute([$l['id']]);
                    }
                }
            }

            // 3. هشدار چک‌های سررسید شده
            if (has_permission($user_id, 'checks_manage')) {
                $today = now_jalali();
                $checks = $db->query("SELECT * FROM checks WHERE status='pending' AND due_date_sh <= '$today'")->fetchAll();
                foreach ($checks as $c) {
                    $type_label = ($c['type'] == 'issued') ? 'پرداختی' : 'دریافتی';
                    $alert_class = ($c['type'] == 'issued') ? 'danger' : 'warning';
                    echo '<div class="alert alert-' . $alert_class . ' alert-dismissible fade show shadow-sm" role="alert">
                            <i class="fas fa-money-check"></i> <strong>چک ' . $type_label . '</strong> به شماره ' . htmlspecialchars($c['check_number']) . ' از بانک ' . htmlspecialchars($c['bank_name']) . ' به مبلغ ' . number_format($c['amount']) . ' تومان در تاریخ ' . $c['due_date_sh'] . ' سررسید شده است.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                          </div>';
                }
            }

            // 4. هشدار فروش نسیه (سررسید گذشته یا امروز)
            if (has_permission($user_id, 'credit_sales_manage')) {
                $today = now_jalali();
                $credit_sales = $db->query("SELECT cs.*, c.fullname as customer_name FROM credit_sales cs JOIN customers c ON c.id = cs.customer_id WHERE cs.status != 'paid' AND cs.due_date_sh <= '$today'")->fetchAll();
                foreach ($credit_sales as $cs) {
                    $remaining = $cs['total_amount'] - $cs['paid_amount'];
                    echo '<div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
                            <i class="fas fa-credit-card"></i> <strong>فروش نسیه</strong> مشتری ' . htmlspecialchars($cs['customer_name']) . ' - فاکتور ' . htmlspecialchars($cs['invoice_no']) . ' به مبلغ باقیمانده ' . number_format($remaining) . ' تومان در تاریخ ' . $cs['due_date_sh'] . ' سررسید شده است.
                            <a href="' . BASE_URL . 'modules/financial/credit_sales.php" class="alert-link">مشاهده و تسویه</a>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                          </div>';
                }
            }
            ?>