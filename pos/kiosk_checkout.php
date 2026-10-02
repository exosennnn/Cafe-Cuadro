<?php
require 'kiosk_bootstrap.php';

if (empty($_SESSION['kiosk_order_type'])) {
    header('Location: ' . BASE_URL . 'pos/kiosk-order-type');
    exit;
}
if (!$_SESSION['kiosk_cart']) {
    header('Location: ' . BASE_URL . 'pos/kiosk-cart');
    exit;
}

$message = '';
$default_name = $_SESSION['kiosk_guest_name'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    if (!kiosk_csrf_valid()) {
        $message = 'Your session expired. Please try again.';
    } elseif (!$_SESSION['kiosk_cart']) {
        $message = 'Your cart is empty.';
    } else {
        $customer_name = trim($_POST['customer_name'] ?? '');
        $payment_method = in_array($_POST['payment_method'] ?? '', ['cash', 'gcash'], true) ? $_POST['payment_method'] : 'cash';
        $_SESSION['kiosk_guest_name'] = $customer_name;
        $totals = kiosk_cart_totals();
        $order_type = $_SESSION['kiosk_order_type'];
        // Kiosk orders are anonymous walk-up orders; don't attach a previous
        // kiosk account session to the next customer's transaction.
        $user_id = null;
        $guest_token = bin2hex(random_bytes(16));

        try {
            $conn->beginTransaction();
            $code = 'TXN' . date('ymdHis') . random_int(10, 99);

            $stmt = $conn->prepare("
                INSERT INTO transactions
                    (transaction_code, user_id, subtotal, discount, tax, total, payment_method, amount_paid, change_due, status, source, order_type, customer_name, guest_token)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 'pending', 'kiosk', ?, ?, ?)
            ");
            $stmt->execute([
                $code, $user_id, $totals['subtotal'], $totals['discount'], $totals['tax'], $totals['total'],
                $payment_method, $order_type, $customer_name !== '' ? $customer_name : null, $guest_token,
            ]);
            $transaction_id = (int) $conn->lastInsertId();

            $item_stmt  = $conn->prepare("INSERT INTO transaction_items (transaction_id, product_id, product_name, price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
            $stock_stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");

            $sold_product_ids = [];
            foreach ($_SESSION['kiosk_cart'] as $item) {
                $line_total = round($item['price'] * $item['quantity'], 2);
                $item_stmt->execute([$transaction_id, $item['id'], $item['name'], $item['price'], $item['quantity'], $line_total]);
                $stock_stmt->execute([$item['quantity'], $item['id'], $item['quantity']]);
                if ($stock_stmt->rowCount() !== 1) {
                    throw new Exception('One or more items just sold out. Please review your cart.');
                }
                $sold_product_ids[] = $item['id'];
            }

            $conn->commit();

            foreach ($sold_product_ids as $sold_product_id) {
                sync_low_stock_notification_by_product($sold_product_id);
            }

            $_SESSION['kiosk_cart'] = [];
            unset($_SESSION['kiosk_order_type']);
            $_SESSION['kiosk_last_order_id']    = $transaction_id;
            $_SESSION['kiosk_last_guest_token'] = $guest_token;

            header('Location: ' . BASE_URL . 'pos/kiosk-success?id=' . $transaction_id . '&t=' . $guest_token);
            exit;

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $message = 'We could not place your order: ' . $e->getMessage();
        }
    }
}

$totals = kiosk_cart_totals();
$csrf   = h(kiosk_csrf_token());
$order_type_label = ($_SESSION['kiosk_order_type'] ?? '') === 'dine_in' ? 'Dine-in' : 'Takeout';
$formatted_total = '₱' . money($totals['total']);

kiosk_header('Confirm Order', true, 'kiosk_cart.php');
?>
<h1 class="kiosk-title">Confirm Your Order</h1>
<style>
.checkout-confirm-modal{position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.48);}
.checkout-confirm-modal.is-open{display:flex;}
.checkout-confirm-dialog{width:min(100%,524px);padding:40px 36px 30px;border-radius:28px;background:#fff;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.24);}
.checkout-confirm-icon{width:80px;height:80px;margin:0 auto 20px;border:4px solid #c9a27a;border-radius:50%;color:#5e6b46;font:700 48px/76px Arial,sans-serif;display:grid;place-items:center;}
.checkout-confirm-title{margin:0 0 12px;color:#171717;font:800 28px/1.2 var(--font-brand);}
.checkout-confirm-copy{max-width:420px;margin:0 auto 24px;color:#666;font-size:16px;line-height:1.5;}
.checkout-confirm-actions{display:flex;justify-content:center;gap:12px;flex-wrap:wrap;}
.checkout-confirm-actions .kiosk-btn{min-height:46px;padding:12px 24px;font-size:15px;white-space:nowrap;}
.kiosk-pm-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}
.kiosk-pm-card{
    display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 14px;
    border-radius:18px;border:2px solid var(--k-border);background:#fff;cursor:pointer;
    transition:all .2s ease;position:relative;user-select:none;
}
.kiosk-pm-card:hover{border-color:var(--k-blue-light);transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.06);}
.kiosk-pm-card.active{border-color:var(--k-blue);background:#fbf6ee;box-shadow:0 6px 20px rgba(94,107,70,0.18);}
.kiosk-pm-card input[type="radio"]{position:absolute;top:14px;right:14px;accent-color:var(--k-blue);width:18px;height:18px;}
.kiosk-pm-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:#f3f4f6;color:var(--k-ink-soft);margin-top:6px;}
.kiosk-pm-card.active .kiosk-pm-badge{background:#fde8dc;color:#4c5838;}
@media(max-width:520px){.checkout-confirm-dialog{padding:30px 18px 22px}.checkout-confirm-icon{margin-bottom:16px}.checkout-confirm-title{font-size:24px}.checkout-confirm-copy{font-size:15px}.checkout-confirm-actions{flex-direction:column}.checkout-confirm-actions .kiosk-btn{width:100%}.kiosk-pm-grid{grid-template-columns:1fr;}}
</style>
<?php if ($message): ?><div class="kiosk-alert error"><?= h($message) ?></div><?php endif; ?>

<div class="kiosk-card">
    <p style="margin:0 0 14px;"><span class="kiosk-badge-pending" style="padding:6px 14px; border-radius:999px; font-weight:700;"><?= h($order_type_label) ?></span></p>
    <table style="width:100%; border-collapse:collapse;">
        <tbody>
        <?php foreach ($_SESSION['kiosk_cart'] as $item): ?>
            <tr>
                <td style="padding:8px 4px; border-bottom:1px solid var(--k-border);">
                    <strong><?= h($item['name']) ?></strong><br>
                    <span style="color:var(--k-ink-soft); font-size:13px;">Qty <?= h($item['quantity']) ?> × ₱<?= money($item['price']) ?></span>
                </td>
                <td style="padding:8px 4px; border-bottom:1px solid var(--k-border); text-align:right; font-weight:700;">
                    ₱<?= money($item['price'] * $item['quantity']) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="kiosk-summary" style="margin-top:14px;">
        <p><span>Subtotal</span><span>₱<?= money($totals['subtotal']) ?></span></p>
        <p><span>Tax (<?= (KIOSK_TAX_RATE * 100) ?>%)</span><span>₱<?= money($totals['tax']) ?></span></p>
        <p class="grand"><span>Total</span><span>₱<?= money($totals['total']) ?></span></p>
    </div>
</div>

<div class="kiosk-card" style="margin-top:18px;">
    <form method="POST" id="checkout-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="place_order">

        <label style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:12px; font-size:17px;">
            <i class="bi bi-wallet2 me-1" style="color:var(--k-blue);"></i> Select Payment Method
        </label>
        <div class="kiosk-pm-grid">
            <label class="kiosk-pm-card active" id="pm-card-cash">
                <input type="radio" name="payment_method" value="cash" checked>
                <span style="font-size:40px; line-height:1; margin-bottom:10px;">💵</span>
                <strong style="font-size:17px; color:var(--k-navy);">Cash</strong>
                <span class="kiosk-pm-badge">Pay at Counter</span>
            </label>
            <label class="kiosk-pm-card" id="pm-card-gcash">
                <input type="radio" name="payment_method" value="gcash">
                <span style="font-size:40px; line-height:1; margin-bottom:10px;">📱</span>
                <strong style="font-size:17px; color:var(--k-navy);">GCash</strong>
                <span class="kiosk-pm-badge">Pay via QR at Counter</span>
            </label>
        </div>

        <label for="customer_name" style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:8px;">
            Name for your order <span style="font-weight:400; color:var(--k-ink-soft);">(optional)</span>
        </label>
        <input id="customer_name" name="customer_name" type="text" maxlength="150" value="<?= h($default_name) ?>"
               placeholder="e.g. Juan" style="width:100%; padding:14px; border-radius:12px; border:2px solid var(--k-border); font-size:15px; margin-bottom:18px;">
        <p style="color:var(--k-ink-soft); font-size:13px; margin:0 0 18px;">
            Payment is collected at the counter. You will receive an Order Slip to show the cashier.
        </p>
        <button type="submit" class="kiosk-btn block" style="font-size:18px;" id="place-order-btn">
            <i class="bi bi-check2-circle"></i> Place Order — ₱<?= money($totals['total']) ?>
        </button>
    </form>
</div>

<div class="checkout-confirm-modal" id="checkout-confirm-modal" aria-hidden="true">
    <section class="checkout-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="checkout-confirm-title" aria-describedby="checkout-confirm-copy">
        <div class="checkout-confirm-icon" aria-hidden="true">!</div>
        <h2 class="checkout-confirm-title" id="checkout-confirm-title">Confirm Your Order</h2>
        <p class="checkout-confirm-copy" id="checkout-confirm-copy">
            Place this order for <strong><?= h($formatted_total) ?></strong> using <strong id="modal-pm-label">Cash</strong>?
            <br><span style="font-size:14px; color:var(--k-ink-soft);">This will generate your Order Slip for the cashier.</span>
        </p>
        <div class="checkout-confirm-actions">
            <button type="button" class="kiosk-btn secondary" id="checkout-go-back">No, Go Back</button>
            <button type="button" class="kiosk-btn" id="checkout-confirm-place">Yes, Place Order</button>
        </div>
    </section>
</div>

<script>
var checkoutForm = document.getElementById('checkout-form');
var checkoutModal = document.getElementById('checkout-confirm-modal');
var placeOrderButton = document.getElementById('place-order-btn');
var confirmPlaceButton = document.getElementById('checkout-confirm-place');
var goBackButton = document.getElementById('checkout-go-back');
var modalPmLabel = document.getElementById('modal-pm-label');

// Handle Payment Method Card clicks
document.querySelectorAll('.kiosk-pm-card').forEach(function (card) {
    card.addEventListener('click', function () {
        document.querySelectorAll('.kiosk-pm-card').forEach(function (c) {
            c.classList.remove('active');
        });
        card.classList.add('active');
        var radio = card.querySelector('input[type="radio"]');
        if (radio) {
            radio.checked = true;
        }
    });
});

function closeCheckoutConfirmation() {
    checkoutModal.classList.remove('is-open');
    checkoutModal.setAttribute('aria-hidden', 'true');
}

document.getElementById('checkout-form').addEventListener('submit', function (e) {
    e.preventDefault();
    if (placeOrderButton.dataset.submitted === '1') return;

    var selectedPm = document.querySelector('input[name="payment_method"]:checked');
    var pmName = (selectedPm && selectedPm.value === 'gcash') ? 'GCash' : 'Cash';
    if (modalPmLabel) {
        modalPmLabel.textContent = pmName;
    }

    checkoutModal.classList.add('is-open');
    checkoutModal.setAttribute('aria-hidden', 'false');
    goBackButton.focus();
});

goBackButton.addEventListener('click', closeCheckoutConfirmation);
checkoutModal.addEventListener('click', function (e) {
    if (e.target === checkoutModal) closeCheckoutConfirmation();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && checkoutModal.classList.contains('is-open')) closeCheckoutConfirmation();
});

confirmPlaceButton.addEventListener('click', function () {
    placeOrderButton.dataset.submitted = '1';
    placeOrderButton.disabled = true;
    confirmPlaceButton.disabled = true;
    confirmPlaceButton.textContent = 'Placing Order…';
    checkoutForm.submit();
});
</script>
<?php kiosk_footer(); ?>
