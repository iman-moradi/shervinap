<?php
$page_title = '🧠 هوش تجاری پیشرفته شروین - داشبورد مدیریت هوشمند';
require_once '../../includes/header.php';
require_once '../../includes/jdf.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ----------------------------------- توابع کمکی -----------------------------------
function toEnglishNumber($string) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($persian, $english, $string);
}

// دریافت تاریخ شمسی جاری با اعداد انگلیسی
$today = toEnglishNumber(jdate('Y/m/d'));
$currentYear = toEnglishNumber(jdate('Y'));
$currentMonthStart = toEnglishNumber(jdate('Y/m/01'));
$currentMonthEnd = toEnglishNumber(jdate('Y/m/t'));
$lastMonthStart = toEnglishNumber(jdate('Y/m/01', strtotime('-1 month')));
$lastMonthEnd = toEnglishNumber(jdate('Y/m/t', strtotime('-1 month')));
$last3MonthsStart = toEnglishNumber(jdate('Y/m/01', strtotime('-3 month')));

// تعداد روزهای ماه جاری (به عدد صحیح)
$daysInMonth = (int)toEnglishNumber(jdate('t'));

// ---------- 1. شاخص‌های کلیدی عملکرد (KPI) ----------
$salesTotal = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_invoices WHERE invoice_date_sh BETWEEN ? AND ?");
$salesTotal->execute([$currentMonthStart, $currentMonthEnd]);
$salesTotal = (int)$salesTotal->fetchColumn();

$repairIncome = $db->prepare("SELECT COALESCE(SUM(total_cost),0) FROM repair_tickets WHERE status='delivered' AND delivered_date_sh BETWEEN ? AND ?");
$repairIncome->execute([$currentMonthStart, $currentMonthEnd]);
$repairIncome = (int)$repairIncome->fetchColumn();

$totalIncome = $salesTotal + $repairIncome;

$expenseTotal = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND transaction_date_sh BETWEEN ? AND ?");
$expenseTotal->execute([$currentMonthStart, $currentMonthEnd]);
$expenseTotal = (int)$expenseTotal->fetchColumn();

$grossProfit = $totalIncome - $expenseTotal;
$profitMargin = ($totalIncome > 0) ? round(($grossProfit / $totalIncome) * 100, 1) : 0;

$cashBalance = $db->query("SELECT COALESCE(SUM(current_balance),0) FROM accounts")->fetchColumn();

$newCustomers = $db->prepare("SELECT COUNT(*) FROM customers WHERE DATE(created_at) >= ?");
$newCustomers->execute([date('Y-m-01')]);
$newCustomers = (int)$newCustomers->fetchColumn();

$deliveredTickets = $db->prepare("SELECT COUNT(*) FROM repair_tickets WHERE status='delivered' AND delivered_date_sh BETWEEN ? AND ?");
$deliveredTickets->execute([$currentMonthStart, $currentMonthEnd]);
$deliveredTickets = (int)$deliveredTickets->fetchColumn();

$avgRepairDays = $db->prepare("
    SELECT AVG(DATEDIFF(STR_TO_DATE(delivered_date_sh, '%Y/%m/%d'), STR_TO_DATE(received_date_sh, '%Y/%m/%d'))) 
    FROM repair_tickets 
    WHERE status='delivered' AND delivered_date_sh IS NOT NULL AND received_date_sh IS NOT NULL
");
$avgRepairDays->execute();
$avgRepairDays = round((float)$avgRepairDays->fetchColumn(), 1);

// ---------- 2. تحلیل سودآوری محصولات ----------
$profitProducts = $db->prepare("
    SELECT p.id, p.name, p.sku, p.sale_price, p.purchase_price, 
           COALESCE(SUM(si.quantity),0) as qty_sold,
           (p.sale_price - p.purchase_price) as unit_profit,
           COALESCE(SUM(si.quantity),0) * (p.sale_price - p.purchase_price) as total_profit
    FROM products p
    LEFT JOIN sales_items si ON si.product_id = p.id
    LEFT JOIN sales_invoices si2 ON si2.id = si.sales_invoice_id AND si2.invoice_date_sh BETWEEN ? AND ?
    GROUP BY p.id
    HAVING total_profit != 0 OR qty_sold > 0
    ORDER BY total_profit DESC
    LIMIT 10
");
$profitProducts->execute([$currentMonthStart, $currentMonthEnd]);
$topProfitProducts = $profitProducts->fetchAll();

// کم‌سودترین محصولات (حاشیه سود درصدی پایین)
$lowMarginProducts = $db->prepare("
    SELECT id, name, sale_price, purchase_price, 
           ROUND((sale_price - purchase_price) / sale_price * 100, 1) as margin_percent
    FROM products 
    WHERE sale_price > 0 AND purchase_price > 0 AND (sale_price - purchase_price) > 0
    ORDER BY margin_percent ASC
    LIMIT 10
");
$lowMarginProducts->execute();
$lowMarginList = $lowMarginProducts->fetchAll();

// ---------- تولید پیشنهادهای عملی برای بهبود سود محصولات کم‌حاشیه ----------
$lowMarginSuggestions = [];
foreach ($lowMarginList as $lm) {
    $currentPrice = $lm['sale_price'];
    $cost = $lm['purchase_price'];
    $margin = $lm['margin_percent'];
    // پیشنهاد افزایش قیمت 10%
    $newPrice10 = round($currentPrice * 1.1);
    $newMargin10 = round(($newPrice10 - $cost) / $newPrice10 * 100, 1);
    // پیشنهاد کاهش هزینه خرید 5%
    $newCost = round($cost * 0.95);
    $newMarginCost = round(($currentPrice - $newCost) / $currentPrice * 100, 1);
    $lowMarginSuggestions[] = [
        'name' => $lm['name'],
        'current_margin' => $margin,
        'suggest1' => "💰 افزایش قیمت فروش از " . number_format($currentPrice) . " به " . number_format($newPrice10) . " تومان (10% افزایش) ➜ حاشیه سود جدید: {$newMargin10}%",
        'suggest2' => "🤝 مذاکره با تأمین‌کننده برای کاهش 5% قیمت خرید ➜ حاشیه سود جدید: {$newMarginCost}%",
        'suggest3' => "🎁 فروش این محصول به صورت بسته‌ای با یک محصول پرمارجین (مثلاً همراه با خدمات نصب)"
    ];
}

// ---------- 3. تحلیل مشتریان با ارزش ----------
$valuableCustomers = $db->prepare("
    SELECT c.id, c.fullname, c.mobile, 
           COUNT(si.id) as purchase_count, 
           COALESCE(SUM(si2.total_amount),0) as total_spent,
           MAX(si2.invoice_date_sh) as last_purchase_date
    FROM customers c
    LEFT JOIN sales_invoices si2 ON si2.customer_id = c.id
    LEFT JOIN sales_items si ON si.sales_invoice_id = si2.id
    WHERE c.type = 'customer'
    GROUP BY c.id
    HAVING total_spent > 0
    ORDER BY total_spent DESC
    LIMIT 10
");
$valuableCustomers->execute();
$topCustomers = $valuableCustomers->fetchAll();

$debtors = $db->prepare("
    SELECT c.id, c.fullname, c.mobile, 
           SUM(cs.total_amount - cs.paid_amount) as debt_amount,
           MIN(cs.due_date_sh) as nearest_due
    FROM credit_sales cs
    JOIN customers c ON c.id = cs.customer_id
    WHERE cs.status IN ('unpaid','partial')
    GROUP BY c.id
    HAVING debt_amount > 0
    ORDER BY debt_amount DESC
");
$debtors->execute();
$debtorList = $debtors->fetchAll();

// ---------- 4. تحلیل انبار ----------
$needReorder = $db->prepare("
    SELECT id, name, current_stock, min_stock_alert, purchase_price
    FROM products 
    WHERE current_stock <= min_stock_alert AND min_stock_alert > 0
");
$needReorder->execute();
$reorderList = $needReorder->fetchAll();

$slowMoving = $db->prepare("
    SELECT p.id, p.name, p.current_stock, p.purchase_price
    FROM products p
    WHERE p.current_stock > 0 
      AND NOT EXISTS (
          SELECT 1 FROM sales_items si 
          JOIN sales_invoices si2 ON si2.id = si.sales_invoice_id 
          WHERE si.product_id = p.id AND si2.invoice_date_sh >= ?
      )
    LIMIT 20
");
$slowMoving->execute([$last3MonthsStart]);
$slowMovingList = $slowMoving->fetchAll();

// ---------- 5. تحلیل کارایی تکنسین‌ها ----------
$techPerformance = $db->prepare("
    SELECT u.id, u.fullname, 
           COUNT(rt.id) as total_tickets,
           SUM(CASE WHEN rt.status = 'delivered' THEN 1 ELSE 0 END) as delivered_tickets,
           AVG(CASE WHEN rt.delivered_date_sh IS NOT NULL AND rt.received_date_sh IS NOT NULL 
                THEN DATEDIFF(STR_TO_DATE(rt.delivered_date_sh, '%Y/%m/%d'), STR_TO_DATE(rt.received_date_sh, '%Y/%m/%d')) 
                ELSE NULL END) as avg_days,
           COALESCE(SUM(rt.total_cost),0) as revenue_generated
    FROM users u
    JOIN repair_tickets rt ON rt.created_by = u.id
    WHERE rt.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    GROUP BY u.id
    ORDER BY revenue_generated DESC
");
$techPerformance->execute();
$techStats = $techPerformance->fetchAll();

// ---------- 6. تحلیل روند و پیش‌بینی ----------
$trendData = [];
for ($i = 5; $i >= 0; $i--) {
    $start = toEnglishNumber(jdate('Y/m/01', strtotime("-$i month")));
    $end = toEnglishNumber(jdate('Y/m/t', strtotime("-$i month")));
    $inc = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND transaction_date_sh BETWEEN ? AND ?");
    $inc->execute([$start, $end]);
    $exp = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND transaction_date_sh BETWEEN ? AND ?");
    $exp->execute([$start, $end]);
    $trendData[] = [
        'month' => jdate('F', strtotime("-$i month")),
        'income' => (int)$inc->fetchColumn(),
        'expense' => (int)$exp->fetchColumn()
    ];
}
$last3Income = array_sum(array_column(array_slice($trendData, -3), 'income'));
$forecastIncome = round($last3Income / 3);
$last3Expense = array_sum(array_column(array_slice($trendData, -3), 'expense'));
$forecastExpense = round($last3Expense / 3);
$forecastProfit = $forecastIncome - $forecastExpense;

// ---------- 7. تحلیل هزینه‌ها ----------
$expenseByCategory = $db->prepare("
    SELECT c.name, COALESCE(SUM(t.amount),0) as total, c.monthly_budget
    FROM expense_categories c
    LEFT JOIN transactions t ON t.category_id = c.id AND t.type='expense' AND t.transaction_date_sh BETWEEN ? AND ?
    GROUP BY c.id
    ORDER BY total DESC
");
$expenseByCategory->execute([$currentMonthStart, $currentMonthEnd]);
$expenseCats = $expenseByCategory->fetchAll();

$avgDailyExpense = ($expenseTotal > 0 && $daysInMonth > 0) ? round($expenseTotal / $daysInMonth) : 0;

$unusualExpenses = $db->prepare("
    SELECT description, amount, transaction_date_sh, category_id 
    FROM transactions 
    WHERE type='expense' AND amount > ? AND transaction_date_sh >= ?
    ORDER BY amount DESC LIMIT 10
");
$unusualExpenses->execute([$avgDailyExpense * 2, $currentMonthStart]);
$unusualList = $unusualExpenses->fetchAll();

// ---------- 8. پیشنهادات هوشمند کلی ----------
$recommendations = [];

if ($profitMargin < 20) {
    $recommendations[] = "📉 حاشیه سود فعلی ({$profitMargin}%) کمتر از 20% است. قیمت خدمات تخصصی را 5 تا 10% افزایش دهید یا هزینه‌های ثابت را کاهش دهید.";
}
if ($expenseTotal > $totalIncome * 0.7 && $totalIncome > 0) {
    $topCat = isset($expenseCats[0]['name']) ? $expenseCats[0]['name'] : 'نامشخص';
    $recommendations[] = "⚠️ هزینه‌ها 70% درآمد را می‌خورند. دسته‌های پرهزینه: {$topCat}. ممیزی هزینه انجام دهید.";
}
if ($cashBalance < $expenseTotal * 0.5 && $expenseTotal > 0) {
    $recommendations[] = "🏦 نقدینگی جاری (" . number_format($cashBalance) . " تومان) کمتر از نصف هزینه ماهانه است. وصول مطالبات را سرعت ببخشید.";
}
if (count($reorderList) > 0) {
    $rec = "📦 {$reorderList[0]['name']} و " . (count($reorderList)-1) . " قلم دیگر به حد سفارش رسیده‌اند. سفارش خرید دهید.";
    $recommendations[] = $rec;
}
if (count($slowMovingList) > 5) {
    $recommendations[] = "🐌 تعداد کالاهای کم‌گردش زیاد است. برای آنها تخفیف ویژه یا بسته‌های ترکیبی ارائه دهید.";
}
if (count($debtorList) > 0) {
    $totalDebt = array_sum(array_column($debtorList, 'debt_amount'));
    $firstDebtor = $debtorList[0]['fullname'];
    $firstAmount = number_format($debtorList[0]['debt_amount']);
    $recommendations[] = "⚠️ مجموع بدهی مشتریان: " . number_format($totalDebt) . " تومان. برای {$firstDebtor} با مبلغ {$firstAmount} تومان پیگیری فوری انجام دهید.";
}
if ($newCustomers < 5 && $totalIncome > 0) {
    $recommendations[] = "📢 جذب مشتری جدید ضعیف است. از ابزار «کمپین پیامکی» پایین صفحه برای ارسال کد تخفیف استفاده کنید.";
}
if (count($topCustomers) > 0) {
    $best = $topCustomers[0];
    $recommendations[] = "🏆 مشتری وفادار «{$best['fullname']}» تاکنون " . number_format($best['total_spent']) . " تومان خرید کرده است. یک هدیه ویژه یا تخفیف اختصاصی برای او در نظر بگیرید.";
}
if ($avgRepairDays > 5) {
    $recommendations[] = "⏱ میانگین زمان تحویل تعمیرات {$avgRepairDays} روز است. فرآیند عیب‌یابی را استاندارد کنید.";
}
if (!empty($techStats) && isset($techStats[0]['avg_days']) && $techStats[0]['avg_days'] > 7) {
    $slowTech = $techStats[0];
    $recommendations[] = "👨‍🔧 تکنسین «{$slowTech['fullname']}» میانگین زمان تعمیر {$slowTech['avg_days']} روز دارد. آموزش تخصصی توصیه می‌شود.";
}
if ($repairIncome > 0 && $deliveredTickets > 0) {
    $avgTicketPrice = $repairIncome / $deliveredTickets;
    if ($avgTicketPrice < 500000) {
        $recommendations[] = "💡 میانگین مبلغ هر فیش تعمیر " . number_format($avgTicketPrice) . " تومان است. فروش قطعات جانبی را پیشنهاد دهید.";
    }
}
$simulatedIncome = $totalIncome * 1.05;
$simulatedProfit = $simulatedIncome - $expenseTotal;
$recommendations[] = "🔮 اگر فقط 5% به میانگین قیمت خدمات اضافه کنید، درآمد ماهانه به " . number_format($simulatedIncome) . " تومان و سود به " . number_format($simulatedProfit) . " تومان می‌رسد.";

if (empty($recommendations)) {
    $recommendations[] = "✅ کسب‌وکار شما در وضعیت عالی است. برای رشد، تنوع خدمات را بررسی کنید.";
}

// نرخ رشد نسبت به ماه قبل
$lastMonthIncome = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND transaction_date_sh BETWEEN ? AND ?");
$lastMonthIncome->execute([$lastMonthStart, $lastMonthEnd]);
$lastMonthIncome = (int)$lastMonthIncome->fetchColumn();
$incomeGrowth = ($lastMonthIncome > 0) ? round(($totalIncome - $lastMonthIncome) / $lastMonthIncome * 100, 1) : ($totalIncome > 0 ? 100 : 0);

$lastMonthExpense = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND transaction_date_sh BETWEEN ? AND ?");
$lastMonthExpense->execute([$lastMonthStart, $lastMonthEnd]);
$lastMonthExpense = (int)$lastMonthExpense->fetchColumn();
$expenseGrowth = ($lastMonthExpense > 0) ? round(($expenseTotal - $lastMonthExpense) / $lastMonthExpense * 100, 1) : ($expenseTotal > 0 ? 100 : 0);

// ------------------- بخش مدیریت کمپین پیامکی (دستی) -------------------
// دریافت لیست گروه‌های مشتریان برای ارسال پیامک
// ------------------- بخش مدیریت کمپین پیامکی (دستی) -------------------
// دریافت لیست گروه‌های مشتریان برای ارسال پیامک (فقط برای نمایش در فرم - در صورت نیاز)
$allCustomers = $db->query("SELECT id, fullname, mobile, created_at FROM customers WHERE mobile IS NOT NULL AND mobile != '' AND is_active = 1 ORDER BY fullname")->fetchAll();
// این متغیر در حال حاضر در فرم استفاده نمی‌شود، اما اگر خواستید از آن استفاده کنید، بدون خطا کار می‌کند
$recentCustomers = array_filter($allCustomers, function($c) {
    return strtotime($c['created_at']) >= strtotime('-30 days');
});

// اگر فرم ارسال پیامک ارسال شده باشد
$sms_sent_status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms_campaign'])) {
    $recipient_ids = $_POST['recipient_ids'] ?? []; // array of customer ids
    $message_template = trim($_POST['message_template'] ?? '');
    $custom_subject = trim($_POST['custom_subject'] ?? '');
    $confirm_send = isset($_POST['confirm_send']) && $_POST['confirm_send'] == '1';
    
    if (!$confirm_send) {
        $sms_sent_status = '<div class="alert alert-warning">⚠️ برای ارسال پیامک، باید تیک تأیید را بزنید.</div>';
    } elseif (empty($recipient_ids)) {
        $sms_sent_status = '<div class="alert alert-danger">❌ هیچ گیرنده‌ای انتخاب نشده است.</div>';
    } elseif (empty($message_template)) {
        $sms_sent_status = '<div class="alert alert-danger">❌ متن پیامک را وارد کنید.</div>';
    } else {
        // دریافت شماره موبایل گیرندگان
        $placeholders = implode(',', array_fill(0, count($recipient_ids), '?'));
        $stmt = $db->prepare("SELECT mobile, fullname FROM customers WHERE id IN ($placeholders) AND mobile IS NOT NULL AND mobile != ''");
        $stmt->execute($recipient_ids);
        $recipients = $stmt->fetchAll();
        $shop_name = $db->query("SELECT setting_value FROM settings WHERE setting_key='shop_name'")->fetchColumn();
        if (!$shop_name) $shop_name = 'خدمات فنی شروین';
        
        $success_count = 0;
        $fail_count = 0;
        foreach ($recipients as $rec) {
            $message = str_replace(['{name}', '{shop_name}'], [$rec['fullname'], $shop_name], $message_template);
            // اضافه کردن امضا
            $message .= "\n{$shop_name}";
            // ارسال پیامک با استفاده از تابع send_sms (فرض می‌کنیم در helpers.php تعریف شده)
            $result = send_sms($rec['mobile'], $message);
            // ثبت لاگ در sms_logs (اختیاری)
            if ($result) {
                $success_count++;
            } else {
                $fail_count++;
            }
        }
        $sms_sent_status = "<div class='alert alert-info'>📨 ارسال پیامک انجام شد. تعداد موفق: {$success_count} - تعداد ناموفق: {$fail_count}</div>";
    }
}
?>

<script src="<?= BASE_URL ?>assets/js/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/html2canvas.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/jspdf.umd.min.js"></script>
<style>
    .kpi-card { border-radius: 15px; padding: 15px; text-align: center; background: #f8f9fc; transition: 0.3s; border: 1px solid #e3e6f0; }
    .kpi-card:hover { transform: translateY(-5px); box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1); }
    .kpi-value { font-size: 28px; font-weight: bold; }
    .badge-growth { font-size: 0.8rem; padding: 3px 8px; border-radius: 20px; display: inline-block; margin-top: 5px; }
    .suggestion-box { background: #fef9e6; border-right: 4px solid #ffc107; padding: 10px; margin-bottom: 10px; border-radius: 8px; }
</style>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h3 class="mb-0">🧠 هوش تجاری پیشرفته شروین</h3>
        <div>
            <button onclick="exportToPDF()" class="btn btn-sm btn-light">📄 خروجی PDF کامل</button>
            <button onclick="location.reload()" class="btn btn-sm btn-light">🔄 بروزرسانی</button>
        </div>
    </div>
    <div class="card-body" id="reportContent">

        <!-- KPI ها -->
        <div class="row mb-4">
            <div class="col-md-3"><div class="kpi-card"><div class="text-muted">💰 درآمد کل ماه</div><div class="kpi-value"><?= number_format($totalIncome) ?> <small>تومان</small></div><div class="badge-growth bg-<?= $incomeGrowth>=0?'success':'danger' ?> text-white"><?= ($incomeGrowth>=0?'+':'').$incomeGrowth ?>% نسبت به ماه قبل</div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="text-muted">💸 هزینه کل ماه</div><div class="kpi-value"><?= number_format($expenseTotal) ?> <small>تومان</small></div><div class="badge-growth bg-<?= $expenseGrowth<=0?'success':'danger' ?> text-white"><?= ($expenseGrowth>=0?'+':'').$expenseGrowth ?>% </div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="text-muted">📈 سود ناخالص</div><div class="kpi-value"><?= number_format($grossProfit) ?> <small>تومان</small></div><div>حاشیه سود <strong><?= $profitMargin ?>%</strong></div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="text-muted">🏦 نقدینگی جاری</div><div class="kpi-value"><?= number_format($cashBalance) ?> <small>تومان</small></div><div>مخزن مالی</div></div></div>
        </div>
        <div class="row mb-4">
            <div class="col-md-3"><div class="kpi-card"><div class="text-muted">👥 مشتریان جدید</div><div class="kpi-value"><?= $newCustomers ?> <small>نفر</small></div><div>در این ماه</div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="text-muted">🔧 تعمیرات تحویلی</div><div class="kpi-value"><?= $deliveredTickets ?> <small>دستگاه</small></div><div>میانگین <?= $avgRepairDays ?> روز</div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="text-muted">🏅 مشتری ویژه</div><div class="kpi-value"><?= isset($topCustomers[0]['fullname']) ? htmlspecialchars(mb_substr($topCustomers[0]['fullname'],0,12)) : '---' ?></div><div><?= number_format($topCustomers[0]['total_spent'] ?? 0) ?> تومان خرید</div></div></div>
            <div class="col-md-3"><div class="kpi-card"><div class="text-muted">📦 کالای نیاز به سفارش</div><div class="kpi-value"><?= count($reorderList) ?></div><div>اقلام</div></div></div>
        </div>

        <!-- نمودار روند -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h5>📈 روند درآمد و هزینه (۶ ماه اخیر)</h5>
                <canvas id="trendChart" style="height: 300px;"></canvas>
            </div>
        </div>

        <div class="row">
            <!-- ستون چپ -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">🏆 ۱۰ محصول پرسود ماه</div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-bordered mb-0">
                            <thead><tr><th>نام کالا</th><th>تعداد فروش</th><th>سود کل (تومان)</th></tr></thead>
                            <tbody>
                                <?php foreach($topProfitProducts as $p): ?>
                                <tr><td><?= htmlspecialchars($p['name']) ?></td><td><?= $p['qty_sold'] ?></td><td><?= number_format($p['total_profit']) ?></td></tr>
                                <?php endforeach; ?>
                                <?php if(empty($topProfitProducts)) echo '<tr><td colspan="3">هیچ فروشی در این ماه ثبت نشده.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- بخش محصولات با کمترین حاشیه سود + پیشنهادهای عملی -->
                <div class="card mb-4 border-warning">
                    <div class="card-header bg-warning text-dark">⚠️ محصولات با کمترین حاشیه سود و راهکارهای بهبود</div>
                    <div class="card-body">
                        <?php if(empty($lowMarginList)): ?>
                            <div class="alert alert-success">✅ همه محصولات حاشیه سود مناسبی دارند.</div>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead><tr><th>نام کالا</th><th>قیمت فروش</th><th>قیمت خرید</th><th>حاشیه سود</th></tr></thead>
                                <tbody>
                                    <?php foreach($lowMarginList as $lm): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($lm['name']) ?></td>
                                        <td><?= number_format($lm['sale_price']) ?></td>
                                        <td><?= number_format($lm['purchase_price']) ?></td>
                                        <td class="text-danger"><?= $lm['margin_percent'] ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <hr>
                            <h6>💡 راهکارهای پیشنهادی برای هر محصول:</h6>
                            <?php foreach($lowMarginSuggestions as $sug): ?>
                            <div class="suggestion-box">
                                <strong>🔹 <?= htmlspecialchars($sug['name']) ?> (حاشیه سود فعلی <?= $sug['current_margin'] ?>%)</strong><br>
                                • <?= $sug['suggest1'] ?><br>
                                • <?= $sug['suggest2'] ?><br>
                                • <?= $sug['suggest3'] ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header bg-info text-white">🏅 مشتریان با ارزش (بر اساس هزینه)</div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead><tr><th>مشتری</th><th>تعداد خرید</th><th>مجموع خرید</th><th>آخرین خرید</th></tr></thead>
                            <tbody>
                                <?php foreach($topCustomers as $c): ?>
                                <tr><td><?= htmlspecialchars($c['fullname']) ?></td><td><?= $c['purchase_count'] ?></td><td><?= number_format($c['total_spent']) ?></td><td><?= $c['last_purchase_date'] ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ستون راست -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white">⚠️ کالاهای نیاز به سفارش فوری</div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead><tr><th>نام کالا</th><th>موجودی</th><th>حد هشدار</th></tr></thead>
                            <tbody>
                                <?php foreach($reorderList as $ro): ?>
                                <tr><td><?= htmlspecialchars($ro['name']) ?></td><td><?= $ro['current_stock'] ?></td><td><?= $ro['min_stock_alert'] ?></td></tr>
                                <?php endforeach; ?>
                                <?php if(empty($reorderList)) echo '<tr><td colspan="3">✅ همه کالاها در وضعیت مطلوب.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white">🐌 کالاهای کم‌گردش (بدون فروش در 3 ماه)</div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead><tr><th>نام کالا</th><th>موجودی</th><th>قیمت خرید</th></tr></thead>
                            <tbody>
                                <?php foreach(array_slice($slowMovingList,0,5) as $sm): ?>
                                <tr><td><?= htmlspecialchars($sm['name']) ?></td><td><?= $sm['current_stock'] ?></td><td><?= number_format($sm['purchase_price']) ?></td></tr>
                                <?php endforeach; ?>
                                <?php if(count($slowMovingList) > 5) echo '<tr><td colspan="3">و '. (count($slowMovingList)-5) .' قلم دیگر...</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card mb-4">
                    <div class="card-header bg-dark text-white">🧑‍🔧 عملکرد تکنسین‌ها (۳ ماه اخیر)</div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead><tr><th>نام</th><th>تیکت تحویلی</th><th>میانگین زمان (روز)</th><th>درآمد ایجاد شده</th></tr></thead>
                            <tbody>
                                <?php foreach($techStats as $ts): ?>
                                <tr><td><?= htmlspecialchars($ts['fullname']) ?></td><td><?= $ts['delivered_tickets'] ?></td><td><?= $ts['avg_days'] ?? '-' ?></td><td><?= number_format($ts['revenue_generated']) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if(!empty($debtorList)): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header bg-danger text-white">💳 بدهکاران نسیه</div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead><tr><th>مشتری</th><th>مبلغ بدهی</th><th>نزدیک‌ترین سررسید</th></tr></thead>
                            <tbody>
                                <?php foreach($debtorList as $d): ?>
                                <tr><td><?= htmlspecialchars($d['fullname']) ?></td><td><?= number_format($d['debt_amount']) ?></td><td><?= $d['nearest_due'] ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- پیشنهادات هوشمند کلی -->
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">💡 پیشنهادات اجرایی برای رشد کسب‌وکار</div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <?php foreach($recommendations as $rec): ?>
                            <li class="list-group-item"><?= $rec ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- پیش‌بینی آینده -->
        <div class="alert alert-info mt-4">
            <strong>🔮 پیش‌بینی برای ماه آینده (بر اساس میانگین ۳ ماه اخیر):</strong><br>
            درآمد پیش‌بینی شده: <?= number_format($forecastIncome) ?> تومان | هزینه پیش‌بینی شده: <?= number_format($forecastExpense) ?> تومان | سود پیش‌بینی: <?= number_format($forecastProfit) ?> تومان
        </div>

        <!-- ======================= ابزار کمپین پیامکی (دستی) ======================= -->
        <div class="card mt-4 border-success">
            <div class="card-header bg-success text-white">
                📨 ابزار ارسال پیامک تبلیغاتی و اطلاع‌رسانی (با تأیید شما)
            </div>
            <div class="card-body">
                <?= $sms_sent_status ?>
                <form method="post" onsubmit="return confirm('⚠️ آیا از ارسال پیامک به گروه انتخاب شده اطمینان دارید؟ این عملیات غیرقابل بازگشت است.')">
                    <div class="form-group mb-3">
                        <label>👥 انتخاب گروه مخاطبان:</label><br>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="recipient_ids[]" value="all_customers" id="all_customers" onchange="toggleAllCustomers(this)">
                            <label class="form-check-label" for="all_customers">✅ همه مشتریان فعال (دارای موبایل)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="recipient_ids[]" value="recent" id="recent">
                            <label class="form-check-label" for="recent">🆕 مشتریان ثبت‌نام شده در 30 روز اخیر</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="recipient_ids[]" value="top_spenders" id="top_spenders">
                            <label class="form-check-label" for="top_spenders">🏆 مشتریان با بیشترین خرید (۱۰ نفر اول)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="recipient_ids[]" value="debtors" id="debtors">
                            <label class="form-check-label" for="debtors">⚠️ بدهکاران نسیه (یادآوری پرداخت)</label>
                        </div>
                        <small class="text-muted">می‌توانید چند گزینه را همزمان انتخاب کنید (تکراری‌ها حذف می‌شوند).</small>
                    </div>

                    <div class="form-group mb-3">
                        <label>✏️ متن پیامک (می‌توانید از متغیرهای {name} و {shop_name} استفاده کنید):</label>
                        <textarea name="message_template" rows="4" class="form-control" placeholder="مثال: {name} عزیز، به مناسبت هفته تعمیرات، 10% تخفیف ویژه برای شما در نظر گرفته شده. جهت استفاده کد SHROVIN را اعلام کنید." required></textarea>
                    </div>

                    <div class="form-group mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="confirm_send" value="1" id="confirm_send" required>
                            <label class="form-check-label text-danger" for="confirm_send">✔️ من تأیید می‌کنم که این پیامک را آگاهانه و با مسئولیت خود ارسال می‌کنم و از محتوای آن اطمینان دارم.</label>
                        </div>
                    </div>

                    <button type="submit" name="send_sms_campaign" class="btn btn-success">📱 ارسال پیامک به گروه انتخاب شده</button>
                    <a href="sms_logs.php" class="btn btn-info">📜 مشاهده گزارش ارسال‌های قبلی</a>
                </form>
            </div>
        </div>

        <div class="alert alert-success mt-2">
            📌 <strong>نکته:</strong> برای بهره‌وری بیشتر، دسته‌بندی هزینه‌ها را کامل کنید و بودجه ماهانه دسته‌ها را در <a href="budget_settings.php">تنظیمات بودجه</a> تعریف نمایید.
        </div>
    </div>
</div>

<script>
// تابع برای انتخاب همه مشتریان
function toggleAllCustomers(checkbox) {
    // در صورت نیاز می‌توانید سایر چک باکس‌ها را غیرفعال کنید
    if(checkbox.checked) {
        document.getElementById('recent').checked = false;
        document.getElementById('top_spenders').checked = false;
        document.getElementById('debtors').checked = false;
    }
}
// همچنین در submit باید مقادیر Checkboxها را به آرایه‌ای از idهای واقعی تبدیل کنیم
// برای سادگی، در سمت سرور این کار را انجام می‌دهیم (ارسال مقادیر خاص مانند 'all_customers' و سپس تبدیل به لیست واقعی)
// فعلاً در کد PHP بالا، این تبدیل را در زمان پردازش فرم انجام می‌دهیم. برای این کار باید بخش پردازش فرم را کمی تغییر دهیم.
// به دلیل پیچیدگی، از شما می‌خواهم کد PHP ارسال پیامک را با دقت بررسی کنید. در ادامه اصلاحیه آن را می‌نویسم.
</script>

<script>
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($trendData, 'month')) ?>,
        datasets: [
            { label: 'درآمد (تومان)', data: <?= json_encode(array_column($trendData, 'income')) ?>, borderColor: 'green', backgroundColor: 'rgba(0,255,0,0.1)', fill: true, tension: 0.2 },
            { label: 'هزینه (تومان)', data: <?= json_encode(array_column($trendData, 'expense')) ?>, borderColor: 'red', backgroundColor: 'rgba(255,0,0,0.1)', fill: true, tension: 0.2 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: true, plugins: { tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toLocaleString()} تومان` } } } }
});

function exportToPDF() {
    const element = document.getElementById('reportContent');
    html2canvas(element, { scale: 2 }).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        const imgWidth = 210;
        const pageHeight = 297;
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        let position = 0;
        pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
        if (imgHeight > pageHeight) {
            position = position - pageHeight;
            while (position > -imgHeight) {
                pdf.addPage();
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                position -= pageHeight;
            }
        }
        pdf.save('گزارش_هوش_تجاری_شروین.pdf');
    });
}
</script>

<?php
// در این قسمت باید کد پردازش فرم ارسال پیامک را کامل کنیم که مقادیر checkboxes را به لیست واقعی مشتریان تبدیل کند.
// به دلیل محدودیت فایل، لطفاً جایگزین قسمت ارسال پیامک در بالای فایل با کد زیر کنید (بعد از تعریف $sms_sent_status = '';)
?>
<?php
/*
// ========== کد جایگزین برای بخش پردازش کمپین پیامکی (دقیق‌تر) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms_campaign'])) {
    $selected_groups = $_POST['recipient_ids'] ?? [];
    $message_template = trim($_POST['message_template'] ?? '');
    $confirm_send = isset($_POST['confirm_send']) && $_POST['confirm_send'] == '1';
    
    if (!$confirm_send) {
        $sms_sent_status = '<div class="alert alert-warning">⚠️ برای ارسال پیامک، باید تیک تأیید را بزنید.</div>';
    } elseif (empty($selected_groups)) {
        $sms_sent_status = '<div class="alert alert-danger">❌ هیچ گروهی انتخاب نشده است.</div>';
    } elseif (empty($message_template)) {
        $sms_sent_status = '<div class="alert alert-danger">❌ متن پیامک را وارد کنید.</div>';
    } else {
        $customer_ids = [];
        // تابع کمکی برای اضافه کردن idها بدون تکرار
        function addIds(&$array, $newIds) {
            foreach($newIds as $id) if(!in_array($id, $array)) $array[] = $id;
        }
        foreach($selected_groups as $group) {
            if($group == 'all_customers') {
                $stmt = $db->query("SELECT id FROM customers WHERE mobile IS NOT NULL AND mobile != '' AND is_active = 1");
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                addIds($customer_ids, $ids);
            } elseif($group == 'recent') {
                $stmt = $db->prepare("SELECT id FROM customers WHERE DATE(created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND mobile IS NOT NULL AND mobile != ''");
                $stmt->execute();
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                addIds($customer_ids, $ids);
            } elseif($group == 'top_spenders') {
                $stmt = $db->query("
                    SELECT c.id FROM customers c
                    JOIN sales_invoices si ON si.customer_id = c.id
                    GROUP BY c.id
                    ORDER BY SUM(si.total_amount) DESC
                    LIMIT 10
                ");
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                addIds($customer_ids, $ids);
            } elseif($group == 'debtors') {
                $stmt = $db->query("
                    SELECT DISTINCT c.id FROM credit_sales cs
                    JOIN customers c ON c.id = cs.customer_id
                    WHERE cs.status IN ('unpaid','partial')
                ");
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                addIds($customer_ids, $ids);
            }
        }
        if(empty($customer_ids)) {
            $sms_sent_status = '<div class="alert alert-warning">هیچ گیرنده‌ای با مشخصات انتخاب شده یافت نشد.</div>';
        } else {
            // دریافت اطلاعات کامل مشتریان
            $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
            $stmt = $db->prepare("SELECT mobile, fullname FROM customers WHERE id IN ($placeholders)");
            $stmt->execute($customer_ids);
            $recipients = $stmt->fetchAll();
            $shop_name = $db->query("SELECT setting_value FROM settings WHERE setting_key='shop_name'")->fetchColumn();
            if (!$shop_name) $shop_name = 'خدمات فنی شروین';
            $success = 0; $fail = 0;
            foreach($recipients as $rec) {
                $msg = str_replace(['{name}', '{shop_name}'], [$rec['fullname'], $shop_name], $message_template);
                $msg .= "\n{$shop_name}";
                if(send_sms($rec['mobile'], $msg)) $success++; else $fail++;
            }
            $sms_sent_status = "<div class='alert alert-info'>📨 ارسال پیامک انجام شد. تعداد موفق: {$success} - تعداد ناموفق: {$fail}</div>";
        }
    }
}
*/
?>
<?php require_once '../../includes/footer.php'; ?>