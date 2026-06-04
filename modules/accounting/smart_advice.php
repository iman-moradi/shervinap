<?php
$page_title = '🧠 هوش مالی پیشرفته - تحلیل و پیشنهادات';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'accounting_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ---------- بررسی و ارسال خودکار هشدار پیامکی (رشد هزینه بیش از 50%) ----------
$check_key = 'last_expense_growth_alert_sent';
$last_alert_date = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?")->execute([$check_key]);
$last_alert_date = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
$last_alert_date->execute([$check_key]);
$last_alert_date = $last_alert_date->fetchColumn();

// محاسبه رشد هزینه بین ماه جاری و ماه قبل
$currentMonthStart = jdate('Y/m/01');
$currentMonthEnd   = jdate('Y/m/t');
$lastMonthStart    = jdate('Y/m/01', strtotime('-1 month'));
$lastMonthEnd      = jdate('Y/m/t', strtotime('-1 month'));

$expenseNow = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND transaction_date_sh BETWEEN ? AND ?");
$expenseNow->execute([$currentMonthStart, $currentMonthEnd]);
$expenseNow = $expenseNow->fetchColumn();

$expenseBefore = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND transaction_date_sh BETWEEN ? AND ?");
$expenseBefore->execute([$lastMonthStart, $lastMonthEnd]);
$expenseBefore = $expenseBefore->fetchColumn();

$growthPercent = ($expenseBefore > 0) ? (($expenseNow - $expenseBefore) / $expenseBefore * 100) : 0;

// اگر رشد بیش از 50% باشد و امروز هنوز هشدار ارسال نشده (هر روز یکبار)
$today = jdate('Ymd');
if ($growthPercent > 50 && ($last_alert_date != $today)) {
    // دریافت شماره مدیر از تنظیمات
    $adminMobile = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'admin_mobile'")->execute();
    $adminMobile = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'admin_mobile'");
    $adminMobile->execute();
    $adminMobile = $adminMobile->fetchColumn();
    if ($adminMobile) {
        $msg = "هشدار مالی: هزینه‌های این ماه نسبت به ماه قبل {$growthPercent}% افزایش یافته. لطفاً بررسی کنید.";
        send_sms($adminMobile, $msg); // تابع send_sms در helpers.php شما موجود است
    }
    // ثبت تاریخ ارسال هشدار
    $db->prepare("INSERT INTO settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'general') ON DUPLICATE KEY UPDATE setting_value = ?")
       ->execute([$check_key, $today, $today]);
}

// ---------- توابع کمکی ----------
function getPersianMonthName($monthNumber) {
    $monthNumber = (int)$monthNumber;
    // اگر عدد ماه بین 1 تا 12 نبود، ماه جاری را به عنوان پیش‌فرض بگیر (یا فروردین)
    if ($monthNumber < 1 || $monthNumber > 12) {
        $monthNumber = (int)jdate('n'); // ماه جاری
    }
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return $months[$monthNumber - 1];
}

// ---------- محاسبه شاخص‌های اصلی ----------
$incomeCurrent = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND transaction_date_sh BETWEEN ? AND ?");
$incomeCurrent->execute([$currentMonthStart, $currentMonthEnd]);
$incomeCurrent = $incomeCurrent->fetchColumn();

$expenseCurrent = $expenseNow; // از قبل داریم

$profitCurrent = $incomeCurrent - $expenseCurrent;
$profitMargin = ($incomeCurrent > 0) ? round(($profitCurrent / $incomeCurrent) * 100, 1) : 0;

// درآمد و هزینه ماه قبل
$incomeLast = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND transaction_date_sh BETWEEN ? AND ?");
$incomeLast->execute([$lastMonthStart, $lastMonthEnd]);
$incomeLast = $incomeLast->fetchColumn();

$incomeChange = ($incomeLast > 0) ? round(($incomeCurrent - $incomeLast) / $incomeLast * 100, 1) : ($incomeCurrent > 0 ? 100 : 0);
$expenseChange = ($expenseBefore > 0) ? round(($expenseCurrent - $expenseBefore) / $expenseBefore * 100, 1) : ($expenseCurrent > 0 ? 100 : 0);
$avgDailyExpense = ($daysInMonth = jdate('t')) > 0 ? round($expenseCurrent / $daysInMonth) : 0;

// ---------- تحلیل دسته‌بندی هزینه با بودجه ----------
$categoryAnalysis = [];
$stmt = $db->prepare("
    SELECT c.id, c.name, c.monthly_budget,
           COALESCE(SUM(t.amount),0) as current_month_total,
           COALESCE(SUM(CASE WHEN t.transaction_date_sh BETWEEN ? AND ? THEN t.amount ELSE 0 END),0) as last_month_total
    FROM expense_categories c
    LEFT JOIN transactions t ON t.category_id = c.id AND t.type='expense'
    GROUP BY c.id
");
$stmt->execute([$lastMonthStart, $lastMonthEnd]);
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

$topExpensive = [];
$budgetAlerts = [];
foreach ($cats as $cat) {
    $current = $cat['current_month_total'];
    $last = $cat['last_month_total'];
    $growth = ($last > 0) ? round(($current - $last) / $last * 100, 1) : ($current > 0 ? 100 : 0);
    $share = ($expenseCurrent > 0) ? round($current / $expenseCurrent * 100, 1) : 0;
    $budget = (int)$cat['monthly_budget'];
    $budgetPercent = ($budget > 0) ? round(($current / $budget) * 100, 1) : 0;
    
    $categoryAnalysis[] = [
        'name' => $cat['name'],
        'current' => $current,
        'last' => $last,
        'growth' => $growth,
        'share' => $share,
        'budget' => $budget,
        'budget_percent' => $budgetPercent
    ];
    
    if ($share > 20) $topExpensive[] = ['name' => $cat['name'], 'share' => $share, 'current' => $current];
    if ($budget > 0 && $current > $budget) {
        $budgetAlerts[] = "⚠️ دسته «{$cat['name']}» از بودجه ماهانه خود (".number_format($budget)." تومان) فراتر رفته و هم‌اکنون ".number_format($current)." تومان ({$budgetPercent}%) هزینه شده است.";
    }
}

// ---------- هزینه‌های غیرعادی و تکراری ----------
$avgMonthlyExpenseOverall = ($expenseCurrent > 0) ? $expenseCurrent / max(1, $daysInMonth) * 30 : 0;
$threshold = $avgMonthlyExpenseOverall * 1.5;
$unusualExpenses = $db->prepare("
    SELECT description, amount, transaction_date_sh, category_id 
    FROM transactions WHERE type='expense' AND amount > ? AND transaction_date_sh >= ?
    ORDER BY amount DESC LIMIT 5
");
$unusualExpenses->execute([$threshold, $currentMonthStart]);
$unusualList = $unusualExpenses->fetchAll();

$duplicateExpenses = $db->prepare("
    SELECT description, amount, COUNT(*) as cnt, MIN(transaction_date_sh) as first_date, MAX(transaction_date_sh) as last_date
    FROM transactions WHERE type='expense' AND transaction_date_sh >= ?
    GROUP BY description, amount
    HAVING cnt >= 2 AND DATEDIFF(STR_TO_DATE(last_date, '%Y/%m/%d'), STR_TO_DATE(first_date, '%Y/%m/%d')) <= 7
    ORDER BY cnt DESC LIMIT 3
");
$duplicateExpenses->execute([$currentMonthStart]);
$duplicateList = $duplicateExpenses->fetchAll();

// ---------- تولید پیشنهادات هوشمند ----------
$suggestions = [];

if ($incomeCurrent == 0) {
    $suggestions[] = "💡 هیچ درآمدی ثبت نشده. لطفاً فروش‌ها را وارد کنید.";
} else {
    if ($profitMargin < 15) $suggestions[] = "💰 حاشیه سود کمتر از 15% است. افزایش قیمت یا کاهش هزینه‌ها را بررسی کنید.";
    if ($expenseCurrent > $incomeCurrent * 0.7) $suggestions[] = "⚠️ هزینه‌ها بیش از 70% درآمد را می‌بلعد. روی دسته‌های بزرگ تمرکز کنید.";
}

foreach ($topExpensive as $t) {
    if (strpos($t['name'], 'قبوض') !== false) $suggestions[] = "💡 هزینه قبوض بالاست. نصب تایمر، لامپ LED و کاهش مصرف می‌تواند تا 30% صرفه‌جویی کند.";
    elseif (strpos($t['name'], 'اجاره') !== false) $suggestions[] = "🏢 هزینه اجاره سهم بالایی دارد. مذاکره یا جابجایی را بررسی کنید.";
    elseif (strpos($t['name'], 'خوراک') !== false) $suggestions[] = "🍽 هزینه خوراک بالا. خرید عمده یا استفاده از تخفیف‌ها را امتحان کنید.";
    elseif (strpos($t['name'], 'حمل') !== false) $suggestions[] = "⛽ هزینه حمل و نقل بالا. خودروی کم‌مصرف یا مذاکره با باربری می‌تواند کمک کند.";
}

foreach ($categoryAnalysis as $cat) {
    if ($cat['growth'] > 40 && $cat['current'] > 500000)
        $suggestions[] = "📈 هزینه «{$cat['name']}» نسبت به ماه قبل {$cat['growth']}% رشد داشته. علت را بررسی کنید.";
    if ($cat['budget'] > 0 && $cat['budget_percent'] > 100)
        $suggestions[] = "⚠️ دسته «{$cat['name']}» از بودجه تعیین‌شده فراتر رفته. مصرف خود را مدیریت کنید.";
}

foreach ($duplicateList as $dup) {
    if ($dup['cnt'] >= 3)
        $suggestions[] = "🔄 شما {$dup['cnt']} بار مبلغ " . number_format($dup['amount']) . " تومان با عنوان «{$dup['description']}» هزینه کرده‌اید. آیا ضروری است؟";
}

$customerCount = $db->query("SELECT COUNT(*) FROM customers WHERE type='customer'")->fetchColumn();
if ($customerCount < 20 && $incomeCurrent > 0)
    $suggestions[] = "📢 تعداد مشتریان کم است. از پیامک تبلیغاتی استفاده کنید.";

$invoiceCount = $db->prepare("SELECT COUNT(*) FROM sales_invoices WHERE invoice_date_sh BETWEEN ? AND ?");
$invoiceCount->execute([$currentMonthStart, $currentMonthEnd]);
if ($invoiceCount->fetchColumn() < 5 && $incomeCurrent > 0)
    $suggestions[] = "📦 تعداد فاکتورهای فروش کم است. تخفیف یا تبلیغات بیشتر دهید.";

$lowStockCount = $db->query("SELECT COUNT(*) FROM products WHERE current_stock <= min_stock_alert AND min_stock_alert > 0")->fetchColumn();
if ($lowStockCount > 0)
    $suggestions[] = "⚠️ {$lowStockCount} قلم کالا موجودی آن به حد مجاز رسیده. سفارش خرید بدهید.";

// شبیه‌ساز
$billsTotal = 0;
foreach ($categoryAnalysis as $cat) if (strpos($cat['name'], 'قبوض') !== false) $billsTotal += $cat['current'];
if ($billsTotal > 0) {
    $saving = round($billsTotal * 0.10);
    $newProfit = $profitCurrent + $saving;
    $suggestions[] = "🔮 اگر 10% از هزینه قبوض (حدود ".number_format($saving)." تومان) کم شود، سود ماهانه به ".number_format($newProfit)." تومان می‌رسد.";
}

if (count($suggestions) == 0) $suggestions[] = "✅ همه چیز در وضعیت مطلوب است. به ثبت دقیق ادامه دهید.";

// ---------- داده‌های نمودار ۶ ماهه (بدون خطا) ----------
$chartMonths = [];
$chartIncome = [];
$chartExpense = [];

// استفاده از DateTime برای جلوگیری از خطای strtotime
$date = new DateTime();
for ($i = 5; $i >= 0; $i--) {
    $interval = new DateInterval('P' . $i . 'M');
    $targetDate = clone $date;
    $targetDate->sub($interval);
    
    $timestamp = $targetDate->getTimestamp();
    $monthStart = jdate('Y/m/01', $timestamp);
    $monthEnd   = jdate('Y/m/t', $timestamp);
    $monthNumber = (int)jdate('n', $timestamp);
    
    // اطمینان از معتبر بودن عدد ماه
    if ($monthNumber < 1 || $monthNumber > 12) {
        $monthNumber = 1;
    }
    $monthName = getPersianMonthName($monthNumber);
    
    $incQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND transaction_date_sh BETWEEN ? AND ?");
    $incQ->execute([$monthStart, $monthEnd]);
    $expQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND transaction_date_sh BETWEEN ? AND ?");
    $expQ->execute([$monthStart, $monthEnd]);
    
    $chartMonths[] = $monthName;
    $chartIncome[] = $incQ->fetchColumn();
    $chartExpense[] = $expQ->fetchColumn();
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- برای PDF از html2canvas و jspdf استفاده می‌کنیم (ساده‌تر) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h3 class="mb-0">🧠 هوش مالی پیشرفته</h3>
        <div>
            <a href="budget_settings.php" class="btn btn-sm btn-light">🎯 تنظیم بودجه دسته‌ها</a>
            <button onclick="exportToPDF()" class="btn btn-sm btn-light">📄 خروجی PDF</button>
        </div>
    </div>
    <div class="card-body" id="reportContent">
        <!-- KPI ها -->
        <div class="row mb-4 text-center">
            <div class="col-md-3"><div class="alert alert-success">💰 درآمد این ماه<br><b><?= number_format($incomeCurrent) ?></b> تومان<br><small class="badge bg-<?= $incomeChange>=0?'success':'danger' ?>"><?= ($incomeChange>=0?'+':'').$incomeChange ?>%</small></div></div>
            <div class="col-md-3"><div class="alert alert-danger">💸 هزینه این ماه<br><b><?= number_format($expenseCurrent) ?></b> تومان<br><small class="badge bg-<?= $expenseChange<=0?'success':'danger' ?>"><?= ($expenseChange>=0?'+':'').$expenseChange ?>%</small></div></div>
            <div class="col-md-3"><div class="alert alert-warning">📊 سود ناخالص<br><b><?= number_format($profitCurrent) ?></b> تومان<br><small>حاشیه سود <?= $profitMargin ?>%</small></div></div>
            <div class="col-md-3"><div class="alert alert-info">📅 میانگین هزینه روزانه<br><b><?= number_format($avgDailyExpense) ?></b> تومان</div></div>
        </div>

        <!-- نمودار -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h5>📈 روند درآمد و هزینه (۶ ماه اخیر)</h5>
                <canvas id="trendChart" style="height: 300px;"></canvas>
            </div>
        </div>

        <!-- جدول دسته‌بندی هزینه با بودجه -->
        <div class="row">
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">📂 هزینه بر اساس دسته (ماه جاری)</div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-bordered mb-0">
                            <thead><tr><th>دسته</th><th>مبلغ</th><th>سهم</th><th>رشد</th><th>بودجه</th><th>مصرف</th></tr></thead>
                            <tbody>
                                <?php foreach ($categoryAnalysis as $cat): if($cat['current']==0 && $cat['last']==0) continue; ?>
                                <tr>
                                    <td><?= htmlspecialchars($cat['name']) ?></td>
                                    <td class="text-start"><?= number_format($cat['current']) ?></td>
                                    <td><?= $cat['share'] ?>%</td>
                                    <td class="<?= $cat['growth']>20?'text-danger':($cat['growth']<-10?'text-success':'') ?>"><?= ($cat['growth']>0?'+':'').$cat['growth'] ?>%</td>
                                    <td><?= $cat['budget']>0?number_format($cat['budget']):'-' ?></td>
                                    <td><?= $cat['budget']>0?$cat['budget_percent'].'%':'-' ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header bg-light">💡 پیشنهادات هوشمند</div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($suggestions as $sug): ?>
                            <li class="list-group-item"><?= $sug ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php if(count($budgetAlerts)): ?>
                <div class="alert alert-warning mt-3"><strong>⚠️ هشدارهای بودجه‌ای</strong><ul><?php foreach($budgetAlerts as $a): ?><li><?= $a ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <?php if(count($unusualList)): ?>
                <div class="alert alert-secondary mt-3"><strong>⚠️ هزینه‌های غیرعادی</strong><ul><?php foreach($unusualList as $u): ?><li><?= htmlspecialchars($u['description']) ?> - <?= number_format($u['amount']) ?> تومان (<?= $u['transaction_date_sh'] ?>)</li><?php endforeach; ?></ul></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="alert alert-success mt-4">📌 نکته: برای بهبود دقت، همه تراکنش‌ها را دسته‌بندی کنید و بودجه ماهانه را در صفحه «تنظیم بودجه» تعریف نمایید.</div>
    </div>
</div>

<script>
// رسم نمودار
const ctx = document.getElementById('trendChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chartMonths) ?>,
        datasets: [
            { label: 'درآمد (تومان)', data: <?= json_encode($chartIncome) ?>, borderColor: 'green', backgroundColor: 'rgba(0,255,0,0.1)', fill: true, tension: 0.2 },
            { label: 'هزینه (تومان)', data: <?= json_encode($chartExpense) ?>, borderColor: 'red', backgroundColor: 'rgba(255,0,0,0.1)', fill: true, tension: 0.2 }
        ]
    },
    options: { responsive: true, maintainAspectRatio: true, plugins: { tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw.toLocaleString()} تومان` } } } }
});

// تابع خروجی PDF با استفاده از html2canvas + jspdf
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
        pdf.save('گزارش_هوش_مالی.pdf');
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>