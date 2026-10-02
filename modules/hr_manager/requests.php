<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/requests.php';
requireRole([ROLE_HR_MANAGER]);

/**
 * REQUEST FORMS - step 4 of the approval route.
 *
 * The queue, decision handler, and modals are shared by all three reviewer
 * roles; see includes/request_review_page.php. This file only declares which
 * step of the route this role occupies.
 */
$reviewStep = 4;
$reviewRedirect = 'modules/hr_manager/requests.php';
require __DIR__ . '/../../includes/request_review_page.php';
