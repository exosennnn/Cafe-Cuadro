<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/requests.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

/**
 * REQUEST FORMS - step 2 of the approval route.
 *
 * The queue, decision handler, and modals are shared by all three reviewer
 * roles; see includes/request_review_page.php. This file only declares which
 * step of the route this role occupies.
 */
$reviewStep = 2;
$reviewRedirect = 'modules/employee_manager/requests.php';
$reviewCompactHeader = true; // plain "Request Forms" title, no route/explainer text
require __DIR__ . '/../../includes/request_review_page.php';