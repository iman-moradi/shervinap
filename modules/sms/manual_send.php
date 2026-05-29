<?php
$page_title = 'ارسال پیامک دلخواه';
require_once '../../includes/header.php';
require_once '../../includes/SMSManager.php';

if (!has_permission($_SESSION['user_id'], 'settings_manage')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$sms = new SMSManager($db);
$error = '';
$success = '';

// بررسی وجود ستون is_active
try {
    $db->query("SELECT is_active FROM sms_templates LIMIT 1");
    $hasIsActive = true;
} catch (PDOException $e) {
    $hasIsActive = false;
}

// لیست مشتریان
$customers = $db->query("SELECT id, fullname, mobile FROM customers WHERE is_active = 1 AND mobile IS NOT NULL AND mobile != '' ORDER BY fullname");

// دریافت قالب‌ها
if ($hasIsActive) {
    $templates = $db->query("SELECT id, title, content FROM sms_templates WHERE is_active = 1");
} else {
    $templates = $db->query("SELECT id, title, content FROM sms_templates");
}
$templatesList = $templates->fetchAll();

// مقادیر فرم
$recipientType = $_POST['recipient_type'] ?? $_GET['recipient_type'] ?? 'manual';
$manualNumbers = '';
if (isset($_POST['manual_numbers']) && is_string($_POST['manual_numbers'])) {
    $manualNumbers = trim($_POST['manual_numbers']);
} elseif (isset($_GET['manual_numbers']) && is_string($_GET['manual_numbers'])) {
    $manualNumbers = trim($_GET['manual_numbers']);
}
$selectedCustomerIds = isset($_POST['customer_ids']) && is_array($_POST['customer_ids']) ? $_POST['customer_ids'] : [];
$templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : (isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0);
$message = '';

if ($templateId > 0 && empty($_POST['submit_send'])) {
    foreach ($templatesList as $tpl) {
        if ($tpl['id'] == $templateId) {
            $message = $tpl['content'];
            break;
        }
    }
} elseif (isset($_POST['message']) && is_string($_POST['message'])) {
    $message = trim($_POST['message']);
}

// پردازش ارسال
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_send'])) {
    $recipientType = $_POST['recipient_type'] ?? 'manual';
    $message = trim($_POST['message'] ?? '');
    $useTemplate = isset($_POST['use_template']) ? (int)$_POST['template_id'] : 0;
    
    if ($useTemplate) {
        $stmt = $db->prepare("SELECT content FROM sms_templates WHERE id = ?");
        $stmt->execute([$useTemplate]);
        $template = $stmt->fetch();
        if ($template) $message = $template['content'];
    }
    
    $numbers = [];
    if ($recipientType == 'all_customers') {
        $stmt = $db->query("SELECT mobile FROM customers WHERE is_active = 1 AND mobile IS NOT NULL AND mobile != ''");
        while ($row = $stmt->fetch()) $numbers[] = $row['mobile'];
    } elseif ($recipientType == 'selected_customers' && !empty($_POST['customer_ids']) && is_array($_POST['customer_ids'])) {
        $ids = array_map('intval', $_POST['customer_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT mobile FROM customers WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        while ($row = $stmt->fetch()) $numbers[] = $row['mobile'];
    } else {
        $manualNumbersRaw = trim($_POST['manual_numbers'] ?? '');
        $manualNumbersArray = preg_split('/[\n,]+/', $manualNumbersRaw);
        foreach ($manualNumbersArray as $num) {
            $num = trim($num);
            if (!empty($num)) $numbers[] = $num;
        }
    }
    
    $numbers = array_unique($numbers);
    if (empty($numbers)) {
        $error = 'حداقل یک شماره مقصد انتخاب کنید.';
    } elseif (empty($message)) {
        $error = 'متن پیامک را وارد کنید.';
    } else {
        $result = $sms->send($numbers, $message);
        if ($result['success']) {
            $stmt = $db->prepare("INSERT INTO sms_logs (mobile, message, sent_at, status, provider_ref_id) VALUES (?, ?, NOW(), 'sent', ?)");
            foreach ($numbers as $num) {
                $stmt->execute([$num, $message, $result['message_id'] ?? null]);
            }
            $success = "پیامک با موفقیت به " . count($numbers) . " نفر ارسال شد.";
        } else {
            $error = '<div class="alert alert-danger" dir="ltr" style="font-family:monospace; white-space:pre-wrap; max-height:300px; overflow:auto;">';
            $error .= '<strong>❌ خطا در ارسال:</strong><br>';
            $error .= nl2br(htmlspecialchars($result['error']));
            if (!empty($result['raw_response'])) {
                $error .= '<hr><strong>پاسخ خام سرور:</strong><br>';
                $error .= htmlspecialchars($result['raw_response']);
            }
            $error .= '</div>';
        }
    }
}
?>
<div class="card">
    <div class="card-header">ارسال پیامک دلخواه</div>
    <div class="card-body">
        <?php if ($error): ?><?= $error ?><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
        
        <?php if (!$sms->isAvailable()): ?>
            <div class="alert alert-warning">⚠️ سرویس پیامک فعال نیست. لطفاً ابتدا در <a href="../settings/general.php">تنظیمات عمومی</a> کلید API را وارد کرده و سرویس را فعال کنید.</div>
        <?php endif; ?>
        
        <form method="post" id="smsForm">
            <div class="mb-3">
                <label>نوع گیرنده</label>
                <select name="recipient_type" id="recipient_type" class="form-control" onchange="this.form.submit()">
                    <option value="manual" <?= $recipientType == 'manual' ? 'selected' : '' ?>>ورود دستی شماره‌ها</option>
                    <option value="selected_customers" <?= $recipientType == 'selected_customers' ? 'selected' : '' ?>>انتخاب از بین مشتریان</option>
                    <option value="all_customers" <?= $recipientType == 'all_customers' ? 'selected' : '' ?>>همه مشتریان فعال</option>
                </select>
            </div>
            
            <div id="manual_numbers_div" style="<?= $recipientType == 'manual' ? 'display:block' : 'display:none' ?>" class="mb-3">
                <label>شماره‌های مقصد (هر شماره در یک خط یا با کاما جدا کنید)</label>
                <textarea name="manual_numbers" class="form-control" rows="3" placeholder="09123456789&#10;09123456788"><?= htmlspecialchars($manualNumbers) ?></textarea>
            </div>
            
            <div id="selected_customers_div" style="<?= $recipientType == 'selected_customers' ? 'display:block' : 'display:none' ?>" class="mb-3">
                <label>انتخاب مشتریان (Ctrl+چندگانه)</label>
                <select name="customer_ids[]" multiple class="form-control" size="6">
                    <?php 
                    $customers = $db->query("SELECT id, fullname, mobile FROM customers WHERE is_active = 1 AND mobile IS NOT NULL AND mobile != '' ORDER BY fullname");
                    while($c = $customers->fetch()): 
                        $selected = in_array($c['id'], $selectedCustomerIds) ? 'selected' : '';
                    ?>
                        <option value="<?= $c['id'] ?>" <?= $selected ?>><?= htmlspecialchars($c['fullname']) ?> - <?= $c['mobile'] ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="mb-3">
                <label>استفاده از قالب آماده</label>
                <select name="template_id" id="template_id" class="form-control" onchange="this.form.submit()">
                    <option value="0">-- بدون قالب --</option>
                    <?php foreach($templatesList as $tpl): ?>
                        <option value="<?= $tpl['id'] ?>" <?= $templateId == $tpl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($tpl['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="use_template" value="0">
            </div>
            
            <div class="mb-3">
                <label>متن پیامک</label>
                <textarea name="message" id="message" class="form-control" rows="6" required placeholder="متن پیام خود را وارد کنید..."><?= htmlspecialchars($message) ?></textarea>
                <small><span id="charCount">0</span> کاراکتر - <span id="smsCount">0</span> پیامک (هر پیامک فارسی 70 کاراکتر)</small>
            </div>
            
            <button type="submit" name="submit_send" class="btn btn-primary" <?= !$sms->isAvailable() ? 'disabled' : '' ?>>📨 ارسال پیامک</button>
        </form>
    </div>
</div>

<script>
function updateCharCount() {
    let len = document.getElementById('message').value.length;
    let smsCount = Math.ceil(len / 70);
    document.getElementById('charCount').innerText = len;
    document.getElementById('smsCount').innerText = smsCount;
}
document.getElementById('message').addEventListener('input', updateCharCount);
updateCharCount();
</script>
<?php require_once '../../includes/footer.php'; ?>