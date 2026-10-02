<?php
/**
 * MOVED - this shared dashboard used to mix Inventory, Procurement and
 * Finance numbers on one page. Each module now has its own:
 *   Inventory Staff -> modules/inventory/dashboard.php
 *   Finance Staff   -> modules/finance/dashboard.php
 * dashboard.php sends every role to the right landing page, so this file
 * just forwards there (keeps old bookmarks working).
 */
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
header('Location: ' . BASE_URL . 'dashboard.php');
exit;
