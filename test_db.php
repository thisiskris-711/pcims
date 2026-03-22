<?php
// Database test script
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>PCIMS Database Connection Test</h2>";

// Test database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=pcims_db", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>✓ Database connection successful</p>";
    
    // Check if users table exists
    $stmt = $conn->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Users table exists</p>";
    } else {
        echo "<p style='color: red;'>✗ Users table not found</p>";
    }
    
    // Check users in database
    $stmt = $conn->query("SELECT user_id, username, full_name, role, status FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Users in Database:</h3>";
    if (count($users) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . $user['user_id'] . "</td>";
            echo "<td>" . $user['username'] . "</td>";
            echo "<td>" . $user['full_name'] . "</td>";
            echo "<td>" . $user['role'] . "</td>";
            echo "<td>" . $user['status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>No users found in database</p>";
    }
    
    // Test password verification
    $stmt = $conn->query("SELECT username, password FROM users WHERE username = 'admin'");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        echo "<h3>Admin User Test:</h3>";
        echo "<p>Username: " . $admin['username'] . "</p>";
        echo "<p>Password Hash: " . substr($admin['password'], 0, 20) . "...</p>";
        
        // Test password verification
        if (password_verify('admin123', $admin['password'])) {
            echo "<p style='color: green;'>✓ Password verification successful</p>";
        } else {
            echo "<p style='color: red;'>✗ Password verification failed</p>";
        }
    }
    
    // Check other tables
    echo "<h3>Database Tables:</h3>";
    $stmt = $conn->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        echo "<p style='color: green;'>✓ " . $table . "</p>";
    }
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}

echo "<h3>PHP Configuration:</h3>";
echo "<p>PHP Version: " . PHP_VERSION . "</p>";
echo "<p>PDO MySQL: " . (extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled') . "</p>";
echo "<p>Session Support: " . (extension_loaded('session') ? 'Enabled' : 'Disabled') . "</p>";

// Test session
session_start();
$_SESSION['test'] = 'working';
echo "<p>Session: " . (isset($_SESSION['test']) ? 'Working' : 'Not Working') . "</p>";
session_unset();

echo "<p><a href='login.php'>Go to Login Page</a></p>";
?>
