<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

/**
 * RETIRED - leave is now handled by the Request Forms module.
 *
 * Leave used to be filed and approved on this page, separately from every
 * other kind of request. It is now one of the four Request Forms types, so
 * there is a single place to review requests rather than two that behave alike.
 *
 * Nothing was lost in the move:
 *   - every existing leave request was imported into Request Forms, with its
 *     approval history rebuilt from the reviewer columns it already carried
 *   - `leave_requests` is still the canonical leave record and is still
 *     written on every action, so leave balances, dashboards, reports and the
 *     attendance checks all continue to read exactly what they always did
 *
 * This file is kept as a redirect so old bookmarks and any link still pointing
 * here land in the right place instead of on a 404. The original page is in
 * _retired/ for reference and can be deleted once you're happy.
 */
redirect('modules/employee_manager/requests.php');
