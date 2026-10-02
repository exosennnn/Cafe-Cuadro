<?php
require 'kiosk_bootstrap.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kiosk_csrf_valid()) {
        $message = 'Your session expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            foreach ($_POST['qty'] ?? [] as $product_id => $qty) {
                $product_id = (int) $product_id;
                $qty = max(1, (int) $qty);
                if (isset($_SESSION['kiosk_cart'][$product_id])) {
                    $stock = (int) $_SESSION['kiosk_cart'][$product_id]['stock'];
                    $_SESSION['kiosk_cart'][$product_id]['quantity'] = min($qty, $stock);
                }
            }
        }

        if ($action === 'remove') {
            $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
            if ($product_id && isset($_SESSION['kiosk_cart'][$product_id])) {
                unset($_SESSION['kiosk_cart'][$product_id]);
            }
        }

        if ($action === 'clear') {
            $_SESSION['kiosk_cart'] = [];
        }
    }
}

$totals = kiosk_cart_totals();
$csrf   = h(kiosk_csrf_token());

kiosk_header('Your Cart', true, 'kiosk_menu.php');
?>
<style>
.kiosk-cart-table{ width:100%; border-collapse:collapse; }
.kiosk-cart-table td{ padding:12px 8px; border-bottom:1px solid var(--k-border); vertical-align:middle; }
.kiosk-cart-item{ display:flex; align-items:center; gap:12px; }
.kiosk-cart-item img, .kiosk-cart-item .ph{ width:56px; height:56px; border-radius:10px; object-fit:cover; background:#f1f5f9; display:flex; align-items:center; justify-content:center; font-size:24px; flex-shrink:0; }
.kiosk-qty-stepper{ display:flex; align-items:center; gap:10px; }
.kiosk-qty-stepper button{ width:38px; height:38px; border-radius:50%; border:2px solid var(--k-border); background:#fff; font-size:18px; font-weight:800; cursor:pointer; }
.kiosk-summary p{ display:flex; justify-content:space-between; margin:8px 0; font-size:15px; }
.kiosk-summary p.grand{ font-size:20px; font-weight:800; color:var(--k-navy); border-top:2px solid var(--k-border); padding-top:12px; margin-top:12px; }
.kiosk-cancel-modal{position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.48);}
.kiosk-cancel-modal.is-open{display:flex;}
.kiosk-cancel-dialog{width:min(100%,524px);padding:52px 42px 30px;border-radius:28px;background:#fff;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.24);}
.kiosk-cancel-icon{width:90px;height:90px;margin:0 auto 34px;border:4px solid #c9a27a;border-radius:50%;color:#c9a27a;font:300 64px/82px Arial,sans-serif;}
.kiosk-cancel-title{margin:0 0 18px;color:#171717;font:800 30px/1.2 var(--font-brand);}
.kiosk-cancel-copy{max-width:410px;margin:0 auto 28px;color:#666;font-size:18px;line-height:1.35;}
.kiosk-cancel-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;}
.kiosk-cancel-actions .kiosk-btn{min-height:42px;padding:10px 21px;font-size:14px;white-space:nowrap;}
@media(max-width:520px){.kiosk-cancel-dialog{padding:36px 20px 24px}.kiosk-cancel-icon{margin-bottom:24px}.kiosk-cancel-title{font-size:25px}.kiosk-cancel-copy{font-size:16px}.kiosk-cancel-actions{flex-direction:column}.kiosk-cancel-actions .kiosk-btn{width:100%}}
</style>

<h1 class="kiosk-title">Your Cart</h1>
<?php if ($message): ?><div class="kiosk-alert error"><?= h($message) ?></div><?php endif; ?>

<?php if (!$_SESSION['kiosk_cart']): ?>
    <div class="kiosk-card" style="text-align:center; padding:50px 20px;">
        <div style="font-size:56px;">🛒</div>
        <p style="font-size:18px; font-weight:700; color:var(--k-navy); margin:14px 0 4px;">Your cart is empty</p>
        <p style="color:var(--k-ink-soft); margin:0 0 20px;">Browse the menu to add something delicious.</p>
        <a href="<?= BASE_URL ?>pos/kiosk-menu" class="kiosk-btn"><i class="bi bi-shop"></i> Browse Menu</a>
    </div>
<?php else: ?>
    <form method="POST" id="kiosk-cart-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update">
        <div class="kiosk-card">
            <table class="kiosk-cart-table">
                <tbody>
                <?php foreach ($_SESSION['kiosk_cart'] as $item): ?>
                    <tr>
                        <td>
                            <div class="kiosk-cart-item">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= h($item['image_url']) ?>" alt="">
                                <?php else: ?>
                                    <div class="ph">🍽️</div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:700;"><?= h($item['name']) ?></div>
                                    <div style="color:var(--k-ink-soft); font-size:13px;">₱<?= money($item['price']) ?> each</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="kiosk-qty-stepper">
                                <button type="button" class="kiosk-qty-minus" data-id="<?= h($item['id']) ?>">&minus;</button>
                                <input type="number" name="qty[<?= h($item['id']) ?>]" value="<?= h($item['quantity']) ?>"
                                       min="1" max="<?= h($item['stock']) ?>" style="width:44px; text-align:center; border:2px solid var(--k-border); border-radius:8px; padding:8px 0; font-weight:700;">
                                <button type="button" class="kiosk-qty-plus" data-id="<?= h($item['id']) ?>" data-max="<?= h($item['stock']) ?>">+</button>
                            </div>
                        </td>
                        <td class="kiosk-line-total" data-price="<?= h($item['price']) ?>" style="text-align:right; font-weight:700;">₱<?= money($item['price'] * $item['quantity']) ?></td>
                        <td>
                            <button type="button" class="kiosk-btn danger kiosk-cancel-item-btn" data-form="remove-form-<?= h($item['id']) ?>" style="min-height:40px; padding:8px 14px; font-size:13px;">
                                <i class="bi bi-x-circle"></i> Cancel Item
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
    <?php foreach ($_SESSION['kiosk_cart'] as $item): ?>
        <form method="POST" id="remove-form-<?= h($item['id']) ?>" style="display:none;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="product_id" value="<?= h($item['id']) ?>">
        </form>
    <?php endforeach; ?>

    <div class="kiosk-card kiosk-summary" style="margin-top:18px;">
        <p><span>Subtotal</span><span id="kiosk-cart-subtotal">₱<?= money($totals['subtotal']) ?></span></p>
        <p><span>Tax (<?= (KIOSK_TAX_RATE * 100) ?>%)</span><span id="kiosk-cart-tax">₱<?= money($totals['tax']) ?></span></p>
        <p class="grand"><span>Total</span><span id="kiosk-cart-total">₱<?= money($totals['total']) ?></span></p>
    </div>

    <div style="display:flex; gap:12px; margin-top:18px; flex-wrap:wrap;">
        <a href="<?= BASE_URL ?>pos/kiosk-menu" class="kiosk-btn secondary" style="flex:1; min-width:180px;"><i class="bi bi-plus-lg"></i> Add More Items</a>
        <a href="<?= BASE_URL ?>pos/kiosk-checkout" class="kiosk-btn" style="flex:1; min-width:180px;"><i class="bi bi-check2-circle"></i> Proceed to Confirm</a>
    </div>
    <div class="kiosk-cancel-modal" id="kiosk-cancel-modal" aria-hidden="true">
        <section class="kiosk-cancel-dialog" role="dialog" aria-modal="true" aria-labelledby="kiosk-cancel-title" aria-describedby="kiosk-cancel-copy">
            <div class="kiosk-cancel-icon" aria-hidden="true">!</div>
            <h2 class="kiosk-cancel-title" id="kiosk-cancel-title">Cancel Transaction</h2>
            <p class="kiosk-cancel-copy" id="kiosk-cancel-copy">Are you sure you want to cancel this transaction? All items currently in the cart will be removed.</p>
            <div class="kiosk-cancel-actions">
                <button type="button" class="kiosk-btn" id="keep-transaction-btn">No, Keep Transaction</button>
                <button type="button" class="kiosk-btn" id="confirm-cancel-transaction-btn">Yes, Cancel Transaction</button>
            </div>
        </section>
    </div>
<?php endif; ?>

<script>
var kioskTaxRate = <?= json_encode((float) KIOSK_TAX_RATE) ?>;
var peso = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function recalculateKioskCart() {
    var subtotal = 0;
    document.querySelectorAll('#kiosk-cart-form tbody tr').forEach(function (row) {
        var input = row.querySelector('input[type=number]');
        var line = row.querySelector('.kiosk-line-total');
        if (!input || !line) return;
        var qty = Math.max(1, parseInt(input.value, 10) || 1);
        var max = parseInt(input.max, 10);
        if (!isNaN(max) && max > 0) qty = Math.min(max, qty);
        input.value = qty;
        var lineTotal = (parseFloat(line.dataset.price) || 0) * qty;
        line.textContent = '₱' + peso.format(lineTotal);
        subtotal += lineTotal;
    });
    var tax = Math.round(subtotal * kioskTaxRate * 100) / 100;
    var total = Math.round((subtotal + tax) * 100) / 100;
    document.getElementById('kiosk-cart-subtotal').textContent = '₱' + peso.format(subtotal);
    document.getElementById('kiosk-cart-tax').textContent = '₱' + peso.format(tax);
    document.getElementById('kiosk-cart-total').textContent = '₱' + peso.format(total);
}
var quantitySaveTimer;
function saveKioskQuantities() {
    window.clearTimeout(quantitySaveTimer);
    quantitySaveTimer = window.setTimeout(function () {
        document.getElementById('kiosk-cart-form').submit();
    }, 300);
}
document.querySelectorAll('.kiosk-qty-minus, .kiosk-qty-plus').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = this.parentElement.querySelector('input[type=number]');
        var val = parseInt(input.value, 10) || 1;
        if (this.classList.contains('kiosk-qty-minus')) {
            val = Math.max(1, val - 1);
        } else {
            var max = parseInt(this.dataset.max, 10);
            val = isNaN(max) ? val + 1 : Math.min(max, val + 1);
        }
        input.value = val;
        recalculateKioskCart();
        saveKioskQuantities();
    });
});
document.querySelectorAll('#kiosk-cart-form input[name^="qty["]').forEach(function (input) {
    input.addEventListener('input', recalculateKioskCart);
    input.addEventListener('change', function () {
        recalculateKioskCart();
        saveKioskQuantities();
    });
});

var cancelModal = document.getElementById('kiosk-cancel-modal');
var keepTransactionButton = document.getElementById('keep-transaction-btn');
var confirmCancelButton = document.getElementById('confirm-cancel-transaction-btn');
var cancelTitle = document.getElementById('kiosk-cancel-title');
var cancelCopy = document.getElementById('kiosk-cancel-copy');
var selectedCancelForm = null;
if (cancelModal) {
    function closeCancelModal() {
        cancelModal.classList.remove('is-open');
        cancelModal.setAttribute('aria-hidden', 'true');
        selectedCancelForm = null;
    }
    document.querySelectorAll('.kiosk-cancel-item-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            selectedCancelForm = document.getElementById(button.dataset.form);
            cancelTitle.textContent = 'Cancel Item';
            cancelCopy.textContent = 'Are you sure you want to remove this item from your cart?';
            cancelModal.classList.add('is-open');
            cancelModal.setAttribute('aria-hidden', 'false');
            keepTransactionButton.focus();
        });
    });
    keepTransactionButton.addEventListener('click', closeCancelModal);
    confirmCancelButton.addEventListener('click', function () {
        if (selectedCancelForm) selectedCancelForm.submit();
    });
    cancelModal.addEventListener('click', function (e) { if (e.target === cancelModal) closeCancelModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && cancelModal.classList.contains('is-open')) closeCancelModal();
    });
}
</script>
<?php kiosk_footer(); ?>
