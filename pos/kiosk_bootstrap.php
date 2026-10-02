<?php
/**
 * kiosk_bootstrap.php
 * -----------------------------------------------------------------------
 * Shared foundation for every Customer/Kiosk page. This file is NEW and
 * does not modify any existing Admin/POS file. It intentionally uses its
 * own session cookie name so a guest ordering on a kiosk never collides
 * with a cashier's logged-in POS session on the same browser/device, and
 * a kiosk "cart" never touches $_SESSION['cart'] used by sales_transaction.php.
 *
 * All kiosk orders are written into the SAME `transactions` /
 * `transaction_items` tables the existing POS uses (see sales_transaction.php)
 * so they appear normally to Admin. A handful of additive, nullable
 * columns are added to `transactions` (and `users.role` is widened) the
 * first time this file runs, via ensure_kiosk_schema() below — the same
 * "create table/column if missing" pattern app.php already uses for
 * low_stock_notifications.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('POS_KIOSK');
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/app.php'; // reuse h(), money(), page shell helpers are NOT used here — kiosk has its own chrome

define('KIOSK_TAX_RATE', defined('TAX_RATE') ? TAX_RATE : 0.08);
const KIOSK_ORDER_STATUSES = ['pending', 'paid', 'confirmed', 'processing', 'ready', 'completed', 'cancelled'];
const KIOSK_STATUS_LABELS = [
    'pending'         => 'Pending Payment',
    'pending_payment' => 'Pending Payment',
    'paid'            => 'Paid',
    'confirmed'       => 'Paid',
    'processing'      => 'Preparing',
    'ready'           => 'Ready for Pickup',
    'completed'       => 'Completed',
    'cancelled'       => 'Cancelled',
];

function kiosk_payment_method_label($method) {
    $m = strtolower(trim((string)$method));
    if ($m === 'gcash') return 'GCash';
    if ($m === 'cash') return 'Cash';
    if ($m === 'credit') return 'Credit Card';
    if ($m === 'debit') return 'Debit Card';
    return ucfirst($m ?: 'Cash');
}

/* -------------------------------------------------------------------
 * Schema — additive & idempotent. Never drops/renames/narrows anything
 * the existing POS relies on; only widens or adds nullable columns.
 * ------------------------------------------------------------------- */
function ensure_kiosk_schema() {
    global $conn;
    static $done = false;
    if ($done || !isset($conn)) {
        return;
    }
    $done = true;

    try {
        $dbname = $conn->query('SELECT DATABASE()')->fetchColumn();

        $col_exists = function ($table, $column) use ($conn, $dbname) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
            $stmt->execute([$dbname, $table, $column]);
            return (bool) $stmt->fetchColumn();
        };
        $col_type = function ($table, $column) use ($conn, $dbname) {
            $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
            $stmt->execute([$dbname, $table, $column]);
            $val = $stmt->fetchColumn();
            return $val !== false ? $val : null;
        };
        $col_nullable = function ($table, $column) use ($conn, $dbname) {
            $stmt = $conn->prepare("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?");
            $stmt->execute([$dbname, $table, $column]);
            return $stmt->fetchColumn() === 'YES';
        };

        // --- transactions: additive columns that track kiosk orders ---
        if (!$col_exists('transactions', 'source')) {
            $conn->exec("ALTER TABLE transactions ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'pos' AFTER status");
        }
        if (!$col_exists('transactions', 'order_type')) {
            $conn->exec("ALTER TABLE transactions ADD COLUMN order_type VARCHAR(10) NULL DEFAULT NULL AFTER source");
        }
        if (!$col_exists('transactions', 'customer_name')) {
            $conn->exec("ALTER TABLE transactions ADD COLUMN customer_name VARCHAR(150) NULL DEFAULT NULL AFTER order_type");
        }
        if (!$col_exists('transactions', 'guest_token')) {
            $conn->exec("ALTER TABLE transactions ADD COLUMN guest_token CHAR(36) NULL DEFAULT NULL AFTER customer_name");
            $conn->exec("ALTER TABLE transactions ADD INDEX idx_guest_token (guest_token)");
        }

        // Existing POS inserts always pass a status of 'completed' as a
        // literal, so widening this column to a plain VARCHAR is a pure
        // superset — no existing value or query breaks.
        $status_type = $col_type('transactions', 'status');
        if ($status_type !== null && stripos($status_type, 'varchar(20)') === false && stripos($status_type, 'varchar(30)') === false) {
            $conn->exec("ALTER TABLE transactions MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending'");
        }

        // Widen payment_method so 'gcash' is supported alongside 'cash', 'credit', 'debit'
        $pm_type = $col_type('transactions', 'payment_method');
        if ($pm_type !== null && (stripos($pm_type, 'enum') !== false || (stripos($pm_type, 'varchar(30)') === false && stripos($pm_type, 'varchar(50)') === false))) {
            $conn->exec("ALTER TABLE transactions MODIFY COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cash'");
        }

        // Guest checkout means a transaction may have no staff user_id.
        if (!$col_nullable('transactions', 'user_id')) {
            $conn->exec("ALTER TABLE transactions MODIFY COLUMN user_id INT NULL DEFAULT NULL");
        }

        // --- users: widen role so customers can self-register without ---
        // --- touching the admin/cashier values user_management.php uses ---
        $role_type = $col_type('users', 'role');
        if ($role_type !== null && stripos($role_type, 'enum') !== false) {
            $conn->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(20) NOT NULL DEFAULT 'customer'");
        }
    } catch (PDOException $e) {
        // Never take the kiosk down over a migration hiccup (e.g. the DB
        // user lacks ALTER privileges) — degrade instead of fatal-erroring,
        // and surface it once so it's visible in server logs.
        error_log('Kiosk schema check failed: ' . $e->getMessage());
    }
}
ensure_kiosk_schema();

/* -------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------- */
function kiosk_csrf_token() {
    if (empty($_SESSION['kiosk_csrf_token'])) {
        $_SESSION['kiosk_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['kiosk_csrf_token'];
}

function kiosk_csrf_valid() {
    return isset($_POST['csrf_token']) && !empty($_SESSION['kiosk_csrf_token']) && hash_equals($_SESSION['kiosk_csrf_token'], (string) $_POST['csrf_token']);
}

function kiosk_customer_logged_in() {
    return isset($_SESSION['customer_id']);
}

function kiosk_customer_name() {
    return $_SESSION['customer_full_name'] ?? '';
}

function require_kiosk_customer_login() {
    if (!kiosk_customer_logged_in()) {
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/unified/') . 'pos/kiosk-login');
        exit;
    }
}

if (!isset($_SESSION['kiosk_cart'])) {
    $_SESSION['kiosk_cart'] = [];
}

function kiosk_cart_totals() {
    $subtotal = 0;
    foreach ($_SESSION['kiosk_cart'] as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    $tax   = round($subtotal * KIOSK_TAX_RATE, 2);
    $total = round($subtotal + $tax, 2);
    return [
        'subtotal' => $subtotal,
        'discount' => 0.0,
        'tax'      => $tax,
        'total'    => $total,
    ];
}

function kiosk_cart_count() {
    $count = 0;
    foreach ($_SESSION['kiosk_cart'] as $item) {
        $count += (int) $item['quantity'];
    }
    return $count;
}

function kiosk_status_label($status) {
    return KIOSK_STATUS_LABELS[$status] ?? ucfirst((string) $status);
}

function kiosk_status_badge_class($status) {
    $map = [
        'pending'    => 'kiosk-badge-pending',
        'confirmed'  => 'kiosk-badge-confirmed',
        'processing' => 'kiosk-badge-processing',
        'ready'      => 'kiosk-badge-ready',
        'completed'  => 'kiosk-badge-completed',
        'cancelled'  => 'kiosk-badge-cancelled',
    ];
    return $map[$status] ?? 'kiosk-badge-pending';
}

/* -------------------------------------------------------------------
 * Shared touch-friendly page chrome (visually consistent with the
 * existing "Sky Glass" POS theme, but a separate, self-contained shell
 * so app.php's page_header()/page_footer() — used by every Admin page —
 * is never touched).
 * ------------------------------------------------------------------- */
function kiosk_header($title, $show_back = true, $back_url = 'kiosk', $extra_head = '') {
    $cart_count = kiosk_cart_count();
    $brand = h(CAFE_BUSINESS_NAME);
    $assetBase = BASE_URL;
    $cleanBack = preg_match('~^(?:https?://|/)~i', $back_url)
        ? $back_url
        : (function_exists('url') ? url($back_url) : (BASE_URL . 'pos/' . ltrim($back_url, '/')));
    $cartUrl = function_exists('url') ? url('pos/kiosk-cart') : (BASE_URL . 'pos/kiosk-cart');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>{$brand} Kiosk - {$title}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
{$extra_head}
<style>
:root{
    --k-navy:#2a1810; --k-navy-deep:#1d100a; --k-blue:#5e6b46; --k-blue-light:#6f7d54;
    --k-mint:#5e6b46; --k-orange:#5e6b46; --k-red:#dc2626; --k-ink:#1e2022; --k-ink-soft:#7a6558;
    --k-border:#e6d8c5; --k-radius:18px; --k-radius-lg:24px;
    --k-shadow:0 10px 30px rgba(42,24,16,0.08);
    --font-brand:'Fraunces',Georgia,serif; --font-body:'DM Sans','Segoe UI',Roboto,Arial,sans-serif;
}
*{box-sizing:border-box;}
html,body{height:100%;}
body{
    margin:0; font-family:var(--font-body); color:var(--k-ink);
    background:#f5ebdd;
    min-height:100vh; display:flex; flex-direction:column; overscroll-behavior:none;
}
.kiosk-topbar{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:16px 20px; background:var(--k-navy);
    color:#fff; box-shadow:0 4px 18px rgba(29,16,10,0.25);
}
.kiosk-brand{display:flex; align-items:center; gap:12px; font-family:var(--font-brand); font-weight:800; font-size:20px;}
.kiosk-brand .logo{width:44px; height:44px; border-radius:12px; background:rgba(201,162,122,0.22); display:grid; place-items:center; font-size:20px;}
.kiosk-back-btn, .kiosk-cart-btn{
    display:inline-flex; align-items:center; gap:8px; min-height:48px; min-width:48px; padding:10px 18px;
    border-radius:999px; border:1px solid rgba(255,255,255,0.25); background:rgba(255,255,255,0.14);
    color:#fff; text-decoration:none; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s, transform .15s;
}
.kiosk-back-btn:hover, .kiosk-cart-btn:hover{background:rgba(255,255,255,0.26); transform:translateY(-1px);}
.kiosk-cart-btn{background:var(--k-blue); border-color:transparent; position:relative;}
.kiosk-cart-badge{
    position:absolute; top:-6px; right:-6px; background:var(--k-red); color:#fff; font-size:11px; font-weight:800;
    min-width:20px; height:20px; border-radius:999px; display:flex; align-items:center; justify-content:center; padding:0 5px;
}
.kiosk-main{flex:1; padding:22px; max-width:1100px; margin:0 auto; width:100%;}
.kiosk-title{font-family:var(--font-brand); font-weight:800; font-size:26px; color:var(--k-navy); margin:4px 0 18px;}
.kiosk-card{background:#fff; border:1px solid var(--k-border); border-radius:var(--k-radius); box-shadow:var(--k-shadow); padding:20px;}
.kiosk-btn{
    display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:52px; padding:12px 24px;
    border-radius:50px; border:0; background:var(--k-blue); color:#fff;
    font-family:var(--font-body); font-weight:800; font-size:16px; cursor:pointer; box-shadow:0 6px 18px rgba(94,107,70,0.30);
    transition:transform .12s, box-shadow .12s; text-decoration:none;
}
.kiosk-btn:hover{transform:translateY(-1px); box-shadow:0 10px 22px rgba(94,107,70,0.36);}
.kiosk-btn:active{transform:translateY(1px);}
.kiosk-btn:disabled{background:#cbd5e1; box-shadow:none; cursor:not-allowed;}
.kiosk-btn.secondary{background:#fff; color:var(--k-navy); border:2px solid var(--k-border); box-shadow:none;}
.kiosk-btn.secondary:hover{border-color:var(--k-blue); background:#eef1e4;}
.kiosk-btn.danger{background:linear-gradient(135deg,#ef4444,var(--k-red));}
.kiosk-btn.block{width:100%;}
.kiosk-alert{border-radius:14px; padding:14px 16px; font-weight:600; font-size:14px; margin-bottom:16px;}
.kiosk-alert.error{background:#fee2e2; color:#991b1b;}
.kiosk-alert.success{background:#d1fae5; color:#065f46;}
.kiosk-alert.info{background:#eef1e4; color:#4c5838;}
.kiosk-badge-pending{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.kiosk-badge-confirmed{background:#eef1e4;color:#4c5838;border:1px solid #cfd8bd;}
.kiosk-badge-processing{background:#f5ede6;color:#5c4033;border:1px solid #e8ded4;}
.kiosk-badge-ready{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;}
.kiosk-badge-completed{background:#e8f5e9;color:#1b5e20;border:1px solid #c8e6c9;}
.kiosk-badge-cancelled{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}
[class^="kiosk-badge-"]{display:inline-block; padding:4px 12px; border-radius:999px; font-size:12px; font-weight:700;}
footer.kiosk-footer{text-align:center; padding:14px; color:var(--k-ink-soft); font-size:12px;}
@media (prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.001ms !important; transition-duration:.001ms !important;}}
</style>
<link rel="stylesheet" href="{$assetBase}assets/shared/responsive.css">
</head>
<body>
<div class="kiosk-topbar">
HTML;

    if ($show_back) {
        echo '<a class="kiosk-back-btn" href="' . h($cleanBack) . '"><i class="bi bi-arrow-left"></i> Back</a>';
    } else {
        echo '<span class="kiosk-brand">' . $brand . '</span>';
    }

    echo '<span class="kiosk-brand" style="font-size:16px;">' . h($title) . '</span>';

    echo '<a class="kiosk-cart-btn" href="' . h($cartUrl) . '"><i class="bi bi-cart3"></i> Cart';
    if ($cart_count > 0) {
        echo '<span class="kiosk-cart-badge">' . (int) $cart_count . '</span>';
    }
    echo '</a>';

    echo '</div><main class="kiosk-main">';
}

function kiosk_footer($inactivity_redirect = 'pos/kiosk', $inactivity_seconds = 30) {
    if (preg_match('~^(?:https?://|/|#)~i', $inactivity_redirect)) {
        $redirect = $inactivity_redirect;
    } else {
        $redirect = function_exists('url') ? url($inactivity_redirect) : (BASE_URL . 'pos/kiosk');
    }
    $redirect = h($redirect);
    $seconds  = (int) $inactivity_seconds * 1000;
    $brand    = h(CAFE_BUSINESS_NAME);
    echo <<<HTML
</main>
<footer class="kiosk-footer">{$brand} Self-Order Kiosk</footer>
<script>
(function () {
    var timer;
    var redirectTo = "{$redirect}";
    var timeoutMs = {$seconds};
    function resetTimer() {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
            window.location.href = redirectTo;
        }, timeoutMs);
    }
    ['click','touchstart','mousemove','keydown','scroll'].forEach(function (evt) {
        document.addEventListener(evt, resetTimer, { passive: true });
    });
    resetTimer();
})();
</script>
</body>
</html>
HTML;
}

