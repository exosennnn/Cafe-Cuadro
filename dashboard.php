<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

switch ((int)currentRoleId()) {
    case ROLE_OWNER:
        redirect('owner/dashboard');
        break;
    case ROLE_HR_MANAGER:
        redirect('hr-manager/dashboard');
        break;
    case ROLE_HR_STAFF:
        redirect('hr-staff/dashboard');
        break;
    case ROLE_EMPLOYEE_MANAGER:
        redirect('employee-manager/dashboard');
        break;
    case ROLE_EMPLOYEE:
        redirect('employee/dashboard');
        break;
    case ROLE_APPLICANT:
        redirect('applicant/dashboard');
        break;
    case ROLE_CASHIER:
        redirect('pos/dashboard');
        break;
    case ROLE_INVENTORY_STAFF:
        redirect('inventory/dashboard');
        break;
    case ROLE_FINANCE_STAFF:
        redirect('finance/dashboard');
        break;
    default:
        logoutUser();
        redirect('?error=unknown_role');
}
