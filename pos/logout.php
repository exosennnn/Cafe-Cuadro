<?php
/**
 * Delegates to the unified logout so the ONE shared session is torn
 * down consistently, instead of destroying only the POS view of it.
 */
require_once __DIR__ . '/../config/constants.php';
header('Location: ' . BASE_URL . 'logout');
exit;
