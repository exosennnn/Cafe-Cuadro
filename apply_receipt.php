<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/libs/simplepdf.php';

$ref = trim($_GET['ref'] ?? '');
$stmt = $pdo->prepare("SELECT ja.reference_code, ja.applied_at, jv.title AS job_title,
        COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
        COALESCE(a.email, u.email) AS email
    FROM job_applications ja
    JOIN applicants a ON ja.applicant_id = a.applicant_id
    LEFT JOIN users u ON a.user_id = u.user_id
    JOIN job_vacancies jv ON ja.job_id = jv.job_id
    WHERE ja.reference_code = ?");
$stmt->execute([$ref]);
$app = $stmt->fetch();

if (!$app) {
    http_response_code(404);
    die('Application not found.');
}

$returnDate = date('F j, Y', strtotime('+7 days'));

$pdf = new SimplePDF('Application Acknowledgment Receipt');
$pdf->h2('Reference Code: ' . $app['reference_code']);
$pdf->spacer(6);
$pdf->table(['Field', 'Details'], [
    ['Applicant Name', $app['first_name'] . ' ' . $app['last_name']],
    ['Email', $app['email']],
    ['Position Applied For', $app['job_title']],
    ['Date Submitted', date('F j, Y g:i A', strtotime($app['applied_at']))],
], [1, 2]);
$pdf->spacer(10);
$pdf->paragraph('Please keep this Reference Code. Return on or after ' . $returnDate . ' to check the');
$pdf->paragraph('status of your application using your Unique Reference Code on the');
$pdf->paragraph('Check Application Status page. If your application is shortlisted, your');
$pdf->paragraph('interview schedule and further instructions will be available there.');
$pdf->output('application_receipt_' . $app['reference_code'] . '.pdf');
