<?php
// =========================================================
// Mailer Helper — send OTP email via PHPMailer + Gmail SMTP
// =========================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Send a 6-digit OTP to the given address.
 *
 * @param string $to   Recipient email address
 * @param string $otp  6-digit OTP string
 * @return bool        true on success, false on failure
 */
function sendOtpEmail(string $to, string $otp): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = 'Your OTP Code — TB5 Monitoring System';
        $mail->Body    = '
            <div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;
                        border:1px solid #ddd;border-radius:8px;overflow:hidden;">
                <div style="background:#1a3a5c;padding:18px 24px;">
                    <h2 style="color:#fff;margin:0;font-size:18px;">TB5 Monitoring System</h2>
                </div>
                <div style="padding:24px;">
                    <p style="margin:0 0 12px;">You requested a password reset. Use the OTP below:</p>
                    <div style="font-size:36px;font-weight:bold;letter-spacing:10px;
                                text-align:center;background:#f4f4f4;padding:16px;
                                border-radius:6px;color:#1a3a5c;">' . htmlspecialchars($otp) . '</div>
                    <p style="margin:16px 0 0;font-size:13px;color:#666;">
                        This code expires in <strong>15 minutes</strong>. Do not share it with anyone.
                    </p>
                </div>
            </div>';
        $mail->AltBody = "Your OTP code is: $otp\nThis code expires in 15 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}
