<?php
require_once 'config/config.php';
require_once 'config/email_config.php';

echo "<h2>🔐 Complete Password Reset Test</h2>";

// Test 1: Check if user with email exists
echo "<h3>Step 1: Check User Database</h3>";
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT user_id, username, full_name, email FROM users WHERE email = :email AND status = 'active'";
    $stmt = $db->prepare($query);
    $test_email = 'kriseanestares@gmail.com';
    $stmt->bindParam(':email', $test_email);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✅ User found: " . htmlspecialchars($user['full_name']) . " (" . htmlspecialchars($user['username']) . ")<br>";
        echo "   Email: " . htmlspecialchars($user['email']) . "<br>";
        
        // Test 2: Generate reset token
        echo "<h3>Step 2: Generate Reset Token</h3>";
        $reset_token = bin2hex(random_bytes(32));
        $expiry_time = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        echo "✅ Token generated: " . substr($reset_token, 0, 12) . "...<br>";
        echo "✅ Expires: $expiry_time<br>";
        
        // Store token in database
        $token_query = "UPDATE users SET reset_token = :reset_token, reset_expiry = :reset_expiry WHERE user_id = :user_id";
        $token_stmt = $db->prepare($token_query);
        $token_stmt->bindParam(':reset_token', $reset_token);
        $token_stmt->bindParam(':reset_expiry', $expiry_time);
        $token_stmt->bindParam(':user_id', $user['user_id']);
        
        if ($token_stmt->execute()) {
            echo "✅ Token stored in database<br>";
            
            // Test 3: Send password reset email
            echo "<h3>Step 3: Send Password Reset Email</h3>";
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $reset_token;
            
            echo "📧 Sending email to: " . htmlspecialchars($test_email) . "<br>";
            echo "🔗 Reset link: <a href='$reset_link' target='_blank' style='color: #e74a3b;'>Click here to test reset</a><br>";
            
            $email_sent = send_password_reset_email($test_email, $user['full_name'], $reset_link);
            
            if ($email_sent) {
                echo "✅ Password reset email sent successfully!<br>";
                echo "📬 Check inbox (and spam folder) for: " . htmlspecialchars($test_email) . "<br>";
                
                // Test 4: Verify token in database
                echo "<h3>Step 4: Verify Token Storage</h3>";
                $verify_query = "SELECT reset_token, reset_expiry FROM users WHERE user_id = :user_id";
                $verify_stmt = $db->prepare($verify_query);
                $verify_stmt->bindParam(':user_id', $user['user_id']);
                $verify_stmt->execute();
                
                $token_data = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($token_data && $token_data['reset_token'] === $reset_token) {
                    echo "✅ Token correctly stored in database<br>";
                    echo "✅ Token expiry: " . $token_data['reset_expiry'] . "<br>";
                    
                    echo "<h3>🎉 Password Reset System Test Complete!</h3>";
                    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                    echo "<strong>✅ All tests passed!</strong><br>";
                    echo "The password reset functionality should now work correctly.<br><br>";
                    echo "<strong>Next steps:</strong><br>";
                    echo "1. Check email at " . htmlspecialchars($test_email) . "<br>";
                    echo "2. Click the reset link in the email<br>";
                    echo "3. Set a new password<br>";
                    echo "4. Try logging in with the new password";
                    echo "</div>";
                    
                } else {
                    echo "❌ Token verification failed<br>";
                }
                
            } else {
                echo "❌ Failed to send password reset email<br>";
                echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                echo "<strong>Troubleshooting:</strong><br>";
                echo "1. Check Gmail App Password setup<br>";
                echo "2. Verify 2FA is enabled on Gmail<br>";
                echo "3. Check if ports are blocked by firewall<br>";
                echo "4. Review error logs for detailed messages";
                echo "</div>";
            }
            
        } else {
            echo "❌ Failed to store token in database<br>";
        }
        
    } else {
        echo "❌ No user found with email: " . htmlspecialchars($test_email) . "<br>";
        echo "   You may need to create a user account first.";
    }
    
} catch (Exception $e) {
    echo "❌ Database error: " . htmlspecialchars($e->getMessage()) . "<br>";
}

echo "<hr>";
echo "<h3>🔧 Manual Test Links</h3>";
echo "<p><a href='forgot_password.php'>🔗 Go to Forgot Password Form</a></p>";
echo "<p><a href='simple_email_test.php'>📧 Test Basic Email Function</a></p>";
echo "<p><a href='login.php'>🔐 Back to Login</a></p>";
?>
