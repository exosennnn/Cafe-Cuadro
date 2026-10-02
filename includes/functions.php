<?php
/**
 * General utility / helper functions
 */
require_once __DIR__ . '/mailer.php';

/** Sanitize a string for safe output */
function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Render a small circular avatar: the person's photo if one exists,
 * otherwise a colored circle with their initials.
 * $size is in pixels (applies to width/height/font-size proportionally).
 */
function renderAvatar(?string $photoFilename, ?string $firstName, ?string $lastName, int $size = 40): string {
    $initials = strtoupper(substr($firstName ?? '', 0, 1) . substr($lastName ?? '', 0, 1));
    if ($initials === '') $initials = '?';

    if (!empty($photoFilename)) {
        $url = BASE_URL . 'uploads/profile_photos/' . rawurlencode($photoFilename);
        return '<img src="' . e($url) . '" alt="' . e(trim(($firstName ?? '') . ' ' . ($lastName ?? ''))) . '" '
             . 'style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;border:1px solid #dee2e6;">';
    }

    // Deterministic warm café palette based on the initials so the same person always gets the same color
    $palette = ['#5e6b46', '#2a1810', '#2e7d32', '#d97706', '#8d5b4c', '#b37d6f', '#c25e2e', '#3b241a'];
    $colorIndex = array_sum(array_map('ord', str_split($initials))) % count($palette);
    $bg = $palette[$colorIndex];
    $fontSize = max(10, (int)round($size * 0.4));

    return '<span class="d-inline-flex align-items-center justify-content-center text-white fw-bold" '
         . 'style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;background-color:' . $bg . ';font-size:' . $fontSize . 'px;">'
         . e($initials) . '</span>';
}

/**
 * Converts internal script paths or legacy URLs into clean, .php-free routes.
 */
function cleanRoute(string $path): string {
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $parts = explode('?', $path, 2);
    $urlPath = $parts[0];
    $query = isset($parts[1]) ? '?' . $parts[1] : '';

    if (defined('BASE_URL') && BASE_URL !== '/' && strpos($urlPath, BASE_URL) === 0) {
        $urlPath = substr($urlPath, strlen(BASE_URL));
    }
    $urlPath = trim($urlPath, '/');

    if ($urlPath === '' || $urlPath === 'index.php') {
        return $query;
    }

    $rootMap = [
        'dashboard.php'              => 'dashboard',
        'logout.php'                 => 'logout',
        'register.php'               => 'register',
        'apply.php'                  => 'apply',
        'apply_receipt.php'          => 'apply-receipt',
        'application_submitted.php'  => 'application-submitted',
        'check_status.php'           => 'check-status',
        'forgot_password.php'        => 'forgot-password',
        'notifications_read.php'     => 'notifications-read',
        'login.php'                  => '',
        'login_page.php'             => '',

        // Standalone POS files (when called without pos/ prefix)
        'product_management.php'      => 'pos/products',
        'sales_transaction.php'       => 'pos/sales',
        'sales_reports.php'           => 'pos/sales-reports',
        'user_management.php'         => 'pos/users',
        'low_stock_notifications.php' => 'pos/low-stock-notifications',
        'receipt.php'                 => 'pos/receipt',
        'kiosk.php'                   => 'pos/kiosk',
        'kiosk_menu.php'              => 'pos/kiosk-menu',
        'kiosk_cart.php'              => 'pos/kiosk-cart',
        'kiosk_checkout.php'          => 'pos/kiosk-checkout',
        'kiosk_login.php'             => 'pos/kiosk-login',
        'kiosk_register.php'          => 'pos/kiosk-register',
        'kiosk_order_type.php'        => 'pos/kiosk-order-type',
        'kiosk_track.php'             => 'pos/kiosk-track',
        'kiosk_receipt.php'           => 'pos/kiosk-receipt',
        'kiosk_success.php'           => 'pos/kiosk-success',
        'kiosk_account.php'           => 'pos/kiosk-account',
        'kiosk_admin_orders.php'      => 'pos/kiosk-admin-orders',
        'kiosk_logout.php'            => 'pos/kiosk-logout',
    ];
    if (isset($rootMap[$urlPath])) {
        return $rootMap[$urlPath] . $query;
    }

    // POS paths
    if (strpos($urlPath, 'pos/') === 0 || strpos($urlPath, 'modules/pos/') === 0) {
        $sub = preg_replace('#^(modules/)?pos/#', '', $urlPath);
        $sub = preg_replace('/\.php$/i', '', $sub);
        $posMap = [
            ''                        => 'pos/dashboard',
            'index'                   => 'pos/dashboard',
            'dashboard'               => 'pos/dashboard',
            'product_management'      => 'pos/products',
            'products'                => 'pos/products',
            'sales_transaction'       => 'pos/sales',
            'sales'                   => 'pos/sales',
            'sales_reports'           => 'pos/sales-reports',
            'user_management'         => 'pos/users',
            'users'                   => 'pos/users',
            'low_stock_notifications' => 'pos/low-stock-notifications',
            'receipt'                 => 'pos/receipt',
            'kiosk'                   => 'pos/kiosk',
            'kiosk_menu'              => 'pos/kiosk-menu',
            'kiosk_cart'              => 'pos/kiosk-cart',
            'kiosk_checkout'          => 'pos/kiosk-checkout',
            'kiosk_login'             => 'pos/kiosk-login',
            'kiosk_register'          => 'pos/kiosk-register',
            'kiosk_order_type'        => 'pos/kiosk-order-type',
            'kiosk_track'             => 'pos/kiosk-track',
            'kiosk_receipt'           => 'pos/kiosk-receipt',
            'kiosk_success'           => 'pos/kiosk-success',
            'kiosk_account'           => 'pos/kiosk-account',
            'kiosk_admin_orders'      => 'pos/kiosk-admin-orders',
            'kiosk_logout'            => 'pos/kiosk-logout',
            'login'                   => 'login',
            'login_page'              => 'login',
            'logout'                  => 'logout',
        ];
        $cleanSub = $posMap[$sub] ?? ('pos/' . str_replace('_', '-', $sub));
        return $cleanSub . $query;
    }

    // Module paths
    if (strpos($urlPath, 'modules/') === 0) {
        $sub = substr($urlPath, strlen('modules/'));
        $segments = explode('/', $sub, 2);
        $mod = $segments[0];
        $file = $segments[1] ?? '';
        $file = preg_replace('/\.php$/i', '', $file);

        $cleanMod = str_replace('_', '-', $mod);
        if ($file === 'index' || $file === '') {
            if (in_array($mod, ['inventory', 'procurement', 'finance', 'reports', 'profile'], true)) {
                return $cleanMod . $query;
            }
        }
        $cleanFile = str_replace('_', '-', $file);
        return $cleanMod . '/' . $cleanFile . $query;
    }

    // If path has no slash, detect if called from within a module or POS
    if (strpos($urlPath, '/') === false) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        if (preg_match('#/modules/([^/]+)/#i', $script, $m)) {
            $curMod = str_replace('_', '-', $m[1]);
            $baseFile = preg_replace('/\.php$/i', '', $urlPath);
            if ($baseFile === 'index' && in_array($m[1], ['inventory', 'procurement', 'finance', 'reports', 'profile'], true)) {
                return $curMod . $query;
            }
            return $curMod . '/' . str_replace('_', '-', $baseFile) . $query;
        } elseif (preg_match('#/pos/#i', $script)) {
            $baseFile = preg_replace('/\.php$/i', '', $urlPath);
            $posMap = [
                'dashboard'               => 'pos/dashboard',
                'product_management'      => 'pos/products',
                'products'                => 'pos/products',
                'sales_transaction'       => 'pos/sales',
                'sales'                   => 'pos/sales',
                'sales_reports'           => 'pos/sales-reports',
                'user_management'         => 'pos/users',
                'users'                   => 'pos/users',
                'low_stock_notifications' => 'pos/low-stock-notifications',
                'receipt'                 => 'pos/receipt',
                'kiosk'                   => 'pos/kiosk',
                'kiosk_menu'              => 'pos/kiosk-menu',
                'kiosk_cart'              => 'pos/kiosk-cart',
                'kiosk_checkout'          => 'pos/kiosk-checkout',
                'kiosk_login'             => 'pos/kiosk-login',
                'kiosk_register'          => 'pos/kiosk-register',
                'kiosk_order_type'        => 'pos/kiosk-order-type',
                'kiosk_track'             => 'pos/kiosk-track',
                'kiosk_receipt'           => 'pos/kiosk-receipt',
                'kiosk_success'           => 'pos/kiosk-success',
                'kiosk_account'           => 'pos/kiosk-account',
                'kiosk_admin_orders'      => 'pos/kiosk-admin-orders',
                'kiosk_logout'            => 'pos/kiosk-logout',
            ];
            $cleanSub = $posMap[$baseFile] ?? ('pos/' . str_replace('_', '-', $baseFile));
            return $cleanSub . $query;
        }
    }

    $cleaned = preg_replace('/\.php$/i', '', $urlPath);
    return str_replace('_', '-', $cleaned) . $query;
}

/**
 * Clean URL generator
 */
function url(string $path = ''): string {
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $clean = cleanRoute($path);
    return BASE_URL . ltrim($clean, '/');
}

/** Redirect helper using clean URLs */
function redirect(string $path): void {
    if (preg_match('#^https?://#i', $path)) {
        header('Location: ' . $path);
    } else {
        $clean = cleanRoute($path);
        header('Location: ' . BASE_URL . ltrim($clean, '/'));
    }
    exit;
}

/** Set a flash message shown once on next page load */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Render and clear flash messages */
function renderFlash(): string {
    if (empty($_SESSION['flash'])) return '';
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $type = $f['type'] === 'error' ? 'danger' : e($f['type']);
        $html .= '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">'
               . e($f['message'])
               . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/** Write an entry to the audit_logs table */
function logAudit(PDO $pdo, ?int $userId, string $action, string $module = '', string $details = ''): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, module, details, ip_address) VALUES (?,?,?,?,?)");
        $stmt->execute([$userId, $action, $module, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) {
        // fail silently - auditing should never break the app
    }
}

/**
 * Validate and move an uploaded file.
 * Returns ['ok'=>bool, 'filename'=>string|null, 'error'=>string|null]
 */
function handleFileUpload(array $file, string $destDir, array $allowedExt, int $maxSize, string $prefix = 'file'): array {
    if (!isset($file['error'])) {
        return ['ok' => false, 'filename' => null, 'error' => 'Invalid upload.'];
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'filename' => null, 'error' => 'No file uploaded.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'filename' => null, 'error' => 'Upload error code: ' . $file['error']];
    }
    if (!isset($file['size']) || !is_numeric($file['size']) || (int)$file['size'] < 0) {
        return ['ok' => false, 'filename' => null, 'error' => 'Invalid uploaded file size.'];
    }
    if ((int)$file['size'] > $maxSize) {
        return ['ok' => false, 'filename' => null, 'error' => 'File exceeds maximum allowed size of ' . round($maxSize / 1024 / 1024, 1) . 'MB.'];
    }
    if (!isset($file['name']) || !is_string($file['name']) || $file['name'] === '') {
        return ['ok' => false, 'filename' => null, 'error' => 'Invalid uploaded filename.'];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'filename' => null, 'error' => 'Invalid uploaded file.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = array_map('strtolower', $allowedExt);
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExt)];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return ['ok' => false, 'filename' => null, 'error' => 'Unable to validate file content.'];
    }
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
    ];
    if ($mime === false || !isset($allowedMimes[$ext]) || !in_array($mime, $allowedMimes[$ext], true)) {
        return ['ok' => false, 'filename' => null, 'error' => 'File content does not match its extension.'];
    }
    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return ['ok' => false, 'filename' => null, 'error' => 'Failed to create upload directory.'];
        }
    }
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = rtrim($destDir, '/') . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'filename' => null, 'error' => 'Failed to move uploaded file.'];
    }
    return ['ok' => true, 'filename' => $safeName, 'error' => null];
}

/** Simple pagination helper. Returns [offset, limit, currentPage, totalPages] */
function paginate(int $totalRows, int $perPage = 10): array {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    return [$offset, $perPage, $page, $totalPages];
}

/** Render bootstrap pagination links, preserving other query string params */
function renderPagination(int $currentPage, int $totalPages): string {
    if ($totalPages <= 1) return '';
    $params = $_GET;
    $html = '<nav><ul class="pagination justify-content-center">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $params['page'] = $i;
        $qs = http_build_query($params);
        $active = $i === $currentPage ? 'active' : '';
        $html .= "<li class=\"page-item $active\"><a class=\"page-link\" href=\"?$qs\">$i</a></li>";
    }
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Generate a Unique Reference Code for a job application, e.g. APP-20260728-K7F2.
 * Shown to the applicant right after they submit, and used to look up their
 * status later on the public Check Application Status page (no account needed).
 */
function generateReferenceCode(PDO $pdo): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I to avoid confusion when read aloud/typed
    do {
        $suffix = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $code = 'APP-' . date('Ymd') . '-' . $suffix;
        $stmt = $pdo->prepare("SELECT 1 FROM job_applications WHERE reference_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

/**
 * Decrement a job vacancy's remaining slots by 1 the moment someone applies
 * (submits their resume) - not at hire time. Auto-closes the posting once
 * slots hit 0 so it stops appearing on the public job board / apply form.
 * Uses an atomic UPDATE ... WHERE slots > 0 to guard against a race
 * condition where two people submit for the very last slot at once.
 *
 * @return bool false if there were no slots left to take (caller should abort/rollback).
 */
function decrementJobSlot(PDO $pdo, int $jobId): bool {
    $stmt = $pdo->prepare("UPDATE job_vacancies SET slots = slots - 1 WHERE job_id = ? AND slots > 0");
    $stmt->execute([$jobId]);
    if ($stmt->rowCount() === 0) {
        return false;
    }
    $remaining = $pdo->prepare("SELECT slots FROM job_vacancies WHERE job_id = ?");
    $remaining->execute([$jobId]);
    if ((int)$remaining->fetchColumn() <= 0) {
        $pdo->prepare("UPDATE job_vacancies SET status = 'CLOSED' WHERE job_id = ?")->execute([$jobId]);
    }
    return true;
}

/** Generate next employee code e.g. EMP-0002 */
function generateEmployeeCode(PDO $pdo): string {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) AS max_code FROM employees WHERE employee_code LIKE 'EMP-%'");
    $maxCode = (int)$stmt->fetch()['max_code'];
    $nextCode = $maxCode + 1;
    return 'EMP-' . str_pad((string)$nextCode, 4, '0', STR_PAD_LEFT);
}

/**
 * Returns the employee_id of the sole ACTIVE Employee Manager, or null if
 * there isn't exactly one (zero, or more than one - in either case we can't
 * safely auto-pick which one owns every department, so callers should fall
 * back to manual per-department assignment).
 */
function getSoleEmployeeManagerId(PDO $pdo): ?int {
    $rows = $pdo->query("SELECT e.employee_id FROM employees e
        JOIN users u ON e.user_id = u.user_id
        WHERE u.role_id = " . ROLE_EMPLOYEE_MANAGER . " AND u.status = 'ACTIVE'")->fetchAll();
    return count($rows) === 1 ? (int)$rows[0]['employee_id'] : null;
}

/**
 * There is only ever meant to be one Employee Manager for the whole company
 * (baristas, cashiers, crew, etc. all report to them - see
 * modules/employee_manager/employees.php), so every department's manager_id
 * should always point to that same person instead of being picked one
 * department at a time. Whenever exactly one active Employee Manager exists,
 * this syncs every department to them. No-op (leaves existing manager_id
 * values alone) if there are zero or more than one, since we can't tell
 * which one should own which department in that case.
 */
function syncDepartmentsToSoleEmployeeManager(PDO $pdo): void {
    $managerId = getSoleEmployeeManagerId($pdo);
    if ($managerId === null) {
        return;
    }
    $pdo->prepare("UPDATE departments SET manager_id = ? WHERE manager_id IS NULL OR manager_id != ?")
        ->execute([$managerId, $managerId]);
}

/**
 * Convert a hired applicant's user account into an Employee account.
 *
 * Triggered explicitly by HR Staff after the applicant accepts a job offer
 * (see modules/hr_staff/applications.php). Applicant acceptance itself must
 * not create or upgrade an account. Idempotent - if an employee record
 * already exists for this user, it does nothing.
 *
 * Sets the account role to Employee, creates the `employees` row with the
 * department AND position taken from the job vacancy applied for, and
 * closes the vacancy if that was its last open slot.
 *
 * @return string|null The new employee_code, or null if the application/user could not be found.
 */
function convertApplicantToEmployee(PDO $pdo, int $applicationId): ?string {
    $app = $pdo->prepare("SELECT ja.application_id, a.applicant_id, a.user_id,
            a.first_name AS guest_first_name, a.last_name AS guest_last_name, a.email AS guest_email, a.phone AS guest_phone,
            jv.job_id, jv.title AS job_title, jv.target_role_id, jv.department_id, jv.slots, jv.status AS job_status
        FROM job_applications ja
        JOIN applicants a ON ja.applicant_id = a.applicant_id
        JOIN job_vacancies jv ON ja.job_id = jv.job_id
        WHERE ja.application_id = ?");
    $app->execute([$applicationId]);
    $app = $app->fetch();
    if (!$app) {
        return null;
    }

    // Track whether this applicant already had their own account (registered/
    // logged-in applicant) before this function touches anything, so we know
    // which notification to send them further down.
    $wasAlreadyRegistered = !empty($app['user_id']);

    // Which role this hire's account gets. The job vacancy can declare an
    // operational role (Cashier / Inventory Staff / Finance Staff); anything else, or no
    // job-level role at all, falls back to plain Employee (HR self-service
    // only). Allow-listed so a bad/legacy target_role_id can never grant
    // something like ROLE_HR_MANAGER through the hire flow.
    $hireableRoles = [ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF];
    $hireRoleId = (!empty($app['target_role_id']) && in_array((int)$app['target_role_id'], $hireableRoles, true))
        ? (int)$app['target_role_id'] : ROLE_EMPLOYEE;

    // Guest applicant (applied via the public Apply Now form, no account
    // was ever created). Now that they're being hired, create their user
    // account for them so they have Employee login credentials, and link
    // it back to the applicants row.
    if (empty($app['user_id'])) {
        $email = $app['guest_email'];
        if (empty($email)) {
            return null; // can't create an account without an email on file
        }
        $existingUser = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $existingUser->execute([$email]);
        $tempPassword = null;
        if ($existingUserRow = $existingUser->fetch()) {
            $newUserId = (int)$existingUserRow['user_id'];
        } else {
            $tempPassword = bin2hex(random_bytes(5)); // random temp password; shown once via check_status.php (reference code), cleared on first successful login
            $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (role_id, email, password_hash, first_name, last_name, phone, status) VALUES (?,?,?,?,?,?, 'ACTIVE')")
                ->execute([$hireRoleId, $email, $hash, $app['guest_first_name'] ?: 'Applicant', $app['guest_last_name'] ?: '', $app['guest_phone']]);
            $newUserId = (int)$pdo->lastInsertId();
            logAudit($pdo, $newUserId, 'AUTO_CREATE_ACCOUNT', 'Recruitment', 'Employee account auto-created on hire for applicant_id=' . $app['applicant_id']);
        }
        $pdo->prepare("UPDATE applicants SET user_id=?, temp_login_password=? WHERE applicant_id=?")->execute([$newUserId, $tempPassword, $app['applicant_id']]);
        $app['user_id'] = $newUserId;

        // Email their login credentials right away - this is the only
        // realistic way a guest applicant (no prior account/session) finds
        // out they now have one. They can also still see it once via
        // check_status.php, but not everyone will think to look there.
        if ($tempPassword !== null) {
            sendMail($email, trim(($app['guest_first_name'] ?: 'Applicant') . ' ' . $app['guest_last_name']),
                'Welcome Aboard - Your ' . APP_NAME . ' Account',
                mailTemplate('Welcome Aboard!', '<p>Hi ' . e($app['guest_first_name'] ?: 'there') . ',</p>
                    <p>Congratulations, you\'ve been hired for <strong>' . e($app['job_title']) . '</strong>! An employee account has been created for you.</p>
                    <p><strong>Email:</strong> ' . e($email) . '<br>
                    <strong>Temporary Password:</strong> ' . e($tempPassword) . '</p>
                    <p>Please log in and change your password as soon as possible via Settings &rarr; Change My Password.</p>'));
        }
    }

    // Already converted - don't create a duplicate employee record.
    $existing = $pdo->prepare("SELECT employee_code FROM employees WHERE user_id=?");
    $existing->execute([$app['user_id']]);
    if ($existingRow = $existing->fetch()) {
        return $existingRow['employee_code'];
    }

    $pdo->prepare("UPDATE users SET role_id=? WHERE user_id=?")->execute([$hireRoleId, $app['user_id']]);

    // Registered applicant (already had their own account/login) - no new
    // credentials to send, but they still need to be told their account was
    // just upgraded to Employee access, since their current session won't
    // reflect it until they log in again.
    if ($wasAlreadyRegistered) {
        $userRow = $pdo->prepare("SELECT email, first_name FROM users WHERE user_id=?");
        $userRow->execute([$app['user_id']]);
        if ($userRow = $userRow->fetch()) {
            $roleLabel = ROLE_NAMES[$hireRoleId] ?? 'Employee';
            sendMail($userRow['email'], $userRow['first_name'],
                'Welcome Aboard - Your Account is Now a ' . $roleLabel . ' Account',
                mailTemplate('Welcome Aboard!', '<p>Hi ' . e($userRow['first_name']) . ',</p>
                    <p>Congratulations, you\'ve been hired for <strong>' . e($app['job_title']) . '</strong>! Your existing account has been upgraded from Applicant to ' . e($roleLabel) . ' access.</p>
                    <p>Log in again with your usual email and password to see your new dashboard.</p>'));
        }
    }

    // Carry over the offered salary and employment type (contract) from the
    // job offer, if one exists (e.g. HR may set status to HIRED directly
    // without going through the offer flow). Shift is intentionally NOT
    // carried over here - it defaults to DEFAULT_SHIFT and is assigned
    // manually by the HR Manager (Manage Employees) after the employee is
    // hired, since it depends on staffing needs (Morning/Afternoon/Graveyard).
    $offer = $pdo->prepare("SELECT offered_salary, employment_type FROM job_offers WHERE application_id=?");
    $offer->execute([$applicationId]);
    $offer = $offer->fetch();
    $basicSalary = $offer['offered_salary'] ?? 0;
    $employmentType = (!empty($offer['employment_type']) && in_array($offer['employment_type'], EMPLOYMENT_TYPES, true))
        ? $offer['employment_type'] : 'PROBATIONARY';

    $code = generateEmployeeCode($pdo);
    $pdo->prepare("INSERT INTO employees (user_id, employee_code, department_id, position, date_hired, employment_status, basic_salary, employment_type, shift)
        VALUES (?,?,?,?,CURDATE(),'ACTIVE',?,?,?)")
        ->execute([$app['user_id'], $code, $app['department_id'], $app['job_title'], $basicSalary, $employmentType, DEFAULT_SHIFT]);

    // Probationary hires start with a default probation end date so the HR
    // Manager alert works from day one (editable in Manage Employees).
    // Contractual hires are left blank - contract length is set by HR.
    require_once __DIR__ . '/employment_terms.php';
    if ($employmentType === 'PROBATIONARY' && employmentTermsReady($pdo)) {
        $pdo->prepare("UPDATE employees SET probation_end_date=? WHERE employee_code=?")
            ->execute([defaultProbationEndDate(date('Y-m-d')), $code]);
    }

    return $code;
}

/** Format a date nicely */
function fdate(?string $date, string $format = 'M d, Y'): string {
    if (empty($date) || $date === '0000-00-00') return '-';
    return date($format, strtotime($date));
}

/** Human-readable label for the multi-stage leave request status */
function leaveStatusLabel(string $status): string {
    $labels = [
        'PENDING_MANAGER'  => 'Pending Manager Review',
        'PENDING_HR'       => 'Pending HR Processing',
        'PENDING_APPROVAL' => 'Pending Final Approval',
        'APPROVED'         => 'Approved',
        'REJECTED'         => 'Rejected',
        'CANCELLED'        => 'Cancelled',
    ];
    return $labels[$status] ?? str_replace('_', ' ', $status);
}

/** Bootstrap badge color for the multi-stage leave request status */
function leaveStatusColor(string $status): string {
    $colors = [
        'PENDING_MANAGER'  => 'warning',
        'PENDING_HR'       => 'warning',
        'PENDING_APPROVAL' => 'warning',
        'APPROVED'         => 'success',
        'REJECTED'         => 'danger',
        'CANCELLED'        => 'secondary',
    ];
    return $colors[$status] ?? 'secondary';
}

/** Format currency */
function fmoney($amount): string {
    return '₱' . number_format((float)$amount, 2);
}

/**
 * Currency formatting for SimplePDF documents only. SimplePDF draws with
 * the base-14 Helvetica font and no custom encoding, so the ₱ glyph (and
 * any accented letter - see pdfText()) does not render correctly there.
 * Everywhere else (HTML pages, printed receipts) keeps using fmoney().
 */
function fmoneyPdf($amount): string {
    return 'PHP ' . number_format((float)$amount, 2);
}

/**
 * Strip characters SimplePDF's plain Helvetica can't render (accented
 * letters like the é in "Café", ñ, etc.) down to a safe ASCII fallback,
 * so generated contract/report PDFs don't show garbled glyphs. Only use
 * this for text passed into SimplePDF - HTML pages render UTF-8 fine and
 * should keep the original text.
 */
function pdfText(?string $text): string {
    if ($text === null || $text === '') return '';
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($converted !== false) return $converted;
    }
    $map = ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','á'=>'a','à'=>'a','â'=>'a','ä'=>'a',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c',
        'É'=>'E','Á'=>'A','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N'];
    return strtr($text, $map);
}

/** Human-readable label for the payroll workflow status */
function payrollStatusLabel(string $status): string {
    $labels = [
        'DRAFT'     => 'Returned for Correction',
        'PREPARED'  => 'Prepared - HR Review',
        'SUBMITTED' => 'Submitted for Approval',
        'PROCESSED' => 'Approved - Pending Finance',
        'PAID'      => 'Paid',
    ];
    return $labels[$status] ?? str_replace('_', ' ', $status);
}

/** Bootstrap badge color for the payroll workflow status */
function payrollStatusColor(string $status): string {
    $colors = [
        'DRAFT'     => 'secondary',
        'PREPARED'  => 'info text-dark',
        'SUBMITTED' => 'warning text-dark',
        'PROCESSED' => 'primary',
        'PAID'      => 'success',
    ];
    return $colors[$status] ?? 'secondary';
}

if (!function_exists('preparePayrollBatch')) {
    function preparePayrollBatch(PDO $pdo, int $branchId, string $periodStart, string $periodEnd, int $generatedBy): array {
        $start = DateTime::createFromFormat('!Y-m-d', $periodStart);
        $end = DateTime::createFromFormat('!Y-m-d', $periodEnd);
        if ($branchId < 1 || $generatedBy < 1 || !$start || !$end || $start->format('Y-m-d') !== $periodStart || $end->format('Y-m-d') !== $periodEnd || $end < $start || $start->diff($end)->days > 30) {
            throw new InvalidArgumentException('Choose a valid active branch and payroll period of 1 to 31 days.');
        }
        $branchCheck = $pdo->prepare("SELECT branch_id FROM branches WHERE branch_id=? AND status='ACTIVE'");
        $branchCheck->execute([$branchId]);
        if (!$branchCheck->fetchColumn()) throw new RuntimeException('The selected branch is not active.');

        $employeesStmt = $pdo->prepare("SELECT e.employee_id FROM employees e JOIN users u ON u.user_id=e.user_id
            WHERE e.branch_id=? AND e.employment_status='ACTIVE' AND u.status='ACTIVE' ORDER BY e.employee_id");
        $employeesStmt->execute([$branchId]);
        $employeeIds = array_map('intval', $employeesStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$employeeIds) throw new RuntimeException('There are no active employees assigned to that branch.');

        $parseCents = static function ($value): ?int {
            $value = trim((string)$value);
            if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $value)) return null;
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            return ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
        };
        $money = static fn(int $cents): string => number_format($cents / 100, 2, '.', '');
        $maxMoneyCents = 999999999999;
        $periodDays = $start->diff($end)->days + 1;
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();

        try {
            $created = 0;
            foreach ($employeeIds as $employeeId) {
                $employeeLock = $pdo->prepare("SELECT e.basic_salary FROM employees e JOIN users u ON u.user_id=e.user_id
                    WHERE e.employee_id=? AND e.branch_id=? AND e.employment_status='ACTIVE' AND u.status='ACTIVE' FOR UPDATE");
                $employeeLock->execute([$employeeId, $branchId]);
                $employee = $employeeLock->fetch();
                if (!$employee) continue;

                $duplicate = $pdo->prepare('SELECT payroll_id FROM payroll WHERE employee_id=? AND pay_period_start=? AND pay_period_end=? LIMIT 1 FOR UPDATE');
                $duplicate->execute([$employeeId, $periodStart, $periodEnd]);
                if ($duplicate->fetch()) throw new RuntimeException('A payroll record already exists for an employee in this branch and period. No new payroll was created.');

                $monthlySalaryCents = $parseCents($employee['basic_salary']);
                if ($monthlySalaryCents === null) throw new RuntimeException('An employee has an invalid basic salary.');
                
                // Realistic Semi-Monthly Calculation for Cafes
                if ($periodDays >= 13 && $periodDays <= 16) {
                    $basicCents = (int)round($monthlySalaryCents / 2); // exactly half for semi-monthly cutoffs
                } elseif ($periodDays >= 28) {
                    $basicCents = $monthlySalaryCents; // exactly full for monthly cutoffs
                } else {
                    $basicCents = (int)round($monthlySalaryCents * ($periodDays / 30)); // fallback
                }

                $attendanceStmt = $pdo->prepare('SELECT attendance_date, time_in, time_out, status FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date FOR UPDATE');
                $attendanceStmt->execute([$employeeId, $periodStart, $periodEnd]);
                $regularHours = 0.0;
                $overtimeHours = 0.0;
                $absentDays = 0;
                foreach ($attendanceStmt->fetchAll() as $record) {
                    if ($record['status'] === 'ABSENT') { $absentDays++; continue; }
                    if ($record['status'] === 'ON_LEAVE') continue;
                    if (empty($record['time_in']) || empty($record['time_out'])) {
                        if ($record['status'] === 'HALF_DAY') $regularHours += WORK_HOURS_PER_DAY / 2;
                        continue;
                    }
                    $clockIn = strtotime($record['attendance_date'] . ' ' . $record['time_in']);
                    $clockOut = strtotime($record['attendance_date'] . ' ' . $record['time_out']);
                    if ($clockIn === false || $clockOut === false) continue;
                    if ($clockOut <= $clockIn) $clockOut += 86400;
                    $workedHours = ($clockOut - $clockIn) / 3600;
                    if ($workedHours < 0 || $workedHours > 24) continue;
                    $regularHours += min($workedHours, WORK_HOURS_PER_DAY);
                    $overtimeHours += max(0, $workedHours - WORK_HOURS_PER_DAY);
                }
                $regularHours = round($regularHours, 2);
                $overtimeHours = round($overtimeHours, 2);

                $deductionStmt = $pdo->prepare("SELECT ad.deduction_id, ad.amount FROM attendance_deductions ad
                    JOIN attendance a ON a.attendance_id=ad.attendance_id
                    WHERE ad.employee_id=? AND a.attendance_date BETWEEN ? AND ? AND ad.included_in_payroll_id IS NULL FOR UPDATE");
                $deductionStmt->execute([$employeeId, $periodStart, $periodEnd]);
                $deductionIds = [];
                $attendanceDeductionsCents = 0;
                foreach ($deductionStmt->fetchAll() as $deduction) {
                    $amount = $parseCents($deduction['amount']);
                    if ($amount === null) throw new RuntimeException('An attendance deduction has an invalid amount.');
                    $deductionIds[] = (int)$deduction['deduction_id'];
                    $attendanceDeductionsCents += $amount;
                }

                // Deduct based on 26 realistic working days for Cafes
                $absenceDeductionsCents = (int)round(($monthlySalaryCents / 26) * $absentDays);
                $hourlyRateCents = $monthlySalaryCents / (WORK_DAYS_PER_WEEK * (52 / 12) * WORK_HOURS_PER_DAY);
                $overtimePayCents = (int)round($hourlyRateCents * $overtimeHours * OVERTIME_RATE_MULTIPLIER);
                $deductionsCents = $attendanceDeductionsCents + $absenceDeductionsCents;
                $grossCents = $basicCents + $overtimePayCents;
                if ($deductionsCents > $grossCents) {
                    $deductionsCents = $grossCents;
                }
                $netCents = $grossCents - $deductionsCents;
                if ($basicCents > $maxMoneyCents || $overtimePayCents > $maxMoneyCents || $deductionsCents > $maxMoneyCents || $netCents > $maxMoneyCents) {
                    throw new RuntimeException('Calculated payroll exceeds the supported amount range.');
                }

                $pdo->prepare("INSERT INTO payroll (employee_id, branch_id, pay_period_start, pay_period_end, basic_pay, regular_hours, overtime_hours, overtime_pay, allowances, deductions, attendance_deductions, absence_deductions, tax, net_pay, status, generated_by)
                    VALUES (?,?,?,?,?,?,?,?,0,?,?,?,?,?,'PREPARED',?)")
                    ->execute([$employeeId, $branchId, $periodStart, $periodEnd, $money($basicCents), $regularHours, $overtimeHours, $money($overtimePayCents), $money($deductionsCents), $money($attendanceDeductionsCents), $money($absenceDeductionsCents), '0.00', $money($netCents), $generatedBy]);
                $payrollId = (int)$pdo->lastInsertId();
                if ($deductionIds) {
                    $placeholders = implode(',', array_fill(0, count($deductionIds), '?'));
                    $claim = $pdo->prepare("UPDATE attendance_deductions SET included_in_payroll_id=? WHERE deduction_id IN ($placeholders) AND included_in_payroll_id IS NULL");
                    $claim->execute(array_merge([$payrollId], $deductionIds));
                    if ($claim->rowCount() !== count($deductionIds)) throw new RuntimeException('Attendance deductions were already assigned to another payroll.');
                }
                $created++;
            }
            if ($created === 0) throw new RuntimeException('No payroll records were prepared.');
            if ($ownsTransaction) $pdo->commit();
            return ['branch_id' => $branchId, 'period_start' => $periodStart, 'period_end' => $periodEnd, 'count' => $created];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}

if (!function_exists('payApprovedPayroll')) {
    function payApprovedPayroll(PDO $pdo, int $payrollId, int $userId, ?int $allowedBranchId = null): array {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $sql = "SELECT p.*, e.branch_id AS employee_branch_id, e.employee_code,
                    u.first_name, u.last_name
                FROM payroll p
                JOIN employees e ON e.employee_id=p.employee_id
                JOIN users u ON u.user_id=e.user_id
                WHERE p.payroll_id=?" . ($allowedBranchId ? ' AND p.branch_id=?' : '') . " FOR UPDATE";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($allowedBranchId ? [$payrollId, $allowedBranchId] : [$payrollId]);
            $payroll = $stmt->fetch();
            if (!$payroll || $payroll['status'] !== 'PROCESSED' || empty($payroll['approved_by']) || empty($payroll['approved_at']) || !empty($payroll['finance_transaction_id'])) {
                throw new RuntimeException('This payroll is not approved for payment or has already been paid.');
            }
            if (empty($payroll['branch_id'])) throw new RuntimeException('This payroll has no assigned branch.');
            $existing = $pdo->prepare("SELECT transaction_id FROM finance_transactions WHERE reference_type='Payroll' AND reference_id=? LIMIT 1 FOR UPDATE");
            $existing->execute([$payrollId]);
            if ($existing->fetchColumn()) {
                throw new RuntimeException('A Finance transaction already exists for this payroll.');
            }

            $description = 'Payroll payment: ' . $payroll['first_name'] . ' ' . $payroll['last_name'] . ' (' . $payroll['pay_period_start'] . ' to ' . $payroll['pay_period_end'] . ')';
            $pdo->prepare("INSERT INTO finance_transactions (fin_category_id, transaction_type, amount, transaction_date, description, reference_type, reference_id, created_by)
                VALUES (?, 'Expense', ?, CURDATE(), ?, 'Payroll', ?, ?)")
                ->execute([FIN_CATEGORY_SALARIES, $payroll['net_pay'], $description, $payrollId, $userId]);
            $transactionId = (int)$pdo->lastInsertId();

            $update = $pdo->prepare("UPDATE payroll SET status='PAID', finance_transaction_id=?, released_by=?, released_at=NOW()
                WHERE payroll_id=? AND status='PROCESSED' AND finance_transaction_id IS NULL");
            $update->execute([$transactionId, $userId, $payrollId]);
            if ($update->rowCount() !== 1) throw new RuntimeException('This payroll was paid by another Finance user.');

            if ($ownsTransaction) $pdo->commit();
            return [
                'payroll_id' => $payrollId,
                'employee_name' => $payroll['first_name'] . ' ' . $payroll['last_name'],
                'amount' => (float)$payroll['net_pay'],
                'finance_transaction_id' => $transactionId,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}

/**
 * Determine PRESENT vs LATE based on the START time of the employee's own
 * assigned shift (Morning/Afternoon/Graveyard) + grace period (see config/constants.php).
 * $timeIn is a 'H:i:s' string. $shift is one of the SHIFT_SCHEDULES keys.
 * Returns ['status'=>'PRESENT'|'LATE', 'minutes_late'=>int]
 */
function computeClockInStatus(string $timeIn, string $shift = DEFAULT_SHIFT): array {
    $shiftStart = SHIFT_SCHEDULES[$shift]['start'] ?? SHIFT_SCHEDULES[DEFAULT_SHIFT]['start'];
    $cutoff = strtotime($shiftStart) + (LATE_GRACE_MINUTES * 60);
    $in = strtotime($timeIn);
    if ($in > $cutoff) {
        $minutesLate = (int)round(($in - strtotime($shiftStart)) / 60);
        return ['status' => 'LATE', 'minutes_late' => $minutesLate];
    }
    return ['status' => 'PRESENT', 'minutes_late' => 0];
}

/** Tiered late-arrival deduction amount (pesos) for a given number of minutes late. See LATE_DEDUCTION_TIERS. */
function computeLateDeductionAmount(int $minutesLate): float {
    foreach (LATE_DEDUCTION_TIERS as $tier) {
        if ($minutesLate <= $tier['max_minutes']) {
            return (float)$tier['amount'];
        }
    }
    $tiers = LATE_DEDUCTION_TIERS;
    $lastTier = $tiers[array_key_last($tiers)];
    return (float)$lastTier['amount'];
}

/** Human-readable label for a shift, e.g. "Morning (6:00 AM - 2:00 PM)" */
function shiftLabel(?string $shift): string {
    if (!$shift || !isset(SHIFT_SCHEDULES[$shift])) return '-';
    $s = SHIFT_SCHEDULES[$shift];
    return $s['label'] . ' (' . date('g:i A', strtotime($s['start'])) . ' - ' . date('g:i A', strtotime($s['end'])) . ')';
}

/**
 * Resolve a shift's start/end as real DateTime objects anchored to a given
 * attendance date. Handles overnight shifts (e.g. Graveyard 10:00 PM - 6:00 AM)
 * by rolling the end time over to the next calendar day.
 * Returns ['start' => DateTime, 'end' => DateTime]
 */
function getShiftBounds(string $shift, string $date): array {
    $s = SHIFT_SCHEDULES[$shift] ?? SHIFT_SCHEDULES[DEFAULT_SHIFT];
    $start = new DateTime($date . ' ' . $s['start']);
    $end = new DateTime($date . ' ' . $s['end']);
    if ($end <= $start) {
        $end->modify('+1 day'); // overnight shift ends the next calendar day
    }
    return ['start' => $start, 'end' => $end];
}

/** Earliest moment an employee is allowed to Time In for their shift on a given date (see EARLY_CLOCKIN_WINDOW_MINUTES) */
function earliestClockInTime(string $shift, string $date): DateTime {
    $bounds = getShiftBounds($shift, $date);
    $earliest = clone $bounds['start'];
    $earliest->modify('-' . EARLY_CLOCKIN_WINDOW_MINUTES . ' minutes');
    return $earliest;
}

/** Fetch an employee's half-day request for a specific date, if any */
function getHalfDayRequest(PDO $pdo, int $employeeId, string $date): ?array {
    $stmt = $pdo->prepare("SELECT * FROM half_day_requests WHERE employee_id=? AND request_date=?");
    $stmt->execute([$employeeId, $date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Bootstrap badge color for a half-day request status */
function halfDayStatusColor(string $status): string {
    $colors = ['PENDING' => 'warning', 'APPROVED' => 'success', 'REJECTED' => 'danger', 'CANCELLED' => 'secondary'];
    return $colors[$status] ?? 'secondary';
}

/** Create an in-app notification for a user (e.g. interview scheduled). Fails silently. */
function createNotification(PDO $pdo, int $userId, string $title, string $message, ?string $relatedType = null, ?int $relatedId = null): void {
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, related_type, related_id) VALUES (?,?,?,?,?)")
            ->execute([$userId, $title, $message, $relatedType, $relatedId]);
    } catch (Exception $e) {
        // fail silently - notifications should never break the app
    }
}

/** Notify every ACTIVE user of a given role (e.g. all HR Managers) */
function notifyRole(PDO $pdo, int $roleId, string $title, string $message, ?string $relatedType = null, ?int $relatedId = null): void {
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role_id = ? AND status = 'ACTIVE'");
        $stmt->execute([$roleId]);
        foreach ($stmt->fetchAll() as $row) {
            createNotification($pdo, (int)$row['user_id'], $title, $message, $relatedType, $relatedId);
        }
    } catch (Exception $e) {
        // fail silently
    }
}

/**
 * Build the Employment Contract PDF for an application (requires an
 * existing job_offers row for it). Shared by the on-screen download
 * (modules/hr_staff/contract.php) and the copy attached to the offer
 * email when HR sends the job offer.
 *
 * @return array{pdf: SimplePDF, filename: string, data: array}|null null if no offer exists yet
 */
function buildEmploymentContractPdf(PDO $pdo, int $appId): ?array {
    require_once __DIR__ . '/../libs/simplepdf.php';
    require_once __DIR__ . '/contracts.php';

    // Source data and company identity are shared with the on-screen contract
    // document (see employmentContractData() in includes/contracts.php).
    $data = employmentContractData($pdo, $appId);
    if (!$data) {
        return null;
    }

    $company = companyIdentity($pdo);
    $companyName = pdfText($company['name']);
    $companyAddress = pdfText($company['address']);
    $companyContact = pdfText($company['contact']);

    $employeeName = pdfText(trim($data['first_name'] . ' ' . $data['last_name']));
    $employeeAddress = pdfText($data['address'] ?? '');
    $jobTitle = pdfText($data['job_title']);
    $departmentName = pdfText($data['department_name'] ?? '');
    $branchName = pdfText($data['branch_name'] ?? '');
    $branchAddress = pdfText($data['branch_address'] ?? '');
    $employmentTypeLabel = EMPLOYMENT_TYPE_LABELS[$data['employment_type']] ?? str_replace('_', ' ', $data['employment_type']);
    $shiftLabel = SHIFT_SCHEDULES[$data['shift']]['label'] ?? str_replace('_', ' ', (string) $data['shift']);
    $isProbationary = $data['employment_type'] === 'PROBATIONARY';
    $startDate = fdate($data['offer_date']);

    // The contract's own record: its unique Contract No. and the two
    // e-signatures applied to it. Auto-issued on first access so contracts
    // generated before this feature existed still get a number.
    $contract = getContractByApplication($pdo, $appId, true);
    $contractNo = $contract['contract_no'] ?? null;

    $pdf = new SimplePDF('Employment Contract');

    // ---- Letterhead / parties ----
    $pdf->paragraph($companyName);
    if ($companyAddress) { $pdf->paragraph($companyAddress); }
    if ($companyContact) { $pdf->paragraph($companyContact); }
    if ($contractNo) {
        $pdf->spacer(6);
        $pdf->paragraph('Contract No.: ' . $contractNo);
    }
    $pdf->spacer(14);

    $pdf->wrappedParagraph('This Employment Contract is entered into by and between:');
    $pdf->spacer(6);
    $pdf->wrappedParagraph(
        strtoupper($companyName) . ', a business duly organized and operating under the laws of the '
        . 'Republic of the Philippines, with office address at ' . $companyAddress . ', represented herein by '
        . '_______________________________, hereinafter referred to as the "EMPLOYER";'
    );
    $pdf->spacer(4);
    $pdf->paragraph('-and-');
    $pdf->spacer(4);
    $pdf->wrappedParagraph(
        $employeeName . ', of legal age, Filipino, residing at ' . ($employeeAddress ?: '_______________________________')
        . ', hereinafter referred to as the "EMPLOYEE."'
    );
    $pdf->spacer(6);
    $pdf->wrappedParagraph('The Employer and Employee agree to the following terms and conditions:');

    // ---- Numbered clauses ----
    // The clause wording lives in employmentContractClauses() (includes/contracts.php)
    // so this PDF and the on-screen contract document render identical text.
    $clauses = employmentContractClauses([
        'job_title'             => $jobTitle,
        'department_name'       => $departmentName,
        'branch_name'           => $branchName,
        'branch_address'        => $branchAddress,
        'employment_type_label' => $employmentTypeLabel,
        'is_probationary'       => $isProbationary,
        'start_date'            => $startDate,
        'salary'                => fmoneyPdf($data['offered_salary']),
        'shift_label'           => $shiftLabel,
    ]);

    foreach ($clauses as $clause) {
        $pdf->h2($clause['heading']);
        foreach ($clause['paragraphs'] as $paragraph) {
            $pdf->wrappedParagraph($paragraph);
        }
    }

    if (!empty($data['offer_details'])) {
        $pdf->spacer(6);
        $pdf->wrappedParagraph('The following additional terms were included in the written offer:');
        $pdf->wrappedParagraph(pdfText($data['offer_details']));
    }

    // ---- Signatures ----
    // Where an e-signature has actually been recorded, print the signatory's
    // name, role, and date signed instead of a blank line to write on. An
    // unsigned party still gets the blank line, so a half-signed contract can
    // be printed and completed by hand if needed.
    $pdf->spacer(24);
    $pdf->h2('SIGNATURES');
    $pdf->wrappedParagraph(
        'The parties confirm their acknowledgment and acceptance of this Contract. Signatures recorded '
        . 'electronically through the company\'s HR system are shown below with the date and time they were applied.'
    );
    $pdf->spacer(20);

    $employerLines = ['EMPLOYER', strtoupper($companyName)];
    if (!empty($contract['employer_signed_at'])) {
        $employerLines[] = 'Signed: ' . pdfText($contract['employer_signed_name']);
        $employerLines[] = 'Role: ' . pdfText($contract['employer_signed_role']);
        $employerLines[] = 'Date: ' . fdate($contract['employer_signed_at'], 'M d, Y g:i A');
        $employerLines[] = 'Ref: ' . pdfText($contract['employer_signature_hash']);
    } else {
        $employerLines[] = 'By: Authorized Representative';
        $employerLines[] = 'Date: _______________';
    }

    $employeeLines = ['EMPLOYEE', $employeeName];
    if (!empty($contract['employee_signed_at'])) {
        $employeeLines[] = 'Signed: ' . pdfText($contract['employee_signed_name']);
        $employeeLines[] = 'Role: ' . pdfText($contract['employee_signed_role'] ?: $jobTitle);
        $employeeLines[] = 'Date: ' . fdate($contract['employee_signed_at'], 'M d, Y g:i A');
        $employeeLines[] = 'Ref: ' . pdfText($contract['employee_signature_hash']);
    } else {
        $employeeLines[] = 'Position: ' . $jobTitle;
        $employeeLines[] = 'Date: _______________';
    }

    $pdf->signatureRow($employerLines, $employeeLines);
    $pdf->spacer(20);
    $pdf->singleSignature(['WITNESS', 'Name: _______________________________', 'Date: _______________']);

    if ($contractNo) {
        $pdf->spacer(12);
        $pdf->wrappedParagraph('This document is Contract No. ' . $contractNo . ' on record with the company\'s HR system.');
    }

    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $employeeName);
    $filenameNo = $contractNo ? preg_replace('/[^A-Za-z0-9_-]+/', '_', $contractNo) . '_' : '';
    return [
        'pdf' => $pdf,
        'filename' => "employment_contract_{$filenameNo}{$safeName}_{$appId}.pdf",
        'data' => $data,
        'contract' => $contract,
        'contract_no' => $contractNo,
    ];
}


/** Count unread notifications for the header bell icon */
function unreadNotificationCount(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetch()['c'];
    } catch (Exception $e) {
        return 0;
    }
}

/** Human-readable label for an interview stage */
function interviewStageLabel(string $stage): string {
    return $stage === 'FINAL_DEPARTMENT' ? 'Final Department Interview' : 'HR Initial Interview';
}

/** Log an automatic attendance deduction (e.g. late arrival) tied to an attendance record */
function logAttendanceDeduction(PDO $pdo, int $attendanceId, int $employeeId, float $amount, string $reason): void {
    $pdo->prepare("INSERT INTO attendance_deductions (attendance_id, employee_id, amount, reason) VALUES (?,?,?,?)")
        ->execute([$attendanceId, $employeeId, $amount, $reason]);
}

// =====================================================================
// MERGED FROM: Café Inventory/Finance/Procurement module
// (setFlash/redirect were duplicated in both systems and are IDENTICAL
// in spirit; the HRMS versions above are kept as canonical. getFlash()
// below is a compatibility shim so Café IFMS pages - which call
// getFlash() instead of renderFlash() - keep working against the same
// $_SESSION['flash'] array structure used by setFlash()/renderFlash().)
// =====================================================================

/** Sanitize a string input (Café IFMS naming - identical to e()) */
if (!function_exists('clean')) {
    function clean($str) {
        return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/** Format a number as currency (Philippine Peso) */
if (!function_exists('peso')) {
    function peso($amount) {
        return '₱' . number_format((float)$amount, 2);
    }
}

/** Format a date for display (Café IFMS naming - identical to fdate()) */
if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'M d, Y') {
        if (empty($date)) return '-';
        return date($format, strtotime($date));
    }
}

/**
 * Retrieve and clear ONE flash message, Café-IFMS style. Compatible with
 * the HRMS setFlash()/renderFlash() array-based flash structure above.
 */
if (!function_exists('getFlash')) {
    function getFlash() {
        if (!empty($_SESSION['flash'])) {
            $flash = array_shift($_SESSION['flash']);
            if (empty($_SESSION['flash'])) unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }
}

/**
 * Finance category that purchase expenses are auto-posted under (goods
 * received against a PO, and direct stock-ins that carry a cost).
 *
 * Looked up by the is_system flag rather than by the name 'Purchases':
 * Finance Staff may rename a system category, and the old name-based lookup
 * would then silently skip the expense while the stock still went up. If no
 * system Expense category exists at all, it is created, so a purchase can
 * never be received without landing in Finance. Call inside the caller's
 * transaction.
 */
if (!function_exists('purchasesFinanceCategoryId')) {
    function purchasesFinanceCategoryId(PDO $pdo): int {
        $id = $pdo->query("SELECT fin_category_id FROM finance_categories
                           WHERE type = 'Expense' AND is_system = 1
                           ORDER BY (category_name = 'Purchases') DESC, fin_category_id ASC LIMIT 1")->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $pdo->prepare("INSERT INTO finance_categories (category_name, type, is_system) VALUES ('Purchases', 'Expense', 1)")->execute();
        return (int)$pdo->lastInsertId();
    }
}

/** Log an activity for the Inventory/Finance dashboard feed */
if (!function_exists('logActivity')) {
    function logActivity(PDO $pdo, $userId, $module, $action) {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, module, action) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $module, $action]);
    }
}

/**
 * HRMS -> Auth integration. Blocks a cashier (or any employee-linked
 * account) from logging in if they have an APPROVED leave request that
 * covers today's date. Checked once at login time in index.php, before
 * loginUser() establishes the session.
 *
 * @param PDO $pdo
 * @param int $userId  users.user_id of the account attempting to log in
 * @return bool  true if this user is on approved leave today
 */
if (!function_exists('cashierOnApprovedLeaveToday')) {
    function cashierOnApprovedLeaveToday(PDO $pdo, int $userId): bool {
        $stmt = $pdo->prepare("
            SELECT lr.leave_id
            FROM leave_requests lr
            INNER JOIN employees e ON e.employee_id = lr.employee_id
            WHERE e.user_id = ?
              AND lr.status = 'APPROVED'
              AND CURDATE() BETWEEN lr.date_from AND lr.date_to
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }
}

/**
 * INTEGRATION: POS -> Inventory -> Finance
 * Call this right after a POS sale is committed (inside the same DB
 * transaction, before COMMIT, so a failure here rolls back the sale too).
 *
 * 1) For every product sold, look up its recipe in `product_ingredients`
 *    and deduct the matching quantity from each ingredient's
 *    `items.current_stock`, logging a `stock_movements` row for each.
 * 2) Post the sale's total as Income in `finance_transactions` (POS -> Finance).
 *
 * @param PDO   $pdo
 * @param int   $transactionId  transactions.id of the completed sale
 * @param array $soldItems      [['id' => productId, 'quantity' => qty], ...]
 * @param float $saleTotal
 * @param int   $cashierUserId
 */
if (!function_exists('inventoryAssignedBranchId')) {
    function inventoryAssignedBranchId(PDO $pdo, ?int $userId = null): ?int {
        $userId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return null;
        }
        $stmt = $pdo->prepare("SELECT e.branch_id
            FROM employees e
            JOIN branches b ON b.branch_id=e.branch_id AND b.status='ACTIVE'
            WHERE e.user_id=? AND e.employment_status IN ('ACTIVE','ON_LEAVE')");
        $stmt->execute([$userId]);
        $branchId = $stmt->fetchColumn();
        return $branchId === false || $branchId === null ? null : (int)$branchId;
    }
}

if (!function_exists('adjustInventoryItemStock')) {
    function adjustInventoryItemStock(PDO $pdo, int $branchId, int $itemId, float $quantityDelta): void {
        if ($branchId < 1 || $itemId < 1 || !is_finite($quantityDelta) || $quantityDelta == 0.0) {
            throw new InvalidArgumentException('A valid branch, item, and non-zero stock adjustment are required.');
        }

        $quantity = abs($quantityDelta);
        if ($quantityDelta > 0) {
            $branchUpdate = $pdo->prepare("INSERT INTO branch_item_inventory (branch_id, item_id, current_stock)
                VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE current_stock=current_stock+VALUES(current_stock)");
            $branchUpdate->execute([$branchId, $itemId, $quantity]);
            $totalUpdate = $pdo->prepare("UPDATE items SET current_stock=COALESCE(current_stock,0)+? WHERE item_id=?");
            $totalUpdate->execute([$quantity, $itemId]);
        } else {
            $branchUpdate = $pdo->prepare("UPDATE branch_item_inventory SET current_stock=current_stock-?
                WHERE branch_id=? AND item_id=? AND current_stock>=?");
            $branchUpdate->execute([$quantity, $branchId, $itemId, $quantity]);
            if ($branchUpdate->rowCount() !== 1) {
                throw new RuntimeException('Not enough inventory in the assigned branch.');
            }
            $totalUpdate = $pdo->prepare("UPDATE items SET current_stock=COALESCE(current_stock,0)-?
                WHERE item_id=? AND COALESCE(current_stock,0)>=?");
            $totalUpdate->execute([$quantity, $itemId, $quantity]);
        }

        if ($totalUpdate->rowCount() !== 1) {
            throw new RuntimeException('The inventory item could not be updated.');
        }
    }
}

if (!function_exists('markSupplierPayablePaid')) {
    function markSupplierPayablePaid(PDO $pdo, int $payableId, int $userId, ?int $allowedBranchId = null): array {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();

        try {
            $payableStmt = $pdo->prepare("SELECT pp.*, d.delivery_number, d.delivery_date, po.po_number, po.branch_id AS order_branch_id, s.supplier_name
                FROM purchase_payables pp
                JOIN deliveries d ON d.delivery_id=pp.delivery_id
                JOIN purchase_orders po ON po.po_id=pp.po_id
                JOIN suppliers s ON s.supplier_id=pp.supplier_id
                WHERE pp.payable_id=?" . ($allowedBranchId ? ' AND pp.branch_id=?' : '') . " FOR UPDATE");
            $payableStmt->execute($allowedBranchId ? [$payableId, $allowedBranchId] : [$payableId]);
            $payable = $payableStmt->fetch();
            if (!$payable || (int)$payable['branch_id'] !== (int)$payable['order_branch_id'] || $payable['status'] !== 'UNPAID' || $payable['finance_transaction_id'] !== null) {
                throw new RuntimeException('This payable is not available for payment or has already been paid.');
            }

            $lineStmt = $pdo->prepare("SELECT di.item_id, di.quantity_delivered, di.expiry_date, i.item_name, poi.unit_price,
                    m.product_id, m.pos_units_per_item, p.name AS product_name, p.is_active,
                    (SELECT COUNT(*) FROM product_ingredients pi WHERE pi.product_id=m.product_id) AS recipe_count
                FROM delivery_items di
                JOIN items i ON i.item_id=di.item_id
                JOIN purchase_order_items poi ON poi.po_item_id=di.po_item_id
                LEFT JOIN item_pos_mappings m ON m.item_id=di.item_id
                LEFT JOIN products p ON p.id=m.product_id
                WHERE di.delivery_id=?
                FOR UPDATE");
            $lineStmt->execute([$payable['delivery_id']]);
            $deliveryLines = $lineStmt->fetchAll();
            if (!$deliveryLines) throw new RuntimeException('The delivery has no received item lines.');

            $calculatedAmount = 0.0;
            foreach ($deliveryLines as $line) {
                $quantity = (float)$line['quantity_delivered'];
                $calculatedAmount += round($quantity * (float)$line['unit_price'], 2);
                if ($line['product_id'] !== null) {
                    $posUnits = $quantity * (float)$line['pos_units_per_item'];
                    if (!(int)$line['is_active'] || (int)$line['recipe_count'] > 0 || $posUnits <= 0 || abs($posUnits - round($posUnits)) > 0.0001 || $posUnits > 2147483647) {
                        throw new RuntimeException("The POS mapping for {$line['item_name']} is invalid. Check the product and received quantity before payment.");
                    }
                } else {
                    $recipeStmt = $pdo->prepare('SELECT 1 FROM product_ingredients WHERE item_id=? LIMIT 1');
                    $recipeStmt->execute([$line['item_id']]);
                    if (!$recipeStmt->fetchColumn()) {
                        throw new RuntimeException("{$line['item_name']} has no POS product mapping or recipe use. Link it to a POS product before payment.");
                    }
                }
            }
            $calculatedAmount = round($calculatedAmount, 2);
            if ($calculatedAmount <= 0 || abs($calculatedAmount - (float)$payable['amount']) > 0.001) {
                throw new RuntimeException('The payable total does not match the delivered quantities and purchase order costs.');
            }

            $description = "Paid goods receipt {$payable['delivery_number']} for {$payable['po_number']} ({$payable['supplier_name']})";
            $pdo->prepare("INSERT INTO finance_transactions (transaction_type, fin_category_id, amount, transaction_date, description, reference_type, reference_id, created_by)
                VALUES ('Expense', ?, ?, CURDATE(), ?, 'PurchasePayable', ?, ?)")
                ->execute([purchasesFinanceCategoryId($pdo), $calculatedAmount, $description, $payableId, $userId]);
            $financeTransactionId = (int)$pdo->lastInsertId();

            $stockMovement = $pdo->prepare("INSERT INTO stock_movements (item_id, branch_id, movement_type, reason, quantity, expiry_date, reference_type, reference_id, remarks, user_id)
                VALUES (?, ?, 'IN', 'Purchase Delivery', ?, ?, 'Delivery', ?, ?, ?)");
            foreach ($deliveryLines as $line) {
                $quantity = (float)$line['quantity_delivered'];
                adjustInventoryItemStock($pdo, (int)$payable['branch_id'], (int)$line['item_id'], $quantity);
                $stockMovement->execute([
                    $line['item_id'], $payable['branch_id'], $quantity, $line['expiry_date'], $payable['delivery_id'],
                    "Paid GRN {$payable['delivery_number']} for PO {$payable['po_number']}", $userId,
                ]);
                if ($line['product_id'] !== null) {
                    $posQuantity = (int)round($quantity * (float)$line['pos_units_per_item']);
                    $pdo->prepare("INSERT INTO branch_inventory (branch_id, product_id, stock) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE stock=stock+VALUES(stock)")
                        ->execute([$payable['branch_id'], $line['product_id'], $posQuantity]);
                    $globalPosStock = $pdo->prepare("UPDATE products SET stock=COALESCE(stock,0)+?
                        WHERE id=? AND is_active=1 AND COALESCE(stock,0)<=2147483647-?");
                    $globalPosStock->execute([$posQuantity, $line['product_id'], $posQuantity]);
                    if ($globalPosStock->rowCount() !== 1) {
                        throw new RuntimeException("POS stock could not be updated for {$line['item_name']}.");
                    }
                }
            }

            $paidUpdate = $pdo->prepare("UPDATE purchase_payables SET status='PAID', finance_transaction_id=?, paid_by=?, paid_at=NOW()
                WHERE payable_id=? AND status='UNPAID' AND finance_transaction_id IS NULL");
            $paidUpdate->execute([$financeTransactionId, $userId, $payableId]);
            if ($paidUpdate->rowCount() !== 1) throw new RuntimeException('This payable was paid by another Finance user.');

            if ($ownsTransaction) $pdo->commit();
            return [
                'delivery_number' => $payable['delivery_number'],
                'po_number' => $payable['po_number'],
                'amount' => $calculatedAmount,
                'finance_transaction_id' => $financeTransactionId,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}

if (!function_exists('recordPurchaseDelivery')) {
    function recordPurchaseDelivery(PDO $pdo, int $poId, int $branchId, int $receivedBy, string $deliveryDate, string $remarks, array $quantityInput, array $expiryInput): array {
        $date = DateTime::createFromFormat('!Y-m-d', $deliveryDate);
        if ($poId < 1 || $branchId < 1 || $receivedBy < 1 || !$date || $date->format('Y-m-d') !== $deliveryDate || $deliveryDate > date('Y-m-d') || strlen($remarks) > 255) {
            throw new InvalidArgumentException('Enter a valid delivery date and remarks under 256 characters.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $poLock = $pdo->prepare("SELECT po_id, po_number, supplier_id, status FROM purchase_orders WHERE po_id=? AND branch_id=? FOR UPDATE");
            $poLock->execute([$poId, $branchId]);
            $po = $poLock->fetch();
            if (!$po || !in_array($po['status'], ['Approved', 'Ordered', 'Partially Received'], true)) {
                throw new RuntimeException('This order is not open for receiving at your assigned branch.');
            }

            $lineStmt = $pdo->prepare("SELECT poi.*, i.item_name FROM purchase_order_items poi
                JOIN items i ON i.item_id=poi.item_id WHERE poi.po_id=? ORDER BY poi.po_item_id FOR UPDATE");
            $lineStmt->execute([$poId]);
            $lines = $lineStmt->fetchAll();
            if (!$lines) throw new RuntimeException('This purchase order has no line items.');

            $validLineIds = array_fill_keys(array_map(static fn($line) => (int)$line['po_item_id'], $lines), true);
            foreach ($quantityInput as $lineId => $rawQuantity) {
                if ($rawQuantity !== '' && (!ctype_digit((string)$lineId) || !isset($validLineIds[(int)$lineId]))) {
                    throw new RuntimeException('The submitted receipt contains an item that does not belong to this purchase order.');
                }
            }

            $receivedLines = [];
            $payableAmount = 0.0;
            foreach ($lines as $line) {
                $lineId = (int)$line['po_item_id'];
                $rawQuantity = $quantityInput[$lineId] ?? '';
                if ($rawQuantity === '') continue;
                if (!is_string($rawQuantity) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $rawQuantity) || (float)$rawQuantity <= 0) {
                    throw new RuntimeException('Enter a positive quantity with up to two decimal places for each received item.');
                }
                $quantity = (float)$rawQuantity;
                $remaining = (float)$line['quantity_ordered'] - (float)$line['quantity_received'];
                if ($quantity > $remaining + 0.0001) {
                    throw new RuntimeException("Quantity for {$line['item_name']} exceeds the remaining balance ({$remaining}).");
                }
                $expiry = $expiryInput[$lineId] ?? '';
                if ($expiry !== '') {
                    $expiryDate = is_string($expiry) ? DateTime::createFromFormat('!Y-m-d', $expiry) : false;
                    if (!$expiryDate || $expiryDate->format('Y-m-d') !== $expiry) throw new RuntimeException('Enter a valid expiry date.');
                } else {
                    $expiry = null;
                }
                $subtotal = round($quantity * (float)$line['unit_price'], 2);
                $payableAmount += $subtotal;
                $receivedLines[] = [
                    'po_item_id' => $lineId,
                    'item_id' => (int)$line['item_id'],
                    'item_name' => $line['item_name'],
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'expiry' => $expiry,
                ];
            }
            $payableAmount = round($payableAmount, 2);
            if (!$receivedLines || $payableAmount <= 0 || $payableAmount > 99999999.99) {
                throw new RuntimeException('Enter at least one received quantity with a valid payable total.');
            }

            $deliveryNumber = generateDocNumber($pdo, 'GRN');
            $pdo->prepare("INSERT INTO deliveries (delivery_number, po_id, delivery_date, received_by, remarks) VALUES (?, ?, ?, ?, ?)")
                ->execute([$deliveryNumber, $poId, $deliveryDate, $receivedBy, $remarks ?: null]);
            $deliveryId = (int)$pdo->lastInsertId();
            $insertLine = $pdo->prepare('INSERT INTO delivery_items (delivery_id, po_item_id, item_id, quantity_delivered, expiry_date) VALUES (?, ?, ?, ?, ?)');
            $updateLine = $pdo->prepare("UPDATE purchase_order_items SET quantity_received=COALESCE(quantity_received,0)+?
                WHERE po_item_id=? AND po_id=? AND COALESCE(quantity_received,0)+?<=quantity_ordered");
            foreach ($receivedLines as $line) {
                $insertLine->execute([$deliveryId, $line['po_item_id'], $line['item_id'], $line['quantity'], $line['expiry']]);
                $updateLine->execute([$line['quantity'], $line['po_item_id'], $poId, $line['quantity']]);
                if ($updateLine->rowCount() !== 1) throw new RuntimeException("Received quantity for {$line['item_name']} changed before it could be saved.");
            }

            $pdo->prepare("INSERT INTO purchase_payables (delivery_id, po_id, supplier_id, branch_id, amount, status, received_by)
                VALUES (?, ?, ?, ?, ?, 'UNPAID', ?)")
                ->execute([$deliveryId, $poId, $po['supplier_id'], $branchId, $payableAmount, $receivedBy]);

            $totalsStmt = $pdo->prepare('SELECT SUM(quantity_ordered) AS ordered_qty, SUM(quantity_received) AS received_qty FROM purchase_order_items WHERE po_id=?');
            $totalsStmt->execute([$poId]);
            $totals = $totalsStmt->fetch();
            $newStatus = (float)$totals['received_qty'] >= (float)$totals['ordered_qty'] ? 'Received' : 'Partially Received';
            $pdo->prepare('UPDATE purchase_orders SET status=? WHERE po_id=? AND branch_id=?')->execute([$newStatus, $poId, $branchId]);

            if ($ownsTransaction) $pdo->commit();
            return [
                'delivery_id' => $deliveryId,
                'delivery_number' => $deliveryNumber,
                'po_number' => $po['po_number'],
                'payable_amount' => $payableAmount,
                'po_status' => $newStatus,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}

if (!function_exists('applyPosSaleToInventoryAndFinance')) {
    function applyPosSaleToInventoryAndFinance(PDO $pdo, $transactionId, array $soldItems, $saleTotal, $cashierUserId, ?int $branchId = null) {
        $branchId = $branchId ?? (function_exists('currentBranchId') ? (int)currentBranchId() : 1);
        if ($branchId < 1) {
            throw new RuntimeException('A valid branch is required to deduct POS ingredients.');
        }

        // 1) Deduct recipe ingredients from Inventory
        $recipeStmt = $pdo->prepare("SELECT item_id, qty_per_unit FROM product_ingredients WHERE product_id = ?");
        $directMappingStmt = $pdo->prepare("SELECT item_id, pos_units_per_item FROM item_pos_mappings WHERE product_id=?");
        $moveStmt   = $pdo->prepare("INSERT INTO stock_movements (item_id, branch_id, movement_type, reason, quantity, reference_type, reference_id, remarks, user_id) VALUES (?, ?, 'OUT', 'POS Sale', ?, 'Sale', ?, ?, ?)");

        foreach ($soldItems as $line) {
            $directMappingStmt->execute([$line['id']]);
            $directMapping = $directMappingStmt->fetch();
            if ($directMapping) {
                $conversion = (float)$directMapping['pos_units_per_item'];
                if ($conversion <= 0) throw new RuntimeException('The POS product has an invalid Inventory unit conversion.');
                $rawQtyUsed = (float)$line['quantity'] / $conversion;
                $qtyUsed = round($rawQtyUsed, 2);
                if (abs($qtyUsed - $rawQtyUsed) > 0.000001) {
                    throw new RuntimeException('The POS product conversion cannot be represented by the Inventory unit precision.');
                }
                adjustInventoryItemStock($pdo, $branchId, (int)$directMapping['item_id'], -$qtyUsed);
                $moveStmt->execute([
                    (int)$directMapping['item_id'],
                    $branchId,
                    $qtyUsed,
                    $transactionId,
                    'Directly stocked POS product sale #' . $transactionId,
                    $cashierUserId,
                ]);
                continue;
            }

            $recipeStmt->execute([$line['id']]);
            $ingredients = $recipeStmt->fetchAll();
            foreach ($ingredients as $ing) {
                $qtyUsed = $ing['qty_per_unit'] * $line['quantity'];
                adjustInventoryItemStock($pdo, $branchId, (int)$ing['item_id'], -$qtyUsed);
                $moveStmt->execute([
                    $ing['item_id'],
                    $branchId,
                    $qtyUsed,
                    $transactionId,
                    'Auto-deducted from POS sale #' . $transactionId,
                    $cashierUserId,
                ]);
            }
        }

        // 2) Post sale total as Income in Finance
        $finStmt = $pdo->prepare("INSERT INTO finance_transactions (fin_category_id, transaction_type, amount, transaction_date, description, reference_type, reference_id, created_by) VALUES (?, 'Income', ?, CURDATE(), ?, 'Sale', ?, ?)");
        $finStmt->execute([
            FIN_CATEGORY_SALES,
            $saleTotal,
            'POS sale #' . $transactionId,
            $transactionId,
            $cashierUserId,
        ]);
    }
}

/**
 * Generate the next document number (PO-2026-0001 / GRN-2026-0001 / PR-2026-0001)
 * Uses doc_sequences table with row locking to avoid duplicate numbers.
 *
 * Nestable: creating a PO (po_form.php), converting a PR to a PO
 * (purchase_requests.php) and receiving a delivery (receive.php) all call this
 * from INSIDE their own transaction. It used to call beginTransaction() blindly,
 * so PDO threw "There is already an active transaction" and those three actions
 * always failed - which is why purchases never reached Inventory or Finance.
 * It now only opens (and commits) a transaction of its own when the caller has
 * none; otherwise the number joins the caller's transaction and rolls back with it.
 */
if (!function_exists('generateDocNumber')) {
    function generateDocNumber(PDO $pdo, $docType) {
        $year = date('Y');
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare("SELECT last_number FROM doc_sequences WHERE doc_type = ? AND year = ? FOR UPDATE");
            $stmt->execute([$docType, $year]);
            $row = $stmt->fetch();

            if (!$row) {
                $pdo->prepare("INSERT INTO doc_sequences (doc_type, year, last_number) VALUES (?, ?, 0)")
                    ->execute([$docType, $year]);
                $nextNumber = 1;
            } else {
                $nextNumber = $row['last_number'] + 1;
            }

            $pdo->prepare("UPDATE doc_sequences SET last_number = ? WHERE doc_type = ? AND year = ?")
                ->execute([$nextNumber, $docType, $year]);

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return sprintf('%s-%s-%04d', $docType, $year, $nextNumber);
    }
}