<?php
require_once 'config/config.php';
require_once 'includes/email.php';

echo "<h2>🔧 Email Configuration Diagnostic Tool</h2>";

// Check if email is enabled in config
echo "<h3>1. Configuration Status</h3>";
echo "<strong>Email Enabled (config):</strong> " . (defined('EMAIL_ENABLED') && EMAIL_ENABLED ? '✅ Yes' : '❌ No') . "<br>";

try {
    $emailHelper = new EmailHelper();
    $config = $emailHelper->getConfig();
    
    echo "<strong>Email Enabled (database):</strong> " . ($config['enabled'] === '1' ? '✅ Yes' : '❌ No') . "<br>";
    echo "<strong>SMTP Host:</strong> " . htmlspecialchars($config['host']) . "<br>";
    echo "<strong>SMTP Port:</strong> " . htmlspecialchars($config['port']) . "<br>";
    echo "<strong>Username:</strong> " . (!empty($config['username']) ? '✅ Set' : '❌ Empty') . "<br>";
    echo "<strong>Password:</strong> " . (!empty($config['password']) ? '✅ Set' : '❌ Empty') . "<br>";
    echo "<strong>From Email:</strong> " . htmlspecialchars($config['from_email']) . "<br>";
    echo "<strong>Encryption:</strong> " . htmlspecialchars($config['encryption']) . "<br>";
    
} catch (Exception $e) {
    echo "<strong>Error:</strong> ❌ " . $e->getMessage() . "<br>";
}

echo "<h3>2. PHP Environment Check</h3>";
echo "<strong>PHP Version:</strong> " . PHP_VERSION . "<br>";
echo "<strong>OpenSSL:</strong> " . (extension_loaded('openssl') ? '✅ Enabled' : '❌ Disabled - Required for SSL/TLS') . "<br>";
echo "<strong>MBString:</strong> " . (extension_loaded('mbstring') ? '✅ Enabled' : '❌ Disabled - Recommended') . "<br>";

echo "<h3>3. Network Connectivity Test</h3>";
$host = $config['host'] ?? 'smtp.gmail.com';
$port = $config['port'] ?? 587;

$timeout = 5;
$connection = @fsockopen($host, $port, $errno, $errstr, $timeout);

if ($connection) {
    echo "<strong>SMTP Connection:</strong> ✅ Successfully connected to $host:$port<br>";
    fclose($connection);
} else {
    echo "<strong>SMTP Connection:</strong> ❌ Failed to connect to $host:$port<br>";
    echo "<strong>Error:</strong> $errstr ($errno)<br>";
}

echo "<h3>4. Common Issues & Solutions</h3>";
echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0;'>";
echo "<strong>🚨 If test email failed, check these:</strong><br>";
echo "1. <strong>Gmail Users:</strong> Use App Password, not regular password<br>";
echo "2. <strong>Firewall:</strong> Port $port must be open<br>";
echo "3. <strong>SSL/TLS:</strong> Make sure OpenSSL extension is enabled<br>";
echo "4. <strong>From Email:</strong> Must match authenticated email address<br>";
echo "5. <strong>2FA:</strong> Enable 2FA and use app passwords<br>";
echo "</div>";

echo "<h3>5. Test with Different Settings</h3>";
echo "<form method='post'>";
echo "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 10px 0;'>";
echo "<label>Test Email: <input type='email' name='test_email' placeholder='your-email@example.com' required></label>";
echo "<label>Host: <input type='text' name='test_host' value='" . htmlspecialchars($config['host'] ?? 'smtp.gmail.com') . "'></label>";
echo "<label>Port: <input type='number' name='test_port' value='" . htmlspecialchars($config['port'] ?? '587') . "'></label>";
echo "<label>Username: <input type='email' name='test_username' placeholder='your-email@gmail.com'></label>";
echo "<label>Password: <input type='password' name='test_password' placeholder='App password'></label>";
echo "<label>From Email: <input type='email' name='test_from' value='" . htmlspecialchars($config['from_email'] ?? '') . "'></label>";
echo "</div>";
echo "<label>Encryption: 
<select name='test_encryption'>
    <option value='tls'>TLS</option>
    <option value='ssl'>SSL</option>
    <option value=''>None</option>
</select></label><br><br>";
echo "<button type='submit' name='test_smtp' style='background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px;'>Test SMTP Connection</button>";
echo "</form>";

if ($_POST['test_smtp']) {
    echo "<h3>6. Test Results</h3>";
    
    try {
        $testConfig = [
            'email_enabled' => '1',
            'email_host' => $_POST['test_host'],
            'email_port' => $_POST['test_port'],
            'email_username' => $_POST['test_username'],
            'email_password' => $_POST['test_password'],
            'email_encryption' => $_POST['test_encryption'],
            'email_from' => $_POST['test_from'],
            'email_from_name' => 'PCIMS Test'
        ];
        
        $emailHelper->updateConfig($testConfig);
        
        $result = $emailHelper->testConfiguration($_POST['test_email']);
        
        if ($result) {
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px;'>";
            echo "✅ <strong>Test email sent successfully!</strong><br>";
            echo "Check your inbox at: " . htmlspecialchars($_POST['test_email']);
            echo "</div>";
        } else {
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
            echo "❌ <strong>Test email failed!</strong><br>";
            echo "Check the error details and try again.";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
        echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "File: " . $e->getFile() . "<br>";
        echo "Line: " . $e->getLine();
        echo "</div>";
    }
}

echo "<h3>7. Quick Fixes</h3>";
echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px;'>";
echo "<strong>🔧 Most Common Solutions:</strong><br>";
echo "<strong>Gmail:</strong> <a href='https://myaccount.google.com/apppasswords' target='_blank'>Generate App Password</a><br>";
echo "<strong>Outlook:</strong> Use your regular password<br>";
echo "<strong>Yahoo:</strong> Generate App Password in Account Security<br>";
echo "<strong>Work Email:</strong> Contact IT for SMTP credentials<br>";
echo "</div>";
?>
