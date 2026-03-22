<?php
require_once 'config/config.php';
require_once 'config/email_config.php';

echo "<h2>🔧 Simple Email Test for Password Reset</h2>";

echo "<h3>Current Configuration</h3>";
echo "<strong>SMTP Host:</strong> " . SMTP_HOST . "<br>";
echo "<strong>SMTP Port:</strong> " . SMTP_PORT . "<br>";
echo "<strong>SMTP Username:</strong> " . SMTP_USERNAME . "<br>";
echo "<strong>From Address:</strong> " . EMAIL_FROM_ADDRESS . "<br>";
echo "<strong>Encryption:</strong> " . SMTP_ENCRYPTION . "<br>";

echo "<h3>Test Email to kriseanestares@gmail.com</h3>";

try {
    $test_email = 'kriseanestares@gmail.com';
    $test_subject = 'PCIMS Password Reset Test';
    $test_body = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Email Test</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 30px; border-radius: 10px; }
            .header { background: #e74a3b; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🔧 Email Test</h1>
                <p>PCIMS Password Reset System</p>
            </div>
            <div style="padding: 30px;">
                <p>Hello,</p>
                <p>This is a test email to verify that the password reset functionality is working correctly.</p>
                <p><strong>Test Details:</strong></p>
                <ul>
                    <li>SMTP Server: ' . SMTP_HOST . '</li>
                    <li>Port: ' . SMTP_PORT . '</li>
                    <li>Encryption: ' . SMTP_ENCRYPTION . '</li>
                    <li>Time: ' . date('Y-m-d H:i:s') . '</li>
                </ul>
                <p>If you receive this email, the password reset system should work properly.</p>
                <hr>
                <p style="color: #666; font-size: 12px;">
                    This is an automated test message from PCIMS.<br>
                    © 2024 PCIMS. All rights reserved.
                </p>
            </div>
        </div>
    </body>
    </html>';

    $result = send_email($test_email, $test_subject, $test_body);
    
    if ($result) {
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "✅ <strong>Test email sent successfully!</strong><br>";
        echo "Check the inbox at: " . htmlspecialchars($test_email) . "<br>";
        echo "Also check spam/junk folder if not in inbox.";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "❌ <strong>Test email failed!</strong><br>";
        echo "Check the error logs for detailed information.";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "❌ <strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine();
    echo "</div>";
}

echo "<h3>Next Steps</h3>";
echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
echo "<strong>🔧 Troubleshooting:</strong><br>";
echo "1. If test fails, check Gmail App Password setup<br>";
echo "2. Ensure 2FA is enabled on Gmail account<br>";
echo "3. Verify app password: qkwl nrqg ycvh xwjl<br>";
echo "4. Check if Gmail is blocking less secure apps<br>";
echo "5. Try sending to a different email address first<br>";
echo "</div>";

echo "<p><a href='forgot_password.php'>🔗 Test Password Reset Form</a></p>";
echo "<p><a href='login.php'>🔐 Back to Login</a></p>";
?>
