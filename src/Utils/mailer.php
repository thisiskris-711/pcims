<?php
/**
 * Mailer Utility
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendEmail(string $to, string $subject, string $body, bool $isHtml = true): bool {
    // Fail fast if required SMTP environment variables are not configured
    $requiredEnvVars = ['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'];
    foreach ($requiredEnvVars as $var) {
        if (empty($_ENV[$var])) {
            error_log("Mailer Error: Required environment variable '$var' is not set. Check your .env file.");
            return false;
        }
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['SMTP_USER'];
        $mail->Password   = $_ENV['SMTP_PASS'];
        $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $_ENV['SMTP_PORT'] ?? 587;

        // Recipients
        $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'] ?? $_ENV['SMTP_USER'], $_ENV['MAIL_FROM_NAME'] ?? APP_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML($isHtml);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
