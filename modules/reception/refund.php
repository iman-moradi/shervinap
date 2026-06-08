<?php
$page_title = 'ãÑÌæÚí æ ÈÑÔÊ æÌå';
require_once '../../includes/header.php';
require_once '../../includes/date_helper.php';

if (!has_permission($_SESSION['user_id'], 'reception_access')) {
    echo '<div class="alert alert-danger">ÏÓÊÑÓí äÏÇÑíÏ.</div>';
    require_once '../../includes/footer.php';
    exit;
}

$ticket_id = (int)($_GET['id'] ?? 0);
if (!$ticket_id) {
    header('Location: index.php');
    exit;
}

// ÏÑíÇİÊ ÇØáÇÚÇÊ Êí˜Ê Èå åãÑÇå ãÈÇáÛ
$stmt = $db->prepare("
    SELECT r.*, c.fullname, c.mobile 
    FROM repair_tickets r 
    JOIN customers c ON c.id = r.customer_id 
    WHERE r.id = ?
");
$stmt->execute([$ticket_id]);
$ticket = $stmt->fetch();
if (!$ticket) {
    echo '<div class="alert alert-danger">Êí˜Ê íÇİÊ äÔÏ.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ÈÑÑÓí æÖÚíÊ: ÇÑ ŞÈáÇğ áÛæ ÔÏå íÇ ÊÍæíá ÔÏå¡ ÇÌÇÒå ÈÑÔÊ äÏå
if ($ticket['status'] == 'canceled') {
    echo '<div class="alert alert-warning">Çíä Êí˜Ê ŞÈáÇğ áÛæ ÔÏå ÇÓÊ.</div>';
    require_once '../../includes/footer.php';
    exit;
}
if ($ticket['status'] == 'delivered') {
    echo '<div class="alert alert-warning">ÏÓÊÇå ÊÍæíá ÏÇÏå ÔÏå æ ŞÇÈá ÈÑÔÊ äíÓÊ.</div>';
    require_once '../../includes/footer.php';
    exit;
}

// ãÍÇÓÈå ãÈáÛ ŞÇÈá ÈÑÔÊ (˜á ãÈÇáÛ ÏÑíÇİÊí ÇÒ ãÔÊÑí)
$total_paid = $ticket['deposit'] + $ticket['paid_amount'];
if ($total_paid <= 0) {
    echo '<div class="alert alert-info">åí ãÈáÛí ÇÒ ãÔÊÑí ÏÑíÇİÊ äÔÏå ÇÓÊ. İŞØ áÛæ Êí˜Ê ˜ÇİíÓÊ.</div>';
    // ÏÑ ÇíäÌÇ ãíÊæÇä İŞØ æÖÚíÊ ÑÇ áÛæ ˜ÑÏ ÈÏæä ËÈÊ ÊÑÇ˜äÔ ãÇáí
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_only'])) {
        $db->prepare("UPDATE repair_tickets SET status = 'canceled', refund_date_sh = ? WHERE id = ?")
           ->execute([now_jalali(), $ticket_id]);
        echo '<meta http-equiv="refresh" content="2;url=view.php?id='.$ticket_id.'">';
        exit;
    }
    // äãÇíÔ İÑã ÓÇÏå ÈÑÇí áÛæ ÈÏæä ÈÑÔÊ æÌå
    ?>
    <div class="modern-card">
        <div class="card-header-custom"><i class="fas fa-ban"></i> áÛæ Êí˜Ê ÈÏæä ÈÑÔÊ æÌå</div>
        <div class="card-body">
            <p>åí æÌåí ÏÑíÇİÊ äÔÏå¡ İŞØ æÖÚíÊ Êí˜Ê áÛæ ãíÔæÏ.</p>
            <form method="post">
                <input type="hidden" name="cancel_only" value="1">
                <button type="submit" class="btn btn-danger">áÛæ Êí˜Ê</button>
                <a href="view.php?id=<?= $ticket_id ?>" class="btn btn-secondary">ÈÇÒÔÊ</a>
            </form>
        </div>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

// ÏÑíÇİÊ áíÓÊ ÍÓÇÈåÇí İÚÇá ÈÑÇí ÇäÊÎÇÈ ÍÓÇÈí ˜å æÌå ÇÒ Âä ÈÑÔÊ ÏÇÏå ãíÔæÏ
$accounts = $db->query("SELECT id, account_name FROM accounts WHERE account_type IN ('cash','bank_card') ORDER BY account_name")->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund'])) {
    $refund_amount = (int)$_POST['refund_amount'];
    $account_id = (int)$_POST['account_id'];
    $refund_date_sh = $_POST['refund_date_sh'];
    $description = trim($_POST['description'] ?? 'ÈÑÔÊ æÌå ÈÇÈÊ áÛæ ÊÚãíÑ');
    
    if ($refund_amount <= 0) {
        $error = "ãÈáÛ ÈÑÔÊí ÈÇíÏ ÈíÔÊÑ ÇÒ ÕİÑ ÈÇÔÏ.";
    } elseif ($refund_amount > $total_paid) {
        $error = "ãÈáÛ ÈÑÔÊí ÈíÔÊÑ ÇÒ ãÈáÛ ˜á ÏÑíÇİÊí ÇÓÊ.";
    } else {
        $db->beginTransaction();
        try {
            // ËÈÊ ÊÑÇ˜äÔ ÎÑÌ (expense) ÈÑÇí ÈÑÔÊ æÌå
            $trans_sql = "INSERT INTO transactions 
                          (transaction_date_sh, account_id, amount, type, ref_type, ref_id, description, created_by)
                          VALUES (?, ?, ?, 'expense', 'repair_refund', ?, ?, ?)";
            $trans_stmt = $db->prepare($trans_sql);
            $trans_stmt->execute([$refund_date_sh, $account_id, $refund_amount, $ticket_id, $description, $_SESSION['user_id']]);
            
            // ˜ÇåÔ ãæÌæÏí ÍÓÇÈ
            $upd_acc = $db->prepare("UPDATE accounts SET current_balance = current_balance - ? WHERE id = ?");
            $upd_acc->execute([$refund_amount, $account_id]);
            
            // ÈåÑæÒÑÓÇäí ÌÏæá repair_tickets
            $upd_ticket = $db->prepare("UPDATE repair_tickets 
                                        SET status = 'canceled', refund_amount = ?, refund_date_sh = ? 
                                        WHERE id = ?");
            $upd_ticket->execute([$refund_amount, $refund_date_sh, $ticket_id]);
            
            // (ÇÎÊíÇÑí) ÈÇÒÑÏÇäÏä ãæÌæÏí ŞØÚÇÊ ãÕÑİí Èå ÇäÈÇÑ
            // ÏÑ ÕæÑÊí ˜å äíÇÒ ÇÓÊ ŞØÚÇÊí ˜å ÇÒ ÇäÈÇÑ ÎÇÑÌ ÔÏåÇäÏ ÈÑÑÏÇäÏå ÔæäÏ¡ Çíä ÈÎÔ ÑÇ İÚÇá ˜äíÏ
            $items = $db->prepare("SELECT * FROM repair_items WHERE ticket_id = ? AND item_type = 'part' AND product_id IS NOT NULL");
            $items->execute([$ticket_id]);
            while ($item = $items->fetch()) {
                // ÈÑÑÏÇäÏä ãæÌæÏí
                $db->prepare("UPDATE products SET current_stock = current_stock + ? WHERE id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
                // ËÈÊ í˜ ÍÑ˜Ê æÑæÏí ÏÑ stock_movements
                $db->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, price, ref_type, ref_id) 
                              VALUES (?, 'in', ?, ?, 'refund_repair', ?)")
                   ->execute([$item['product_id'], $item['quantity'], $item['unit_price'], $ticket_id]);
            }
            
            $db->commit();
            $success = "ãÑÌæÚí ÈÇ ãæİŞíÊ ËÈÊ ÔÏ. ãÈáÛ ÈÑÔÊí: " . number_format($refund_amount) . " ÊæãÇä";
            echo '<meta http-equiv="refresh" content="2;url=view.php?id='.$ticket_id.'">';
        } catch (Exception $e) {
            $db->rollBack();
            $error = "ÎØÇ ÏÑ ËÈÊ ãÑÌæÚí: " . $e->getMessage();
        }
    }
}
?>

<div class="modern-card">
    <div class="card-header-custom"><i class="fas fa-undo-alt"></i> ÈÑÔÊ æÌå æ ãÑÌæÚí ÏÓÊÇå</div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <table class="table table-bordered">
                    <tr><th>ÔãÇÑå Êí˜Ê</th><td><?= htmlspecialchars($ticket['ticket_no']) ?></td></tr>
                    <tr><th>ãÔÊÑí</th><td><?= htmlspecialchars($ticket['fullname']) ?> - <?= htmlspecialchars($ticket['mobile']) ?></td></tr>
                    <tr><th>ÏÓÊÇå</th><td><?= htmlspecialchars($ticket['device_type'] . ' ' . $ticket['brand']) ?></td></tr>
                    <tr><th>ÈíÚÇäå ÑÏÇÎÊí</th><td><?= number_format($ticket['deposit']) ?> ÊæãÇä</td></tr>
                    <tr><th>ÑÏÇÎÊí ÈÚÏí</th><td><?= number_format($ticket['paid_amount']) ?> ÊæãÇä</td></tr>
                    <tr><th>ÌãÚ ÏÑíÇİÊí</th><td class="fw-bold text-danger"><?= number_format($total_paid) ?> ÊæãÇä</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> ÊæÌå: ÈÇ ËÈÊ ãÑÌæÚí¡ ÊãÇã ŞØÚÇÊ ãÕÑİí Èå ÇäÈÇÑ ÈÇÒÑÏÇäÏå ãíÔæäÏ æ ãæÌæÏí ÍÓÇÈ ˜ÇåÔ ãííÇÈÏ.
                </div>
            </div>
        </div>
        
        <form method="post">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label>ÊÇÑíÎ ÈÑÔÊ</label>
                    <input type="text" name="refund_date_sh" class="form-control" required value="<?= now_jalali() ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label>ãÈáÛ ÈÑÔÊí (ÊæãÇä)</label>
                    <input type="number" name="refund_amount" class="form-control" required value="<?= $total_paid ?>" min="1" max="<?= $total_paid ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label>ÍÓÇÈ ÈÑÔÊ æÌå</label>
                    <select name="account_id" class="form-select" required>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">ÍÓÇÈí ˜å æÌå ÇÒ Âä ÈÑÏÇÔÊ ãíÔæÏ (ãÚãæáÇğ åãÇä ÍÓÇÈí ˜å ÏÑíÇİÊ ÔÏå ÈæÏ)</small>
                </div>
                <div class="col-md-12 mb-3">
                    <label>ÊæÖíÍÇÊ (ÇÎÊíÇÑí)</label>
                    <input type="text" name="description" class="form-control" placeholder="Ïáíá ãÑÌæÚí¡ ÔãÇÑå ãÑÌÚ æ ...">
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" name="refund" class="btn btn-danger" onclick="return confirm('ÂíÇ ÇÒ ÈÑÔÊ æÌå æ áÛæ ÊÚãíÑ ÇØãíäÇä ÏÇÑíÏ¿');">
                    <i class="fas fa-undo-alt"></i> ËÈÊ ãÑÌæÚí æ ÈÑÔÊ æÌå
                </button>
                <a href="view.php?id=<?= $ticket_id ?>" class="btn btn-secondary">ÈÇÒÔÊ</a>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>