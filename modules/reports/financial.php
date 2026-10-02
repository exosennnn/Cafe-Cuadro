<?php
/**
 * MOVED - the Financial Report now belongs to the Finance module.
 * Kept as a plain redirect so old bookmarks and links still land on it.
 * (Deliberately does NOT use auth_check.php: that would test the "reports"
 * module, and the redirect target does its own finance-module check.)
 */
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
header('Location: ' . BASE_URL . 'finance/report' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit;
