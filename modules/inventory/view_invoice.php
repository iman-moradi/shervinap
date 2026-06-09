<?php
$page_title = 'جزئیات فاکتور';
require_once '../../includes/header.php';

if (!has_permission($_SESSION['user_id'], 'inventory_access')) {
    echo '<div class="alert alert-danger">دسترسی ندارید.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$type = $_GET['type'] ?? ''; // sale یا purchase
$id = (int)($_GET['id'] ?? 0);

if (!$id || !in_array($type, ['sale', 'purchase'])) {
    echo '<div class="alert alert-danger">لینک نامعتبر است.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$invoice = null;
$items = [];

if ($type == 'sale') {
    // اطلاعات فاکتور فروش
    $stmt = $db->prepare("SELECT s.*, c.fullname as customer_name 
                          FROM sales_invoices s 
                          LEFT JOIN customers c ON c.id = s.customer_id 
                          WHERE s.id = ?");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    
    if ($invoice) {
        // اقلام فروش
        $stmt_items = $db->prepare("SELECT si.*, p.name as product_name, p.sku 
                                    FROM sales_items si 
                                    JOIN products p ON p.id = si.product_id 
                                    WHERE si.sales_invoice_id = ?");
        $stmt_items->execute([$id]);
        $items = $stmt_items->fetchAll();
    }
} else { // purchase
    $stmt = $db->prepare("SELECT * FROM purchase_invoices WHERE id = ?");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    
    if ($invoice) {
        $stmt_items = $db->prepare("SELECT pi.*, p.name as product_name, p.sku 
                                    FROM purchase_items pi 
                                    JOIN products p ON p.id = pi.product_id 
                                    WHERE pi.purchase_invoice_id = ?");
        $stmt_items->execute([$id]);
        $items = $stmt_items->fetchAll();
    }
}

if (!$invoice) {
    echo '<div class="alert alert-danger">فاکتور مورد نظر یافت نشد.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$remaining = $invoice['total_amount'] - $invoice['paid_amount'];
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <i class="fas fa-receipt"></i> جزئیات فاکتور 
        <?php if ($type == 'sale'): ?>
            فروش - <?= htmlspecialchars($invoice['invoice_no']) ?>
        <?php else: ?>
            خرید - <?= htmlspecialchars($invoice['invoice_no']) ?>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- اطلاعات کلی فاکتور -->
        <div class="row mb-4">
            <div class="col-md-6">
                <table class="table table-bordered table-sm">
                    <?php if ($type == 'sale'): ?>
                        <tr><th>شماره فاکتور</th><td><?= htmlspecialchars($invoice['invoice_no']) ?></td></tr>
                        <tr><th>مشتری</th><td><?= htmlspecialchars($invoice['customer_name'] ?? 'مشتری عمومی') ?></td></tr>
                        <tr><th>تاریخ فاکتور</th><td><?= htmlspecialchars($invoice['invoice_date_sh']) ?></td></tr>
                        <tr><th>حساب دریافت</th><td><?= htmlspecialchars($invoice['account_id']) ?> (<?= $invoice['account_id'] ?>)</td></tr>
                    <?php else: ?>
                        <tr><th>شماره فاکتور</th><td><?= htmlspecialchars($invoice['invoice_no']) ?></td></tr>
                        <tr><th>تأمین‌کننده</th><td><?= htmlspecialchars($invoice['supplier_name']) ?></td></tr>
                        <tr><th>تاریخ فاکتور</th><td><?= htmlspecialchars($invoice['invoice_date_sh']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-bordered table-sm">
                    <tr><th>مبلغ کل</th><td class="fw-bold"><?= number_format($invoice['total_amount']) ?> تومان</td></tr>
                    <tr><th>پرداخت شده</th><td><?= number_format($invoice['paid_amount']) ?> تومان</td></tr>
                    <tr><th>مانده بدهی</th>
                        <td class="<?= $remaining > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
                            <?= number_format($remaining) ?> تومان
                        </td>
                    </tr>
                    <?php if ($type == 'purchase'): ?>
                    <tr><th>وضعیت پرداخت</th>
                        <td>
                            <?php 
                                $status_text = '';
                                if ($invoice['payment_status'] == 'paid') $status_text = 'تسویه شده';
                                elseif ($invoice['payment_status'] == 'partial') $status_text = 'بدهی جزیی';
                                else $status_text = 'پرداخت نشده';
                            ?>
                            <?= $status_text ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- جدول اقلام فاکتور -->
        <h5 class="mt-3">📦 اقلام فاکتور</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead class="table-light">
                    <tr>
                        <th>ردیف</th>
                        <th>کالا</th>
                        <th>کد کالا (SKU)</th>
                        <th>تعداد</th>
                        <th>قیمت واحد (تومان)</th>
                        <th>جمع (تومان)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($items) == 0): ?>
                        <tr><td colspan="6" class="text-center">هیچ آیتمی ثبت نشده است</td></tr>
                    <?php else: ?>
                        <?php $counter = 1; $total_check = 0; ?>
                        <?php foreach ($items as $item): 
                            $total_check += $item['total_price'];
                        ?>
                        <tr>
                            <td><?= $counter++ ?></td>
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td><?= htmlspecialchars($item['sku']) ?></td>
                            <td><?= number_format($item['quantity']) ?></td>
                            <td><?= number_format($item['unit_price']) ?></td>
                            <td><?= number_format($item['total_price']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-secondary">
                            <th colspan="5" class="text-start">جمع کل</th>
                            <th class="fw-bold"><?= number_format($total_check) ?> تومان</th>
                        </tr>
                        <?php if ($total_check != $invoice['total_amount']): ?>
                        <tr class="table-warning">
                            <td colspan="6" class="text-danger">توجه: جمع اقلام با مبلغ کل فاکتور همخوانی ندارد! (<?= number_format($total_check) ?> vs <?= number_format($invoice['total_amount']) ?>)</td>
                        </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-4 d-flex gap-2">
            <?php if ($type == 'sale'): ?>
                <a href="sales_invoices.php?filter=all" class="btn btn-secondary">🔙 بازگشت به لیست فاکتورهای فروش</a>
                <?php if ($remaining > 0): ?>
                    <a href="sale_payment.php?id=<?= $invoice['id'] ?>" class="btn btn-success">💰 ثبت پرداخت جدید</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="purchase_invoices.php?filter=all" class="btn btn-secondary">🔙 بازگشت به لیست فاکتورهای خرید</a>
                <?php if ($remaining > 0): ?>
                    <a href="purchase_payment.php?id=<?= $invoice['id'] ?>" class="btn btn-success">💰 ثبت پرداخت جدید</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>