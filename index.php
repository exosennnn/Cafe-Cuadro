<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Only redirect to dashboard if the user explicitly navigated to the login route or script.
// The public landing page (/) remains viewable even if staff is logged in.
$reqUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$isLoginRoute = preg_match('#/(login|login_page|login\.php)$#i', (string)$reqUri);
if (isLoggedIn() && $isLoginRoute) {
    redirect('dashboard');
}

$errors = [];

// Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    try {
        csrfVerify();
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $errors[] = 'Please enter both email and password.';
        } else {
            $stmt = $pdo->prepare("SELECT u.*, b.branch_id AS branch_id, b.branch_name AS branch_name
                FROM users u
                LEFT JOIN employees e ON e.user_id = u.user_id
                LEFT JOIN branches b ON b.branch_id = e.branch_id
                WHERE u.email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Invalid email or password.';
                if (function_exists('logAudit')) {
                    logAudit($pdo, null, 'LOGIN_FAILED', 'Auth', 'Email attempted: ' . $email);
                }
            } elseif ($user['status'] !== 'ACTIVE') {
                $errors[] = 'Your account is ' . strtolower($user['status']) . '. Please contact the HR department.';
            } elseif ((int)$user['role_id'] === ROLE_CUSTOMER) {
                // Kiosk customer accounts have no staff/dashboard access at all -
                // they belong on the self-order kiosk (pos/kiosk_login.php), not here.
                $errors[] = 'This account type cannot access the staff system.';
            } elseif ((int)$user['role_id'] === ROLE_CASHIER && cashierOnApprovedLeaveToday($pdo, (int)$user['user_id'])) {
                // HRMS -> POS integration: an approved leave for today blocks
                // POS login even with correct credentials.
                $errors[] = 'Access Denied: You are currently on official leave.';
            } else {
                loginUser($user);
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")->execute([$user['user_id']]);
                
                // Safely clear temporary password only if the column exists in the database
                try {
                    $pdo->prepare("UPDATE applicants SET temp_login_password = NULL WHERE user_id = ?")->execute([$user['user_id']]);
                } catch (PDOException $pe) {
                    // Ignore error if column 'temp_login_password' does not exist in applicants table
                }
                
                if (function_exists('logAudit')) {
                    logAudit($pdo, $user['user_id'], 'LOGIN_SUCCESS', 'Auth', '');
                }
                
                // If submitted via AJAX, return JSON response
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'redirect' => BASE_URL . 'dashboard']);
                    exit;
                }

                setFlash('success', 'Login successful! Welcome back, ' . e($user['first_name'] ?? 'User') . '.');
                redirect('dashboard');
            }
        }
    } catch (Throwable $e) {
        // Clear session if exception occurs after loginUser()
        session_unset();
        session_destroy();
        
        $errors[] = 'An error occurred during login: ' . $e->getMessage();
    }

    // Return JSON errors if AJAX request
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}

// Public "Now Hiring" jobs
$openJobs = $pdo->query("SELECT jv.*, d.department_name
    FROM job_vacancies jv LEFT JOIN departments d ON jv.department_id = d.department_id
    WHERE jv.status = 'OPEN' AND (jv.closing_date IS NULL OR jv.closing_date >= CURDATE())
    ORDER BY jv.posted_at DESC LIMIT 6")->fetchAll();
$openJobsCount = $pdo->query("SELECT COUNT(*) FROM job_vacancies WHERE status = 'OPEN' AND (closing_date IS NULL OR closing_date >= CURDATE())")->fetchColumn();

// ---- Menu, branches and contact info pulled from the system database ----
$menuCategories = [];
$menuByCat = [];
$pickProducts = [];
try {
    $menuCategories = $pdo->query("SELECT id, name, sort_order FROM menu_categories ORDER BY sort_order, id")->fetchAll();
    $products = $pdo->query("SELECT id, category_id, name, price, stock, image_url FROM products WHERE is_active = 1 ORDER BY category_id, id")->fetchAll();
    foreach ($products as $p) { $menuByCat[(int)$p['category_id']][] = $p; }
    // Counter picks: first in-stock item with a photo from the first four non add-on categories
    foreach ($menuCategories as $mc) {
        if (count($pickProducts) >= 4) break;
        if (stripos($mc['name'], 'add') !== false) continue;
        foreach ($menuByCat[(int)$mc['id']] ?? [] as $p) {
            if ((int)$p['stock'] > 0 && $p['image_url'] !== '') { $pickProducts[] = $p + ['category_name' => $mc['name']]; break; }
        }
    }
} catch (Throwable $ex) { /* landing page still renders without a menu */ }

$branches = [];
try {
    $branches = $pdo->query("SELECT branch_name, address, contact_number FROM branches WHERE status = 'ACTIVE' ORDER BY branch_id")->fetchAll();
} catch (Throwable $ex) {}

$settings = [];
try {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_address','company_contact')") as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }
} catch (Throwable $ex) {}

$productCount = array_sum(array_map('count', $menuByCat));
$branchCount  = count($branches);

// Product image_url values are stored as absolute paths (e.g. /unified/assets/products/x.jpg);
// rebuild them from BASE_URL so the page works under any folder name.
function landingImg(?string $url): string {
    if (!$url) return '';
    $pos = strpos($url, 'assets/');
    $rel = $pos !== false ? substr($url, $pos) : ltrim($url, '/');
    return BASE_URL . implode('/', array_map('rawurlencode', explode('/', $rel)));
}
function peso($n): string { return '₱' . number_format((float)$n, 0); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e(APP_NAME) ?> – freshly brewed coffee, baked pastries and a cozy place to stay.">
    <title><?= e(APP_NAME) ?> | Coffee &amp; Café</title>
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/hrms/css/landing-roast.css?v=<?= file_exists(__DIR__.'/assets/hrms/css/landing-roast.css') ? filemtime(__DIR__.'/assets/hrms/css/landing-roast.css') : time() ?>">
</head>
<body id="page-top" class="rt">

<!-- Header -->
<header class="rt-header" id="rtHeader">
    <div class="rt-wrap rt-header-in">
        <a href="#home" class="rt-brand"><?= e(APP_NAME) ?></a>
        <button class="rt-burger" id="rtBurger" aria-label="Toggle menu" aria-expanded="false"><i class="fas fa-bars"></i></button>
        <nav class="rt-nav" id="rtNav">
            <a href="#about">Our story</a>
            <a href="#menu">Café menu</a>
            <a href="#careers">We're hiring</a>
            <a href="#visit">Visit us</a>
            <a href="#login">Staff login</a>
        </nav>
        <a href="<?= BASE_URL ?>pos/kiosk" class="rt-btn rt-btn-dark rt-order">Order now</a>
    </div>
</header>

<!-- Hero -->
<section class="rt-hero" id="home">
    <div class="rt-hero-img" style="background-image:url('assets/hrms/img/bg-hero-coffee.jpg')"></div>
    <div class="rt-wrap rt-hero-in">
        <div class="rt-hero-copy">
            <p class="rt-eyebrow">Neighbourhood café</p>
            <h1>Coffee made fresh, <em>poured</em> the warm way.</h1>
            <p class="rt-lead">Espresso, iced coffee, frappes and pastries baked for the day. Order at the counter or skip the line and order from our kiosk.</p>
            <div class="rt-actions">
                <a href="<?= BASE_URL ?>pos/kiosk" class="rt-btn rt-btn-sage"><i class="fas fa-bag-shopping"></i> Order now</a>
                <a href="#menu" class="rt-btn rt-btn-ghost">View café menu</a>
            </div>
            <dl class="rt-stats">
                <div><dt><?= (int)$productCount ?></dt><dd>Items on the menu</dd></div>
                <div><dt><?= (int)$branchCount ?></dt><dd>Branch<?= $branchCount === 1 ? '' : 'es' ?></dd></div>
                <div><dt><?= (int)$openJobsCount ?></dt><dd>Open position<?= $openJobsCount == 1 ? '' : 's' ?></dd></div>
            </dl>
        </div>
    </div>
</section>

<!-- Our story -->
<section class="rt-sec" id="about">
    <div class="rt-wrap rt-story">
        <div class="rt-story-img">
            <img src="assets/hrms/img/about-cafe.jpg" alt="Inside <?= e(APP_NAME) ?>" loading="lazy">
            <?php if ($branchCount): ?>
            <div class="rt-float"><strong><?= (int)$branchCount ?></strong><span>Welcoming branch<?= $branchCount === 1 ? '' : 'es' ?> to visit</span></div>
            <?php endif; ?>
        </div>
        <div class="rt-story-copy">
            <p class="rt-eyebrow">Our story</p>
            <h2>A cozy retreat where coffee and conversation meet.</h2>
            <p class="rt-muted">Welcome to <?= e(APP_NAME) ?>, your cozy neighborhood retreat where great coffee and warm conversations meet. We take pride in serving carefully crafted espresso, freshly baked pastries, and comforting meals made from the finest ingredients.</p>
            <p>Whether you're stopping by for a quick morning pick-me-up, a productive work session, or a relaxing afternoon break, our welcoming space is designed to feel just like home. Every cup we brew is a testament to our passion for quality, community, and the simple joy of a perfect coffee.</p>
        </div>
    </div>
</section>

<!-- Menu -->
<section class="rt-sec rt-sec-light" id="menu">
    <div class="rt-wrap">
        <div class="rt-head rt-center">
            <p class="rt-eyebrow">On the counter</p>
            <h2>The café menu</h2>
            <p class="rt-muted">Everything we serve, with today's prices. Order any of it from the kiosk.</p>
        </div>

        <?php if (empty($menuCategories)): ?>
            <p class="rt-center rt-muted">The menu is being updated. Please check back soon.</p>
        <?php else: ?>
        <div class="rt-menu">
            <?php foreach ($menuCategories as $mc): $items = $menuByCat[(int)$mc['id']] ?? []; if (!$items) continue; ?>
            <div class="rt-menu-col">
                <h3><?= e($mc['name']) ?></h3>
                <ul>
                    <?php foreach ($items as $p): $out = (int)$p['stock'] <= 0; ?>
                    <li class="<?= $out ? 'is-out' : '' ?>">
                        <span class="rt-item"><?= e($p['name']) ?><?php if ($out): ?> <span class="rt-tag">Sold out</span><?php endif; ?></span>
                        <span class="rt-dots" aria-hidden="true"></span>
                        <span class="rt-price"><?= peso($p['price']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Counter picks -->
<?php if ($pickProducts): ?>
<section class="rt-sec" id="picks">
    <div class="rt-wrap">
        <div class="rt-head rt-split">
            <div>
                <p class="rt-eyebrow">Fresh from the counter</p>
                <h2>Pick something to start with</h2>
                <p class="rt-muted">One from each part of the menu, all ready to order.</p>
            </div>
            <a href="<?= BASE_URL ?>pos/kiosk" class="rt-btn rt-btn-ghost-dark">Open the kiosk <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="rt-cards">
            <?php foreach ($pickProducts as $p): ?>
            <article class="rt-card">
                <div class="rt-card-img"><img src="<?= e(landingImg($p['image_url'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy"></div>
                <div class="rt-card-body">
                    <p class="rt-kicker"><?= e($p['category_name']) ?></p>
                    <h3><?= e($p['name']) ?></h3>
                    <div class="rt-card-foot"><span class="rt-big"><?= peso($p['price']) ?></span></div>
                    <a href="<?= BASE_URL ?>pos/kiosk" class="rt-btn rt-btn-dark rt-block"><i class="fas fa-plus"></i> Order this</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Careers -->
<section class="rt-sec rt-sec-light" id="careers">
    <div class="rt-wrap">
        <div class="rt-head rt-split">
            <div>
                <p class="rt-eyebrow">Join the team</p>
                <h2>We're hiring</h2>
                <p class="rt-muted"><?= (int)$openJobsCount ?> open position<?= $openJobsCount == 1 ? '' : 's' ?> right now.</p>
            </div>
            <a href="<?= BASE_URL ?>check-status" class="rt-btn rt-btn-ghost-dark"><i class="fas fa-magnifying-glass"></i> Check application status</a>
        </div>

        <?php if (empty($openJobs)): ?>
            <p class="rt-empty">No open positions at the moment. Please check back soon.</p>
        <?php else: ?>
        <div class="rt-jobs">
            <?php foreach ($openJobs as $job): ?>
            <article class="rt-job">
                <h3><?= e($job['title']) ?></h3>
                <p class="rt-muted"><?= e($job['department_name'] ?? 'General') ?> &middot; <?= e(str_replace('_', ' ', $job['employment_type'])) ?> &middot; <?= (int)$job['slots'] ?> slot<?= (int)$job['slots'] === 1 ? '' : 's' ?></p>
                <a href="<?= BASE_URL ?>apply?job_id=<?= (int)$job['job_id'] ?>" class="rt-btn rt-btn-sage rt-block">Apply now</a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Visit us -->
<section class="rt-sec" id="visit">
    <div class="rt-wrap">
        <div class="rt-head rt-center">
            <p class="rt-eyebrow">Visit us</p>
            <h2>Come say hello</h2>
        </div>
        <div class="rt-visit">
            <?php foreach ($branches as $b): ?>
            <div class="rt-branch">
                <h3><i class="fas fa-location-dot"></i> <?= e($b['branch_name']) ?></h3>
                <p class="rt-muted"><?= e($b['address'] ?: ($settings['company_address'] ?? 'Address coming soon')) ?></p>
                <?php $tel = $b['contact_number'] ?: ($settings['company_contact'] ?? ''); if ($tel): ?>
                <p class="rt-muted"><i class="fas fa-phone"></i> <?= e($tel) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($branches) && !empty($settings['company_address'])): ?>
            <div class="rt-branch">
                <h3><i class="fas fa-location-dot"></i> <?= e(APP_NAME) ?></h3>
                <p class="rt-muted"><?= e($settings['company_address']) ?></p>
                <?php if (!empty($settings['company_contact'])): ?><p class="rt-muted"><i class="fas fa-phone"></i> <?= e($settings['company_contact']) ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Staff login -->
<section class="rt-sec rt-login-sec" id="login">
    <div class="rt-wrap rt-login-grid">
        <div class="rt-login-copy">
            <p class="rt-eyebrow rt-eyebrow-light">For the team</p>
            <h2>Staff login</h2>
            <p>Sign in to your <?= e(APP_NAME) ?> account to open your dashboard.</p>
        </div>
        <div class="rt-login-card">
            <div id="errorAlertContainer">
                <?php if (isset($_GET['error']) && $_GET['error'] === 'session_expired'): ?>
                    <div class="alert alert-warning small">Your session has expired. Please log in again.</div>
                <?php endif; ?>
                <?php if (isset($_GET['registered'])): ?>
                    <div class="alert alert-success small">Registration successful! You may now log in.</div>
                <?php endif; ?>
                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger small"><?= htmlspecialchars($err) ?></div>
                <?php endforeach; ?>
            </div>
            <form id="loginForm" method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <label class="rt-label" for="rtEmail">Email address</label>
                <input id="rtEmail" type="email" name="email" class="rt-input" required autocomplete="username"
                       value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                <label class="rt-label" for="rtPass">Password</label>
                <input id="rtPass" type="password" name="password" class="rt-input" required autocomplete="current-password">
                <div class="rt-forgot"><a href="<?= BASE_URL ?>forgot-password">Forgot password?</a></div>
                <button type="submit" id="btnLoginSubmit" class="rt-btn rt-btn-dark rt-block">
                    <span id="btnText">Log in</span>
                    <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                </button>
            </form>
        </div>
    </div>
</section>

<footer class="rt-footer">
    <div class="rt-wrap">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</div>
</footer>

<!-- LOGIN SUCCESSFUL MODAL -->
<div class="modal fade" id="loginSuccessModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px;">
        <div class="modal-content text-center border-0 shadow rounded-4 p-4">
            <div class="modal-body p-0 py-2">
                <div class="d-inline-flex align-items-center justify-content-center mb-3 rounded-circle" style="width:70px;height:70px;background:#e9ece5;">
                    <i class="fas fa-check" style="color:#5e6b46; font-size:2.2rem;"></i>
                </div>
                <h5 class="fw-bold mb-2">Login successful</h5>
                <p class="text-muted small mb-3">Opening your dashboard...</p>
                <div class="spinner-border spinner-border-sm" style="color:#5e6b46;" role="status"></div>
            </div>
        </div>
    </div>
</div>

<!-- LOGOUT SUCCESSFUL MODAL -->
<?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
<div class="modal fade" id="logoutSuccessModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px;">
        <div class="modal-content text-center border-0 shadow rounded-4 p-4">
            <div class="modal-body p-0 py-2">
                <div class="d-inline-flex align-items-center justify-content-center mb-3 rounded-circle" style="width:70px;height:70px;background:#e9ece5;">
                    <i class="fas fa-right-from-bracket" style="color:#5e6b46; font-size:2rem;"></i>
                </div>
                <h5 class="fw-bold mb-2">Logout successful</h5>
                <p class="text-muted small mb-3">You have been signed out.</p>
                <div class="spinner-border spinner-border-sm" style="color:#5e6b46;" role="status"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mobile nav toggle + header shadow on scroll
(function () {
    var burger = document.getElementById('rtBurger'), nav = document.getElementById('rtNav'), header = document.getElementById('rtHeader');
    burger.addEventListener('click', function () {
        var open = nav.classList.toggle('is-open');
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    nav.addEventListener('click', function (e) { if (e.target.tagName === 'A') { nav.classList.remove('is-open'); burger.setAttribute('aria-expanded', 'false'); } });
    function onScroll() { header.classList.toggle('is-scrolled', window.scrollY > 4); }
    onScroll(); document.addEventListener('scroll', onScroll, { passive: true });
})();

// AJAX login (unchanged behaviour)
document.getElementById('loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = this,
        submitBtn = document.getElementById('btnLoginSubmit'),
        btnText = document.getElementById('btnText'),
        btnSpinner = document.getElementById('btnSpinner'),
        errorContainer = document.getElementById('errorAlertContainer');

    submitBtn.disabled = true;
    btnText.innerText = 'Verifying...';
    btnSpinner.classList.remove('d-none');
    errorContainer.innerHTML = '';

    fetch(window.location.href, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (response) {
        return response.text().then(function (text) {
            try { return JSON.parse(text); } catch (err) { throw new Error('Server returned non-JSON response: ' + text); }
        });
    })
    .then(function (data) {
        if (data.success) {
            new bootstrap.Modal(document.getElementById('loginSuccessModal')).show();
            setTimeout(function () { window.location.href = data.redirect || '<?= BASE_URL ?>dashboard'; }, 50);
        } else {
            submitBtn.disabled = false;
            btnText.innerText = 'Log in';
            btnSpinner.classList.add('d-none');
            (data.errors || []).forEach(function (err) {
                var d = document.createElement('div');
                d.className = 'alert alert-danger small';
                d.textContent = err;
                errorContainer.appendChild(d);
            });
        }
    })
    .catch(function (error) {
        console.error('Error:', error);
        submitBtn.disabled = false;
        btnText.innerText = 'Log in';
        btnSpinner.classList.add('d-none');
        errorContainer.innerHTML = '<div class="alert alert-danger small">An unexpected error occurred. Please try again.</div>';
    });
});

<?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('logoutSuccessModal');
    var m = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
    // Clean the URL right away so a refresh never re-opens the modal.
    window.history.replaceState({}, document.title, window.location.pathname);
    // Bootstrap ignores hide() while the fade-in is still running, so the old
    // setTimeout(hide, 50) left the modal stuck on slower loads. Wait until it
    // has fully shown, keep it visible briefly, then hide it.
    el.addEventListener('shown.bs.modal', function () {
        setTimeout(function () { m.hide(); }, 1200);
    });
    m.show();
});
<?php endif; ?>
</script>
</body>
</html>