<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/contracts.php';
requireRole([ROLE_EMPLOYEE]);

$userId = (int)$_SESSION['user_id'];
$employeeStmt = $pdo->prepare('SELECT employee_id FROM employees WHERE user_id=?');
$employeeStmt->execute([$userId]);
$employeeId = (int)$employeeStmt->fetchColumn();
$contract = $employeeId ? getContractByEmployee($pdo, $employeeId) : null;

if (!$contract || empty($contract['application_id'])) {
    setFlash('error', 'No employment contract is available for your account yet.');
    redirect('modules/employee/profile.php');
}

redirect('modules/hr_staff/contract.php?application_id=' . (int)$contract['application_id']);
