<?php
require 'database.php';
require 'app.php';
require_login();
// H1 fix: this is the main cashier checkout/cart page. require_login()
// only proves the visitor is *some* logged-in user; without a module
// check, any authenticated role could ring up sales and touch stock/
// finance postings. requireModule('pos') closes that by re-checking the
// MODULE_ACCESS matrix (config/constants.php) live on every request.
requireModule('pos');
// Sales / Checkout is a cashier station, not an oversight screen: the
// Owner has POS access for the dashboard, products and reports, but
// ringing up a sale (which writes transactions, deducts stock and posts
// to finance) stays with the Cashier. See CAPABILITY_ROLES['pos.sell'].
requireCapability('pos.sell');

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// CSRF token for all cart/checkout POSTs (add, update, remove, discount,
// checkout). Generated once per session and echoed into every mutating
// form below; verified before any of those actions run.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (!empty($_GET['ajax']) && $_GET['ajax'] == '1');

$message = "";
$ajax_response = null;

define('TAX_RATE', 0.08);

function cart_totals() {
    $subtotal = 0;
    foreach ($_SESSION['cart'] as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    $discount = (float) ($_SESSION['discount'] ?? 0);
    $discounted = max(0, $subtotal - $discount);
    $tax = round($discounted * TAX_RATE, 2);
    $total = round($discounted + $tax, 2);
    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'tax'      => $tax,
        'total'    => $total,
    ];
}

function render_product_card_html($product, $csrf, $cat_icons) {
    $in_cart = isset($_SESSION['cart'][$product['id']]);
    $cart_qty = $in_cart ? (int)$_SESSION['cart'][$product['id']]['quantity'] : 0;
    $out_of_stock = ((int)$product['stock'] <= 0);
    ob_start(); ?>
    <form method="POST" class="product-card">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">

        <div class="product-card-img-wrap">
            <?php if (!empty($product['image_url'])): ?>
                <img src="<?= h($product['image_url']) ?>" alt="<?= h($product['name']) ?>" loading="lazy">
            <?php else: ?>
                <div class="img-placeholder">
                    <?= h($cat_icons[$product['category_id']] ?? '🍽️') ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-body">
            <div class="product-card-head">
                <h3 class="card-name"><?= h($product['name']) ?></h3>
                <span class="card-price">₱<?= money($product['price']) ?></span>
            </div>
            <div class="card-cat"><?= h($product['category_name'] ?? 'Cafe Item') ?></div>
            <div class="card-stock">
                <?= $out_of_stock ? 'Out of stock' : h($product['stock']) . ' left' ?>
            </div>

            <div class="product-card-actions">
                <button type="submit" class="btn-add-cart <?= $in_cart ? 'in-cart' : '' ?>" <?= $out_of_stock ? 'disabled' : '' ?>>
                    <?php if ($out_of_stock): ?>
                        <i class="bi bi-x-circle"></i> Out of Stock
                    <?php elseif ($in_cart): ?>
                        <i class="bi bi-check2"></i> Added (<?= $cart_qty ?>)
                    <?php else: ?>
                        <i class="bi bi-plus-lg"></i> Add to Cart
                    <?php endif; ?>
                </button>
            </div>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

function render_cart_panel_html() {
    $totals = cart_totals();
    $csrf = h($_SESSION['csrf_token']);
    $orderCode = '#' . date('md') . '-' . (function_exists('currentBranchId') ? currentBranchId() : '1');
    ob_start(); ?>
        <div class="cart-header-row">
            <h2 class="cart-header-title">Cart <span id="cart-count-badge" class="badge" style="background:#eef1e4; color:#5e6b46; font-size:13px; font-weight:800;"><?= count($_SESSION['cart']) ?></span></h2>
            <span class="cart-order-badge">Order <?= $orderCode ?></span>
        </div>

        <!-- Dining option switcher matching Purr'Coffee design -->
        <div class="dining-selector">
            <button type="button" class="dining-pill active" onclick="this.parentElement.querySelectorAll('.dining-pill').forEach(b=>b.classList.remove('active')); this.classList.add('active');">Delivery</button>
            <button type="button" class="dining-pill" onclick="this.parentElement.querySelectorAll('.dining-pill').forEach(b=>b.classList.remove('active')); this.classList.add('active');">Dine in</button>
            <button type="button" class="dining-pill" onclick="this.parentElement.querySelectorAll('.dining-pill').forEach(b=>b.classList.remove('active')); this.classList.add('active');">Take away</button>
        </div>

        <p id="cart-feedback" class="muted small mb-2" style="min-height:18px; color:#5e6b46; font-weight:600;" aria-live="polite"></p>

        <form method="POST" id="cart-update-form">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="product_id" class="remove-id" value="">

            <div class="cart-items-wrap">
                <table style="width:100%; border-collapse:collapse;">
                    <tbody>
                    <?php if (!$_SESSION['cart']): ?>
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted small">
                                <i class="bi bi-cart-x fs-2 d-block mb-1 opacity-50"></i>
                                Cart is empty. Select items from the menu.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($_SESSION['cart'] as $item): ?>
                        <tr class="cart-item-row" style="border-bottom: 1px solid #f6f0ea;">
                            <td style="padding:10px 4px 10px 0; vertical-align:middle;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?= h($item['image_url']) ?>" alt=""
                                             class="cart-item-thumb">
                                    <?php else: ?>
                                        <div class="cart-item-thumb d-flex align-items-center justify-content-center bg-light" style="font-size:18px;">
                                            ☕
                                        </div>
                                    <?php endif; ?>
                                    <div style="min-width:0; max-width:130px;">
                                        <div class="cart-item-title"><?= h($item['name']) ?></div>
                                        <div class="muted small">₱<?= money($item['price']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:10px 4px; vertical-align:middle; text-align:center;">
                                <div class="qty-stepper-pill">
                                    <button type="button" class="qty-stepper-btn" onclick="var inp=this.parentElement.querySelector('input'); var val=Math.max(1, (parseInt(inp.value,10)||1)-1); inp.value=val; inp.dispatchEvent(new Event('input', {bubbles:true})); inp.dispatchEvent(new Event('change', {bubbles:true}));">&minus;</button>
                                    <input type="number" min="1" max="<?= h($item['stock']) ?>"
                                           name="qty[<?= h($item['id']) ?>]"
                                           value="<?= h($item['quantity']) ?>"
                                           data-price="<?= h($item['price']) ?>"
                                           class="qty-stepper-input"
                                           style="width:40px !important; min-width:40px !important; padding:0 !important; background:#fff !important; color:#2a1810 !important; -webkit-text-fill-color:#2a1810 !important; opacity:1 !important; text-align:center !important;"
                                           title="Max available: <?= h($item['stock']) ?>">
                                    <button type="button" class="qty-stepper-btn" onclick="var inp=this.parentElement.querySelector('input'); var max=parseInt(inp.max,10)||999; var val=Math.min(max, (parseInt(inp.value,10)||1)+1); inp.value=val; inp.dispatchEvent(new Event('input', {bubbles:true})); inp.dispatchEvent(new Event('change', {bubbles:true}));">+</button>
                                </div>
                            </td>
                            <td class="line-total cart-item-total" style="padding:10px 4px; vertical-align:middle;">
                                ₱<?= money($item['price'] * $item['quantity']) ?>
                            </td>
                            <td style="padding:10px 0 10px 4px; vertical-align:middle; text-align:right;">
                                <button type="submit" name="remove" value="<?= h($item['id']) ?>" class="cart-item-remove-btn" title="Remove item">
                                    ✕
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($_SESSION['cart'])): ?>
                <div class="text-end mb-2">
                    <button type="button" id="cancel-transaction-btn" class="btn btn-sm btn-outline-danger" style="border-radius:50px; font-size:12px; padding:4px 14px;">
                        <i class="bi bi-trash3 me-1"></i> Clear Cart
                    </button>
                </div>
            <?php endif; ?>
        </form>

        <form method="POST" class="discount-box">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="apply_discount">
            <div class="d-flex gap-2 align-items-center">
                <span class="small fw-bold text-muted text-nowrap"><i class="bi bi-ticket-perforated me-1"></i> Discount (₱)</span>
                <input id="discount" name="discount" type="number" step="0.01" min="0" value="<?= money($totals['discount']) ?>" class="form-control form-control-sm" style="height:34px; border-radius:50px;">
                <button type="submit" class="btn btn-sm btn-secondary text-nowrap" style="height:34px; padding:0 14px; border-radius:50px;">Apply</button>
            </div>
        </form>

        <div class="cart-summary-box">
            <div class="cart-summary-line">
                <span>Items Subtotal</span>
                <strong id="summary-subtotal">₱<?= money($totals['subtotal']) ?></strong>
            </div>
            <div class="cart-summary-line">
                <span>Discounts</span>
                <strong class="text-success" id="summary-discount">&minus;₱<?= money($totals['discount']) ?></strong>
            </div>
            <div class="cart-summary-line">
                <span>Tax (<?= (TAX_RATE * 100) ?>%)</span>
                <strong id="summary-tax">₱<?= money($totals['tax']) ?></strong>
            </div>
            <div class="cart-total-line">
                <span class="cart-total-label">Total</span>
                <span class="cart-total-amount" id="summary-total">₱<?= money($totals['total']) ?></span>
            </div>
            <!-- Keep hidden p elements with text for JavaScript backwards-compatibility -->
            <div style="display:none;">
                <p>Subtotal: ₱<?= money($totals['subtotal']) ?></p>
                <p>Discount: &minus;₱<?= money($totals['discount']) ?></p>
                <p>Tax (<?= (TAX_RATE * 100) ?>%): ₱<?= money($totals['tax']) ?></p>
                <p>Total: ₱<?= money($totals['total']) ?></p>
            </div>
        </div>

        <form method="POST" id="checkout-form" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="checkout">
            
            <div class="mb-2">
                <label for="payment_method" class="small fw-bold mb-1">Payment Method</label>
                <select id="payment_method" name="payment_method" class="form-select form-select-sm" style="border-radius:14px; height:40px;">
                    <option value="cash"   <?= (($_SESSION['payment_method_retry'] ?? '') === 'cash')   ? 'selected' : '' ?>>💵 Cash</option>
                    <option value="credit" <?= (($_SESSION['payment_method_retry'] ?? '') === 'credit') ? 'selected' : '' ?>>💳 Credit Card</option>
                    <option value="debit"  <?= (($_SESSION['payment_method_retry'] ?? '') === 'debit')  ? 'selected' : '' ?>>💳 Debit Card</option>
                </select>
            </div>

            <?php if (!empty($_SESSION['payment_retry'])): ?>
                <div class="mb-2">
                    <label for="amount_paid" class="small fw-bold mb-1">Amount Paid So Far (₱)</label>
                    <input id="amount_paid" name="amount_paid" type="number" step="0.01" min="0"
                           data-total="<?= number_format($totals['total'], 2, '.', '') ?>"
                           value="<?= money($_SESSION['amount_paid_so_far'] ?? 0) ?>"
                           readonly
                           style="background:#f1f5f9; cursor:not-allowed;" class="form-control">
                </div>
            <?php else: ?>
                <div class="mb-2">
                    <label for="amount_paid" class="small fw-bold mb-1">Amount Paid (₱)</label>
                    <input id="amount_paid" name="amount_paid" type="number" step="0.01" min="0" required
                           data-total="<?= number_format($totals['total'], 2, '.', '') ?>"
                           value=""
                           placeholder="0.00" class="form-control" style="border-radius:14px; height:42px; font-weight:700; font-size:16px;">
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center px-1 my-2">
                <span class="small fw-bold text-muted">Change:</span>
                <span id="change_due_display" class="badge" style="background:#ecfdf5; color:#047857; font-size:14px; font-weight:800; border:1px solid #a7f3d0; padding:6px 14px;">₱0.00</span>
            </div>

            <?php if (!empty($_SESSION['payment_retry'])): ?>
                <div style="background:#fef9c3; border:1px solid #fde047; border-radius:16px; padding:12px; margin-top:10px;">
                    <strong style="color:#854d0e;">💳 Add Additional Payment</strong>
                    <p style="font-size:12px; color:#854d0e; margin:4px 0 8px;">
                        Already paid: <strong>₱<?= money($_SESSION['amount_paid_so_far'] ?? 0) ?></strong>
                        &nbsp;|&nbsp; Still needed: <strong>₱<?= money(max(0, $totals['total'] - ($_SESSION['amount_paid_so_far'] ?? 0))) ?></strong>
                    </p>
                    <label for="extra_payment" style="font-size:12px;">Additional Amount (₱)</label>
                    <input id="extra_payment" name="extra_payment" type="number" step="0.01" min="0"
                           value="<?= money(max(0, $totals['total'] - ($_SESSION['amount_paid_so_far'] ?? 0))) ?>"
                           class="form-control form-control-sm" style="margin-bottom:0;">
                </div>
            <?php else: ?>
                <input type="hidden" name="extra_payment" value="0">
            <?php endif; ?>

            <div class="quick-cash-grid">
                <?php foreach ([20, 50, 100, 200, 500, 1000] as $cash): ?>
                    <button type="button" class="quick-cash-btn quick-cash-pill" data-amount="<?= $cash ?>">
                        +₱<?= $cash ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn-place-order" <?= !$_SESSION['cart'] ? 'disabled' : '' ?>>
                <?php if (!empty($_SESSION['payment_retry'])): ?>
                    <i class="bi bi-arrow-repeat"></i> Retry Payment
                <?php else: ?>
                    <i class="bi bi-bag-check-fill"></i> Place an order
                <?php endif; ?>
            </button>
        </form>
    <?php
    return ob_get_clean();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf_valid = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);

    if (!$csrf_valid) {
        $message = "<div class='alert error'>Your session could not be verified. Please refresh the page and try again.</div>";
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success'    => false,
                'message'    => 'Session could not be verified. Please refresh the page and try again.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html'  => render_cart_panel_html(),
            ]);
            exit;
        }
    } else {

    $action = $_POST['action'] ?? '';
    if (isset($_POST['remove'])) {
        $action = 'remove';
    }

    if ($action === 'add') {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $branch_id = currentBranchId();
        $stmt = $conn->prepare("SELECT p.id, p.name, p.price, p.image_url, COALESCE(bi.stock, 0) AS stock
                                 FROM products p
                                 LEFT JOIN branch_inventory bi ON bi.product_id = p.id AND bi.branch_id = ?
                                 WHERE p.id = ? AND p.is_active = 1");
        $stmt->execute([$branch_id, $product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product && (int) $product['stock'] > 0) {
            $current_qty = $_SESSION['cart'][$product_id]['quantity'] ?? 0;
            if ($current_qty + 1 <= (int) $product['stock']) {
                $_SESSION['cart'][$product_id] = [
                    'id'        => $product['id'],
                    'name'      => $product['name'],
                    'price'     => (float) $product['price'],
                    'quantity'  => $current_qty + 1,
                    'stock'     => (int) $product['stock'],
                    'image_url' => $product['image_url'] ?? '',
                ];
                $ajax_response = [
                    'success' => true,
                    'message' => 'Added to cart.',
                    'cart_count' => count($_SESSION['cart']),
                    'cart_html' => render_cart_panel_html(),
                ];
            } else {
                $message = "<div class='alert error'>Not enough stock for that product.</div>";
                $ajax_response = [
                    'success' => false,
                    'message' => 'Not enough stock for that product.',
                    'cart_count' => count($_SESSION['cart']),
                ];
            }
        } else {
            $ajax_response = [
                'success' => false,
                'message' => 'Unable to add this product to cart.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html' => render_cart_panel_html(),
            ];
        }
    }

    if ($action === 'update') {
        $capped = false;
        foreach ($_POST['qty'] ?? [] as $product_id => $qty) {
            $product_id = (int) $product_id;
            $qty = max(1, (int) $qty);
            if (isset($_SESSION['cart'][$product_id])) {
                $stock = (int) $_SESSION['cart'][$product_id]['stock'];
                if ($qty > $stock) {
                    $capped = true;
                }
                $_SESSION['cart'][$product_id]['quantity'] = min($qty, $stock);
            }
        }
        if ($is_ajax) {
            $ajax_response = [
                'success' => true,
                'message' => $capped ? 'Quantity adjusted to available stock.' : 'Cart updated.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html' => render_cart_panel_html(),
            ];
        }
    }

    if ($action === 'remove') {
        $product_id = filter_input(INPUT_POST, 'remove', FILTER_VALIDATE_INT);
        if ($product_id && isset($_SESSION['cart'][$product_id])) {
            unset($_SESSION['cart'][$product_id]);
        }
        if ($is_ajax) {
            $ajax_response = [
                'success' => true,
                'message' => 'Item removed.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html' => render_cart_panel_html(),
            ];
        }
    }

    if ($action === 'apply_discount') {
        $discount = filter_input(INPUT_POST, 'discount', FILTER_VALIDATE_FLOAT);
        $_SESSION['discount'] = ($discount !== false && $discount >= 0) ? $discount : 0;
    }

    if ($action === 'clear') {
        $_SESSION['cart'] = [];
        $_SESSION['discount'] = 0;
        unset($_SESSION['payment_retry'], $_SESSION['payment_method_retry'], $_SESSION['amount_paid_so_far']);
        if ($is_ajax) {
            $ajax_response = [
                'success' => true,
                'message' => 'Cart cleared.',
                'cart_count' => count($_SESSION['cart']),
                'cart_html' => render_cart_panel_html(),
            ];
        }
    }

    if ($action === 'checkout') {
        $payment_method  = $_POST['payment_method'] ?? 'cash';
        $amount_paid     = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_FLOAT);
        $extra_payment   = filter_input(INPUT_POST, 'extra_payment', FILTER_VALIDATE_FLOAT) ?: 0;
        $totals          = cart_totals();

        $total_paid = ($amount_paid !== false ? $amount_paid : 0) + $extra_payment;

        if (!$_SESSION['cart']) {
            $message = "<div class='alert error'>Cart is empty.</div>";

        } elseif (!in_array($payment_method, ['cash', 'credit', 'debit'], true)) {
            $message = "<div class='alert error'>Please select a valid payment method.</div>";

        } elseif ($amount_paid === false || $amount_paid < 0) {
            $message = "<div class='alert error'>Please enter a valid amount paid.</div>";
            $_SESSION['payment_retry'] = true;

        } elseif ($total_paid < $totals['total']) {
            $still_needed = round($totals['total'] - $total_paid, 2);
            $message = "<div class='alert error'>
                ⚠️ Amount is insufficient. Still need <strong>₱" . number_format($still_needed, 2) . "</strong> more.
                Please add an additional payment below.
            </div>";
            $_SESSION['payment_retry']       = true;
            $_SESSION['payment_method_retry'] = $payment_method;
            $_SESSION['amount_paid_so_far']  = $total_paid;

        } else {
            try {
                $conn->beginTransaction();
                $code       = 'TXN' . date('ymdHis') . random_int(10, 99);
                $change_due = round($total_paid - $totals['total'], 2);
                $branch_id  = currentBranchId();

                $stmt = $conn->prepare("INSERT INTO transactions (transaction_code, user_id, branch_id, subtotal, discount, tax, total, payment_method, amount_paid, change_due, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')");
                $stmt->execute([$code, $_SESSION['user_id'], $branch_id, $totals['subtotal'], $totals['discount'], $totals['tax'], $totals['total'], $payment_method, $total_paid, $change_due]);
                $transaction_id = (int) $conn->lastInsertId();

                $item_stmt  = $conn->prepare("INSERT INTO transaction_items (transaction_id, product_id, product_name, price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
                // Branch-scoped, atomic compare-and-set: this single UPDATE
                // both checks and deducts stock for THIS branch only, so two
                // cashiers at the same branch can't both sell the last unit.
                $stock_stmt   = $conn->prepare("UPDATE branch_inventory SET stock = stock - ? WHERE branch_id = ? AND product_id = ? AND stock >= ?");
                $remain_stmt  = $conn->prepare("SELECT stock FROM branch_inventory WHERE branch_id = ? AND product_id = ?");

                $sold_product_ids = [];
                foreach ($_SESSION['cart'] as $item) {
                    $line_total = round($item['price'] * $item['quantity'], 2);
                    $item_stmt->execute([$transaction_id, $item['id'], $item['name'], $item['price'], $item['quantity'], $line_total]);

                    $stock_stmt->execute([$item['quantity'], $branch_id, $item['id'], $item['quantity']]);
                    if ($stock_stmt->rowCount() !== 1) {
                        // Names the product, per the "Insufficient stock for X" requirement.
                        throw new Exception("Insufficient stock for {$item['name']}.");
                    }
                    $sold_product_ids[] = $item['id'];

                    // Keep the existing low-stock alert system working: it
                    // was written against the old global products.stock, so
                    // feed it this branch's post-sale remaining stock instead.
                    $remain_stmt->execute([$branch_id, $item['id']]);
                    $remaining = $remain_stmt->fetchColumn();
                    if ($remaining !== false) {
                        sync_low_stock_notification_by_product($item['id'], (int) $remaining);

                        // Old stock before this line's deduction was always
                        // $remaining + quantity sold (the UPDATE above only
                        // succeeds when stock >= quantity), so remaining<=0
                        // here can only mean this exact sale just crossed
                        // the product from in-stock to out-of-stock - fires
                        // once per stockout, never repeats while it sits at 0.
                        if ((int) $remaining <= 0) {
                            notify_inventory_out_of_stock($item['id'], $branch_id);
                        }
                    }
                }

                // INTEGRATION: POS -> Inventory -> Finance. Deduct ingredient
                // stock per the product_ingredients recipe and post the sale
                // as Income, inside this same DB transaction so a failure
                // here rolls back the whole sale.
                applyPosSaleToInventoryAndFinance(
                    $conn,
                    $transaction_id,
                    array_map(fn($i) => ['id' => $i['id'], 'quantity' => $i['quantity']], $_SESSION['cart']),
                    $totals['total'],
                    (int) $_SESSION['user_id'],
                    (int) $branch_id
                );

                $conn->commit();

                $_SESSION['cart']                = [];
                $_SESSION['discount']            = 0;
                $_SESSION['payment_retry']       = false;
                $_SESSION['payment_method_retry'] = '';
                $_SESSION['amount_paid_so_far']   = 0;

                header("Location: " . BASE_URL . "pos/receipt?id=" . $transaction_id);
                exit;

            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $message = "<div class='alert error'>
                    ❌ Payment could not be processed. Please try again.<br>
                    <small>" . h($e->getMessage()) . "</small>
                </div>";
                $_SESSION['payment_retry'] = true;
            }
        }
    }

    } // end csrf_valid
}

$category_id      = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$search           = trim($_GET['search'] ?? '');
$current_branch_id = currentBranchId();

$categories = $conn->query("SELECT id, name, icon FROM menu_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// Branch-scoped: COALESCE to 0 so a product with no branch_inventory row
// yet (e.g. added after this branch was created) shows as out-of-stock
// instead of silently falling back to the global products.stock.
$sql    = "SELECT p.id, p.category_id, p.sku, p.name, p.price, p.image_url, p.is_active,
                   c.name AS category_name,
                   COALESCE(bi.stock, 0) AS stock
            FROM products p
            LEFT JOIN menu_categories c ON c.id = p.category_id
            LEFT JOIN branch_inventory bi ON bi.product_id = p.id AND bi.branch_id = ?
            WHERE p.is_active = 1";
$params = [$current_branch_id];
if ($category_id) {
    $sql      .= " AND p.category_id = ?";
    $params[]  = $category_id;
}
if ($search !== '') {
    $sql      .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params[]  = "%{$search}%";
    $params[]  = "%{$search}%";
}
$sql .= " ORDER BY p.name";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totals   = cart_totals();
$csrf     = h($_SESSION['csrf_token']);

$cat_icons = [];
foreach ($categories as $c) {
    $cat_icons[$c['id']] = $c['icon'] ?? '🍽️';
}

if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($action ?? ''), ['add', 'update', 'remove', 'clear'], true)) {
    header('Content-Type: application/json');
    echo json_encode($ajax_response ?? ['success' => false, 'message' => 'Unable to update cart.', 'cart_count' => count($_SESSION['cart']), 'cart_html' => render_cart_panel_html()]);
    exit;
}

if ($is_ajax) {
    header('Content-Type: text/html; charset=utf-8');
    ob_start();
    foreach ($products as $product) {
        echo render_product_card_html($product, $csrf, $cat_icons);
    }
    if (!$products) {
        echo '<div class="text-center py-5 text-muted col-12" style="grid-column: 1 / -1;"><i class="bi bi-search fs-1 d-block mb-2 opacity-50"></i>No products found.</div>';
    }
    echo ob_get_clean();
    exit;
}

$pageTitle = 'Sales / Checkout';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/pos-legacy.css">

<?php
$active_cat_name = 'All menu';
if ($category_id) {
    foreach ($categories as $cat) {
        if ($cat['id'] == $category_id) {
            $active_cat_name = $cat['name'] . ' menu';
            break;
        }
    }
}
?>

<?= $message ?>

<div class="two-col">
    <section class="panel">
        <!-- Top Search Bar & Filter Button (Purr'Coffee Reference Style) -->
        <?php
        $pending_kiosk_sales = 0;
        try {
            $pending_kiosk_sales = (int)$conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'kiosk' AND status IN ('pending', 'pending_payment')")->fetchColumn();
        } catch (Throwable $e) {}
        ?>
        <div class="sales-topbar">
            <form method="GET" class="sales-search-box" id="product-search-form" onsubmit="return false;">
                <i class="bi bi-search"></i>
                <input id="search" name="search" class="sales-search-input" value="<?= h($search) ?>" placeholder="Search coffee, drinks, pastries..." autocomplete="off">
                <input type="hidden" name="category" value="<?= h($category_id ?? '') ?>">
            </form>
            <button type="button" class="btn-filter-pill" id="filter-btn" onclick="document.querySelector('.cat-tabs').scrollIntoView({behavior:'smooth'});">
                <i class="bi bi-sliders"></i> Filter
            </button>
            <a href="<?= BASE_URL ?>pos/kiosk-admin-orders" class="btn-filter-pill" style="text-decoration:none; background:#fbf6ee; border:1px solid #5e6b46; color:#5e6b46; font-weight:700; display:inline-flex; align-items:center; gap:6px; white-space:nowrap;" title="View Kiosk Orders Queue">
                <i class="bi bi-tablet"></i> Kiosk Orders
                <?php if ($pending_kiosk_sales > 0): ?>
                    <span style="background:#5e6b46; color:#fff; font-size:11px; padding:2px 7px; border-radius:999px;"><?= $pending_kiosk_sales ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= BASE_URL ?>pos/dashboard#recent-transactions" class="btn-filter-pill" style="text-decoration:none; background:#f9fafb; border:1px solid #d1d5db; color:#3b241a; font-weight:700; display:inline-flex; align-items:center; gap:6px; white-space:nowrap;" title="View Recent Transactions">
                <i class="bi bi-clock-history"></i> Recent Transactions
            </a>
        </div>

        <!-- Horizontal Category Filter Tabs -->
        <div class="cat-tabs">
            <a href="<?= BASE_URL ?>pos/sales<?= $search ? '?search=' . urlencode($search) : '' ?>"
               class="cat-tab <?= !$category_id ? 'active' : '' ?>">🍽️ All</a>
            <?php foreach ($categories as $cat): ?>
                <?php if ($cat['name'] === 'All Menu') continue; ?>
                <a href="<?= BASE_URL ?>pos/sales?category=<?= h($cat['id']) ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                   class="cat-tab <?= $category_id == $cat['id'] ? 'active' : '' ?>">
                    <?= h($cat['icon'] ?? '') ?> <?= h($cat['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Menu Section Title -->
        <div class="menu-section-title">
            <span><?= h($active_cat_name) ?></span>
            <span class="badge" style="background:#eef1e4; color:#5e6b46; font-size:12px; font-weight:700;"><?= count($products) ?> items</span>
        </div>

        <!-- Modern Product Cards Grid -->
        <div class="product-grid-new">
            <?php foreach ($products as $product): ?>
                <?= render_product_card_html($product, $csrf, $cat_icons) ?>
            <?php endforeach; ?>
            <?php if (!$products): ?>
                <div class="text-center py-5 text-muted col-12" style="grid-column: 1 / -1;">
                    <i class="bi bi-search fs-1 d-block mb-2 opacity-50"></i>
                    No products found matching your search.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <aside id="cart-panel">
        <?= render_cart_panel_html() ?>
    </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
var TAX_RATE = <?= json_encode(TAX_RATE) ?>; // single source of truth, mirrors the PHP constant

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('product-search-form');
    var searchInput = document.getElementById('search');
    var productGrid = document.querySelector('.product-grid-new');
    var cartPanel = document.getElementById('cart-panel');
    var cartBadge = document.getElementById('cart-count-badge');
    var cartFeedback = document.getElementById('cart-feedback');

    // Quick-cash buttons target extra_payment during a retry (amount_paid
    // is locked then) and amount_paid otherwise. Previously they always
    // wrote into amount_paid, which double-counted against extra_payment
    // once a retry was in progress.
    var bindQuickCashButtons = function () {
        document.querySelectorAll('.quick-cash-btn').forEach(function (button) {
            button.removeEventListener('click', button._quickCashHandler);
            var handler = function () {
                var extraInput = document.getElementById('extra_payment');
                var amountPaidInput = document.getElementById('amount_paid');
                var isRetry = extraInput && !extraInput.matches('input[type="hidden"]');
                var targetInput = isRetry ? extraInput : amountPaidInput;

                if (targetInput) {
                    var currentValue = parseFloat(targetInput.value) || 0;
                    var addAmount = parseFloat(button.dataset.amount) || 0;
                    targetInput.value = (currentValue + addAmount).toFixed(2);
                    targetInput.focus();
                    computeChangeDisplay();
                }
            };
            button._quickCashHandler = handler;
            button.addEventListener('click', handler);
        });
    };

    var computeChangeDisplay = function () {
        var amountPaidInput = document.getElementById('amount_paid');
        var extraInput = document.getElementById('extra_payment');
        var changeDisplay = document.getElementById('change_due_display');
        if (!amountPaidInput || !changeDisplay) {
            return;
        }
        var totalValue = parseFloat(amountPaidInput.dataset.total) || 0;
        var amountPaid = parseFloat(amountPaidInput.value) || 0;
        var extraPaid = extraInput ? (parseFloat(extraInput.value) || 0) : 0;
        var totalPaid = amountPaid + extraPaid;

        if (totalPaid <= 0 || totalPaid < totalValue) {
            changeDisplay.textContent = 'Change: ₱0.00';
            return;
        }
        var change = Math.max(0, totalPaid - totalValue);
        changeDisplay.textContent = 'Change: ₱' + change.toFixed(2);
    };

    var bindAmountPaidChange = function () {
        var amountPaidInput = document.getElementById('amount_paid');
        var extraInput = document.getElementById('extra_payment');
        [amountPaidInput, extraInput].forEach(function (input) {
            if (!input) {
                return;
            }
            input.removeEventListener('input', computeChangeDisplay);
            input.removeEventListener('change', computeChangeDisplay);
            input.addEventListener('input', computeChangeDisplay);
            input.addEventListener('change', computeChangeDisplay);
        });
        computeChangeDisplay();
    };

    // Guards the checkout/retry-payment button against double submission
    // (slow network + an impatient second tap used to be able to fire two
    // "checkout" POSTs). Re-bound every time the cart panel HTML is
    // replaced, since the form itself gets re-rendered.
    var bindCheckoutFormGuard = function () {
        var checkoutForm = document.getElementById('checkout-form');
        if (!checkoutForm) {
            return;
        }
        checkoutForm.removeEventListener('submit', checkoutForm._guardHandler);
        var handler = function (event) {
            var submitBtn = checkoutForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                if (submitBtn.dataset.submitted === '1') {
                    event.preventDefault();
                    return;
                }
                submitBtn.dataset.submitted = '1';
                submitBtn.disabled = true;
                submitBtn.textContent = 'Processing…';
            }
        };
        checkoutForm._guardHandler = handler;
        checkoutForm.addEventListener('submit', handler);
    };

    if (form && searchInput && productGrid) {
        var timer;
        var refreshProducts = function (term) {
            var params = new URLSearchParams();
            params.set('search', term);
            var categoryValue = form.querySelector('input[name="category"]').value;
            if (categoryValue) {
                params.set('category', categoryValue);
            }
            params.set('ajax', '1');

            fetch(window.location.pathname + '?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) {
                return response.text();
            })
            .then(function (html) {
                productGrid.innerHTML = html;
                bindProductCards();
            })
            .catch(function () {
                productGrid.innerHTML = '<p class="muted p-4 text-center">Unable to refresh products.</p>';
            });
        };

        searchInput.addEventListener('input', function () {
            var term = this.value.trim();
            clearTimeout(timer);
            timer = setTimeout(function () {
                refreshProducts(term);
            }, 150);
        });

        if (searchInput.value.trim()) {
            refreshProducts(searchInput.value.trim());
        }
    }

    var bindProductCards = function () {
        document.querySelectorAll('.product-card').forEach(function (cardForm) {
            cardForm.removeEventListener('submit', cardForm._submitHandler);
            var handler = function (event) {
                event.preventDefault();
                var formData = new FormData(this);
                var submitButton = this.querySelector('button[type="submit"]');
                var originalHtml = submitButton ? submitButton.innerHTML : '';

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Adding...';
                }

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.success) {
                        if (cartPanel && data.cart_html) {
                            cartPanel.innerHTML = data.cart_html;
                            cartBadge = cartPanel.querySelector('#cart-count-badge');
                            cartFeedback = cartPanel.querySelector('#cart-feedback');
                        }
                        if (cartBadge) {
                            cartBadge.textContent = data.cart_count;
                        }
                        if (cartFeedback) {
                            cartFeedback.textContent = data.message;
                        }
                        if (submitButton) {
                            submitButton.classList.add('in-cart');
                            submitButton.innerHTML = '<i class="bi bi-check2"></i> Added!';
                            window.setTimeout(function () {
                                submitButton.disabled = false;
                            }, 500);
                        }
                    } else {
                        if (cartPanel && data.cart_html) {
                            cartPanel.innerHTML = data.cart_html;
                            cartBadge = cartPanel.querySelector('#cart-count-badge');
                            cartFeedback = cartPanel.querySelector('#cart-feedback');
                        }
                        if (cartFeedback) {
                            cartFeedback.textContent = data.message || 'Unable to add product.';
                        }
                        if (submitButton) {
                            submitButton.innerHTML = originalHtml;
                            submitButton.disabled = false;
                        }
                    }
                    bindQuantityInputs();
                    bindQuickCashButtons();
                    bindAmountPaidChange();
                    bindCheckoutFormGuard();
                })
                .catch(function () {
                    if (cartFeedback) {
                        cartFeedback.textContent = 'Unable to add product right now.';
                    }
                    if (submitButton) {
                        submitButton.innerHTML = originalHtml;
                        submitButton.disabled = false;
                    }
                });
            };
            cardForm._submitHandler = handler;
            cardForm.addEventListener('submit', handler);
        });
    };
    bindProductCards();

    // The cart panel is replaced after each AJAX update, so handle its clear
    // button through the stable parent instead of binding to one rendered node.
    if (cartPanel) {
        cartPanel.addEventListener('click', function (event) {
            var cancelTransactionButton = event.target.closest('#cancel-transaction-btn');
            if (!cancelTransactionButton) {
                return;
            }
            event.preventDefault();
            Swal.fire({
                title: 'Cancel Transaction',
                text: 'Are you sure you want to cancel this transaction? All items currently in the cart will be removed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Cancel Transaction',
                cancelButtonText: 'No, Keep Transaction',
                reverseButtons: true,
                confirmButtonColor: '#5e6b46',
                cancelButtonColor: '#94a3b8',
                customClass: {
                    popup: 'swal-theme-popup',
                    confirmButton: 'swal-theme-confirm',
                    cancelButton: 'swal-theme-cancel'
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    var form = document.getElementById('cart-update-form');
                    if (form) {
                        var actionInput = form.querySelector('input[name="action"]');
                        if (actionInput) {
                            actionInput.value = 'clear';
                        }
                        var clearEvent = new Event('submit', { bubbles: true, cancelable: true });
                        form.dispatchEvent(clearEvent);
                    }
                }
            });
        });
    }

    var cartUpdateTimer = null;
    var submitCartUpdate = function (form) {
        if (!form) {
            return;
        }
        var formData = new FormData(form);
        fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            refreshCartPanel(data);
        })
        .catch(function () {
            if (cartFeedback) {
                cartFeedback.textContent = 'Unable to update cart right now.';
            }
        });
    };

    var scheduleCartUpdate = function (form) {
        if (!form) {
            return;
        }
        window.clearTimeout(cartUpdateTimer);
        cartUpdateTimer = window.setTimeout(function () {
            submitCartUpdate(form);
        }, 300);
    };

    var refreshCartPanel = function (data) {
        if (cartPanel && data.cart_html) {
            cartPanel.innerHTML = data.cart_html;
            cartBadge = cartPanel.querySelector('#cart-count-badge');
            cartFeedback = cartPanel.querySelector('#cart-feedback');
        }
        if (cartFeedback) {
            cartFeedback.textContent = data.message || '';
        }
        bindQuantityInputs();
        bindQuickCashButtons();
        bindAmountPaidChange();
        bindCheckoutFormGuard();
    };

    var lastCartSubmitter = null;
    if (cartPanel) {
        cartPanel.addEventListener('click', function (event) {
            var button = event.target.closest('button[type="submit"], input[type="submit"]');
            if (button && button.form && button.form.id === 'cart-update-form') {
                lastCartSubmitter = button;
            }
        });

        cartPanel.addEventListener('submit', function (event) {
            var form = event.target.closest('#cart-update-form');
            if (!form) {
                return;
            }
            event.preventDefault();

            var submitter = event.submitter || lastCartSubmitter;
            var formData = new FormData(form);
            if (submitter && submitter.name) {
                formData.set(submitter.name, submitter.value);
            }
            lastCartSubmitter = null;

            fetch(window.location.pathname, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                refreshCartPanel(data);
            })
            .catch(function () {
                if (cartFeedback) {
                    cartFeedback.textContent = 'Unable to update cart right now.';
                }
            });
        });
    }

    var bindQuantityInputs = function () {
        document.querySelectorAll('#cart-panel input[name^="qty["]').forEach(function (qtyInput) {
            var recalc = function () {
                var row = qtyInput.closest('tr');
                var unitPrice = parseFloat(qtyInput.dataset.price) || 0;
                if (!unitPrice) {
                    var priceText = row.querySelector('.muted') ? row.querySelector('.muted').textContent : '';
                    unitPrice = parseFloat(priceText.replace(/[^0-9\.]/g, '')) || 0;
                }
                // Clamp client-side to the item's available stock (the
                // input's own max attribute) so the on-screen line total
                // never shows a quantity higher than what will actually be
                // saved once the debounced server update lands.
                var maxQty = parseInt(qtyInput.max, 10);
                var qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                if (!isNaN(maxQty) && maxQty > 0 && qty > maxQty) {
                    qty = maxQty;
                }
                qtyInput.value = qty;
                var totalCell = row.querySelector('.line-total');
                if (totalCell) {
                    totalCell.textContent = '₱' + (unitPrice * qty).toFixed(2);
                }
                updateCartSummary();
            };

            var debounced = function () {
                recalc();
                var form = qtyInput.closest('#cart-update-form');
                scheduleCartUpdate(form);
            };

            qtyInput.addEventListener('input', debounced);
            qtyInput.addEventListener('change', debounced);
        });
    };

    var updateCartSummary = function () {
        var subtotal = 0;
        document.querySelectorAll('#cart-panel tbody tr').forEach(function (row) {
            var totalCell = row.querySelector('td:nth-child(3)');
            if (totalCell) {
                var line = parseFloat(totalCell.textContent.replace(/[^0-9\.]/g, '')) || 0;
                subtotal += line;
            }
        });
        var discountNode = document.querySelector('#cart-panel input[name="discount"]');
        var discount = discountNode ? parseFloat(discountNode.value) || 0 : 0;
        var tax = parseFloat((Math.max(0, subtotal - discount) * TAX_RATE).toFixed(2));
        var total = parseFloat((Math.max(0, subtotal - discount) + tax).toFixed(2));
        var taxPct = Math.round(TAX_RATE * 100);
        var subEl = document.getElementById('summary-subtotal');
        if (subEl) subEl.textContent = '₱' + subtotal.toFixed(2);
        var discEl = document.getElementById('summary-discount');
        if (discEl) discEl.textContent = '−₱' + discount.toFixed(2);
        var taxEl = document.getElementById('summary-tax');
        if (taxEl) taxEl.textContent = '₱' + tax.toFixed(2);
        var totEl = document.getElementById('summary-total');
        if (totEl) totEl.textContent = '₱' + total.toFixed(2);

        var amountPaidInput = document.getElementById('amount_paid');
        if (amountPaidInput) {
            amountPaidInput.dataset.total = total.toFixed(2);
            computeChangeDisplay();
        }

        cartPanel.querySelectorAll('p').forEach(function (p) {
            if (p.textContent.trim().startsWith('Subtotal:')) {
                p.innerHTML = '<strong>Subtotal:</strong> ₱' + subtotal.toFixed(2);
            }
            if (p.textContent.trim().startsWith('Discount:')) {
                p.innerHTML = '<strong>Discount:</strong> &minus;₱' + discount.toFixed(2);
            }
            if (p.textContent.trim().startsWith('Tax (')) {
                p.innerHTML = '<strong>Tax (' + taxPct + '%):</strong> ₱' + tax.toFixed(2);
            }
            if (p.textContent.trim().startsWith('Total:')) {
                p.innerHTML = '<strong>Total:</strong> ₱' + total.toFixed(2);
            }
        });
    };

    bindQuantityInputs();
    bindQuickCashButtons();
    bindAmountPaidChange();
    bindCheckoutFormGuard();

});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
