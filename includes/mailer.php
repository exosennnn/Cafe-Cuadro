<?php
/**
 * Outgoing email helper (SMTP via PHPMailer).
 *
 * Usage:
 *   sendMail('applicant@example.com', 'Jane Dela Cruz', 'Interview Scheduled',
 *       '<p>Your interview is set for ...</p>');
 *
 * Design notes:
 * - Never throws and never lets a mail failure break the calling page. If
 *   sending fails (or SMTP isn't configured yet - see config/mail.php),
 *   it logs the problem and returns false; the caller can carry on (the
 *   in-app `notifications` table is always the source of truth for
 *   logged-in users - email is a best-effort convenience on top of that,
 *   which matters most for applicants who don't have a session to check).
 */

require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../libs/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Wrap a message body in a simple branded HTML shell, consistent across
 * every email the system sends.
 */
function mailTemplate(string $title, string $bodyHtml): string {
    $appName = e(APP_NAME);
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f1ea;font-family:Poppins,Arial,sans-serif;">
  <div style="max-width:520px;margin:24px auto;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e0d5;">
    <div style="background:#3b2a20;padding:20px 28px;">
      <span style="color:#fff;font-size:18px;font-weight:700;">&#9749; ' . $appName . '</span>
    </div>
    <div style="padding:28px;color:#3a3a3a;font-size:14px;line-height:1.6;">
      <h2 style="margin-top:0;font-size:18px;color:#3b2a20;">' . e($title) . '</h2>
      ' . $bodyHtml . '
    </div>
    <div style="padding:16px 28px;background:#f7f5f0;color:#9a9a9a;font-size:12px;">
      This is an automated message from ' . $appName . '. Please do not reply directly to this email.
    </div>
  </div>
</body></html>';
}

/**
 * Send an email via the configured SMTP server.
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $bodyHtml   Already-built HTML body (see mailTemplate()).
 * @param string $bodyText   Optional plain-text fallback; auto-derived if omitted.
 * @param array  $attachments Optional list of ['path' => string, 'name' => string] to attach.
 * @return bool True if the message was handed off to the SMTP server successfully.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $bodyHtml, string $bodyText = '', array $attachments = []): bool {
    if (!MAIL_ENABLED) {
        error_log("[mail] MAIL_ENABLED is false - skipped sending \"$subject\" to $toEmail. Configure config/mail.php to enable.");
        return false;
    }
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log("[mail] Skipped sending \"$subject\" - invalid recipient address: $toEmail");
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = SMTP_AUTH;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $bodyHtml;
        $mail->AltBody = $bodyText !== '' ? $bodyText : trim(strip_tags($bodyHtml));

        foreach ($attachments as $att) {
            if (!empty($att['path']) && is_file($att['path'])) {
                $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
            }
        }

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[mail] Send failed to ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    } catch (\Throwable $e) {
        error_log('[mail] Unexpected error sending to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}
