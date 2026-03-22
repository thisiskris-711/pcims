<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Login Debug Script</h2>";

// Test database connection first
try {
    $conn = new PDO("mysql:host=localhost;dbname=pcims_db", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>✓ Database connection OK</p>";
} catch(PDOException $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
    exit();
}

// Check if admin user exists
$stmt = $conn->prepare("SELECT * FROM users WHERE username = 'admin'");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "<p style='color: green;'>✓ Admin user found</p>";
    echo "<pre>";
    print_r($user);
    echo "</pre>";
    
    // Test password
    if (password_verify('admin123', $user['password'])) {
        echo "<p style='color: green;'>✓ Password 'admin123' works</p>";
    } else {
        echo "<p style='color: red;'>✗ Password 'admin123' failed</p>";
        
        // Try creating new password hash
        $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
        echo "<p>New hash for 'admin123': " . $new_hash . "</p>";
        
        // Update password
        $update = $conn->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
        if ($update->execute([$new_hash])) {
            echo "<p style='color: green;'>✓ Password updated in database</p>";
        }
    }
} else {
    echo "<p style='color: red;'>✗ Admin user not found</p>";
    
    // Create admin user
    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $insert = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
    if ($insert->execute(['admin', $password, 'System Administrator', 'admin@pcollection.com', 'admin'])) {
        echo "<p style='color: green;'>✓ Admin user created</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to create admin user</p>";
    }
}

// Test session
session_start();
echo "<p>Session ID: " . session_id() . "</p>";
echo "<p>Session data: " . (empty($_SESSION) ? 'Empty' : 'Has data') . "</p>";

echo "<p><a href='login.php'>Try Login Now</a></p>";
echo "<p><a href='test_db.php'>Run Database Test</a></p>";
?>
