<?php
/**
 * Simple Email Test for PCIMS
 * Test the password reset email functionality
 */

require_once 'config/config.php';
require_once 'config/email_config.php';

echo "<h2>📧 Email System Test</h2>";

// Test email function
function test_email_system() {
    $test_email = 'test@example.com';
    $test_name = 'Test User';
    $test_link = 'http://localhost/pcims/reset_password.php?token=test123';
    
    echo "<h3>Testing Email Functions...</h3>";
    
    // Test 1: Basic send_email function
    echo "<h4>1. Testing send_email() function:</h4>";
    $result1 = send_email($test_email, 'Test Subject', '<p>Test HTML body</p>');
    echo "Result: " . ($result1 ? "✅ Success" : "❌ Failed") . "<br>";
    
    // Test 2: Password reset email
    echo "<h4>2. Testing send_password_reset_email() function:</h4>";
    $result2 = send_password_reset_email($test_email, $test_name, $test_link);
    echo "Result: " . ($result2 ? "✅ Success" : "❌ Failed") . "<br>";
    
    // Test 3: Check email constants
    echo "<h4>3. Checking Email Configuration:</h4>";
    $constants = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_ENCRYPTION', 'EMAIL_FROM_ADDRESS'];
    foreach ($constants as $const) {
        if (defined($const)) {
            echo "✅ $const: " . htmlspecialchars(constant($const)) . "<br>";
        } else {
            echo "❌ $const: Not defined<br>";
        }
    }
    
    // Test 4: Check development mode
    echo "<h4>4. Development Mode:</h4>";
    echo "Status: " . (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE ? "✅ Enabled" : "❌ Disabled") . "<br>";
    
    return $result1 && $result2;
}

// Test database functionality
function test_database_reset() {
    echo "<h3>Testing Database Reset Functionality...</h3>";
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if reset columns exist
        $query = "DESCRIBE users";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $reset_token_exists = false;
        $reset_expiry_exists = false;
        
        foreach ($columns as $column) {
            if ($column['Field'] === 'reset_token') {
                $reset_token_exists = true;
            }
            if ($column['Field'] === 'reset_expiry') {
                $reset_expiry_exists = true;
            }
        }
        
        echo "reset_token column: " . ($reset_token_exists ? "✅ Exists" : "❌ Missing") . "<br>";
        echo "reset_expiry column: " . ($reset_expiry_exists ? "✅ Exists" : "❌ Missing") . "<br>";
        
        if ($reset_token_exists && $reset_expiry_exists) {
            // Test creating a reset token
            $test_token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            echo "Test token generated: " . substr($test_token, 0, 8) . "...<br>";
            echo "Test expiry: $expiry<br>";
            
            return true;
        }
        
        return false;
        
    } catch (Exception $e) {
        echo "❌ Database error: " . $e->getMessage() . "<br>";
        return false;
    }
}

// Run tests
$email_ok = test_email_system();
$database_ok = test_database_reset();

echo "<h3>📊 Summary</h3>";
echo "<div class='alert " . ($email_ok ? "alert-success" : "alert-danger") . "'>";
echo "Email System: " . ($email_ok ? "✅ Working" : "❌ Issues detected") . "<br>";
echo "Database Reset: " . ($database_ok ? "✅ Ready" : "❌ Setup required") . "<br>";
echo "</div>";

if ($email_ok && $database_ok) {
    echo "<div class='alert alert-info'>";
    echo "<strong>✅ Password reset system is ready!</strong><br>";
    echo "You can now test the forgot password functionality.<br>";
    echo "Check your error logs for email details in development mode.";
    echo "</div>";
    
    echo "<div class='text-center'>";
    echo "<a href='forgot_password.php' class='btn btn-primary'>Test Forgot Password</a>";
    echo "</div>";
} else {
    echo "<div class='alert alert-warning'>";
    echo "<strong>⚠️ Setup Required:</strong><br>";
    if (!$email_ok) echo "• Email system needs configuration<br>";
    if (!$database_ok) echo "• Run migrate_password_reset.php<br>";
    echo "</div>";
}

?>
<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
.container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.alert { padding: 15px; margin: 10px 0; border-radius: 5px; }
.alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
.alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
.alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
.alert-warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; }
.btn { padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
.btn-primary { background: #007bff; color: white; }
.text-center { text-align: center; }
</style>

<div class="container">
    <?php
    // The PHP code above will run here
    ?>
</div>
