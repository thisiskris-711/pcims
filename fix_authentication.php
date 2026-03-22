<?php

/**
 * Authentication System Fix Script
 * Fixes login issues by ensuring proper database setup and user credentials
 */

require_once 'config/config.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>PCIMS - Authentication Fix</title>";
echo "<style>body {font-family: Arial; max-width: 900px; margin: 20px auto; background: #f5f5f5;} ";
echo ".success {color: green;} .error {color: red;} .warning {color: orange;} ";
echo ".section {background: white; padding: 20px; margin: 10px 0; border-radius: 5px;} ";
echo "h2 {border-bottom: 2px solid #333; padding-bottom: 10px;} ";
echo "pre {background: #f0f0f0; padding: 10px; overflow-x: auto;}</style>";
echo "</head><body>";

echo "<h1>PCIMS Authentication System Fix</h1>";
echo "<p>This script will ensure your PCIMS authentication system is properly configured.</p>";

try {
  $database = new Database();
  $db = $database->getConnection();

  echo "<div class='section'>";
  echo "<h2>Step 1: Database Connection</h2>";
  echo "<p class='success'>✓ Database connection successful</p>";
  echo "</div>";

  // Step 2: Ensure all required tables exist
  echo "<div class='section'>";
  echo "<h2>Step 2: Verify Required Tables</h2>";

  $required_tables = [
    'users',
    'activity_logs',
    'notifications',
    'login_attempts',
    'account_lockouts'
  ];

  foreach ($required_tables as $table) {
    try {
      $result = $db->query("SHOW TABLES LIKE '{$table}'");
      if ($result->rowCount() > 0) {
        echo "<p class='success'>✓ Table '{$table}' exists</p>";
      } else {
        echo "<p class='warning'>⚠ Table '{$table}' not found - creating it...</p>";
        if ($table === 'activity_logs') {
          $db->exec("CREATE TABLE activity_logs (
                        log_id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        action VARCHAR(100) NOT NULL,
                        details TEXT,
                        ip_address VARCHAR(45),
                        user_agent TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                        INDEX idx_activity_user (user_id),
                        INDEX idx_activity_date (created_at)
                    )");
          echo "<p class='success'>✓ Created activity_logs table</p>";
        } elseif ($table === 'login_attempts') {
          $db->exec("CREATE TABLE login_attempts (
                        attempt_id INT AUTO_INCREMENT PRIMARY KEY,
                        username VARCHAR(50),
                        ip_address VARCHAR(45),
                        user_agent TEXT,
                        success BOOLEAN DEFAULT FALSE,
                        failure_reason VARCHAR(255),
                        attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_username (username),
                        INDEX idx_ip (ip_address),
                        INDEX idx_time (attempt_time)
                    )");
          echo "<p class='success'>✓ Created login_attempts table</p>";
        } elseif ($table === 'account_lockouts') {
          $db->exec("CREATE TABLE account_lockouts (
                        lockout_id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT,
                        username VARCHAR(50),
                        ip_address VARCHAR(45),
                        unlock_time TIMESTAMP,
                        failed_attempts INT DEFAULT 0,
                        is_active BOOLEAN DEFAULT TRUE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                        INDEX idx_username (username),
                        INDEX idx_ip (ip_address),
                        INDEX idx_lockout_time (lockout_time),
                        INDEX idx_is_active (is_active)
                    )");
          echo "<p class='success'>✓ Created account_lockouts table</p>";
        } elseif ($table === 'notifications') {
          $db->exec("CREATE TABLE notifications (
                        notification_id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT,
                        title VARCHAR(255) NOT NULL,
                        message TEXT NOT NULL,
                        type ENUM('info', 'success', 'warning', 'danger') DEFAULT 'info',
                        related_to VARCHAR(50),
                        related_id INT,
                        is_read BOOLEAN DEFAULT FALSE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
                        INDEX idx_user_read (user_id, is_read),
                        INDEX idx_created (created_at)
                    )");
          echo "<p class='success'>✓ Created notifications table</p>";
        }
      }
    } catch (PDOException $e) {
      echo "<p class='error'>✗ Error checking table '{$table}': " . $e->getMessage() . "</p>";
    }
  }

  echo "</div>";

  // Step 3: Check and fix admin user
  echo "<div class='section'>";
  echo "<h2>Step 3: Setup Admin User Account</h2>";

  $admin_username = 'admin';
  $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
  $stmt->bindParam(':username', $admin_username);
  $stmt->execute();
  $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$admin_user) {
    echo "<p class='warning'>Admin user not found - creating new admin account...</p>";
    $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
    $full_name = 'System Administrator';
    $email = 'admin@pcollection.com';
    $role = 'admin';
    $status = 'active';

    $insert = $db->prepare("INSERT INTO users (username, password, full_name, email, role, status) 
                               VALUES (:username, :password, :full_name, :email, :role, :status)");
    $insert->bindParam(':username', $admin_username);
    $insert->bindParam(':password', $password_hash);
    $insert->bindParam(':full_name', $full_name);
    $insert->bindParam(':email', $email);
    $insert->bindParam(':role', $role);
    $insert->bindParam(':status', $status);

    if ($insert->execute()) {
      echo "<p class='success'>✓ Admin user created successfully</p>";
      echo "<p><strong>Username:</strong> admin</p>";
      echo "<p><strong>Password:</strong> admin123</p>";
      echo "<p><strong>Role:</strong> admin</p>";
    } else {
      echo "<p class='error'>✗ Failed to create admin user</p>";
    }
  } else {
    echo "<p>Admin user exists:</p>";
    echo "<ul>";
    echo "<li><strong>User ID:</strong> " . htmlspecialchars($admin_user['user_id']) . "</li>";
    echo "<li><strong>Full Name:</strong> " . htmlspecialchars($admin_user['full_name']) . "</li>";
    echo "<li><strong>Email:</strong> " . htmlspecialchars($admin_user['email']) . "</li>";
    echo "<li><strong>Role:</strong> " . htmlspecialchars($admin_user['role']) . "</li>";
    echo "<li><strong>Status:</strong> " . htmlspecialchars($admin_user['status']) . "</li>";
    echo "</ul>";

    // Check if password verification works
    if (password_verify('admin123', $admin_user['password'])) {
      echo "<p class='success'>✓ Password verification for 'admin123' works correctly</p>";
    } else {
      echo "<p class='warning'>⚠ Password verification failed - updating password hash...</p>";

      $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
      $update = $db->prepare("UPDATE users SET password = :password WHERE username = :username");
      $update->bindParam(':password', $password_hash);
      $update->bindParam(':username', $admin_username);

      if ($update->execute()) {
        echo "<p class='success'>✓ Password hash updated successfully</p>";
        echo "<p>Password 'admin123' will now work for login</p>";
      } else {
        echo "<p class='error'>✗ Failed to update password hash</p>";
      }
    }

    // Ensure admin account is active
    if ($admin_user['status'] !== 'active') {
      echo "<p class='warning'>⚠ Admin account is not active - activating...</p>";

      $activate_status = 'active';
      $activate = $db->prepare("UPDATE users SET status = :status WHERE username = :username");
      $activate->bindParam(':status', $activate_status);
      $activate->bindParam(':username', $admin_username);

      if ($activate->execute()) {
        echo "<p class='success'>✓ Admin account activated</p>";
      }
    }
  }

  echo "</div>";

  // Step 4: Verify other default users
  echo "<div class='section'>";
  echo "<h2>Step 4: Existing User Accounts</h2>";

  $users = $db->query("SELECT user_id, username, full_name, role, status FROM users ORDER BY username");
  if ($users->rowCount() > 0) {
    echo "<table style='width: 100%; border-collapse: collapse;'>";
    echo "<tr style='background: #e0e0e0;'><th style='border: 1px solid #999; padding: 10px; text-align: left;'>ID</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Username</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Full Name</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Role</th>";
    echo "<th style='border: 1px solid #999; padding: 10px; text-align: left;'>Status</th></tr>";

    while ($user = $users->fetch(PDO::FETCH_ASSOC)) {
      echo "<tr>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['user_id']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['username']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['full_name']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>" . htmlspecialchars($user['role']) . "</td>";
      echo "<td style='border: 1px solid #999; padding: 10px;'>";
      echo "<span class='" . ($user['status'] === 'active' ? 'success' : 'error') . "'>";
      echo htmlspecialchars($user['status']);
      echo "</span></td></tr>";
    }
    echo "</table>";
  }

  echo "</div>";

  // Step 5: Test session
  echo "<div class='section'>";
  echo "<h2>Step 5: Session Configuration</h2>";

  if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
    echo "<p class='success'>✓ Session support is available</p>";
    echo "<p>Session save path: " . htmlspecialchars(session_save_path()) . "</p>";
    echo "<p>Session timeout (SESSION_LIFETIME): " . SESSION_LIFETIME . " seconds</p>";
  } else {
    echo "<p class='error'>✗ Session support is disabled</p>";
  }

  echo "</div>";

  // Step 6: Summary
  echo "<div class='section'>";
  echo "<h2>Step 6: Next Steps</h2>";
  echo "<p><strong>Your PCIMS authentication system has been configured!</strong></p>";
  echo "<p>You can now login with:</p>";
  echo "<ul>";
  echo "<li><strong>Username:</strong> admin</li>";
  echo "<li><strong>Password:</strong> admin123</li>";
  echo "</ul>";
  echo "<p><a href='login.php' style='display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px;'>Go to Login</a></p>";
  echo "</div>";
} catch (PDOException $exception) {
  echo "<div class='section'>";
  echo "<p class='error'>✗ Database Error: " . $exception->getMessage() . "</p>";
  echo "</div>";
}

echo "</body></html>";
