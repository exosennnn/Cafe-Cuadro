<?php
/**
 * POS APP HELPERS
 * require_login() / current_user_role() / is_admin() / require_admin()
 * used to be defined here against a standalone POS-only session. They
 * now come from the unified includes/auth.php (required below), which
 * reads/writes the SAME session used by HRMS/Inventory/Finance - so a
 * Cashier or Inventory Staff member logs in once, in one place, and
 * both the staff dashboard AND these POS pages recognize them.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php'; // for applyPosSaleToInventoryAndFinance()

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return number_format((float) $value, 2);
}

define('LOW_STOCK_THRESHOLD', 10);

function initialize_low_stock_notifications_table() {
    global $conn;
    if (!isset($conn)) {
        return;
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS low_stock_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        current_stock INT NOT NULL,
        threshold INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME DEFAULT NULL,
        INDEX (product_id),
        INDEX (resolved_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function low_stock_notification_count() {
    global $conn;
    if (!isset($conn)) {
        return 0;
    }
    initialize_low_stock_notifications_table();
    return (int) $conn->query("SELECT COUNT(*) FROM low_stock_notifications WHERE resolved_at IS NULL")->fetchColumn();
}

function get_low_stock_notifications() {
    global $conn;
    if (!isset($conn)) {
        return [];
    }
    initialize_low_stock_notifications_table();
    $stmt = $conn->query("SELECT n.product_id, n.current_stock, n.threshold, n.created_at, p.name AS product_name
        FROM low_stock_notifications n
        LEFT JOIN products p ON p.id = n.product_id
        WHERE n.resolved_at IS NULL
        ORDER BY n.created_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sync_low_stock_notification_by_product($product_id, $stock = null) {
    global $conn;
    if (!isset($conn) || !$product_id) {
        return;
    }

    if ($stock === null) {
        $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $stock = $stmt->fetchColumn();
        if ($stock === false) {
            return;
        }
    }

    $stock = (int) $stock;
    $threshold = LOW_STOCK_THRESHOLD;

    if ($stock <= $threshold) {
        $stmt = $conn->prepare("SELECT id FROM low_stock_notifications WHERE product_id = ? AND resolved_at IS NULL LIMIT 1");
        $stmt->execute([$product_id]);
        if ($stmt->fetchColumn()) {
            $update = $conn->prepare("UPDATE low_stock_notifications SET current_stock = ?, threshold = ? WHERE product_id = ? AND resolved_at IS NULL");
            $update->execute([$stock, $threshold, $product_id]);
        } else {
            $insert = $conn->prepare("INSERT INTO low_stock_notifications (product_id, current_stock, threshold) VALUES (?, ?, ?)");
            $insert->execute([$product_id, $stock, $threshold]);
        }
    } else {
        $resolve = $conn->prepare("UPDATE low_stock_notifications SET resolved_at = NOW() WHERE product_id = ? AND resolved_at IS NULL");
        $resolve->execute([$product_id]);
    }
}

/**
 * Notify every Inventory Staff account (bell notification, via the shared
 * `notifications` table / notifyRole() in includes/functions.php) the
 * moment a product's branch stock drops to zero. Call this ONLY at the
 * exact transition (old stock > 0, new stock <= 0) - not on every sale or
 * every save - so Inventory Staff get one alert per stockout, not a
 * flood of repeats while it stays at 0.
 *
 * This closes the loop the Owner asked for: Product out of stock ->
 * Inventory Staff notified -> they raise a Purchase Request (Procurement
 * module) -> Owner gets notified to approve it (see notifyRole() call in
 * modules/procurement/purchase_requests.php).
 */
function notify_inventory_out_of_stock($product_id, $branch_id = null) {
    global $conn;
    if (!isset($conn) || !$product_id) {
        return;
    }

    $stmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $productName = $stmt->fetchColumn();
    if ($productName === false) {
        return;
    }

    $branchName = null;
    if ($branch_id) {
        $bstmt = $conn->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
        $bstmt->execute([$branch_id]);
        $branchName = $bstmt->fetchColumn() ?: null;
    }

    $message = $branchName
        ? "\"{$productName}\" is now out of stock at {$branchName}. Check ingredient stock and raise a purchase request if needed."
        : "\"{$productName}\" is now out of stock. Check ingredient stock and raise a purchase request if needed.";

    notifyRole($conn, ROLE_INVENTORY_STAFF, 'Product Out of Stock', $message, 'product', (int) $product_id);
}

function current_user_name() {
    return $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
}

/**
 * Renders a nav link for the sidebar, marking it active against the current file.
 */
function pos_nav_item($url, $icon, $label, $current, $badge = 0) {
    $clean = function_exists('cleanRoute') ? cleanRoute($url) : $url;
    $linkUrl = function_exists('url') ? url($clean) : (BASE_URL . ltrim($clean, '/'));
    $active = (rtrim(cleanRoute($url), '/') === rtrim($current, '/') || basename($url) === $current || basename($clean) === $current) ? 'active' : '';
    $badgeHtml = $badge > 0 ? ' <span class="badge bg-warning text-dark">' . (int) $badge . '</span>' : '';
    echo '<a href="' . h($linkUrl) . '" class="nav-link ' . $active . '"><i class="bi ' . h($icon) . '"></i> <span>' . h($label) . '</span>' . $badgeHtml . '</a>';
}

/**
 * Cycles through the KPI accent palette by index (0-based).
 */
function pos_kpi_accent($index) {
    $accents = ['blue', 'mint', 'orange', 'red', 'purple', 'teal'];
    return $accents[$index % count($accents)];
}

function page_header($title, $subtitle = '', $icon = 'bi-speedometer2') {
    $name    = h(current_user_name());
    $role    = h(currentRoleName());
    $initial = h(strtoupper(substr(current_user_name(), 0, 1)));
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $baseUrlPath = parse_url(BASE_URL, PHP_URL_PATH) ?: BASE_URL;
    $current = (strpos($reqPath, $baseUrlPath) === 0) ? ltrim(substr($reqPath, strlen($baseUrlPath)), '/') : ltrim($reqPath, '/');
    $current = trim($current, '/');
    $low_stock_count = is_admin() ? low_stock_notification_count() : 0;
    $assetBase = BASE_URL;

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - {$title}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="{$assetBase}assets/shared/unified.css">
    <link rel="stylesheet" href="{$assetBase}assets/shared/responsive.css">
    <link rel="stylesheet" href="{$assetBase}assets/shared/roast-app.css">
    <style>
        /* ============================================
           POS — Purr'Coffee Café Theme
           Harmonized with the Unified Design System
           ============================================ */
        :root {
            --navy: #2a1810;
            --navy-deep: #2a1810;
            --navy-mid: #382724;
            --blue: #5e6b46;
            --blue-light: #7d8b62;
            --blue-soft: #eef1e4;
            --mint: #2e7d32;
            --mint-soft: #e8f5e9;
            --orange: #5e6b46;
            --orange-soft: #eef1e4;
            --red: #c62828;
            --red-soft: #ffebee;
            --purple: #795548;
            --purple-soft: #efebe9;
            --teal: #00897b;
            --teal-soft: #e0f2f1;
            --sidebar-bg: #ffffff;
            --content-bg: #f5ebdd;
            --ink: #2a1810;
            --ink-soft: #7a6558;
            --ink-faint: #a08c7a;
            --border-soft: #e6d8c5;
            --glass: #ffffff;
            --glass-border: #e6d8c5;
            --sidebar-width: 260px;
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 22px;
            --shadow-sm: 0 2px 6px rgba(42,24,16,0.04);
            --shadow-md: 0 4px 16px rgba(42,24,16,0.06);
            --shadow-lg: 0 10px 30px rgba(42,24,16,0.10);
            --shadow-glow: 0 0 0 1px rgba(94,107,70,0.15), 0 12px 28px rgba(94,107,70,0.18);
            --font-brand: 'Fraunces', Georgia, 'Times New Roman', serif;
            --font-body: 'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        * { scrollbar-color: rgba(94,107,70,0.35) transparent; }
        *::-webkit-scrollbar-thumb { background: rgba(94,107,70,0.35); border-radius: 999px; }
        body {
            font-family: var(--font-body); color: var(--ink); -webkit-font-smoothing: antialiased;
            background: #f5ebdd;
        }
        a { color: var(--blue); text-decoration: none; }
        a:hover { color: #4c5838; }
        h1, h2, h3 { font-family: var(--font-brand); color: var(--ink); }

        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes dropIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulseSoft { 0%, 100% { box-shadow: 0 0 0 0 rgba(94,107,70,0.25); } 50% { box-shadow: 0 0 0 5px rgba(94,107,70,0); } }

        /* Navbar */
        .app-navbar {
            background: #ffffff;
            position: sticky; top: 0; z-index: 1030;
            border-bottom: 1px solid var(--border-soft);
            box-shadow: 0 2px 12px rgba(42,24,16,0.04);
            min-height: 64px;
            padding: 0 22px;
            display: flex; align-items: center; justify-content: space-between; gap: 14px;
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; }
        #sidebarToggle {
            background: #fbf6ee; border: 1px solid var(--border-soft); color: var(--ink); font-size: 20px;
            width: 40px; height: 40px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center; cursor: pointer;
            transition: all .18s ease;
        }
        #sidebarToggle:hover { background: #eef1e4; color: var(--blue); border-color: var(--blue); }
        .navbar-brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 1.25rem; text-decoration: none; color: var(--ink); font-family: var(--font-brand); }
        .navbar-brand:hover { color: var(--blue); }
        .navbar-brand .brand-icon {
            width: 36px; height: 36px; border-radius: 10px; background: rgba(94,107,70,0.12);
            display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--blue);
        }
        .navbar-right { display: flex; align-items: center; gap: 14px; }
        .navbar-right .role-badge {
            font-weight: 700; font-size: .72rem; letter-spacing: .4px; text-transform: uppercase;
            background: #eef1e4; border: 1px solid #cfd8bd; color: var(--blue); padding: 5px 14px; border-radius: 999px;
        }
        .notification-bell {
            position: relative; color: var(--ink); font-size: 18px; text-decoration: none; display: inline-flex;
            align-items: center; justify-content: center;
            width: 38px; height: 38px; border-radius: 50%; background: #fbf6ee; border: 1px solid var(--border-soft);
            transition: all .18s ease;
        }
        .notification-bell:hover { color: var(--blue); background: #eef1e4; border-color: var(--blue); }
        .user-dropdown { position: relative; }
        .user-dropdown-btn {
            display: flex; align-items: center; gap: 9px;
            background: #fbf6ee; border: 1px solid var(--border-soft); border-radius: 999px;
            padding: 5px 16px 5px 6px;
            color: var(--ink); cursor: pointer; font-family: var(--font-body); font-weight: 600;
            transition: all .18s ease;
        }
        .user-dropdown-btn:hover, .user-dropdown-btn:focus, .user-dropdown-btn[aria-expanded="true"] { background: #eef1e4; border-color: var(--blue); color: var(--blue); }
        .user-avatar {
            width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, var(--blue), #4c5838);
            box-shadow: 0 2px 8px rgba(94,107,70,0.28);
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; color: #fff;
            flex-shrink: 0;
        }
        .app-navbar .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            left: auto;
            z-index: 1050;
            display: none;
            min-width: 200px;
            border: 1px solid var(--border-soft); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); margin-top: 8px;
            background: #ffffff;
            animation: dropIn .18s ease both;
            overflow: hidden;
            padding: 6px;
        }
        .app-navbar .dropdown-menu.show { display: block; }
        .app-navbar .dropdown-menu::before,
        .app-navbar .dropdown-menu::after { display: none !important; content: none !important; }
        .app-navbar .dropdown-item {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; font-size: .88rem; border-radius: var(--radius-sm); margin: 0; width: 100%;
            color: var(--ink); font-weight: 600; transition: background .15s ease, color .15s ease;
            background: none; border: 0; text-align: left;
        }
        .app-navbar .dropdown-item i { font-size: 1rem; width: 18px; text-align: center; }
        .app-navbar .dropdown-item:hover, .app-navbar .dropdown-item:focus { background: var(--blue-soft); color: var(--blue); }
        .app-navbar .dropdown-item.text-danger { color: var(--red); }
        .app-navbar .dropdown-item.text-danger:hover { background: var(--red-soft); color: var(--red); }
        .app-navbar .dropdown-divider { margin: 4px 8px; border-top: 1px solid var(--border-soft); }

        /* Shell / Sidebar */
        .app-wrapper { display: flex; min-height: calc(100vh - 64px); }
        .app-sidebar {
            width: var(--sidebar-width); background: #ffffff;
            border-right: 1px solid var(--border-soft);
            min-height: calc(100vh - 64px); flex-shrink: 0; overflow-y: auto;
            transition: margin-left .25s cubic-bezier(.4,0,.2,1);
        }
        .sidebar-group-label {
            font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
            color: var(--ink-faint); padding: 16px 18px 6px;
        }
        .sidebar-group-label:first-child { padding-top: 18px; }
        .app-sidebar .nav-link {
            color: #5a4638; padding: 10px 16px; border-radius: 50px; margin: 3px 12px;
            font-size: .9rem; font-weight: 600; display: flex; align-items: center; gap: 12px;
            transition: all .18s ease;
        }
        .app-sidebar .nav-link i { width: 20px; text-align: center; font-size: 1.05rem; flex-shrink: 0; color: #a08c7a; transition: color .18s ease; }
        .app-sidebar .nav-link:hover { background: var(--blue-soft); color: var(--blue); transform: translateX(3px); }
        .app-sidebar .nav-link:hover i { color: var(--blue); }
        .app-sidebar .nav-link.active { background: var(--blue); color: #fff; box-shadow: 0 4px 14px rgba(94,107,70,0.32); font-weight: 700; }
        .app-sidebar .nav-link.active i { color: #fff; }
        .app-sidebar .nav-link .badge.bg-warning { animation: pulseSoft 2.4s ease-in-out infinite; }
        .sidebar-footer { border-top: 1px solid var(--border-soft); padding: 14px 18px; font-size: .78rem; color: var(--ink-soft); display: flex; align-items: center; gap: 10px; }

        .app-sidebar.collapsed { margin-left: calc(var(--sidebar-width) * -1); }
        @media (max-width: 900px) {
            .app-sidebar { position: fixed; left: 0; top: 64px; z-index: 1020; box-shadow: 2px 0 16px rgba(42,24,16,0.12); margin-left: calc(var(--sidebar-width) * -1); background: #ffffff; }
            .app-sidebar.show { margin-left: 0; }
            .app-sidebar.collapsed { margin-left: calc(var(--sidebar-width) * -1); }
        }

        /* Content */
        .main, .app-content { flex-grow: 1; padding: 30px; width: 100%; min-width: 0; animation: fadeUp .35s ease; background: #f5ebdd; }
        .emp-page-header { margin-bottom: 1.75rem; animation: fadeUp .4s ease both; }
        .emp-page-header h1 { font-size: 1.6rem; font-weight: 800; margin: 0 0 4px; color: var(--ink); }
        .emp-page-header p { font-size: .92rem; color: var(--ink-soft); margin: 0; }
        .emp-page-icon { display: none; }

        /* Cards / Panels */
        .grid { display: grid; gap: 18px; }
        .cards { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        .card, .panel {
            background: #ffffff; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-soft); padding: 22px;
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
            animation: fadeUp .4s ease both;
            position: relative;
        }
        .card:hover, .panel:hover { transform: translateY(-2px); border-color: var(--blue-light); box-shadow: var(--shadow-md); }
        .card strong { display: block; font-size: 28px; font-weight: 800; margin-top: 10px; color: var(--ink); font-family: var(--font-brand); }
        .panel h2 {
            font-size: 16px; font-weight: 800; color: var(--ink); margin-bottom: 16px;
            padding-bottom: 12px; border-bottom: 1px solid var(--border-soft); font-family: var(--font-brand);
        }

        /* KPI stat cards */
        .stat-card, .kpi-card {
            background: #ffffff; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-soft); padding: 20px 22px; position: relative; overflow: hidden;
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
            animation: fadeUp .4s ease both;
        }
        .stat-card:hover, .kpi-card:hover { transform: translateY(-2px); border-color: var(--blue-light); box-shadow: var(--shadow-md); }
        .kpi-card small, .stat-card small { display: block; font-size: .74rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--ink-soft); }
        .kpi-card h3, .stat-card h3 { font-family: var(--font-brand); font-weight: 800; font-size: 1.75rem; margin: 10px 0 0; color: var(--ink); }
        .kpi-icon {
            position: absolute; top: 18px; right: 18px; width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
        }
        .kpi-icon.blue   { background: var(--blue-soft);   color: var(--blue); }
        .kpi-icon.mint   { background: var(--mint-soft);   color: var(--mint); }
        .kpi-icon.orange { background: var(--orange-soft); color: var(--orange); }
        .kpi-icon.red    { background: var(--red-soft);    color: var(--red); }
        .kpi-icon.purple { background: var(--purple-soft); color: var(--purple); }
        .kpi-icon.teal   { background: var(--teal-soft);   color: var(--teal); }
        .bg-grad-blue, .bg-grad-green, .bg-grad-orange, .bg-grad-purple { background: var(--blue) !important; color: #fff; }

        .chart-card-title { display: flex; align-items: center; gap: 10px; font-weight: 700; color: var(--ink); font-family: var(--font-brand); font-size: 1rem; margin-bottom: 16px; }
        .chart-card-title i { color: var(--blue); }

        /* Tables */
        table { width: 100%; border-collapse: separate; border-spacing: 0; border-radius: var(--radius-md); overflow: hidden; }
        th { background: #fbf6ee; color: var(--ink); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 12px 14px; border-bottom: 1px solid var(--border-soft); white-space: nowrap; }
        td { padding: 12px 14px; border-bottom: 1px solid var(--border-soft); font-size: 14px; vertical-align: middle; color: var(--ink); }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fbf6ee; }

        /* Forms */
        label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: var(--ink); }
        input, select {
            width: 100%; padding: 9px 14px; border: 1px solid var(--border-soft); border-radius: 12px;
            font-size: 14px; font-family: var(--font-body); color: var(--ink); background: #ffffff;
            transition: border-color .18s ease, box-shadow .18s ease;
        }
        input:focus, select:focus { outline: none; background: #fff; border-color: var(--blue); box-shadow: 0 0 0 .2rem rgba(94,107,70,0.18); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; align-items: end; }

        /* Buttons */
        .button, button {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 9px 20px; border: 0; border-radius: 50px;
            background: var(--blue); color: #fff; font-weight: 700; font-size: 14px;
            font-family: var(--font-body); cursor: pointer; transition: all .15s ease;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(94,107,70,0.25);
        }
        .button:hover, button:hover { background: #4c5838; color: #fff; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(94,107,70,0.35); }
        button:active { transform: translateY(1px); }
        .button.secondary, button.secondary { background: #fbf6ee; color: var(--ink); border: 1px solid var(--border-soft); box-shadow: none; }
        .button.secondary:hover, button.secondary:hover { background: #eef1e4; color: var(--blue); border-color: var(--blue); }
        .button.danger, button.danger { background: #c62828; box-shadow: 0 4px 14px rgba(198,40,40,0.25); }
        .button.danger:hover, button.danger:hover { background: #b71c1c; }
        button:disabled { background: #e6d8c5 !important; color: #a08c7a !important; box-shadow: none !important; cursor: not-allowed; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }

        /* Alerts */
        .alert { padding: 12px 18px; margin-bottom: 18px; border-radius: var(--radius-md); font-weight: 600; font-size: 14px; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 10px; animation: fadeUp .3s ease both; border: 1px solid transparent; }
        .success { background: #e8f5e9; color: #1b5e20; border-color: #c8e6c9; }
        .error { background: #ffebee; color: #b71c1c; border-color: #ffcdd2; }
        .muted { color: var(--ink-soft); font-size: 13px; }

        /* Badges */
        .badge-status-active { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .badge-status-inactive { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .badge-role-admin { background: #2a1810; color: #fff; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .badge-role-cashier { background: #eef1e4; color: var(--blue); border: 1px solid #cfd8bd; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }

        .two-col { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 20px; align-items: start; }
        @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

        /* Footer */
        footer { background: #ffffff !important; border-top: 1px solid var(--border-soft) !important; color: var(--ink-soft) !important; }

        /* SweetAlert2 logout modal */
        .swal-theme-popup { border-radius: 20px !important; padding: 26px 28px 24px !important; border: 1px solid var(--border-soft) !important; }
        .swal-theme-title {
            text-align: left !important; color: var(--ink) !important; font-family: var(--font-brand) !important;
            font-weight: 800 !important; font-size: 1.3rem !important; margin: 0 0 4px !important; padding: 0 !important;
        }
        .swal-theme-close { color: #a08c7a !important; font-size: 1.1rem !important; top: 22px !important; right: 20px !important; }
        .swal-logout-icon { font-size: 42px; color: #c62828; margin: 6px 0 10px; }
        .swal-logout-text { color: #5a4638; font-size: .98rem; margin: 0 0 6px; }
        .swal-theme-confirm { border-radius: 50px !important; font-weight: 700 !important; padding: 10px 24px !important; background: #c62828 !important; }
        .swal-theme-cancel { border-radius: 50px !important; font-weight: 700 !important; padding: 10px 24px !important; background: #fbf6ee !important; color: var(--ink) !important; border: 1px solid var(--border-soft) !important; }
    </style>
</head>
<body>
<nav class="app-navbar">
    <div class="navbar-left">
        <button id="sidebarToggle" title="Toggle sidebar" aria-label="Toggle sidebar"><i class="bi bi-list"></i></button>
        <a class="navbar-brand" href="{$assetBase}pos/dashboard">
            <span class="brand-icon"><i class="bi bi-cash-register"></i></span>
            <span>POS</span>
        </a>
    </div>
    <div class="navbar-right">
        <span class="role-badge">{$role}</span>
HTML;

    if (is_admin()) {
        $badge = $low_stock_count > 0 ? '<span class="badge rounded-pill bg-danger" style="position:absolute;top:-6px;right:-8px;font-size:.6rem;">' . h($low_stock_count) . '</span>' : '';
        echo <<<HTML
        <a href="{$assetBase}pos/low-stock-notifications" class="notification-bell" title="Low stock alerts">
            <i class="bi bi-bell"></i>{$badge}
        </a>
HTML;
    }

    echo <<<HTML
        <div class="dropdown user-dropdown">
            <button class="user-dropdown-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                <span class="user-avatar">{$initial}</span>
                <span class="d-none d-sm-inline">{$name}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item text-danger" href="{$assetBase}logout" id="logoutLink"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="app-wrapper">
    <aside class="app-sidebar" id="appSidebar">
        <div class="nav flex-column p-2">
            <div class="sidebar-group-label">Overview</div>
HTML;

    pos_nav_item('pos/dashboard', 'bi-speedometer2', 'Dashboard', $current);

    $pending_kiosk_count = 0;
    try {
        $pending_kiosk_count = (int) $conn->query("SELECT COUNT(*) FROM transactions WHERE source = 'kiosk' AND status IN ('pending', 'pending_payment')")->fetchColumn();
    } catch (Throwable $e) {}

    if (userCanDo('pos.sell') || is_admin()) {
        echo '<div class="sidebar-group-label">Sales & Orders</div>';
        if (userCanDo('pos.sell')) {
            pos_nav_item('pos/sales', 'bi-receipt', 'New Sale', $current);
        }
        pos_nav_item('pos/kiosk-admin-orders', 'bi-tablet', 'Kiosk Orders', $current, $pending_kiosk_count > 0 ? $pending_kiosk_count : null);
    }

    if (is_admin()) {
        echo '<div class="sidebar-group-label">Inventory</div>';
        pos_nav_item('pos/products', 'bi-box-seam', 'Product Management', $current);
        pos_nav_item('pos/low-stock-notifications', 'bi-exclamation-triangle', 'Low Stock Alerts', $current, $low_stock_count);

        echo '<div class="sidebar-group-label">Kiosk</div>';
        echo '<a href="' . $assetBase . 'pos/kiosk" target="_blank" class="nav-link"><i class="bi bi-box-arrow-up-right"></i> <span>Launch Kiosk</span></a>';

        echo '<div class="sidebar-group-label">Insights</div>';
        pos_nav_item('pos/sales-reports', 'bi-graph-up', 'Sales Reports', $current);

        echo '<div class="sidebar-group-label">Administration</div>';
        pos_nav_item('pos/users', 'bi-people', 'User Management', $current);
    }

    echo <<<HTML
        </div>
        <div class="sidebar-footer">
            <i class="bi bi-person-badge-fill"></i>
            <span>{$role} account</span>
        </div>
    </aside>
    <main class="main app-content">
HTML;

    if ($title) {
        echo '<div class="emp-page-header"><h1>' . h($title) . '</h1>';
        if ($subtitle) {
            echo '<p>' . h($subtitle) . '</p>';
        }
        echo '</div>';
    }
}

function page_footer() {
    $assetBase = BASE_URL;
    echo <<<HTML
    </main>
</div>
<footer class="text-center text-muted small py-3 border-top" style="background: #fff;">
    &copy; 2026 POS
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="{$assetBase}assets/shared/responsive.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('appSidebar');
    var toggleBtn = document.getElementById('sidebarToggle');

    // Logout confirmation as an in-page modal instead of navigating
    // straight to logout.php. logout.php itself now destroys the
    // session immediately with no confirmation step of its own — all
    // confirmation happens here, before we ever visit that URL.
    var logoutLink = document.getElementById('logoutLink');
    if (logoutLink && typeof Swal !== 'undefined') {
        logoutLink.addEventListener('click', function (event) {
            event.preventDefault();
            var targetHref = logoutLink.getAttribute('href');
            Swal.fire({
                title: 'Logout',
                html: '<div class="swal-logout-icon"><i class="bi bi-box-arrow-right"></i></div>' +
                      '<p class="swal-logout-text">Are you sure you want to logout?</p>',
                showCancelButton: true,
                showCloseButton: true,
                confirmButtonText: 'Yes, Logout',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#7a6558',
                customClass: {
                    popup: 'swal-theme-popup',
                    title: 'swal-theme-title',
                    closeButton: 'swal-theme-close',
                    confirmButton: 'swal-theme-confirm',
                    cancelButton: 'swal-theme-cancel'
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    window.location.href = targetHref;
                }
            });
        });
    }
    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            if (window.innerWidth <= 900) {
                sidebar.classList.toggle('show');
            } else {
                sidebar.classList.toggle('collapsed');
            }
        });
    }

    // Defensive fallback: if Bootstrap's JS bundle itself failed to load
    // (not just the CSS), data-bs-toggle="dropdown" won't do anything.
    // This lightweight handler only kicks in when window.bootstrap is
    // unavailable, so it never double-fires alongside the real thing.
    if (typeof window.bootstrap === 'undefined') {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (toggle) {
            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var menu = toggle.parentElement.querySelector('.dropdown-menu');
                if (!menu) { return; }
                var isOpen = menu.classList.contains('show');
                document.querySelectorAll('.dropdown-menu.show').forEach(function (m) { m.classList.remove('show'); });
                if (!isOpen) {
                    menu.classList.add('show');
                    toggle.setAttribute('aria-expanded', 'true');
                }
            });
        });
        document.addEventListener('click', function () {
            document.querySelectorAll('.dropdown-menu.show').forEach(function (m) { m.classList.remove('show'); });
        });
    }
});
</script>
</body>
</html>
HTML;
}

if (isset($conn)) {
    initialize_low_stock_notifications_table();
}
