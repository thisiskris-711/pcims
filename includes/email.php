<?php

// Import PHPMailer classes with proper namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer from the src directory
require_once __DIR__ . '/phpmailer/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/phpmailer/PHPMailer-master/src/Exception.php';

/**
 * Email Helper Class for PCIMS
 * Provides email functionality using PHPMailer
 */
class EmailHelper
{
    private $mail;
    private $config;
    private $last_error = '';

    public function __construct()
    {
        try {
            $this->config = $this->getEmailConfig();
            $this->mail = new PHPMailer(true);
            $this->setupMailer();
        } catch (Exception $e) {
            $this->last_error = 'PHPMailer initialization error: ' . $e->getMessage();
            error_log($this->last_error);
        }
    }

    /**
     * Get email configuration from database or use defaults
     */
    private function getEmailConfig()
    {
        // Default configuration from constants
        $config = [
            'host' => SMTP_HOST,
            'port' => (int)SMTP_PORT,
            'username' => SMTP_USER,
            'password' => SMTP_PASS,
            'encryption' => SMTP_ENCRYPTION,
            'from_email' => SMTP_FROM,
            'from_name' => SMTP_FROM_NAME,
            'enabled' => EMAIL_ENABLED ? '1' : '0',
            'timeout' => SMTP_TIMEOUT
        ];

        // Try to get from database first
        try {
            $database = new Database();
            $db = $database->getConnection();

            $query = "SELECT setting_key, setting_value FROM system_settings 
                      WHERE setting_key LIKE 'email_%'";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            if (!empty($settings)) {
                $config['host'] = $settings['email_host'] ?? $config['host'];
                $config['port'] = (int)($settings['email_port'] ?? $config['port']);
                $config['username'] = $settings['email_username'] ?? $config['username'];
                $config['password'] = $settings['email_password'] ?? $config['password'];
                $config['encryption'] = $settings['email_encryption'] ?? $config['encryption'];
                $config['from_email'] = $settings['email_from'] ?? $config['from_email'];
                $config['from_name'] = $settings['email_from_name'] ?? $config['from_name'];
                $config['enabled'] = $settings['email_enabled'] ?? $config['enabled'];
                $config['timeout'] = (int)($settings['email_timeout'] ?? $config['timeout']);
            }
        } catch (Exception $e) {
            error_log("Warning: Could not load email config from database: " . $e->getMessage());
        }

        return $config;
    }

    /**
     * Setup PHPMailer with configuration
     */
    private function setupMailer()
    {
        try {
            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host = trim($this->config['host']);
            $this->mail->Port = (int)$this->config['port'];
            $this->mail->Timeout = (int)($this->config['timeout'] ?? 30);

            // Encryption mode
            if (!empty($this->config['encryption'])) {
                $encryption = strtolower(trim($this->config['encryption']));
                if ($encryption === 'ssl' || $encryption === 'tls') {
                    $this->mail->SMTPSecure = $encryption;
                } else {
                    $this->mail->SMTPSecure = '';
                }
            } else {
                $this->mail->SMTPSecure = '';
            }

            // Authentication
            if (!empty(trim($this->config['username']))) {
                $this->mail->SMTPAuth = true;
                $this->mail->Username = trim($this->config['username']);
                $this->mail->Password = $this->config['password'];
            } else {
                $this->mail->SMTPAuth = false;
            }

            // TLS/SSL Options
            $this->mail->SMTPAutoTLS = true;

            // Allow self-signed certificates in development
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                $this->mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ];
            }

            // Set from address
            if (!empty(trim($this->config['from_email']))) {
                $this->mail->setFrom(
                    trim($this->config['from_email']),
                    trim($this->config['from_name'])
                );
            }

            // Email settings
            $this->mail->CharSet = 'UTF-8';
            $this->mail->Encoding = 'base64';
            $this->mail->isHTML(true);

            // Debug settings (development only)
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                $this->mail->SMTPDebug = SMTP::DEBUG_SERVER;
                $this->mail->Debugoutput = 'error_log';
            } else {
                $this->mail->SMTPDebug = SMTP::DEBUG_OFF;
            }
        } catch (Exception $e) {
            $this->last_error = 'Mailer setup failed: ' . $e->getMessage();
            error_log($this->last_error);
        }
    }

    /**
     * Check if email is enabled and configured
     */
    public function isConfigured()
    {
        return !empty($this->config['enabled']) &&
            $this->config['enabled'] !== '0' &&
            !empty(trim($this->config['host'])) &&
            !empty(trim($this->config['from_email']));
    }

    /**
     * Get last error message
     */
    public function getLastError()
    {
        return $this->last_error ?: ($this->mail ? $this->mail->ErrorInfo : 'Unknown error');
    }

    /**
     * Send email with comprehensive error handling
     */
    public function sendEmail($to, $subject, $body, $altBody = '', $attachments = [])
    {
        try {
            if (!$this->isConfigured()) {
                throw new Exception('Email is not configured or enabled. Check SMTP settings in Admin Panel.');
            }

            if (!is_object($this->mail)) {
                throw new Exception('PHPMailer instance not properly initialized');
            }

            // Clear previous recipients and attachments
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            $this->mail->clearCCs();
            $this->mail->clearBCCs();

            // Add recipient(s) with validation
            if (is_array($to)) {
                foreach ($to as $email => $name) {
                    $email = is_numeric($email) ? $name : $email;
                    $name = is_numeric($email) ? '' : $name;
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->mail->addAddress($email, $name);
                    }
                }
            } else {
                if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    $this->mail->addAddress($to);
                } else {
                    throw new Exception("Invalid recipient email format: $to");
                }
            }

            // Set email content
            $this->mail->Subject = $subject;
            $this->mail->Body = $body;
            $this->mail->AltBody = $altBody ?: strip_tags($body);

            // Add attachments with validation
            if (!empty($attachments)) {
                foreach ($attachments as $attachment) {
                    try {
                        if (is_array($attachment)) {
                            if (isset($attachment['path']) && file_exists($attachment['path'])) {
                                $this->mail->addAttachment(
                                    $attachment['path'],
                                    $attachment['name'] ?? basename($attachment['path'])
                                );
                            }
                        } elseif (is_string($attachment) && file_exists($attachment)) {
                            $this->mail->addAttachment($attachment);
                        }
                    } catch (Exception $e) {
                        error_log("Warning: Failed to attach file - " . $e->getMessage());
                    }
                }
            }

            // Send the email
            if (!$this->mail->send()) {
                throw new Exception('Mail send failed: ' . $this->mail->ErrorInfo);
            }

            // Log successful transmission
            $recipient_info = is_array($to) ? json_encode($to) : $to;
            error_log("Email sent successfully - To: $recipient_info, Subject: $subject");

            return true;
        } catch (Exception $e) {
            $error_msg = "Email sending failed: " . $e->getMessage();
            if (isset($this->mail) && !empty($this->mail->ErrorInfo)) {
                $error_msg .= " | Server Response: " . $this->mail->ErrorInfo;
            }
            $this->last_error = $error_msg;
            error_log($error_msg);
            return false;
        }
    }

    /**
     * Send low stock alert email
     */
    public function sendLowStockAlert($product_name, $current_stock, $recipients = [])
    {
        if (empty($recipients)) {
            $recipients = $this->getManagerEmails();
        }

        if (empty($recipients)) {
            return false;
        }

        $subject = "Low Stock Alert: {$product_name}";

        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .alert { background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; }
                .product { font-weight: bold; color: #721c24; }
                .stock { font-size: 18px; font-weight: bold; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; }
            </style>
        </head>
        <body>
            <h2>Low Stock Alert</h2>
            <div class='alert'>
                <p>The following product is running low on stock:</p>
                <p><span class='product'>Product:</span> {$product_name}</p>
                <p><span class='product'>Current Stock:</span> <span class='stock'>{$current_stock}</span> units</p>
            </div>
            <p>Please review your inventory and consider replenishing stock for this item.</p>
            <p>You can view the product details in the PCIMS system.</p>
            <div class='footer'>
                <p>This is an automated message from PCIMS (Personal Collection Inventory Management System).</p>
                <p>If you believe this is an error, please contact your system administrator.</p>
            </div>
        </body>
        </html>";

        return $this->sendEmail($recipients, $subject, $body);
    }

    /**
     * Send out of stock alert email
     */
    public function sendOutOfStockAlert($product_name, $recipients = [])
    {
        if (empty($recipients)) {
            $recipients = $this->getManagerEmails();
        }

        if (empty($recipients)) {
            return false;
        }

        $subject = "OUT OF STOCK: {$product_name}";

        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .alert { background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; }
                .product { font-weight: bold; color: #721c24; }
                .stock { font-size: 18px; font-weight: bold; color: #dc3545; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; }
            </style>
        </head>
        <body>
            <h2>Out of Stock Alert</h2>
            <div class='alert'>
                <p>The following product is now OUT OF STOCK:</p>
                <p><span class='product'>Product:</span> {$product_name}</p>
                <p><span class='product'>Current Stock:</span> <span class='stock'>0</span> units</p>
            </div>
            <p><strong>Immediate action required!</strong> This product cannot be sold until stock is replenished.</p>
            <p>Please update your inventory as soon as possible.</p>
            <div class='footer'>
                <p>This is an automated message from PCIMS (Personal Collection Inventory Management System).</p>
                <p>If you believe this is an error, please contact your system administrator.</p>
            </div>
        </body>
        </html>";

        return $this->sendEmail($recipients, $subject, $body);
    }

    /**
     * Send welcome email to new user
     */
    public function sendWelcomeEmail($user_email, $user_name, $temp_password = '')
    {
        $subject = "Welcome to PCIMS";

        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; }
                .btn { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Welcome to PCIMS</h1>
                <p>Personal Collection Inventory Management System</p>
            </div>
            <div class='content'>
                <h2>Hello {$user_name}!</h2>
                <p>Your account has been created in the PCIMS system.</p>";

        if (!empty($temp_password)) {
            $body .= "
                <p><strong>Your temporary password:</strong> <code>{$temp_password}</code></p>
                <p>Please log in and change your password immediately for security.</p>";
        }

        $body .= "
                <p>You can now access the system to manage inventory, view reports, and more.</p>
                <p><a href='" . APP_URL . "' class='btn'>Access PCIMS</a></p>
            </div>
            <div class='footer'>
                <p>This is an automated message from PCIMS.</p>
                <p>If you have any questions, please contact your system administrator.</p>
            </div>
        </body>
        </html>";

        return $this->sendEmail($user_email, $subject, $body);
    }

    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($user_email, $user_name, $reset_link)
    {
        $subject = "Password Reset - PCIMS";

        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; }
                .btn { display: inline-block; padding: 10px 20px; background-color: #dc3545; color: white; text-decoration: none; border-radius: 5px; }
                .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Password Reset Request</h1>
                <p>PCIMS - Personal Collection Inventory Management System</p>
            </div>
            <div class='content'>
                <h2>Hello {$user_name}!</h2>
                <p>A password reset request was made for your account.</p>
                <div class='warning'>
                    <p><strong>If you did not request this, please ignore this email.</strong></p>
                    <p>This link will expire in 1 hour for security reasons.</p>
                </div>
                <p>Click the button below to reset your password:</p>
                <p><a href='{$reset_link}' class='btn'>Reset Password</a></p>
                <p>Or copy and paste this link into your browser:</p>
                <p><code>{$reset_link}</code></p>
            </div>
            <div class='footer'>
                <p>This is an automated message from PCIMS.</p>
                <p>If you have any questions, please contact your system administrator.</p>
            </div>
        </body>
        </html>";

        return $this->sendEmail($user_email, $subject, $body);
    }

    /**
     * Send order confirmation email
     */
    public function sendOrderConfirmation($order_data, $recipient_email)
    {
        $order_type = $order_data['type'] ?? 'sales';
        $order_number = $order_data['order_number'] ?? 'N/A';
        $customer_name = $order_data['customer_name'] ?? 'Customer';
        $total_amount = $order_data['total_amount'] ?? 0;
        $items = $order_data['items'] ?? [];

        $subject = "Order Confirmation #{$order_number} - PCIMS";

        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; }
                .header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .order-info { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .items-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                .items-table th, .items-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
                .items-table th { background-color: #f8f9fa; }
                .total { font-weight: bold; font-size: 18px; text-align: right; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Order Confirmation</h1>
                <p>PCIMS - Personal Collection Inventory Management System</p>
            </div>
            <div class='content'>
                <h2>Order #{$order_number}</h2>
                <p>Dear {$customer_name},</p>
                <p>Your " . ucfirst($order_type) . " order has been confirmed and processed.</p>
                
                <div class='order-info'>
                    <p><strong>Order Number:</strong> {$order_number}</p>
                    <p><strong>Order Type:</strong> " . ucfirst($order_type) . "</p>
                    <p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>
                    <p><strong>Total Amount:</strong> " . format_currency($total_amount) . "</p>
                </div>
                
                <h3>Order Items:</h3>
                <table class='items-table'>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>";

        foreach ($items as $item) {
            $item_total = $item['quantity'] * $item['unit_price'];
            $body .= "
                        <tr>
                            <td>{$item['product_name']}</td>
                            <td>{$item['quantity']}</td>
                            <td>" . format_currency($item['unit_price']) . "</td>
                            <td>" . format_currency($item_total) . "</td>
                        </tr>";
        }

        $body .= "
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan='3' class='total'>Total:</td>
                            <td class='total'>" . format_currency($total_amount) . "</td>
                        </tr>
                    </tfoot>
                </table>
                
                <p>Thank you for your business!</p>
            </div>
            <div class='footer'>
                <p>This is an automated message from PCIMS.</p>
                <p>If you have any questions about your order, please contact us.</p>
            </div>
        </body>
        </html>";

        return $this->sendEmail($recipient_email, $subject, $body);
    }

    /**
     * Get manager and admin emails
     */
    private function getManagerEmails()
    {
        try {
            $database = new Database();
            $db = $database->getConnection();

            $query = "SELECT email FROM users 
                      WHERE role IN ('manager', 'admin') 
                      AND status = 'active' 
                      AND email IS NOT NULL 
                      AND email != ''";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return !empty($emails) ? $emails : [];
        } catch (Exception $e) {
            error_log("Error getting manager emails: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get current configuration (for diagnostics)
     */
    public function getConfig()
    {
        $config = $this->config;
        // Mask password for security
        $config['password'] = !empty($config['password']) ? '***' . substr($config['password'], -2) : '';
        return $config;
    }

    /**
     * Update configuration in database
     */
    public function updateConfig($new_config)
    {
        try {
            $database = new Database();
            $db = $database->getConnection();

            foreach ($new_config as $key => $value) {
                // Handle key prefixing properly
                $setting_key = (strpos($key, 'email_') === 0) ? $key : 'email_' . $key;

                $query = "INSERT INTO system_settings (setting_key, setting_value) 
                          VALUES (:key, :value)
                          ON DUPLICATE KEY UPDATE setting_value = :value2";

                $stmt = $db->prepare($query);
                $stmt->bindParam(':key', $setting_key);
                $stmt->bindParam(':value', $value);
                $stmt->bindParam(':value2', $value);

                if (!$stmt->execute()) {
                    throw new Exception("Failed to update $setting_key");
                }
            }

            // Refresh configuration
            $this->config = $this->getEmailConfig();
            $this->setupMailer();

            error_log("Email configuration updated successfully");
            return true;
        } catch (Exception $e) {
            $this->last_error = "Config update failed: " . $e->getMessage();
            error_log($this->last_error);
            return false;
        }
    }

    /**
     * Test email configuration
     */
    public function testConfiguration($test_email = '')
    {
        try {
            if (empty($test_email)) {
                throw new Exception('Test email address is required');
            }

            if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address format');
            }

            $encryption_display = $this->config['encryption'] ?: 'None';

            $subject = "PCIMS Email Configuration Test - " . date('Y-m-d H:i:s');
            $body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .test-info { background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; }
                    .config-item { margin: 8px 0; font-size: 14px; }
                    .success { color: #28a745; font-weight: bold; margin-top: 20px; }
                </style>
            </head>
            <body>
                <h2>✓ Email Configuration Test</h2>
                <p>This test email verifies that your PCIMS email configuration is working correctly.</p>
                <div class='test-info'>
                    <div class='config-item'><strong>Test Time:</strong> " . date('Y-m-d H:i:s') . "</div>
                    <div class='config-item'><strong>SMTP Host:</strong> " . htmlspecialchars($this->config['host']) . "</div>
                    <div class='config-item'><strong>SMTP Port:</strong> " . $this->config['port'] . "</div>
                    <div class='config-item'><strong>Encryption:</strong> " . strtoupper($encryption_display) . "</div>
                    <div class='config-item'><strong>From Email:</strong> " . htmlspecialchars($this->config['from_email']) . "</div>
                </div>
                <p class='success'>If you received this email, your email configuration is working correctly!</p>
                <hr>
                <p style='color: #666; font-size: 12px;'>This is an automated test message from PCIMS. If you have questions, contact your administrator.</p>
            </body>
            </html>";

            return $this->sendEmail($test_email, $subject, $body);
        } catch (Exception $e) {
            $this->last_error = "Test failed: " . $e->getMessage();
            if (isset($this->mail) && !empty($this->mail->ErrorInfo)) {
                $this->last_error .= " | Server: " . $this->mail->ErrorInfo;
            }
            error_log($this->last_error);
            return false;
        }
    }
}
