<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Complete Login Fix Script - All User Roles</h2>";

// Test database connection
try {
    $conn = new PDO("mysql:host=localhost;dbname=pcims_db", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>✓ Database connection successful</p>";
} catch(PDOException $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
    exit();
}

// Define all user roles with their credentials
$users_to_create = [
    ['admin', 'admin123', 'System Administrator', 'admin@pcollection.com', 'admin'],
    ['manager', 'manager123', 'Manager', 'manager@pcollection.com', 'manager'],
    ['staff', 'staff123', 'Staff User', 'staff@pcollection.com', 'staff'],
    ['viewer', 'viewer123', 'Viewer User', 'viewer@pcollection.com', 'viewer']
];

echo "<h3>Creating/Updating All User Accounts:</h3>";

foreach ($users_to_create as $user_data) {
    [$username, $password, $full_name, $email, $role] = $user_data;
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_user) {
        echo "<p style='color: blue;'>ℹ User '$username' exists - updating password...</p>";
        
        // Update password
        $new_hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ?, full_name = ?, email = ?, role = ?, status = 'active' WHERE username = ?");
        if ($update->execute([$new_hash, $full_name, $email, $role, $username])) {
            echo "<p style='color: green;'>✓ Updated $username password and info</p>";
        } else {
            echo "<p style='color: red;'>✗ Failed to update $username</p>";
        }
    } else {
        echo "<p style='color: orange;'>➕ Creating user '$username'...</p>";
        
        // Create new user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $insert = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
        if ($insert->execute([$username, $hashed_password, $full_name, $email, $role])) {
            echo "<p style='color: green;'>✓ Created $username successfully</p>";
        } else {
            echo "<p style='color: red;'>✗ Failed to create $username</p>";
        }
    }
}

// Test all login credentials
echo "<h3>Testing All Login Credentials:</h3>";

$test_credentials = [
    'admin' => 'admin123',
    'manager' => 'manager123',
    'staff' => 'staff123',
    'viewer' => 'viewer123'
];

$stmt = $conn->query("SELECT user_id, username, full_name, email, role, status, password FROM users ORDER BY role, username");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f0f0f0;'>";
echo "<th>Username</th><th>Full Name</th><th>Role</th><th>Status</th><th>Password</th><th>Login Test</th>";
echo "</tr>";

foreach ($users as $user) {
    $test_password = $test_credentials[$user['username']] ?? 'unknown';
    $login_works = password_verify($test_password, $user['password'] ?? '') ? '✓' : '✗';
    $row_color = $login_works === '✓' ? '#d4edda' : '#f8d7da';
    
    echo "<tr style='background: $row_color;'>";
    echo "<td><strong>{$user['username']}</strong></td>";
    echo "<td>{$user['full_name']}</td>";
    echo "<td><span class='badge'>" . ucfirst($user['role']) . "</span></td>";
    echo "<td>" . ucfirst($user['status']) . "</td>";
    echo "<td><code>$test_password</code></td>";
    echo "<td style='text-align: center; font-size: 20px; color: " . ($login_works === '✓' ? 'green' : 'red') . ";'>$login_works</td>";
    echo "</tr>";
}
echo "</table>";

// Show role permissions
echo "<h3>Role Permissions Overview:</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f0f0f0;'>";
echo "<th>Role</th><th>Can Access</th><th>Cannot Access</th>";
echo "</tr>";

$role_permissions = [
    'admin' => ['Everything', 'Nothing - Full Access'],
    'manager' => ['Products, Inventory, Categories, Suppliers, Orders, Reports', 'User Management, System Settings'],
    'staff' => ['Products (View), Inventory, Stock Movements, Orders, Profile', 'Categories, Suppliers, User Management, Reports'],
    'viewer' => ['View All Modules', 'Edit, Delete, Create - Read Only']
];

foreach ($role_permissions as $role => $permissions) {
    echo "<tr>";
    echo "<td><strong>" . ucfirst($role) . "</strong></td>";
    echo "<td>{$permissions[0]}</td>";
    echo "<td>{$permissions[1]}</td>";
    echo "</tr>";
}
echo "</table>";

// Quick access links
echo "<h3>Quick Access Links:</h3>";
echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
echo "<h4>🔑 Login Credentials:</h4>";
echo "<ul>";
echo "<li><strong>Admin:</strong> username: <code>admin</code>, password: <code>admin123</code></li>";
echo "<li><strong>Manager:</strong> username: <code>manager</code>, password: <code>manager123</code></li>";
echo "<li><strong>Staff:</strong> username: <code>staff</code>, password: <code>staff123</code></li>";
echo "<li><strong>Viewer:</strong> username: <code>viewer</code>, password: <code>viewer123</code></li>";
echo "</ul>";
echo "</div>";

echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
echo "<h4>🔗 Quick Links:</h4>";
echo "<p><a href='login.php' style='background: #007bff; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px;'>🚪 Go to Login Page</a></p>";
echo "<p><a href='test_db.php' style='background: #28a745; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px;'>🔍 Run Database Test</a></p>";
echo "<p><a href='debug_login.php' style='background: #ffc107; color: black; padding: 8px 15px; text-decoration: none; border-radius: 4px;'>🐛 Debug Login Issues</a></p>";
echo "</div>";

// Additional troubleshooting
echo "<h3>Troubleshooting Tips:</h3>";
echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 5px; border-left: 4px solid #007bff;'>";
echo "<ol>";
echo "<li><strong>Clear Browser Cache:</strong> Press Ctrl+F5 or Cmd+Shift+R</li>";
echo "<li><strong>Check Session:</strong> Make sure cookies are enabled</li>";
echo "<li><strong>Database Connection:</strong> Ensure MySQL is running</li>";
echo "<li><strong>File Permissions:</strong> Check if PHP can write to session directory</li>";
echo "<li><strong>URL:</strong> Make sure you're accessing via http://localhost/pcims/</li>";
echo "</ol>";
echo "</div>";

echo "<p><em>Script completed at: " . date('Y-m-d H:i:s') . "</em></p>";
?>
