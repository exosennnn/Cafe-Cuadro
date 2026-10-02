<?php

// Keep SMTP credentials outside the repository. Configure these variables in
// Apache/PHP before enabling mail: HRMS_MAIL_ENABLED, HRMS_SMTP_USERNAME,
// HRMS_SMTP_PASSWORD, and optionally HRMS_SMTP_HOST, HRMS_SMTP_PORT,
// HRMS_SMTP_SECURE, HRMS_MAIL_FROM_ADDRESS, and HRMS_MAIL_FROM_NAME.

$mailEnabled = getenv('HRMS_MAIL_ENABLED');
$smtpUsername = getenv('HRMS_SMTP_USERNAME') ?: '';
$smtpPassword = getenv('HRMS_SMTP_PASSWORD') ?: '';
$smtpPassword = preg_replace('/\s+/', '', $smtpPassword) ?? '';

// Sanitize & Validate SMTP Host
$smtpHost = getenv('HRMS_SMTP_HOST');
if (!$smtpHost || $smtpHost === 'true' || $smtpHost === true || !is_string($smtpHost)) {
    $smtpHost = 'smtp.gmail.com';
}

// Sanitize & Validate SMTP Port
$smtpPortRaw = getenv('HRMS_SMTP_PORT');
$smtpPort = (is_numeric($smtpPortRaw) && (int)$smtpPortRaw > 0) ? (int)$smtpPortRaw : 587;

// Sanitize & Validate Secure Protocol
$smtpSecure = getenv('HRMS_SMTP_SECURE');
if (!$smtpSecure || $smtpSecure === 'true' || $smtpSecure === true || !is_string($smtpSecure)) {
    $smtpSecure = 'tls';
}

// Sanitize & Validate From Address
$mailFromAddress = getenv('HRMS_MAIL_FROM_ADDRESS') ?: '';
if (!filter_var($mailFromAddress, FILTER_VALIDATE_EMAIL)) {
    $mailFromAddress = filter_var($smtpUsername, FILTER_VALIDATE_EMAIL) ? $smtpUsername : 'hrmscafe@gmail.com';
}

define('MAIL_ENABLED', $mailEnabled !== false && filter_var($mailEnabled, FILTER_VALIDATE_BOOLEAN) && $smtpUsername !== '' && $smtpPassword !== '');
define('SMTP_HOST', $smtpHost);
define('SMTP_PORT', $smtpPort);
define('SMTP_SECURE', $smtpSecure);
define('SMTP_AUTH', true);
define('SMTP_USERNAME', $smtpUsername);
define('SMTP_PASSWORD', $smtpPassword);

define('MAIL_FROM_ADDRESS', $mailFromAddress);
define('MAIL_FROM_NAME', getenv('HRMS_MAIL_FROM_NAME') ?: 'Café Cuadro');