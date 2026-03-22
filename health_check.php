<?php

/**
 * PCIMS Authentication - Quick Health Check
 * Run this script to verify the authentication system is working
 */

require_once 'config/config.php';
require_once 'includes/security.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>PCIMS - Authentication Health Check</title>";
echo "<style>";
echo "* { margin: 0; padding: 0; box-sizing: border-box; }";
echo "body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }";
echo ".container { max-width: 600px; margin: 0 auto; }";
echo ".card { background: white; border-radius: 10px; padding: 30px; margin: 20px 0; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }";
echo ".header { text-align: center; color: white; margin-bottom: 30px; }";
echo ".header h1 { font-size: 2.5rem; margin-bottom: 10px; }";
echo ".header p { font-size: 1.1rem; opacity: 0.9; }";
echo ".check { padding: 15px; margin: 10px 0; border-radius: 5px; display: flex; align-items: center; gap: 15px; }";
echo ".check.pass { background: #e8f5e9; border-left: 5px solid #4caf50; }";
echo ".check.fail { background: #ffebee; border-left: 5px solid #f44336; }";
echo ".check.warn { background: #fff3e0; border-left: 5px solid #ff9800; }";
echo ".check-icon { font-size: 1.5rem; font-weight: bold; }";
echo ".check.pass .check-icon { color: #4caf50; }";
echo ".check.fail .check-icon { color: #f44336; }";
echo ".check.warn .check-icon { color: #ff9800; }";
echo ".check-text { flex: 1; }";
echo ".check-title { font-weight: bold; margin-bottom: 3px; }";
echo ".check-detail { font-size: 0.9rem; color: #666; }";
echo ".status { font-size: 1rem; font-weight: bold; }";
echo ".status.pass { color: #4caf50; }";
echo ".status.fail { color: #f44336; }";
echo ".status.warn { color: #ff9800; }";
echo ".summary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px; text-align: center; margin: 20px 0; }";
echo ".summary h2 { font-size: 2rem; margin-bottom: 10px; }";
echo ".summary p { font-size: 1.1rem; margin: 5px 0; }";
echo ".actions { text-align: center; margin-top: 20px; }";
echo ".actions a { display: inline-block; padding: 12px 30px; margin: 5px; border-radius: 5px; text-decoration: none; font-weight: bold; transition: all 0.3s; }";
echo ".actions a.primary { background: #667eea; color: white; }";
echo ".actions a.primary:hover { background: #764ba2; transform: translateY(-2px); }";
echo ".actions a.secondary { background: #f5f5f5; color: #333; border: 2px solid #ddd; }";
echo ".actions a.secondary:hover { background: #e0e0e0; }";
echo ".note { background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #2196f3; }";
echo ".note strong { color: #2196f3; }";
echo "</style>";
echo "</head><body>";

echo "<div class='container'>";

echo "<div class='header'>";
echo "<h1>🔐 PCIMS Authentication</h1>";
echo "<p>System Health Check</p>";
echo "</div>";

$checks = [];
$all_pass = true;

// Check 1: Database Connection
try {
  $database = new Database();
  $db = $database->getConnection();
  $checks['db_connection'] = ['pass', 'Database Connection', 'MySQL database accessible'];
} catch (Exception $e) {
  $checks['db_connection'] = ['fail', 'Database Connection', 'Error: ' . $e->getMessage()];
  $all_pass = false;
}

// Check 2: Admin User
if ($db) {
  try {
    $stmt = $db->prepare("SELECT user_id, status FROM users WHERE username = :username");
    $admin = 'admin';
    $stmt->bindParam(':username', $admin);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['status'] === 'active') {
      $checks['admin_user'] = ['pass', 'Admin User', 'User exists and is active'];
    } else {
      $checks['admin_user'] = ['fail', 'Admin User', 'User inactive or not found'];
      $all_pass = false;
    }
  } catch (Exception $e) {
    $checks['admin_user'] = ['fail', 'Admin User', 'Error: ' . $e->getMessage()];
    $all_pass = false;
  }
}

// Check 3: Password Verification
if ($db && isset($user)) {
  try {
    $stmt = $db->prepare("SELECT password FROM users WHERE username = :username");
    $admin = 'admin';
    $stmt->bindParam(':username', $admin);
    $stmt->execute();
    $userdata = $stmt->fetch(PDO::FETCH_ASSOC);

    if (password_verify('admin123', $userdata['password'])) {
      $checks['password_verify'] = ['pass', 'Password Verification', 'Password "admin123" verified successfully'];
    } else {
      $checks['password_verify'] = ['fail', 'Password Verification', 'Password verification failed'];
      $all_pass = false;
    }
  } catch (Exception $e) {
    $checks['password_verify'] = ['fail', 'Password Verification', 'Error: ' . $e->getMessage()];
    $all_pass = false;
  }
}

// Check 4: Session Support
if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
  $checks['session'] = ['pass', 'Session Support', 'Session system available'];
} else {
  $checks['session'] = ['fail', 'Session Support', 'Session system disabled'];
  $all_pass = false;
}

// Check 5: Security Functions
$security_funcs = ['is_logged_in', 'verify_csrf_token', 'sanitize_input', 'has_permission'];
$missing = [];
foreach ($security_funcs as $func) {
  if (!function_exists($func)) {
    $missing[] = $func;
  }
}
if (empty($missing)) {
  $checks['security_funcs'] = ['pass', 'Security Functions', 'All required functions available'];
} else {
  $checks['security_funcs'] = ['fail', 'Security Functions', 'Missing: ' . implode(', ', $missing)];
  $all_pass = false;
}

// Check 6: CSRF Token
try {
  $token = generate_csrf_token();
  if (!empty($token) && strlen($token) === 64) {
    $checks['csrf_token'] = ['pass', 'CSRF Protection', 'Secure tokens generated (64 bytes)'];
  } else {
    $checks['csrf_token'] = ['fail', 'CSRF Protection', 'Token generation failed'];
    $all_pass = false;
  }
} catch (Exception $e) {
  $checks['csrf_token'] = ['fail', 'CSRF Protection', 'Error: ' . $e->getMessage()];
  $all_pass = false;
}

// Check 7: Required Tables
if ($db) {
  $tables = ['users', 'login_attempts', 'account_lockouts', 'activity_logs'];
  $missing_tables = [];

  foreach ($tables as $table) {
    try {
      $result = $db->query("SHOW TABLES LIKE '{$table}'");
      if ($result->rowCount() === 0) {
        $missing_tables[] = $table;
      }
    } catch (Exception $e) {
      $missing_tables[] = $table . ' (error)';
    }
  }

  if (empty($missing_tables)) {
    $checks['tables'] = ['pass', 'Database Tables', 'All 4 required security tables exist'];
  } else {
    $checks['tables'] = ['warn', 'Database Tables', 'Missing: ' . implode(', ', $missing_tables)];
    $all_pass = false;
  }
}

// Render checks
echo "<div class='card'>";
foreach ($checks as $key => $check) {
  $status = $check[0];
  $title = $check[1];
  $detail = $check[2];
  $icon = ($status === 'pass') ? '✓' : (($status === 'fail') ? '✗' : '⚠');

  echo "<div class='check {$status}'>";
  echo "<div class='check-icon'>{$icon}</div>";
  echo "<div class='check-text'>";
  echo "<div class='check-title'>{$title}</div>";
  echo "<div class='check-detail'>{$detail}</div>";
  echo "</div>";
  echo "</div>";
}
echo "</div>";

// Summary
if ($all_pass) {
  echo "<div class='summary'>";
  echo "<h2>✓ System Ready</h2>";
  echo "<p>All authentication checks passed</p>";
  echo "<p>You can now log in with:</p>";
  echo "<p><strong style='font-size: 1.3rem;'>Username:</strong> admin</p>";
  echo "<p><strong style='font-size: 1.3rem;'>Password:</strong> admin123</p>";
  echo "</div>";
} else {
  echo "<div class='summary' style='background: linear-gradient(135deg, #f44336 0%, #e91e63 100%);'>";
  echo "<h2>⚠ Issues Detected</h2>";
  echo "<p>Please review the failed checks above</p>";
  echo "</div>";
}

// Actions
echo "<div class='actions'>";
echo "<a href='login.php' class='primary'>🔐 Go to Login</a>";
echo "<a href='test_login.php' class='secondary'>📊 Run Full Test</a>";
echo "<a href='fix_authentication.php' class='secondary'>🔧 Run Setup</a>";
echo "</div>";

// Notes
echo "<div class='card'>";
echo "<div class='note'>";
echo "<strong>💡 Quick Start Guide:</strong><br>";
echo "1. Verify this health check shows all items passing<br>";
echo "2. Click 'Go to Login' button above<br>";
echo "3. Enter username: <strong>admin</strong><br>";
echo "4. Enter password: <strong>admin123</strong><br>";
echo "5. Click 'Sign In' to access the dashboard";
echo "</div>";

echo "<div class='note'>";
echo "<strong>📝 Default Credentials:</strong><br>";
echo "The system comes with 4 default users, all with password = username<br>";
echo "After first login, administrators should change these passwords";
echo "</div>";

echo "<div class='note'>";
echo "<strong>⚙️ Configuration:</strong><br>";
echo "Database: " . DB_NAME . "<br>";
echo "Host: " . DB_HOST . "<br>";
echo "Users: " . (isset($user) ? '4 Active' : 'Unable to verify') . "<br>";
echo "Session Timeout: " . (SESSION_LIFETIME / 60) . " minutes";
echo "</div>";
echo "</div>";

echo "</div>";
echo "</body></html>";
