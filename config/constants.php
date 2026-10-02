<?php
/**
 * UNIFIED APPLICATION CONSTANTS
 * Merges HRMS + Café Inventory/Finance/Procurement + POS constants into
 * one file. Existing module code (HRMS especially) references these
 * constant NAMES throughout - e.g. requireRole([ROLE_OWNER]) - so the
 * names are preserved even though the underlying role_id values now
 * point at the unified `roles` table instead of the old HRMS-only one.
 */

date_default_timezone_set('Asia/Manila');

// Base URL - automatically detected or configured via APP_BASE_URL environment variable
if (!defined('BASE_URL')) {
    $envBase = getenv('APP_BASE_URL');
    if ($envBase !== false && $envBase !== '') {
        define('BASE_URL', rtrim($envBase, '/') . '/');
    } else {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('#^(.*/unified)/#i', $script, $m)) {
            define('BASE_URL', $m[1] . '/');
        } elseif (isset($_SERVER['DOCUMENT_ROOT']) && isset($_SERVER['SCRIPT_FILENAME'])) {
            $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: '');
            $appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
            if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
                $sub = trim(substr($appRoot, strlen($docRoot)), '/');
                define('BASE_URL', '/' . ($sub !== '' ? $sub . '/' : ''));
            } else {
                define('BASE_URL', '/unified/');
            }
        } else {
            define('BASE_URL', '/unified/');
        }
    }
}

// ---------------------------------------------------------------------
// Roles - values MUST match `roles.role_id` in database/unified_schema.sql
// ---------------------------------------------------------------------
define('ROLE_OWNER', 1);
define('ROLE_HR_MANAGER', 2);
define('ROLE_HR_STAFF', 3);
define('ROLE_EMPLOYEE_MANAGER', 4);
define('ROLE_EMPLOYEE', 5);
define('ROLE_APPLICANT', 6);
define('ROLE_CASHIER', 7);
define('ROLE_INVENTORY_STAFF', 8);
define('ROLE_CUSTOMER', 9); // POS self-order kiosk only - never used for staff login
define('ROLE_FINANCE_STAFF', 10); // Finance module only (transactions, categories, financial report)

define('ROLE_NAMES', [
    ROLE_OWNER            => 'Owner',
    ROLE_HR_MANAGER       => 'HR Manager',
    ROLE_HR_STAFF         => 'HR Staff',
    ROLE_EMPLOYEE_MANAGER => 'Employee Manager',
    ROLE_EMPLOYEE         => 'Employee',
    ROLE_APPLICANT        => 'Applicant',
    ROLE_CASHIER          => 'Cashier',
    ROLE_INVENTORY_STAFF  => 'Inventory Staff',
    ROLE_CUSTOMER         => 'Customer',
    ROLE_FINANCE_STAFF    => 'Finance Staff',
]);

// ---------------------------------------------------------------------
// Module access matrix - which top-level modules each role may open.
// Mirrors the `module_permissions` table (kept here too so pages can do
// a cheap in-memory check without hitting the DB on every request).
// Module codes: dashboard, hrms, pos, inventory, procurement, finance,
// reports, profile
// ---------------------------------------------------------------------
define('MODULE_ACCESS', [
    ROLE_OWNER            => ['dashboard','hrms','pos','inventory','procurement','finance','reports','profile'],
    ROLE_HR_MANAGER       => ['dashboard','hrms','reports','profile'],
    ROLE_HR_STAFF         => ['dashboard','hrms','profile'],
    ROLE_EMPLOYEE_MANAGER => ['dashboard','hrms','profile'],
    ROLE_EMPLOYEE         => ['dashboard','hrms','profile'],
    ROLE_APPLICANT        => ['profile'],
    ROLE_CASHIER          => ['dashboard','pos','hrms','profile'],
    ROLE_INVENTORY_STAFF  => ['dashboard','inventory','procurement','reports','hrms','profile'],
    // Finance is its own module: Finance Staff open ONLY finance (+ HR
    // self-service and profile). No inventory, procurement, or stock/purchase
    // reports - and Inventory Staff, in turn, no longer reach anything in
    // the finance module (the Financial Report now lives under it).
    ROLE_FINANCE_STAFF    => ['dashboard','finance','hrms','profile'],
]);

// ---------------------------------------------------------------------
// WRITE-LEVEL CAPABILITIES
// MODULE_ACCESS above answers "may this role OPEN the module?".
// This matrix answers "may this role CHANGE data inside it?" - which is
// what separates the Owner (oversight) from Inventory Staff (encoding):
//
//   Module              Inventory Staff            Owner
//   Purchase Request    create / submit            review / approve
//   Purchase Orders     create / receive           approve / monitor
//   Suppliers           manage                     view / monitor
//   Ingredients & Stock manage                     view / monitor
//   Stock Movements     manage                     view / monitor
//   Sales / Checkout    -                          - (cashier only)
//   Restock POS Products confirm batch             view / monitor
//
// A role NOT listed for a capability can still open and read the page,
// but every write handler on it is refused (see requireCapability()).
// ---------------------------------------------------------------------
define('CAPABILITY_ROLES', [
    'inventory.manage' => [ROLE_INVENTORY_STAFF], // items, categories, units
    'stock.manage'     => [ROLE_INVENTORY_STAFF], // stock in / out / adjustment
    'suppliers.manage' => [ROLE_INVENTORY_STAFF],
    'pr.create'        => [ROLE_INVENTORY_STAFF], // raise / submit a request
    'pr.approve'       => [ROLE_OWNER],           // approve / reject a request
    'po.manage'        => [ROLE_INVENTORY_STAFF], // create PO, edit, receive, cancel
    'po.approve'       => [ROLE_OWNER],           // approve a Pending PO before supplier order
    'pos.sell'         => [ROLE_CASHIER],         // ring up a sale at checkout
    'production.manage' => [ROLE_INVENTORY_STAFF], // confirm a restock batch (Inventory -> POS)
    'recipes.manage'   => [ROLE_INVENTORY_STAFF], // link products to ingredients + qty per unit
    // Finance: Finance Staff do the day-to-day encoding. The Owner keeps write
    // access too, exactly as before this split (Finance was Owner-only), so no
    // existing ability is taken away. To make the Owner view-only like
    // Inventory, delete ROLE_OWNER from this line.
    'finance.manage'   => [ROLE_FINANCE_STAFF, ROLE_OWNER], // manual transactions + finance categories
]);

// ---------------------------------------------------------------------
// App info
// ---------------------------------------------------------------------
define('APP_NAME', 'Café Cuadro');
define('APP_TAGLINE', 'One system for HR, Sales, Inventory, Procurement & Finance');
define('APP_FULL_NAME', 'Café Business Management System');

// ---------------------------------------------------------------------
// Business identity - shown on printed / kiosk receipts.
// TODO: replace the placeholder address, contact number, and TIN below
// with the branch's actual registered details before going live.
// ---------------------------------------------------------------------
define('CAFE_BUSINESS_NAME', 'Café Cuadro');
define('CAFE_ADDRESS', '123 Rizal Avenue, Poblacion, Morong, Rizal');
define('CAFE_CONTACT', '(02) 8123 4567 / 0917 123 4567');
define('CAFE_TIN', '000-000-000-00000');

// ---------------------------------------------------------------------
// File upload settings (HRMS)
// ---------------------------------------------------------------------
define('RESUME_UPLOAD_DIR', __DIR__ . '/../uploads/resumes/');
define('PROFILE_UPLOAD_DIR', __DIR__ . '/../uploads/profile_photos/');
define('DOCUMENT_UPLOAD_DIR', __DIR__ . '/../uploads/documents/');
define('MAX_RESUME_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_RESUME_TYPES', ['pdf', 'docx']);
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png']);
define('MAX_PHOTO_SIZE', 2 * 1024 * 1024);

// Profile upload settings (Café IFMS module uses PROFILE_UPLOAD_URL)
define('PROFILE_UPLOAD_URL', BASE_URL . 'uploads/profile_photos/');

// ---------------------------------------------------------------------
// Session lifetime
// ---------------------------------------------------------------------
define('SESSION_LIFETIME', 7200); // 2 hours

// ---------------------------------------------------------------------
// Asset cache-busting
// ---------------------------------------------------------------------
define('ASSET_VER', file_exists(__DIR__ . '/../assets/hrms/css/style.css') ? filemtime(__DIR__ . '/../assets/hrms/css/style.css') : time());

// ---------------------------------------------------------------------
// HRMS: shifts, payroll, attendance rules (unchanged from original HRMS)
// ---------------------------------------------------------------------
define('APP_LAUNCH_DATE', '2026-01-01');

define('SHIFT_SCHEDULES', [
    'MORNING'   => ['label' => 'Morning',   'start' => '06:00:00', 'end' => '14:00:00'],
    'AFTERNOON' => ['label' => 'Afternoon', 'start' => '14:00:00', 'end' => '22:00:00'],
    'GRAVEYARD' => ['label' => 'Graveyard', 'start' => '22:00:00', 'end' => '06:00:00'],
]);
define('DEFAULT_SHIFT', 'MORNING');

define('EMPLOYMENT_TYPES', ['FULL_TIME', 'PART_TIME', 'CONTRACTUAL', 'PROBATIONARY']);
define('EMPLOYMENT_TYPE_LABELS', [
    'FULL_TIME'    => 'Regular',
    'PART_TIME'    => 'Part-Time',
    'CONTRACTUAL'  => 'Contractual',
    'PROBATIONARY' => 'Probationary',
]);

// Employment terms: FULL_TIME is the stored value for "Regular".
// Probationary / Contractual employees are flagged for HR Manager review
// this many days before their end date (and stay flagged once it passes).
define('EMPLOYMENT_END_ALERT_DAYS', 30);
// Default probation length applied to newly hired probationary employees.
define('DEFAULT_PROBATION_MONTHS', 6);

define('LATE_GRACE_MINUTES', 0);
define('LATE_DEDUCTION_TIERS', [
    ['max_minutes' => 15,          'amount' => 20.00],
    ['max_minutes' => 60,          'amount' => 50.00],
    ['max_minutes' => PHP_INT_MAX, 'amount' => 100.00],
]);
define('EARLY_CLOCKIN_WINDOW_MINUTES', 30);
define('WORK_DAYS_PER_WEEK', 6);
define('WORK_HOURS_PER_DAY', 8);
define('PAYROLL_CUTOFF_DAYS', 15);
define('OVERTIME_RATE_MULTIPLIER', 1.25);
define('PASSWORD_RESET_LOCK_MINUTES', 30);

// ---------------------------------------------------------------------
// Inventory / Procurement / Finance (Café IFMS)
// ---------------------------------------------------------------------
define('DEFAULT_REORDER_LEVEL', 5);

// ---------------------------------------------------------------------
// Finance categories used by the automatic postings from POS and HRMS
// (must match `finance_categories.fin_category_id` seed rows)
// ---------------------------------------------------------------------
define('FIN_CATEGORY_SALES', 1);     // Income
define('FIN_CATEGORY_PURCHASES', 2); // Expense
define('FIN_CATEGORY_UTILITIES', 3); // Expense
define('FIN_CATEGORY_RENT', 4);      // Expense
define('FIN_CATEGORY_SALARIES', 5);  // Expense
define('FIN_CATEGORY_MISC', 6);      // Expense
