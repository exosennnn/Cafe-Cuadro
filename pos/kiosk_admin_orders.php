<?php
/**
 * kiosk_admin_orders.php
 * -----------------------------------------------------------------------
 * POS Kiosk Orders Queue & Cashier Processing Screen.
 * Accessible to both Cashier and Admin / Owner roles.
 * Displays all incoming self-order kiosk transactions in real-time,
 * allows marking orders as "Paid" upon receiving Cash or GCash,
 * and tracks the order lifecycle (Pending Payment -> Paid -> Preparing -> Ready -> Completed).
 */
require 'database.php';
require 'app.php';
require 'kiosk_bootstrap.php';

require_login();
requireModule('pos');

if (!is_admin() && (int)currentRoleId() !== ROLE_CASHIER) {
    accessDenied();
}

$message = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if ($id) {
        $stmt = $conn->prepare("SELECT id, transaction_code, status, total, payment_method FROM transactions WHERE id = ? AND source = 'kiosk'");
        $stmt->execute([$id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $message = "<div class='alert error'>Order not found.</div>";
        } elseif ($action === 'mark_paid') {
            try {
                $conn->beginTransaction();
                $payment_method = in_array($_POST['payment_method'] ?? '', ['cash', 'gcash', 'credit', 'debit'], true)
                    ? $_POST['payment_method']
                    : ($order['payment_method'] ?: 'cash');

                $amount_paid = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_FLOAT);
                if ($amount_paid === false || $amount_paid <= 0) {
                    $amount_paid = (float) $order['total'];
                }
                if ($payment_method === 'cash' && $amount_paid < (float)$order['total']) {
                    throw new Exception('Cash tendered must be at least the order total of ₱' . money($order['total']));
                }

                $change_due = max(0, round($amount_paid - (float)$order['total'], 2));
                $new_status = (!empty($_POST['and_prepare'])) ? 'processing' : 'completed';

                $cashier_id = (int)($_SESSION['user_id'] ?? 0) ?: null;
                $cashier_branch = (int)($_SESSION['branch_id'] ?? 0) ?: null;

                $update_stmt = $conn->prepare("
                    UPDATE transactions 
                    SET status = ?, 
                        user_id = ?, 
                        branch_id = COALESCE(branch_id, ?), 
                        payment_method = ?, 
                        amount_paid = ?, 
                        change_due = ? 
                    WHERE id = ?
                ");
                $update_stmt->execute([$new_status, $cashier_id, $cashier_branch, $payment_method, $amount_paid, $change_due, $id]);

                $conn->commit();
                $label = ($new_status === 'processing') ? 'Paid and moved to Preparation' : 'marked as Paid and recorded as completed sale';
                header("Location: " . BASE_URL . "pos/kiosk-admin-orders?msg=" . urlencode("Order {$order['transaction_code']} {$label}."));
                exit;
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $message = "<div class='alert error'>" . h($e->getMessage()) . "</div>";
            }
        } elseif ($action === 'update_status') {
            $status = $_POST['status'] ?? '';
            if (in_array($status, KIOSK_ORDER_STATUSES, true)) {
                try {
                    $conn->beginTransaction();

                    if ($status === 'cancelled' && $order['status'] !== 'cancelled') {
                        $item_stmt = $conn->prepare("SELECT product_id, quantity FROM transaction_items WHERE transaction_id = ?");
                        $item_stmt->execute([$id]);
                        $restock_stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                        foreach ($item_stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                            $restock_stmt->execute([$line['quantity'], $line['product_id']]);
                        }
                    }

                    $stmt = $conn->prepare("UPDATE transactions SET status = ? WHERE id = ?");
                    $stmt->execute([$status, $id]);

                    $conn->commit();

                    if ($status === 'cancelled') {
                        $item_stmt = $conn->prepare("SELECT product_id FROM transaction_items WHERE transaction_id = ?");
                        $item_stmt->execute([$id]);
                        foreach ($item_stmt->fetchAll(PDO::FETCH_COLUMN) as $product_id) {
                            sync_low_stock_notification_by_product($product_id);
                        }
                    }

                    header("Location: " . BASE_URL . "pos/kiosk-admin-orders?msg=" . urlencode("Order {$order['transaction_code']} updated to " . kiosk_status_label($status) . "."));
                    exit;
                } catch (Exception $e) {
                    if ($conn->inTransaction()) {
                        $conn->rollBack();
                    }
                    $message = "<div class='alert error'>Could not update order: " . h($e->getMessage()) . "</div>";
                }
            }
        }
    }
}

// Counts for KPI tabs
$counts = [
    'all'        => (int) $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'kiosk'")->fetchColumn(),
    'pending'    => (int) $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'kiosk' AND status IN ('pending', 'pending_payment')")->fetchColumn(),
    'paid'       => (int) $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'kiosk' AND status IN ('paid', 'confirmed')")->fetchColumn(),
    'processing' => (int) $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'kiosk' AND status = 'processing'")->fetchColumn(),
    'ready'      => (int) $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'kiosk' AND status = 'ready'")->fetchColumn(),
    'completed'  => (int) $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'kiosk' AND status = 'completed'")->fetchColumn(),
];

$status_filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql = "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS customer_full_name 
        FROM transactions t 
        LEFT JOIN users u ON u.user_id = t.user_id 
        WHERE t.source = 'kiosk'";
$params = [];

if ($status_filter === 'pending') {
    $sql .= " AND t.status IN ('pending', 'pending_payment')";
} elseif ($status_filter === 'paid') {
    $sql .= " AND t.status IN ('paid', 'confirmed')";
} elseif ($status_filter !== '' && in_array($status_filter, KIOSK_ORDER_STATUSES, true)) {
    $sql .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($search !== '') {
    $sql .= " AND (t.transaction_code LIKE ? OR t.customer_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY (t.status IN ('pending', 'pending_payment')) DESC, 
                   (t.status IN ('paid', 'confirmed')) DESC, 
                   (t.status = 'processing') DESC, 
                   (t.status = 'ready') DESC, 
                   t.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Kiosk Orders Queue';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/pos-legacy.css">

<style>
.kiosk-q-header{display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:20px;}
.kiosk-kpi-grid{display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:20px;}
.kiosk-kpi-card{background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:14px 18px; text-decoration:none; color:inherit; transition:all .15s;}
.kiosk-kpi-card:hover{transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,0.06);}
.kiosk-kpi-card.active{border-color:var(--brand-primary, #5e6b46); background:#fbf6ee; box-shadow:0 0 0 1px var(--brand-primary, #5e6b46);}
.kiosk-kpi-num{font-size:26px; font-weight:800; line-height:1.1; margin-bottom:4px;}
.kiosk-kpi-label{font-size:12px; font-weight:700; color:#7a6558; text-transform:uppercase; letter-spacing:0.5px;}

.kiosk-orders-list{display:flex; flex-direction:column; gap:16px;}
.kiosk-order-card{background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:20px; box-shadow:0 4px 14px rgba(0,0,0,0.04); transition:border-color .15s;}
.kiosk-order-card.is-pending{border-left:6px solid #f59e0b;}
.kiosk-order-card.is-paid{border-left:6px solid #3b82f6;}
.kiosk-order-card.is-preparing{border-left:6px solid #8b5cf6;}
.kiosk-order-card.is-ready{border-left:6px solid #10b981;}
.kiosk-order-card.is-completed{border-left:6px solid #7a6558; opacity:0.88;}
.kiosk-order-card.is-cancelled{border-left:6px solid #ef4444; opacity:0.75;}

.kiosk-order-top{display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:14px;}
.kiosk-order-code{font-size:20px; font-weight:800; color:#2a1810; letter-spacing:0.5px;}
.kiosk-order-badges{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
.kiosk-pill{font-size:12px; font-weight:700; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:5px;}
.kiosk-pill-dine{background:#e0f2fe; color:#0369a1;}
.kiosk-pill-takeout{background:#fef3c7; color:#92400e;}
.kiosk-pill-cash{background:#ecfdf5; color:#047857; border:1px solid #a7f3d0;}
.kiosk-pill-gcash{background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;}

.kiosk-badge-pending{background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:800;}
.kiosk-badge-confirmed{background:#dbeafe; color:#1e40af; border:1px solid #bfdbfe; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:800;}
.kiosk-badge-processing{background:#f3e8ff; color:#6b21a8; border:1px solid #e9d5ff; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:800;}
.kiosk-badge-ready{background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:800;}
.kiosk-badge-completed{background:#f3f4f6; color:#3b241a; border:1px solid #e5e7eb; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:800;}
.kiosk-badge-cancelled{background:#fee2e2; color:#991b1b; border:1px solid #fecaca; padding:5px 14px; border-radius:999px; font-size:12px; font-weight:800;}

.kiosk-items-tbl{width:100%; border-collapse:collapse; margin:12px 0; font-size:14px;}
.kiosk-items-tbl th{text-align:left; font-size:12px; font-weight:700; color:#7a6558; padding:6px 8px; border-bottom:1px solid #e5e7eb; background:#f9fafb;}
.kiosk-items-tbl td{padding:8px 8px; border-bottom:1px solid #f3f4f6;}
.kiosk-items-tbl td.num{text-align:right;}

.kiosk-order-bottom{display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-top:14px; padding-top:14px; border-top:1px dashed #e5e7eb;}
.kiosk-total-box{font-size:18px; font-weight:800; color:#2a1810;}
.kiosk-action-bar{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
.kiosk-action-bar button, .kiosk-action-bar a{font-size:13px; font-weight:700; padding:8px 16px; border-radius:10px; cursor:pointer;}

.kiosk-pay-panel{background:#fbf6ee; border:1px solid #cfd8bd; border-radius:14px; padding:14px 16px; margin-top:12px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;}
</style>

<div class="kiosk-q-header">
    <div>
        <h1 class="h3 fw-bold mb-1"><i class="bi bi-tablet me-2" style="color:var(--brand-primary, #5e6b46);"></i>Kiosk Orders Queue</h1>
        <p class="text-muted small mb-0">Incoming self-order kiosk orders. Receive payments, mark as paid, and track kitchen preparation.</p>
    </div>
    <div class="d-flex align-items:center gap-2">
        <label class="form-check form-switch mb-0 small me-2" style="cursor:pointer;">
            <input class="form-check-input" type="checkbox" id="auto-refresh-toggle" checked>
            <span class="form-check-label text-muted">Auto-refresh (15s)</span>
        </label>
        <button type="button" class="button secondary" onclick="window.location.reload();" title="Refresh queue">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <a href="<?= BASE_URL ?>pos/sales" class="button secondary">
            <i class="bi bi-receipt"></i> POS Register
        </a>
    </div>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert success mb-3"><i class="bi bi-check-circle me-1"></i><?= h($_GET['msg']) ?></div>
<?php endif; ?>
<?= $message ?>

<!-- KPI Status Tabs -->
<div class="kiosk-kpi-grid">
    <a href="<?= BASE_URL ?>pos/kiosk-admin-orders" class="kiosk-kpi-card <?= $status_filter === '' ? 'active' : '' ?>">
        <div class="kiosk-kpi-num"><?= $counts['all'] ?></div>
        <div class="kiosk-kpi-label">All Orders</div>
    </a>
    <a href="<?= BASE_URL ?>pos/kiosk-admin-orders?status=pending" class="kiosk-kpi-card <?= $status_filter === 'pending' ? 'active' : '' ?>">
        <div class="kiosk-kpi-num" style="color:#d97706;"><?= $counts['pending'] ?></div>
        <div class="kiosk-kpi-label">Pending Payment</div>
    </a>
    <a href="<?= BASE_URL ?>pos/kiosk-admin-orders?status=paid" class="kiosk-kpi-card <?= $status_filter === 'paid' ? 'active' : '' ?>">
        <div class="kiosk-kpi-num" style="color:#2563eb;"><?= $counts['paid'] ?></div>
        <div class="kiosk-kpi-label">Paid</div>
    </a>
    <a href="<?= BASE_URL ?>pos/kiosk-admin-orders?status=processing" class="kiosk-kpi-card <?= $status_filter === 'processing' ? 'active' : '' ?>">
        <div class="kiosk-kpi-num" style="color:#7c3aed;"><?= $counts['processing'] ?></div>
        <div class="kiosk-kpi-label">Preparing</div>
    </a>
    <a href="<?= BASE_URL ?>pos/kiosk-admin-orders?status=ready" class="kiosk-kpi-card <?= $status_filter === 'ready' ? 'active' : '' ?>">
        <div class="kiosk-kpi-num" style="color:#059669;"><?= $counts['ready'] ?></div>
        <div class="kiosk-kpi-label">Ready for Pickup</div>
    </a>
    <a href="<?= BASE_URL ?>pos/kiosk-admin-orders?status=completed" class="kiosk-kpi-card <?= $status_filter === 'completed' ? 'active' : '' ?>">
        <div class="kiosk-kpi-num" style="color:#4b5563;"><?= $counts['completed'] ?></div>
        <div class="kiosk-kpi-label">Completed</div>
    </a>
</div>

<!-- Search Bar -->
<section class="panel mb-4" style="padding:14px 18px;">
    <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="status" value="<?= h($status_filter) ?>">
        <div style="flex:1; min-width:240px; position:relative;">
            <i class="bi bi-search" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#9ca3af;"></i>
            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search Order Number (e.g. TXN...) or Customer Name..." 
                   style="width:100%; padding:10px 14px 10px 38px; border-radius:10px; border:1px solid #d1d5db; font-size:14px;">
        </div>
        <button type="submit" class="button primary" style="padding:10px 18px;">Search</button>
        <?php if ($search !== '' || $status_filter !== ''): ?>
            <a href="<?= BASE_URL ?>pos/kiosk-admin-orders" class="button secondary" style="padding:10px 16px;">Reset</a>
        <?php endif; ?>
    </form>
</section>

<!-- Orders List -->
<div class="kiosk-orders-list">
    <?php if (!$orders): ?>
        <section class="panel text-center py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2 text-muted opacity-50"></i>
            <h5 class="fw-bold mb-1">No orders found</h5>
            <p class="text-muted small mb-0">No kiosk orders match your current filter.</p>
        </section>
    <?php endif; ?>

    <?php foreach ($orders as $order): ?>
        <?php
            $item_stmt = $conn->prepare("SELECT product_name, price, quantity, subtotal FROM transaction_items WHERE transaction_id = ? ORDER BY id");
            $item_stmt->execute([$order['id']]);
            $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

            $st = $order['status'];
            $cardClass = 'is-pending';
            if (in_array($st, ['paid', 'confirmed'])) $cardClass = 'is-paid';
            elseif ($st === 'processing') $cardClass = 'is-preparing';
            elseif ($st === 'ready') $cardClass = 'is-ready';
            elseif ($st === 'completed') $cardClass = 'is-completed';
            elseif ($st === 'cancelled') $cardClass = 'is-cancelled';

            $pm = strtolower($order['payment_method'] ?? 'cash');
            $pmBadgeClass = ($pm === 'gcash') ? 'kiosk-pill-gcash' : 'kiosk-pill-cash';
            $pmIcon = ($pm === 'gcash') ? '📱' : '💵';
        ?>
        <div class="kiosk-order-card <?= $cardClass ?>" id="order-card-<?= (int)$order['id'] ?>">
            <div class="kiosk-order-top">
                <div>
                    <span class="kiosk-order-code"><?= h($order['transaction_code']) ?></span>
                    <div style="font-size:13px; color:#4b5563; margin-top:3px;">
                        <span><i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', strtotime($order['created_at'])) ?></span>
                        <?php if ($order['customer_name']): ?>
                            <span class="ms-2">· Customer: <strong><?= h($order['customer_name']) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="kiosk-order-badges">
                    <span class="kiosk-pill <?= $order['order_type'] === 'dine_in' ? 'kiosk-pill-dine' : 'kiosk-pill-takeout' ?>">
                        <?= $order['order_type'] === 'dine_in' ? '🍽️ Dine-in' : '🥡 Takeout' ?>
                    </span>
                    <span class="kiosk-pill <?= $pmBadgeClass ?>">
                        <?= $pmIcon ?> <?= h(kiosk_payment_method_label($pm)) ?>
                    </span>
                    <span class="<?= h(kiosk_status_badge_class($st)) ?>">
                        <?= h(kiosk_status_label($st)) ?>
                    </span>
                </div>
            </div>

            <!-- Items Table -->
            <table class="kiosk-items-tbl">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num" style="width:70px;">Price</th>
                        <th class="num" style="width:70px;">Qty</th>
                        <th class="num" style="width:90px;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><strong><?= h($item['product_name']) ?></strong></td>
                        <td class="num">₱<?= money($item['price']) ?></td>
                        <td class="num">× <?= h($item['quantity']) ?></td>
                        <td class="num"><strong>₱<?= money($item['subtotal']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Bottom Row: Total & Action Controls -->
            <div class="kiosk-order-bottom">
                <div class="kiosk-total-box">
                    <span style="font-size:13px; color:#7a6558; font-weight:600; text-transform:uppercase;">Total Due: </span>
                    <span style="font-size:22px; color:var(--brand-primary, #5e6b46);">₱<?= money($order['total']) ?></span>
                </div>

                <div class="kiosk-action-bar">
                    <?php if (in_array($st, ['pending', 'pending_payment'])): ?>
                        <!-- Quick Mark as Paid Button / Toggle -->
                        <button type="button" class="button primary" onclick="togglePaymentForm(<?= (int)$order['id'] ?>)">
                            <i class="bi bi-cash-stack me-1"></i> Receive Payment & Mark Paid
                        </button>
                    <?php elseif (in_array($st, ['paid', 'confirmed'])): ?>
                        <!-- Move to Preparation -->
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?= h($order['id']) ?>">
                            <input type="hidden" name="status" value="processing">
                            <button type="submit" class="button primary" style="background:#7c3aed; border-color:#7c3aed;">
                                <i class="bi bi-fire me-1"></i> 👨‍🍳 Start Preparing
                            </button>
                        </form>
                    <?php elseif ($st === 'processing'): ?>
                        <!-- Mark Ready -->
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?= h($order['id']) ?>">
                            <input type="hidden" name="status" value="ready">
                            <button type="submit" class="button primary" style="background:#059669; border-color:#059669;">
                                <i class="bi bi-bell-fill me-1"></i> 🔔 Mark Ready for Pickup
                            </button>
                        </form>
                    <?php elseif ($st === 'ready'): ?>
                        <!-- Handover & Complete -->
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?= h($order['id']) ?>">
                            <input type="hidden" name="status" value="completed">
                            <button type="submit" class="button primary" style="background:#16a34a; border-color:#16a34a;">
                                <i class="bi bi-check2-circle me-1"></i> ✔ Hand Over & Complete
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- View Official POS Receipt -->
                    <a href="<?= BASE_URL ?>pos/receipt?id=<?= h($order['id']) ?>" target="_blank" class="button secondary" title="View / Print Official Receipt">
                        <i class="bi bi-receipt"></i> Official Receipt
                    </a>

                    <!-- Cancel button if active -->
                    <?php if (!in_array($st, ['completed', 'cancelled'])): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this order? Reserved stock will be restored to inventory.');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="id" value="<?= h($order['id']) ?>">
                            <input type="hidden" name="status" value="cancelled">
                            <button type="submit" class="button secondary" style="color:#dc2626; border-color:#fca5a5;">
                                <i class="bi bi-x-circle"></i> Cancel
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Expandable Payment Processing Panel for Pending Orders -->
            <?php if (in_array($st, ['pending', 'pending_payment'])): ?>
                <div id="payment-panel-<?= (int)$order['id'] ?>" class="kiosk-pay-panel" style="display:none;">
                    <form method="POST" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; width:100%;">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="hidden" name="id" value="<?= h($order['id']) ?>">

                        <div style="display:flex; align-items:center; gap:8px;">
                            <label class="small fw-bold" style="color:#78350f;">Method:</label>
                            <select name="payment_method" class="form-select form-select-sm" style="border-radius:8px; width:110px;" onchange="handlePayMethodChange(this, <?= (int)$order['id'] ?>, <?= (float)$order['total'] ?>)">
                                <option value="cash" <?= $pm === 'cash' ? 'selected' : '' ?>>💵 Cash</option>
                                <option value="gcash" <?= $pm === 'gcash' ? 'selected' : '' ?>>📱 GCash</option>
                            </select>
                        </div>

                        <div style="display:flex; align-items:center; gap:8px;" id="cash-input-wrap-<?= (int)$order['id'] ?>">
                            <label class="small fw-bold" style="color:#78350f;">Tendered (₱):</label>
                            <input type="number" step="0.01" min="<?= (float)$order['total'] ?>" name="amount_paid" 
                                   value="<?= (float)$order['total'] ?>" class="form-control form-control-sm" style="border-radius:8px; width:110px;"
                                   oninput="computeChange(this, <?= (float)$order['total'] ?>, <?= (int)$order['id'] ?>)">
                            <span id="change-disp-<?= (int)$order['id'] ?>" class="small fw-bold text-success">Change: ₱0.00</span>
                        </div>

                        <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
                            <button type="submit" name="and_prepare" value="0" class="button primary" style="background:#16a34a; border-color:#16a34a;">
                                <i class="bi bi-check2"></i> Mark as Paid
                            </button>
                            <button type="submit" name="and_prepare" value="1" class="button primary" style="background:#7c3aed; border-color:#7c3aed;">
                                <i class="bi bi-fire"></i> Paid & Start Preparing
                            </button>
                            <button type="button" class="button secondary" onclick="togglePaymentForm(<?= (int)$order['id'] ?>)">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<script>
function togglePaymentForm(id) {
    var p = document.getElementById('payment-panel-' + id);
    if (!p) return;
    p.style.display = (p.style.display === 'none' || p.style.display === '') ? 'flex' : 'none';
}

function handlePayMethodChange(sel, id, total) {
    var wrap = document.getElementById('cash-input-wrap-' + id);
    if (wrap) {
        wrap.style.display = (sel.value === 'gcash') ? 'none' : 'flex';
    }
}

function computeChange(input, total, id) {
    var paid = parseFloat(input.value) || 0;
    var change = Math.max(0, paid - total);
    var disp = document.getElementById('change-disp-' + id);
    if (disp) {
        disp.textContent = 'Change: ₱' + change.toFixed(2);
    }
}

// Auto-refresh queue every 15s if enabled
(function () {
    var toggle = document.getElementById('auto-refresh-toggle');
    setInterval(function () {
        if (toggle && toggle.checked) {
            // Only auto-refresh if no payment inputs are focused
            var active = document.activeElement;
            if (active && (active.tagName === 'INPUT' || active.tagName === 'SELECT')) {
                return;
            }
            window.location.reload();
        }
    }, 15000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
