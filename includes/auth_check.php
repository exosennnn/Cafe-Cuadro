<?php
/**
 * AUTH + MODULE GUARD
 * Include this at the very top of every Inventory / Procurement /
 * Finance / Reports / Dashboard / Profile page (exactly as the original
 * Café IFMS pages already do - no changes needed to those files).
 *
 * Unlike the original version, this ALSO enforces role-based module
 * access: the original Café IFMS app let anyone who was logged in see
 * every page. Access is now determined by which folder under /modules/
 * the current script lives in (inventory, procurement, finance, reports,
 * dashboard, profile), checked against MODULE_ACCESS in config/constants.php.
 */
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/auth.php';

requireLogin();

// Determine the module from the calling script's parent directory,
// e.g. .../modules/inventory/index.php -> 'inventory'
$__moduleCode = basename(dirname($_SERVER['SCRIPT_FILENAME']));

// 'profile' and 'dashboard' are available to every authenticated role
// that has them listed in MODULE_ACCESS; everything else is gated.
if (!userCan($__moduleCode)) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:60px;text-align:center;">
            <h2>403 - Access Denied</h2>
            <p>Your role (' . htmlspecialchars(currentRoleName()) . ') does not have access to the "' . htmlspecialchars($__moduleCode) . '" module.</p>
            <a href="' . BASE_URL . 'dashboard.php">Return to Dashboard</a>
         </div>');
}
