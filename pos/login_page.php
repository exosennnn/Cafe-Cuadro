<?php
/**
 * Staff no longer log in here directly - there is ONE login for the
 * whole system now. This file just forwards to it so any old bookmarks
 * or links to pos/login_page.php still work.
 */
require_once __DIR__ . '/../config/constants.php';
header('Location: ' . BASE_URL);
exit;
