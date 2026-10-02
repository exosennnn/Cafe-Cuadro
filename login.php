<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
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
                $errors[] = 'This account type cannot access the staff system.';
            } elseif ((int)$user['role_id'] === ROLE_CASHIER && cashierOnApprovedLeaveToday($pdo, (int)$user['user_id'])) {
                $errors[] = 'Access Denied: You are currently on official leave.';
            } else {
                loginUser($user);
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")->execute([$user['user_id']]);
                
                try {
                    $pdo->prepare("UPDATE applicants SET temp_login_password = NULL WHERE user_id = ?")->execute([$user['user_id']]);
                } catch (PDOException $pe) {}
                
                if (function_exists('logAudit')) {
                    logAudit($pdo, $user['user_id'], 'LOGIN_SUCCESS', 'Auth', '');
                }
                
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
        session_unset();
        session_destroy();
        $errors[] = 'An error occurred during login: ' . $e->getMessage();
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Staff Login | <?= e(APP_NAME) ?></title>
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    
    <!-- Link the main style.css to get the color variables -->
    <link rel="stylesheet" href="assets/hrms/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/shared/responsive.css?v=<?= time() ?>">
    
    <style>
        body, html { 
            height: 100%; 
            margin: 0; 
            background-color: var(--bg-light); 
            font-family: var(--font-body); 
        }
        
        .split-layout { 
            display: flex; 
            height: 100vh; 
            overflow: hidden; 
        }
        
        .split-left { 
            flex: 1.2; 
            background: url('assets/hrms/img/about-cafe.jpg') center center/cover no-repeat; 
            position: relative; 
        }
        
        .split-left::after { 
            content: ''; 
            position: absolute; 
            inset: 0; 
            /* A rich gradient overlay mixing terracotta and espresso */
            background: linear-gradient(135deg, rgba(234, 122, 59, 0.75) 0%, rgba(30, 32, 34, 0.95) 100%); 
            mix-blend-mode: multiply;
        }

        .brand-showcase {
            position: absolute;
            inset: 0;
            z-index: 2;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 5rem;
            color: #fff;
        }

        .brand-showcase h1 { 
            font-weight: 800; 
            font-size: 3.5rem; 
            letter-spacing: -1px; 
            margin-bottom: 1rem; 
            text-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .brand-showcase p { 
            font-size: 1.15rem; 
            opacity: 0.9; 
            max-width: 420px; 
            line-height: 1.7; 
        }

        .split-right { 
            flex: 1; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            background-color: var(--cream); 
            padding: 2rem; 
            box-shadow: -10px 0 40px rgba(0,0,0,0.06);
            z-index: 3;
        }
        
        .login-box { 
            width: 100%; 
            max-width: 420px; 
            padding: 2rem; 
        }
        
        .login-header { margin-bottom: 2.5rem; }
        .login-header h2 { 
            font-weight: 800; 
            font-size: 2.2rem; 
            color: var(--primary-dark); 
            margin-bottom: 0.5rem; 
            letter-spacing: -0.5px; 
        }
        .login-header p { 
            color: var(--ink-soft); 
            font-size: 0.95rem; 
        }
        
        .form-label { 
            color: var(--primary-dark); 
            font-weight: 600; 
            font-size: 0.9rem; 
            margin-bottom: 0.5rem;
        }
        
        .form-control { 
            border: 2px solid var(--border-soft); 
            border-radius: 12px; 
            padding: 0.9rem 1.2rem; 
            font-size: 1rem;
            color: var(--primary-dark);
            background-color: var(--bg-light);
            transition: all 0.25s ease;
        }
        
        .form-control:focus { 
            border-color: var(--primary); 
            background-color: var(--cream);
            box-shadow: 0 0 0 4px rgba(234, 122, 59, 0.12); 
        }
        
        .btn-primary { 
            background-color: var(--primary); 
            border: none; 
            border-radius: 12px; 
            padding: 1rem; 
            font-weight: 700; 
            font-size: 1.05rem;
            width: 100%; 
            margin-top: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 6px 16px rgba(234, 122, 59, 0.25);
        }
        
        .btn-primary:hover, .btn-primary:focus { 
            background-color: #d96828; 
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(234, 122, 59, 0.35);
        }
        
        .forgot-link { 
            color: var(--ink-soft); 
            text-decoration: none; 
            font-size: 0.85rem; 
            font-weight: 500; 
            transition: color 0.2s ease;
        }
        
        .forgot-link:hover { color: var(--primary); }
        
        /* Custom animated checkmark for modal */
        .success-icon-wrap {
            width: 70px; height: 70px; 
            background-color: rgba(22, 163, 74, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.2rem auto;
        }

        @media (max-width: 992px) {
            .split-left { display: none; }
            .split-right { box-shadow: none; background-color: var(--bg-light); }
            .login-box { 
                background: var(--cream); 
                padding: 3rem 2.5rem; 
                border-radius: 20px; 
                box-shadow: var(--shadow-md); 
            }
        }
    </style>
</head>
<body>
    <div class="split-layout">
        <div class="split-left">
            <div class="brand-showcase">
                <h1><?= e(APP_NAME) ?></h1>
                <p>Manage your cafe operations, staff, and inventory effortlessly in one unified platform.</p>
            </div>
        </div>
        
        <div class="split-right">
            <div class="login-box">
                <div class="login-header">
                    <h2>Welcome Back</h2>
                    <p>Please enter your credentials to access the staff portal.</p>
                </div>
                
                <div id="errorAlertContainer">
                    <?php if (isset($_GET['error']) && $_GET['error'] === 'session_expired'): ?>
                        <div class="alert alert-warning small border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i> Your session has expired. Please log in again.</div>
                    <?php endif; ?>
                    <?php foreach ($errors as $err): ?>
                        <div class="alert alert-danger small border-0 shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($err) ?></div>
                    <?php endforeach; ?>
                </div>

                <form id="loginForm" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    
                    <div class="mb-4">
                        <label class="form-label">Email address</label>
                        <input type="email" name="email" class="form-control" placeholder="staff@cafecuadro.com" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                    </div>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">Password</label>
                            <a href="mailto:hr@cafecuadro.com" class="forgot-link">Forgot Password?</a>
                        </div>
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                    
                    <button type="submit" id="btnLoginSubmit" class="btn btn-primary d-flex justify-content-center align-items-center gap-2">
                        <span id="btnText">Log In</span>
                        <span id="btnSpinner" class="spinner-border spinner-border-sm d-none" role="status"></span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Modals for AJAX response -->
    <div class="modal fade" id="loginSuccessModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
            <div class="modal-content text-center border-0 shadow-lg p-4" style="border-radius:20px;">
                <div class="modal-body p-0 py-2">
                    <div class="success-icon-wrap">
                        <div class="d-inline-flex align-items-center justify-content-center mb-3 rounded-circle" style="width:70px;height:70px;background:#e9ece5;">
                            <i class="fas fa-check" style="color:#5e6b46; font-size: 2.2rem;"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2 text-dark">Login Successful</h5>
                    <p class="text-muted small mb-3">Redirecting to your dashboard...</p>
                    <div class="spinner-border spinner-border-sm" style="color:#5e6b46;" role="status"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const submitBtn = document.getElementById('btnLoginSubmit');
        const btnText = document.getElementById('btnText');
        const btnSpinner = document.getElementById('btnSpinner');
        const errorContainer = document.getElementById('errorAlertContainer');

        submitBtn.disabled = true;
        btnText.innerText = 'Verifying...';
        btnSpinner.classList.remove('d-none');
        errorContainer.innerHTML = '';

        const formData = new FormData(form);

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const successModal = new bootstrap.Modal(document.getElementById('loginSuccessModal'));
                successModal.show();
                setTimeout(() => { window.location.href = data.redirect || '<?= BASE_URL ?>dashboard'; }, 50);
            } else {
                submitBtn.disabled = false;
                btnText.innerText = 'Log In';
                btnSpinner.classList.add('d-none');
                if (data.errors) {
                    data.errors.forEach(err => {
                        errorContainer.innerHTML += `<div class="alert alert-danger small border-0 shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> ${err}</div>`;
                    });
                }
            }
        })
        .catch(error => {
            submitBtn.disabled = false;
            btnText.innerText = 'Log In';
            btnSpinner.classList.add('d-none');
            errorContainer.innerHTML = `<div class="alert alert-danger small border-0 shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> An error occurred.</div>`;
        });
    });
    </script>
</body>
</html>

