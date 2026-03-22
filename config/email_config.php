<?php
/**
 * Email Configuration for PCIMS
 * Configure SMTP settings and email functionality
 */

// Prevent multiple inclusion
if (!defined('EMAIL_CONFIG_LOADED')) {
    define('EMAIL_CONFIG_LOADED', true);

// Email Settings - Only define if not already set
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', getenv('PCIMS_SMTP_HOST') ?: '');        // SMTP server
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int) (getenv('PCIMS_SMTP_PORT') ?: 587)); // SMTP port (587 for TLS, 465 for SSL)
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', defined('SMTP_USER') ? SMTP_USER : (getenv('PCIMS_SMTP_USERNAME') ?: getenv('PCIMS_SMTP_USER') ?: ''));
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', defined('SMTP_PASS') ? SMTP_PASS : (getenv('PCIMS_SMTP_PASSWORD') ?: getenv('PCIMS_SMTP_PASS') ?: ''));
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', getenv('PCIMS_SMTP_ENCRYPTION') ?: 'tls'); // Encryption: 'tls' or 'ssl'
}

// Email From Settings
if (!defined('EMAIL_FROM_NAME')) {
    define('EMAIL_FROM_NAME', APP_NAME);          // From name
}
if (!defined('EMAIL_FROM_ADDRESS')) {
    define('EMAIL_FROM_ADDRESS', defined('SMTP_FROM') ? SMTP_FROM : (getenv('PCIMS_SMTP_FROM') ?: ''));
}

// Email Templates
if (!defined('EMAIL_FOOTER')) {
    define('EMAIL_FOOTER', "
    <hr>
    <p style=\"color: #666; font-size: 12px;\">
        This email was sent automatically by " . APP_NAME . ".<br>
        If you did not request this action, please ignore this email.<br>
        &copy; " . date('Y') . " " . APP_NAME . ". All rights reserved.
    </p>
");
}

/**
 * Send email using PHPMailer or fallback
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @param array $attachments Optional attachments
 * @return bool Success status
 */
function send_email($to, $subject, $body, $attachments = []) {
    
    // Try PHPMailer first (if available)
    $phpmailer_paths = [
        __DIR__ . '/../includes/phpmailer/PHPMailer-master/src/PHPMailer.php',
        __DIR__ . '/../vendor/PHPMailer/PHPMailer.php',
        __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
        __DIR__ . '/PHPMailer/PHPMailer.php'
    ];
    
    $phpmailer_available = false;
    foreach ($phpmailer_paths as $path) {
        if (file_exists($path)) {
            $phpmailer_available = true;
            break;
        }
    }
    
    if ($phpmailer_available) {
        try {
            // Import PHPMailer classes
            foreach ($phpmailer_paths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    break;
                }
            }
            
            // Try to load SMTP and Exception classes
            $smtp_path = str_replace('PHPMailer.php', 'SMTP.php', $path);
            $exception_path = str_replace('PHPMailer.php', 'Exception.php', $path);
            
            if (file_exists($smtp_path)) {
                require_once $smtp_path;
            }
            if (file_exists($exception_path)) {
                require_once $exception_path;
            }
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_ENCRYPTION;
            $mail->Port       = SMTP_PORT;
            
            // Recipients
            $mail->setFrom(EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME);
            $mail->addAddress($to);
            
            // Add attachments if provided
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
            
            $mail->send();
            error_log("Email sent successfully via PHPMailer to: $to");
            return true;
            
        } catch (Exception $e) {
            error_log("PHPMailer failed: " . $e->getMessage());
            // Fall back to other methods
        }
    }
    
    // Fallback 1: Try PHP mail() function (only if not in development mode)
    if (!defined('DEVELOPMENT_MODE') || !DEVELOPMENT_MODE) {
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_ADDRESS . ">" . "\r\n";
        
        // Suppress warnings and check result
        $result = @mail($to, $subject, $body, $headers);
        if ($result) {
            error_log("Email sent successfully via PHP mail() to: $to");
            return true;
        } else {
            error_log("PHP mail() failed - this is normal in development without SMTP server");
        }
    }
    
    // Fallback 2: Log email for development (always succeeds)
    error_log("=== EMAIL LOG (DEVELOPMENT MODE) ===");
    error_log("TO: $to");
    error_log("SUBJECT: $subject");
    error_log("BODY: " . strip_tags($body));
    error_log("TIMESTAMP: " . date('Y-m-d H:i:s'));
    error_log("=== END EMAIL LOG ===");
    
    // In development mode, we'll consider this a success
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        error_log("Development mode: Email logged successfully (no actual email sent)");
        return true;
    }
    
    error_log("All email methods failed for: $to");
    return false;
}

/**
 * Send password reset email
 * 
 * @param string $to Recipient email
 * @param string $user_name User's name
 * @param string $reset_link Password reset link
 * @return bool Success status
 */
function send_password_reset_email($to, $user_name, $reset_link) {
    $subject = "Password Reset Request - " . APP_NAME;
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Password Reset</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(180deg, #e74a3b 10%, #c0392b 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; background: linear-gradient(180deg, #e74a3b 10%, #c0392b 100%); color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .button:hover { background: linear-gradient(180deg, #c0392b 10%, #e74a3b 100%); }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🔐 Password Reset</h1>
                <p>" . APP_NAME . "</p>
            </div>
            <div class='content'>
                <p>Hello <strong>" . htmlspecialchars($user_name) . "</strong>,</p>
                <p>We received a request to reset your password for your " . APP_NAME . " account.</p>
                <p>Click the button below to reset your password:</p>
                <div style='text-align: center;'>
                    <a href='" . htmlspecialchars($reset_link) . "' class='button'>Reset Password</a>
                </div>
                <p>Or copy and paste this link into your browser:</p>
                <p style='background: #eee; padding: 10px; border-radius: 5px; word-break: break-all;'>
                    " . htmlspecialchars($reset_link) . "
                </p>
                <p><strong>Important:</strong></p>
                <ul>
                    <li>This link will expire in 1 hour for security reasons</li>
                    <li>If you didn't request this password reset, please ignore this email</li>
                    <li>Your password will remain unchanged if you don't click the link</li>
                </ul>
            </div>
            <div class='footer'>
                " . EMAIL_FOOTER . "
            </div>
        </div>
    </body>
    </html>";
    
    return send_email($to, $subject, $body);
}

/**
 * Send welcome email
 * 
 * @param string $to Recipient email
 * @param string $user_name User's name
 * @param string $login_link Login link
 * @return bool Success status
 */
function send_welcome_email($to, $user_name, $login_link) {
    $subject = "Welcome to " . APP_NAME;
    
    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Welcome</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(180deg, #e74a3b 10%, #c0392b 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .button { display: inline-block; background: linear-gradient(180deg, #e74a3b 10%, #c0392b 100%); color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .button:hover { background: linear-gradient(180deg, #c0392b 10%, #e74a3b 100%); }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎉 Welcome!</h1>
                <p>" . APP_NAME . "</p>
            </div>
            <div class='content'>
                <p>Hello <strong>" . htmlspecialchars($user_name) . "</strong>,</p>
                <p>Welcome to " . APP_NAME . "! Your account has been successfully created.</p>
                <p>You can now access the Personal Collection Inventory Management System.</p>
                <div style='text-align: center;'>
                    <a href='" . htmlspecialchars($login_link) . "' class='button'>Login Now</a>
                </div>
                <p><strong>Getting Started:</strong></p>
                <ul>
                    <li>Log in with your credentials</li>
                    <li>Explore the dashboard features</li>
                    <li>Start managing your personal collection</li>
                </ul>
            </div>
            <div class='footer'>
                " . EMAIL_FOOTER . "
            </div>
        </div>
    </body>
    </html>";
    
    return send_email($to, $subject, $body);
}

/**
 * Fallback email function (for development/testing)
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body
 * @return bool Success status
 */
function send_email_fallback($to, $subject, $body) {
    // For development, just log the email
    error_log("EMAIL TO: $to");
    error_log("SUBJECT: $subject");
    error_log("BODY: " . strip_tags($body));
    
    // In development, you might want to display the email instead of sending it
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        return true; // Pretend email was sent
    }
    
    // Try using PHP mail() as fallback
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . EMAIL_FROM_NAME . " <" . EMAIL_FROM_ADDRESS . ">" . "\r\n";
    
    return mail($to, $subject, $body, $headers);
}

} // Close EMAIL_CONFIG_LOADED if statement
