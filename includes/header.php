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

// تابع کمکی برای تشخیص صفحه فعال
function is_active($url) {
    $current_url = $_SERVER['REQUEST_URI'];
    // اگر آدرس دقیق مطابقت داشت
    if (strpos($current_url, $url) !== false) {
        return 'active';
    }
    return '';
}

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
        'permission' => 'transfers_manage',
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
            ['title' => 'ثبت سند دستی (هزینه/درآمد)', 'url' => BASE_URL . 'modules/accounting/add_transaction.php', 'icon' => 'fas fa-pen-alt'],
            ['title' => 'گزارش گردش حساب', 'url' => BASE_URL . 'modules/accounting/balance_sheet.php', 'icon' => 'fas fa-chart-pie'],
            ['title' => '🧠 تحلیل هوشمند و پیشنهادات', 'url' => BASE_URL . 'modules/accounting/smart_advice.php', 'icon' => 'fas fa-robot']
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
    <!-- فونت وزیرمتن (اختیاری اما زیبا) -->
   <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/Vazirmatn-font-face.css">
    <script src="<?= BASE_URL ?>assets/js/jquery-3.6.0.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --sidebar-bg: rgba(30, 41, 59, 0.95);
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --primary-color: #0ea5e9;
            --primary-dark: #0284c7;
            --text-light: #f1f5f9;
            --text-dim: #cbd5e1;
            --hover-bg: rgba(255, 255, 255, 0.15);
            --transition-smooth: all 0.3s ease-in-out;
            --card-bg: rgba(255, 255, 255, 0.9);
            --card-shadow: 0 20px 35px -15px rgba(0, 0, 0, 0.1);
            --border-radius-lg: 20px;
            --border-radius-md: 12px;
        }

        body {
            font-family: 'Vazirmatn', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #e2e8f0 0%, #f8fafc 100%);
            min-height: 100vh;
            direction: rtl;
        }

        /* استایل مدرن سایدبار (افکت شیشه‌ای) */
        .sidebar {
            min-height: 100vh;
            background: var(--sidebar-bg);
            backdrop-filter: blur(10px);
            border-left: 1px solid var(--glass-border);
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
            transition: var(--transition-smooth);
        }

        .navbar-brand-custom {
            font-size: 1.5rem;
            font-weight: 800;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid var(--glass-border);
            margin-bottom: 20px;
            color: white;
            letter-spacing: 1px;
            background: linear-gradient(135deg, #fff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-link {
            color: var(--text-dim) !important;
            padding: 12px 20px !important;
            border-radius: var(--border-radius-md) !important;
            margin: 6px 12px;
            transition: var(--transition-smooth);
            font-weight: 500;
            display: flex;
            align-items: center;
        }

        .nav-link i {
            width: 28px;
            font-size: 1.2rem;
            margin-left: 12px;
            text-align: center;
        }

        .nav-link:hover {
            background: var(--hover-bg) !important;
            color: white !important;
            transform: translateX(5px);
        }

        .nav-link.active {
            background: var(--primary-color) !important;
            color: white !important;
            box-shadow: 0 4px 10px rgba(14, 165, 233, 0.3);
        }

        .dropdown-toggle::after {
            float: left;
            margin-top: 8px;
            transition: transform 0.3s ease;
        }

        .dropdown.show .dropdown-toggle::after {
            transform: rotate(180deg);
        }

        .dropdown-menu {
            background: rgba(30, 41, 59, 0.98);
            backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius-md);
            margin-right: 12px;
            padding: 8px 0;
            transition: var(--transition-smooth);
        }

        .dropdown-item {
            color: var(--text-dim);
            padding: 10px 24px;
            border-radius: 8px;
            margin: 2px 8px;
            transition: var(--transition-smooth);
        }

        .dropdown-item:hover {
            background: var(--hover-bg);
            color: white;
            transform: translateX(5px);
        }

        .user-info {
            padding: 20px;
            border-top: 1px solid var(--glass-border);
            margin-top: auto;
            font-size: 0.9rem;
            color: var(--text-light);
            text-align: center;
        }

        .sidebar-wrapper {
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        /* استایل محتوای اصلی (با افکت شیشه‌ای) */
        .content {
            padding: 20px;
            background: rgba(255, 255, 255, 0.4);
            backdrop-filter: blur(5px);
            min-height: 100vh;
        }

        /* کارت‌های مدرن */
        .modern-card {
            background: var(--card-bg);
            backdrop-filter: blur(8px);
            border-radius: var(--border-radius-lg);
            border: none;
            box-shadow: var(--card-shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
            height: 100%;
        }

        .modern-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 40px -12px rgba(0, 0, 0, 0.2);
        }

        .card-header-custom {
            background: rgba(255, 255, 255, 0.7);
            border-bottom: 2px solid var(--primary-color);
            padding: 15px 20px;
            font-weight: bold;
        }

        /* دکمه‌ها */
        .btn-modern {
            background: var(--primary-color);
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            color: white;
            transition: var(--transition-smooth);
        }

        .btn-modern:hover {
            background: var(--primary-dark);
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(14, 165, 233, 0.4);
        }

        /* هشدارهای زیبا */
        .alert-glass {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-right: 5px solid;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        /* اسکرول بار سفارشی */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 10px;
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
                            $active_class = is_active($menu['url']);
                            echo '<li class="nav-item">';
                            echo '<a class="nav-link ' . $active_class . '" href="' . $menu['url'] . '">';
                            echo '<i class="' . $menu['icon'] . '"></i> ' . $menu['title'];
                            echo '</a>';
                            echo '</li>';
                        } elseif ($menu['type'] == 'group') {
                            $is_open = false;
                            foreach ($menu['items'] as $sub) {
                                if (strpos($_SERVER['REQUEST_URI'], $sub['url']) !== false) {
                                    $is_open = true;
                                    break;
                                }
                            }
                            echo '<li class="nav-item dropdown">';
                            echo '<a class="nav-link dropdown-toggle ' . ($is_open ? 'show' : '') . '" href="#" id="dropdown' . $key . '" role="button" data-bs-toggle="dropdown" aria-expanded="' . ($is_open ? 'true' : 'false') . '">';
                            echo '<i class="' . $menu['icon'] . '"></i> ' . $menu['title'];
                            echo '</a>';
                            echo '<ul class="dropdown-menu ' . ($is_open ? 'show' : '') . '" aria-labelledby="dropdown' . $key . '">';
                            foreach ($menu['items'] as $sub) {
                                $sub_active = is_active($sub['url']);
                                echo '<li><a class="dropdown-item ' . $sub_active . '" href="' . $sub['url'] . '">';
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
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
                <h1 class="h2" style="color: #1e293b;"><?= $page_title ?? 'داشبورد' ?></h1>
                <div class="text-muted bg-white p-2 px-3 rounded-pill shadow-sm">
                    <i class="fas fa-calendar-alt text-primary"></i> <?= now_jalali() ?>
                </div>
            </div>

            <?php
            // ==================== هشدارهای سیستمی ====================
            
            // 1. هشدار فاکتورهای خرید نسیه
            if (function_exists('get_unpaid_purchase_invoices_count')) {
                $unpaid_count = get_unpaid_purchase_invoices_count($db);
                if ($unpaid_count > 0) {
                    echo '<div class="alert alert-danger alert-glass alert-dismissible fade show shadow-sm" role="alert">
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
                    $start_timestamp = jalali_to_gregorian_timestamp($l['start_date_sh']);
                    $next_date_timestamp = strtotime("+" . $l['paid_installments'] . " months", $start_timestamp);
                    $next_date = jdate('Y/m/d', $next_date_timestamp);
                    $days_left = ($next_date_timestamp - time()) / (60*60*24);
                    if ($days_left <= 7 && $days_left >= 0 && (!$l['last_reminder_date'] || strtotime($l['last_reminder_date']) < strtotime('-1 day'))) {
                        echo '<div class="alert alert-warning alert-glass alert-dismissible fade show shadow-sm" role="alert">
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
                    echo '<div class="alert alert-' . $alert_class . ' alert-glass alert-dismissible fade show shadow-sm" role="alert">
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
                    echo '<div class="alert alert-warning alert-glass alert-dismissible fade show shadow-sm" role="alert">
                            <i class="fas fa-credit-card"></i> <strong>فروش نسیه</strong> مشتری ' . htmlspecialchars($cs['customer_name']) . ' - فاکتور ' . htmlspecialchars($cs['invoice_no']) . ' به مبلغ باقیمانده ' . number_format($remaining) . ' تومان در تاریخ ' . $cs['due_date_sh'] . ' سررسید شده است.
                            <a href="' . BASE_URL . 'modules/financial/credit_sales.php" class="alert-link">مشاهده و تسویه</a>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                          </div>';
                }
            }
            ?>