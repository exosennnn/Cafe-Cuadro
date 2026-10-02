<?php
/**
 * UNIFIED APPLICATION ROUTER / FRONT CONTROLLER
 * Translates clean URLs (no .php) to internal script paths.
 */

// 1. Determine the request path relative to the unified application base
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// Detect base path from SCRIPT_NAME (e.g. /unified/)
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = rtrim($scriptDir, '/') . '/';

// If scriptDir is root '/'
if ($basePath === '//') {
    $basePath = '/';
}

// Strip base path from request path
$relative = $requestPath;
if ($basePath !== '/' && strpos($relative, $basePath) === 0) {
    $relative = substr($relative, strlen($basePath));
}
$relative = trim($relative, '/');

// Normalize hyphens to underscores for module names and files
// Special aliases mapping
$routes = [
    ''                         => 'index.php',
    'login'                    => 'index.php',
    'dashboard'                => 'dashboard.php',
    'logout'                   => 'logout.php',
    'register'                 => 'register.php',
    'apply'                    => 'apply.php',
    'apply-receipt'            => 'apply_receipt.php',
    'apply_receipt'            => 'apply_receipt.php',
    'application-submitted'    => 'application_submitted.php',
    'application_submitted'    => 'application_submitted.php',
    'check-status'             => 'check_status.php',
    'check_status'             => 'check_status.php',
    'forgot-password'          => 'forgot_password.php',
    'forgot_password'          => 'forgot_password.php',
    'notifications-read'       => 'notifications_read.php',
    'notifications_read'       => 'notifications_read.php',

    // POS aliases
    'pos'                      => 'pos/dashboard.php',
    'pos/dashboard'            => 'pos/dashboard.php',
    'pos/products'             => 'pos/product_management.php',
    'pos/product-management'   => 'pos/product_management.php',
    'pos/product_management'   => 'pos/product_management.php',
    'pos/sales'                => 'pos/sales_transaction.php',
    'pos/sales-transaction'    => 'pos/sales_transaction.php',
    'pos/sales_transaction'    => 'pos/sales_transaction.php',
    'pos/sales-reports'        => 'pos/sales_reports.php',
    'pos/sales_reports'        => 'pos/sales_reports.php',
    'pos/users'                => 'pos/user_management.php',
    'pos/user-management'      => 'pos/user_management.php',
    'pos/user_management'      => 'pos/user_management.php',
    'pos/low-stock-notifications' => 'pos/low_stock_notifications.php',
    'pos/low_stock_notifications' => 'pos/low_stock_notifications.php',
    'pos/receipt'              => 'pos/receipt.php',
    'pos/kiosk'                => 'pos/kiosk.php',
    // Public customer entry point; staff login remains at the application root.
    'kiosk'                    => 'pos/kiosk.php',
    'pos/kiosk-menu'           => 'pos/kiosk_menu.php',
    'pos/kiosk_menu'           => 'pos/kiosk_menu.php',
    'pos/kiosk-cart'           => 'pos/kiosk_cart.php',
    'pos/kiosk_cart'           => 'pos/kiosk_cart.php',
    'pos/kiosk-checkout'       => 'pos/kiosk_checkout.php',
    'pos/kiosk_checkout'       => 'pos/kiosk_checkout.php',
    'pos/kiosk-login'          => 'pos/kiosk_login.php',
    'pos/kiosk_login'          => 'pos/kiosk_login.php',
    'pos/kiosk-register'       => 'pos/kiosk_register.php',
    'pos/kiosk_register'       => 'pos/kiosk_register.php',
    'pos/kiosk-order-type'     => 'pos/kiosk_order_type.php',
    'pos/kiosk_order_type'     => 'pos/kiosk_order_type.php',
    'pos/kiosk-track'          => 'pos/kiosk_track.php',
    'pos/kiosk_track'          => 'pos/kiosk_track.php',
    'pos/kiosk-receipt'        => 'pos/kiosk_receipt.php',
    'pos/kiosk_receipt'        => 'pos/kiosk_receipt.php',
    'pos/kiosk-success'        => 'pos/kiosk_success.php',
    'pos/kiosk_success'        => 'pos/kiosk_success.php',
    'pos/kiosk-account'        => 'pos/kiosk_account.php',
    'pos/kiosk_account'        => 'pos/kiosk_account.php',
    'pos/kiosk-admin-orders'   => 'pos/kiosk_admin_orders.php',
    'pos/kiosk_admin_orders'   => 'pos/kiosk_admin_orders.php',
    'pos/kiosk-logout'         => 'pos/kiosk_logout.php',
    'pos/kiosk_logout'         => 'pos/kiosk_logout.php',
    'pos/login'                => 'pos/login.php',
    'pos/login-page'           => 'pos/login_page.php',
    'pos/login_page'           => 'pos/login_page.php',
    'pos/logout'               => 'pos/logout.php',
];

$targetFile = null;

if (isset($routes[$relative])) {
    $targetFile = $routes[$relative];
} else {
    // Check module patterns:
    // e.g. "hr-manager/employees", "modules/hr_manager/employees", "admin/dashboard", "owner/users"
    $parts = explode('/', $relative);
    
    // If prefixed with "modules/", strip it
    if ($parts[0] === 'modules' && count($parts) > 1) {
        array_shift($parts);
    }

    $mod = $parts[0] ?? '';
    $action = $parts[1] ?? '';

    // Normalize module name
    $modNormalized = str_replace('-', '_', $mod);
    if ($modNormalized === 'admin') {
        $modNormalized = 'owner';
    }

    // Default action for modules
    if ($action === '') {
        if (in_array($modNormalized, ['inventory', 'procurement', 'profile', 'reports'], true)) {
            $action = 'index';
        } else {
            $action = 'dashboard';
        }
    }

    // Action normalized (replace '-' with '_')
    $actionNormalized = str_replace('-', '_', $action);

    // Try target in modules/
    $candidateModule = "modules/{$modNormalized}/{$actionNormalized}.php";
    if (file_exists(__DIR__ . '/' . $candidateModule)) {
        $targetFile = $candidateModule;
    } elseif ($modNormalized === 'pos') {
        // POS candidate
        if ($actionNormalized === 'products') {
            $actionNormalized = 'product_management';
        } elseif ($actionNormalized === 'sales') {
            $actionNormalized = 'sales_transaction';
        } elseif ($actionNormalized === 'users') {
            $actionNormalized = 'user_management';
        }
        $candidatePos = "pos/{$actionNormalized}.php";
        if (file_exists(__DIR__ . '/' . $candidatePos)) {
            $targetFile = $candidatePos;
        }
    }
}

// If no route found, 404
if (!$targetFile || !file_exists(__DIR__ . '/' . $targetFile)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body style="font-family:sans-serif;text-align:center;padding:50px;">';
    echo '<h1>404 - Page Not Found</h1><p>The requested page was not found.</p>';
    echo '<a href="' . htmlspecialchars($basePath) . '">Return to Home</a>';
    echo '</body></html>';
    exit;
}

// Prepare execution environment:
$fullPath = __DIR__ . '/' . $targetFile;
$_SERVER['SCRIPT_NAME'] = $basePath . $targetFile;
$_SERVER['PHP_SELF'] = $basePath . $targetFile;
$_SERVER['SCRIPT_FILENAME'] = $fullPath;

// Set current working directory to the target file's directory so relative requires work
chdir(dirname($fullPath));

require $fullPath;
