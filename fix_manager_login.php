<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Fix Manager Login Script</h2>";

// Test database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=pcims_db", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>✓ Database connection successful</p>";
} catch(PDOException $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
    exit();
}

// Check current manager user
$stmt = $conn->prepare("SELECT * FROM users WHERE username = 'manager'");
$stmt->execute();
$manager = $stmt->fetch(PDO::FETCH_ASSOC);

if ($manager) {
    echo "<p style='color: green;'>✓ Manager user found</p>";
    echo "<pre>";
    print_r($manager);
    echo "</pre>";
    
    // Test current password
    if (password_verify('manager123', $manager['password'])) {
        echo "<p style='color: green;'>✓ Password 'manager123' works</p>";
    } else {
        echo "<p style='color: red;'>✗ Password 'manager123' failed</p>";
        
        // Create new password hash for manager123
        $new_hash = password_hash('manager123', PASSWORD_DEFAULT);
        echo "<p>New hash for 'manager123': " . $new_hash . "</p>";
        
        // Update password
        $update = $conn->prepare("UPDATE users SET password = ? WHERE username = 'manager'");
        if ($update->execute([$new_hash])) {
            echo "<p style='color: green;'>✓ Manager password updated in database</p>";
        }
    }
} else {
    echo "<p style='color: red;'>✗ Manager user not found</p>";
    
    // Create manager user
    $password = password_hash('manager123', PASSWORD_DEFAULT);
    $insert = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
    if ($insert->execute(['manager', $password, 'Manager', 'manager@pcollection.com', 'manager'])) {
        echo "<p style='color: green;'>✓ Manager user created</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to create manager user</p>";
    }
}

// Create additional test users for different roles
$test_users = [
    ['staff', 'staff123', 'Staff User', 'staff@pcollection.com', 'staff'],
    ['viewer', 'viewer123', 'Viewer User', 'viewer@pcollection.com', 'viewer']
];

foreach ($test_users as $user_data) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$user_data[0]]);
    $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing_user) {
        $password = password_hash($user_data[1], PASSWORD_DEFAULT);
        $insert = $conn->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
        if ($insert->execute($user_data)) {
            echo "<p style='color: green;'>✓ Created {$user_data[2]} ({$user_data[0]})</p>";
        }
    } else {
        echo "<p style='color: blue;'>ℹ {$user_data[2]} ({$user_data[0]}) already exists</p>";
    }
}

// Show all users
echo "<h3>All Users in Database:</h3>";
$stmt = $conn->query("SELECT user_id, username, full_name, email, role, status, password FROM users ORDER BY role, username");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Username</th><th>Full Name</th><th>Role</th><th>Status</th><th>Login Test</th></tr>";
foreach ($users as $user) {
    $test_password = match($user['role']) {
        'admin' => 'admin123',
        'manager' => 'manager123',
        'staff' => 'staff123',
        'viewer' => 'viewer123',
        default => 'password123'
    };
    
    $login_works = password_verify($test_password, $user['password'] ?? '') ? '✓' : '✗';
    
    echo "<tr>";
    echo "<td>{$user['username']}</td>";
    echo "<td>{$user['full_name']}</td>";
    echo "<td>{$user['role']}</td>";
    echo "<td>{$user['status']}</td>";
    echo "<td style='color: " . ($login_works === '✓' ? 'green' : 'red') . ";'>{$login_works}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Login Credentials:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Role</th><th>Username</th><th>Password</th></tr>";
echo "<tr><td>Admin</td><td>admin</td><td>admin123</td></tr>";
echo "<tr><td>Manager</td><td>manager</td><td>manager123</td></tr>";
echo "<tr><td>Staff</td><td>staff</td><td>staff123</td></tr>";
echo "<tr><td>Viewer</td><td>viewer</td><td>viewer123</td></tr>";
echo "</table>";

echo "<p><a href='login.php'>Go to Login Page</a></p>";
echo "<p><a href='test_db.php'>Run Database Test</a></p>";
?>
