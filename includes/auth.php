<?php
/**
 * UNIFIED AUTHENTICATION & ROLE-BASED ACCESS CONTROL (RBAC)
 *
 * This file is the single authentication source for every module -
 * HRMS, POS, Inventory, Procurement, Finance, Reports. It preserves the
 * exact function names/signatures the original HRMS module code already
 * calls (isLoggedIn, currentRoleId, requireRole, csrfToken, loginUser,
 * etc.) so that ~40 HRMS page files did not need to be edited at all.
 *
 * It also adds:
 *   - userCan($moduleCode) / requireModule($moduleCode)  -> used by the
 *     Inventory/Procurement/Finance/Reports pages (via includes/auth_check.php)
 *     which, in the original Café IFMS app, did NOT enforce per-role access.
 *   - Thin POS-compatible wrapper functions (require_login, is_admin,
 *     require_admin, current_user_role) so the original POS page files
 *     keep working unchanged against the unified session.
 */
require_once __DIR__ . '/../config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Check if a user is currently logged in */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['role_id']);
}

/** Get the logged-in user's role id */
function currentRoleId() {
    return $_SESSION['role_id'] ?? null;
}

/** Get the logged-in user's role name */
function currentRoleName(): string {
    $names = ROLE_NAMES;
    return $names[currentRoleId()] ?? 'Guest';
}

/** Force login - redirect to the unified login page if not authenticated */
function requireLogin(): void {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if (!isLoggedIn()) {
        if ($isAjax) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'login_required', 'message' => 'Please log in to continue.']);
            exit;
        }
        header('Location: ' . BASE_URL . '?error=login_required');
        exit;
    }
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
        session_unset();
        session_destroy();
        if ($isAjax) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'session_expired', 'message' => 'Your session has expired. Please log in again.']);
            exit;
        }
        header('Location: ' . BASE_URL . '?error=session_expired');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Restrict page to a set of allowed role ids.
 * Usage: requireRole([ROLE_HR_MANAGER, ROLE_HR_STAFF]);
 */
function requireRole(array $allowedRoles): void {
    requireLogin();
    if (!in_array((int)currentRoleId(), $allowedRoles, true)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;">
                <h2>403 - Access Denied</h2>
                <p>You do not have permission to access this module.</p>
                <a href="' . BASE_URL . 'dashboard">Return to Dashboard</a>
             </div>');
    }
}

/**
 * NEW: module-level guard shared by Inventory / Procurement / Finance /
 * Reports / POS. Checks the MODULE_ACCESS matrix in config/constants.php
 * (mirrors the `module_permissions` table).
 * Usage: requireModule('inventory');
 */
function userCan(string $moduleCode): bool {
    if (!isLoggedIn()) return false;
    $allowed = MODULE_ACCESS[(int)currentRoleId()] ?? [];
    return in_array($moduleCode, $allowed, true);
}

function requireModule(string $moduleCode): void {
    requireLogin();
    if (!userCan($moduleCode)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;">
                <h2>403 - Access Denied</h2>
                <p>Your role (' . htmlspecialchars(currentRoleName()) . ') does not have access to this module.</p>
                <a href="' . BASE_URL . 'dashboard">Return to Dashboard</a>
             </div>');
    }
}

/**
 * Capability check - "may this role WRITE here?", as opposed to
 * userCan()/requireModule() above which only ask "may this role OPEN
 * the module?". Backed by CAPABILITY_ROLES in config/constants.php.
 *
 * Usage (page body):   <?php if (userCanDo('inventory.manage')): ?> ... buttons ... <?php endif; ?>
 */
function userCanDo(string $capability): bool {
    if (!isLoggedIn()) return false;
    $roles = CAPABILITY_ROLES[$capability] ?? [];
    return in_array((int)currentRoleId(), $roles, true);
}

/**
 * Hard guard for a write handler. Hiding a button is cosmetic - this is
 * what actually stops a hand-typed URL or a replayed POST.
 * Usage (inside the POST/GET write branch): requireCapability('inventory.manage');
 */
function requireCapability(string $capability): void {
    requireLogin();
    if (!userCanDo($capability)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;">
                <h2>403 - Access Denied</h2>
                <p>Your role (' . htmlspecialchars(currentRoleName()) . ') has view / monitor access here, but cannot make changes.</p>
                <a href="' . BASE_URL . 'dashboard">Return to Dashboard</a>
             </div>');
    }
}

/** Small inline banner shown to roles that only have view / monitor access. */
function readOnlyNotice(string $what = 'this module'): string {
    return '<div class="alert alert-info d-flex align-items-center gap-2 py-2 px-3 small mb-3">'
         . '<i class="bi bi-eye"></i>'
         . '<div>You have <strong>view / monitor</strong> access to ' . htmlspecialchars($what)
         . '. Recording and editing is handled by Inventory Staff.</div></div>';
}

/** Generate CSRF token */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Validate CSRF token from POST request */
function csrfVerify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        die('Invalid or expired form submission (CSRF check failed). Please go back and try again.');
    }
}

/** Log the current user in. Expects a row from the unified `users` table
 *  (joined with `roles` if you want role_name available). */
function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = $user['user_id'];
    $_SESSION['role_id']       = $user['role_id'];
    $_SESSION['full_name']     = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['email']         = $user['email'];
    $_SESSION['username']      = $user['username'] ?? null;
    $_SESSION['profile_photo'] = $user['profile_photo'] ?? null;
    $_SESSION['branch_id']     = $user['branch_id'] ?? null;
    $_SESSION['branch_name']   = $user['branch_name'] ?? null;
    $_SESSION['last_activity'] = time();

    // POS-compatible role string, for the original POS page files that
    // still check current_user_role() === 'admin' / 'cashier'.
    // Only the Owner is POS "admin" - Inventory Staff has its own module
    // (MODULE_ACCESS[ROLE_INVENTORY_STAFF] does not include 'pos') and must
    // not be able to reach POS admin pages (user management, product
    // management, sales reports, kiosk order management, etc.).
    $posAdminRoles = [ROLE_OWNER];
    $_SESSION['role'] = in_array((int)$user['role_id'], $posAdminRoles, true) ? 'admin' : 'cashier';
}

function currentBranchId() {
    if (!empty($_SESSION['branch_id'])) {
        return (int)$_SESSION['branch_id'];
    }
    return 1;
}

/**
 * Live branch name for the badge in includes/header.php.
 *
 * $_SESSION['branch_name'] is only a snapshot taken at login (see
 * loginUser() above) - if HR Manager later renames the branch in
 * Manage Branches, everyone already logged in kept seeing the OLD name
 * next to their account until they logged out and back in. This looks
 * the name up fresh from `branches` on every request instead, and only
 * falls back to the stale session copy if the DB isn't reachable for
 * some reason (e.g. a page that hasn't loaded config/db.php yet).
 */
function currentBranchName() {
    global $pdo;
    $branchId = currentBranchId();
    if ($branchId && isset($pdo) && $pdo instanceof PDO) {
        static $cache = [];
        if (!array_key_exists($branchId, $cache)) {
            $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE branch_id = ?");
            $stmt->execute([$branchId]);
            $cache[$branchId] = $stmt->fetchColumn() ?: null;
        }
        if ($cache[$branchId] !== null) {
            return $cache[$branchId];
        }
    }
    return $_SESSION['branch_name'] ?? null;
}

/** Log the current user out */
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
}

// =====================================================================
// POS COMPATIBILITY SHIMS
// The original POS page files call these exact function names. They now
// read from the SAME unified session set by loginUser() above, so a
// Cashier who logs in once can use POS pages without any changes to
// those files.
// =====================================================================
if (!function_exists('require_login')) {
    function require_login() {
        requireLogin();
    }
}
if (!function_exists('current_user_role')) {
    function current_user_role() {
        return $_SESSION['role'] ?? '';
    }
}
if (!function_exists('is_admin')) {
    function is_admin() {
        return current_user_role() === 'admin';
    }
}
if (!function_exists('require_admin')) {
    function require_admin() {
        // First enforce the unified RBAC matrix: a role must have 'pos' in
        // MODULE_ACCESS to reach ANY POS page at all. This is what actually
        // blocks Inventory Staff (MODULE_ACCESS[ROLE_INVENTORY_STAFF] has no
        // 'pos' entry) even if a stale session predates this fix - it is
        // re-checked live against config/constants.php on every request,
        // independent of the $_SESSION['role'] shim below.
        requireModule('pos');
        // Then enforce that only POS "admin" (Owner) may use admin-only
        // pages such as User Management, Product Management, Sales
        // Reports, Low Stock Notifications, and Kiosk Order Management.
        if (!is_admin()) {
            http_response_code(403);
            die('<div style="font-family:sans-serif;padding:40px;text-align:center;"><h2>403 - Access Denied</h2><p>This POS page is restricted to Owner-level accounts.</p><a href="' . BASE_URL . 'dashboard">Return to Dashboard</a></div>');
        }
    }
}
