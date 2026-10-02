<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    logAudit($pdo, $_SESSION['user_id'], 'LOGOUT', 'Auth', '');
}
logoutUser();

// Redirect to login page with query parameter
redirect('?logout=success');