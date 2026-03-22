<?php
/**
 * Security Enhancement Setup Script
 * Run this script to apply all security improvements to PCIMS
 */

require_once 'config/config.php';

echo "<h1>PCIMS Security Enhancement Setup</h1>";
echo "<p>This script will apply security improvements to your PCIMS installation.</p>";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Creating Security Tables...</h2>";
    
    // Read and execute security database schema
    $security_sql = file_get_contents('security_database.sql');
    
    // Split SQL statements by semicolon and execute each
    $statements = array_filter(array_map('trim', explode(';', $security_sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $db->exec($statement);
                echo "<p style='color: green;'>✓ Executed: " . substr($statement, 0, 50) . "...</p>";
            } catch (PDOException $e) {
                // Check if it's a "table already exists" error
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    echo "<p style='color: orange;'>⚠ Table already exists: " . substr($statement, 0, 50) . "...</p>";
                } else {
                    echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>";
                }
            }
        }
    }
    
    echo "<h2>Updating Default Admin Password...</h2>";
    
    // Update default admin password to meet new security requirements
    $new_admin_password = 'Admin123!@#';
    $hashed_password = password_hash($new_admin_password, PASSWORD_DEFAULT);
    
    $query = "UPDATE users SET password = :password WHERE username = 'admin'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':password', $hashed_password);
    $stmt->execute();
    
    echo "<p style='color: green;'>✓ Admin password updated to meet security requirements</p>";
    echo "<p style='color: blue;'><strong>New Admin Password: {$new_admin_password}</strong></p>";
    
    // Update default manager password
    $new_manager_password = 'Manager123!@#';
    $hashed_manager_password = password_hash($new_manager_password, PASSWORD_DEFAULT);
    
    $query = "UPDATE users SET password = :password WHERE username = 'manager'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':password', $hashed_manager_password);
    $stmt->execute();
    
    echo "<p style='color: green;'>✓ Manager password updated to meet security requirements</p>";
    echo "<p style='color: blue;'><strong>New Manager Password: {$new_manager_password}</strong></p>";
    
    echo "<h2>Security Configuration Summary</h2>";
    echo "<ul>";
    echo "<li><strong>Rate Limiting:</strong> " . MAX_LOGIN_ATTEMPTS . " attempts per " . (LOGIN_ATTEMPT_WINDOW / 60) . " minutes</li>";
    echo "<li><strong>Account Lockout:</strong> " . (ACCOUNT_LOCKOUT_DURATION / 60) . " minutes after " . MAX_LOGIN_ATTEMPTS . " failed attempts</li>";
    echo "<li><strong>Password Policy:</strong> Minimum " . PASSWORD_MIN_LENGTH . " characters with complexity requirements</li>";
    echo "<li><strong>Security Headers:</strong> X-Frame-Options, CSP, XSS Protection, HSTS (HTTPS)</li>";
    echo "<li><strong>Error Reporting:</strong> Disabled in production</li>";
    echo "</ul>";
    
    echo "<h2>Security Features Implemented</h2>";
    echo "<ul>";
    echo "<li>✓ Login attempt tracking and rate limiting</li>";
    echo "<li>✓ Account lockout mechanism</li>";
    echo "<li>✓ Strong password policy enforcement</li>";
    echo "<li>✓ Security HTTP headers</li>";
    echo "<li>✓ Production error reporting disabled</li>";
    echo "<li>✓ CSRF token protection</li>";
    echo "<li>✓ Input sanitization and validation</li>";
    echo "<li>✓ Secure password hashing</li>";
    echo "</ul>";
    
    echo "<h2 style='color: green;'>✓ Security Enhancement Complete!</h2>";
    echo "<p><strong>Important Notes:</strong></p>";
    echo "<ul>";
    echo "<li>Change the default admin/manager passwords immediately</li>";
    echo "<li>Ensure your web server is configured for HTTPS to enable HSTS</li>";
    echo "<li>Set ENVIRONMENT to 'development' only for local debugging</li>";
    echo "<li>Regularly review security logs and login attempts</li>";
    echo "</ul>";
    
    echo "<p><a href='login.php'>Go to Login Page</a></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>Setup Error</h2>";
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<p>Please check your database configuration and try again.</p>";
}
?>
