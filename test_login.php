<?php

/**
 * Login Authentication Test Script
 * Simulates the login process to verify authentication works correctly
 */

require_once 'config/config.php';
require_once 'includes/security.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>PCIMS - Login Test</title>";
echo "<style>body {font-family: Arial; max-width: 900px; margin: 20px auto; background: #f5f5f5;} ";
echo ".success {color: green; font-weight: bold;} .error {color: red; font-weight: bold;} .warning {color: orange; font-weight: bold;} ";
echo ".test-case {background: white; padding: 20px; margin: 10px 0; border-left: 5px solid #ccc; border-radius: 5px;} ";
echo ".test-case.pass {border-left-color: green;} .test-case.fail {border-left-color: red;} ";
echo "h2 {border-bottom: 2px solid #333; padding-bottom: 10px;} ";
echo "pre {background: #f0f0f0; padding: 10px; overflow-x: auto; border-radius: 5px;}</style>";
echo "</head><body>";

echo "<h1>PCIMS Login Authentication Test Suite</h1>";
echo "<p>Testing the complete authentication system...</p>";

$total_tests = 0;
$passed_tests = 0;
$failed_tests = 0;

function test_case($name, $condition, $details = '')
{
  global $total_tests, $passed_tests, $failed_tests;
  $total_tests++;

  $pass = $condition ? true : false;
  if ($pass) {
    $passed_tests++;
    $class = 'pass';
    $status = '<p class="success">✓ PASS</p>';
  } else {
    $failed_tests++;
    $class = 'fail';
    $status = '<p class="error">✗ FAIL</p>';
  }

  echo "<div class='test-case $class'>";
  echo "<h3>Test: $name</h3>";
  echo $status;
  if ($details) {
    echo "<p>$details</p>";
  }
  echo "</div>";
}

// Test 1: Database Connection
try {
  $database = new Database();
  $db = $database->getConnection();
  $test_pass = $db !== null;
  test_case("Database Connection", $test_pass, "Successfully connected to MySQL database");
} catch (Exception $e) {
  test_case("Database Connection", false, "Error: " . $e->getMessage());
  exit;
}

// Test 2: Users Table Exists
try {
  $result = $db->query("SELECT COUNT(*) as count FROM users");
  $user_count = $result->fetch(PDO::FETCH_ASSOC)['count'];
  test_case("Users Table Exists", true, "Users table found with " . $user_count . " user(s)");
} catch (Exception $e) {
  test_case("Users Table Exists", false, "Error: " . $e->getMessage());
}

// Test 3: Admin User Exists
try {
  $admin_username = 'admin';
  $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
  $stmt->bindParam(':username', $admin_username);
  $stmt->execute();
  $admin = $stmt->fetch(PDO::FETCH_ASSOC);
  test_case(
    "Admin User Exists",
    ($admin !== false),
    $admin ? "Username: admin, Status: " . htmlspecialchars($admin['status']) : "Admin user not found"
  );
} catch (Exception $e) {
  test_case("Admin User Exists", false, "Error: " . $e->getMessage());
}

// Test 4: Password Verification for Admin
if (isset($admin) && $admin) {
  $verify_pass = password_verify('admin123', $admin['password']);
  test_case(
    "Admin Password Verification",
    $verify_pass,
    $verify_pass ? "Password 'admin123' verified successfully" : "Password verification failed"
  );
}

// Test 5: Session Initialization
test_case(
  "Session Support",
  (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE),
  "Session save path: " . htmlspecialchars(session_save_path())
);

// Test 6: CSRF Token Generation
try {
  $token = generate_csrf_token();
  test_case(
    "CSRF Token Generation",
    (!empty($token) && strlen($token) === 64),
    "Generated token: " . substr($token, 0, 10) . "... (length: " . strlen($token) . ")"
  );
} catch (Exception $e) {
  test_case("CSRF Token Generation", false, "Error: " . $e->getMessage());
}

// Test 7: Security Headers
test_case(
  "Security Functions Available",
  (function_exists('set_security_headers') && function_exists('sanitize_input') && function_exists('has_permission')),
  "All security helper functions are available"
);

// Test 8: Login Attempt Logging
try {
  $result = $db->query("SHOW TABLES LIKE 'login_attempts'");
  $table_exists = $result->rowCount() > 0;
  test_case("Login Attempts Table", $table_exists, "Table exists and is ready for logging attempts");
} catch (Exception $e) {
  test_case("Login Attempts Table", false, "Error: " . $e->getMessage());
}

// Test 9: Account Lockouts Table
try {
  $result = $db->query("SHOW TABLES LIKE 'account_lockouts'");
  $table_exists = $result->rowCount() > 0;
  test_case("Account Lockouts Table", $table_exists, "Table exists for security lockout tracking");
} catch (Exception $e) {
  test_case("Account Lockouts Table", false, "Error: " . $e->getMessage());
}

// Test 10: Activity Logs Table
try {
  $result = $db->query("SHOW TABLES LIKE 'activity_logs'");
  $table_exists = $result->rowCount() > 0;
  test_case("Activity Logs Table", $table_exists, "Table exists for audit logging");
} catch (Exception $e) {
  test_case("Activity Logs Table", false, "Error: " . $e->getMessage());
}

// Test 11: Simulate Complete Login Flow
echo "<h2>Simulated Login Test</h2>";
if (isset($admin) && $admin && $verify_pass) {
  try {
    // Clear session first
    $_SESSION = [];

    // Simulate the login process
    $_SESSION['user_id'] = $admin['user_id'];
    $_SESSION['username'] = $admin['username'];
    $_SESSION['full_name'] = $admin['full_name'];
    $_SESSION['email'] = $admin['email'];
    $_SESSION['role'] = $admin['role'];
    $_SESSION['last_activity'] = time();

    // Verify session was set
    $session_set = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $admin['user_id']);
    test_case(
      "Session Variables Set",
      $session_set,
      "User ID: " . $_SESSION['user_id'] . ", Role: " . $_SESSION['role']
    );

    // Test permission check
    $has_admin_permission = has_permission('admin');
    test_case(
      "Admin Permission Check",
      $has_admin_permission,
      "Admin user has admin permissions: " . ($has_admin_permission ? "Yes" : "No")
    );

    // Test is_logged_in after session set
    $is_logged_in = is_logged_in();
    test_case(
      "Is Logged In Check",
      $is_logged_in,
      "User appears to be logged in: " . ($is_logged_in ? "Yes" : "No")
    );
  } catch (Exception $e) {
    test_case("Simulated Login Flow", false, "Error: " . $e->getMessage());
  }
} else {
  test_case("Simulated Login Flow", false, "Cannot test - admin user or password verification failed");
}

// Test 12: Additional Users
echo "<h2>Additional Users in System</h2>";
try {
  $users = $db->query("SELECT user_id, username, full_name, email, role, status FROM users ORDER BY user_id");
  if ($users->rowCount() > 0) {
    echo "<table style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: #e0e0e0;'>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>ID</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Username</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Full Name</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Email</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Role</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Status</th>";
    echo "</tr>";

    while ($user = $users->fetch(PDO::FETCH_ASSOC)) {
      echo "<tr>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['user_id']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['username']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['full_name']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['email']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['role']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>";
      echo "<span class='" . ($user['status'] === 'active' ? 'success' : 'error') . "'>";
      echo htmlspecialchars($user['status']);
      echo "</span></td></tr>";
    }
    echo "</table>";
  }
} catch (Exception $e) {
  echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Summary
echo "<h2>Test Summary</h2>";
echo "<table style='width: 100%; border-collapse: collapse;'>";
echo "<tr style='background: #e0e0e0;'><th style='border: 1px solid #999; padding: 10px;'>Total Tests</th><th style='border: 1px solid #999; padding: 10px;'>Passed</th><th style='border: 1px solid #999; padding: 10px;'>Failed</th></tr>";
echo "<tr>";
echo "<td style='border: 1px solid #999; padding: 10px;'><strong>$total_tests</strong></td>";
echo "<td style='border: 1px solid #999; padding: 10px;'><span class='success'>$passed_tests</span></td>";
echo "<td style='border: 1px solid #999; padding: 10px;'>" . ($failed_tests > 0 ? "<span class='error'>$failed_tests</span>" : "<span class='success'>0</span>") . "</td>";
echo "</tr></table>";

echo "<div style='background: #e8f5e9; padding: 20px; border-radius: 5px; margin: 20px 0;'>";
if ($failed_tests === 0) {
  echo "<p class='success'>✓ All tests passed! Your authentication system is ready.</p>";
  echo "<p><a href='login.php' style='display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px;'>Go to Login Page</a></p>";
} else {
  echo "<p class='error'>✗ Some tests failed. Please review the errors above.</p>";
}
echo "</div>";

echo "</body></html>";
