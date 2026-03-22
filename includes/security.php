<?php

/**
 * Security Helper Functions for PCIMS
 * Handles rate limiting, account lockouts, password policies, and security headers
 */

// Security Configuration
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW', 900); // 15 minutes in seconds
define('ACCOUNT_LOCKOUT_DURATION', 1800); // 30 minutes in seconds
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);

/**
 * Check if IP or username is rate limited for login attempts
 */
function is_rate_limited($username, $ip_address) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if login_attempts table exists
        $table_check = $db->query("SHOW TABLES LIKE 'login_attempts'");
        if ($table_check->rowCount() === 0) {
            return false; // Table doesn't exist yet, don't limit
        }
        
        // Check recent failed attempts from this IP
        $query = "SELECT COUNT(*) as attempt_count FROM login_attempts 
                  WHERE ip_address = :ip_address AND success = FALSE 
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL :window SECOND)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ip_address', $ip_address);
        $window = LOGIN_ATTEMPT_WINDOW;
        $stmt->bindParam(':window', $window);
        $stmt->execute();
        
        $ip_attempts = $stmt->fetch(PDO::FETCH_ASSOC)['attempt_count'];
        
        // Check recent failed attempts for this username
        $query = "SELECT COUNT(*) as attempt_count FROM login_attempts 
                  WHERE username = :username AND success = FALSE 
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL :window SECOND)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $window = LOGIN_ATTEMPT_WINDOW;
        $stmt->bindParam(':window', $window);
        $stmt->execute();
        
        $username_attempts = $stmt->fetch(PDO::FETCH_ASSOC)['attempt_count'];
        
        return $ip_attempts >= MAX_LOGIN_ATTEMPTS || $username_attempts >= MAX_LOGIN_ATTEMPTS;
        
    } catch (PDOException $exception) {
        error_log("Rate Limiting Check Error: " . $exception->getMessage());
        return false; // Fail open - allow login if check fails
    }
}

/**
 * Check if account is locked out
 */
function is_account_locked($username, $ip_address) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if account_lockouts table exists
        $table_check = $db->query("SHOW TABLES LIKE 'account_lockouts'");
        if ($table_check->rowCount() === 0) {
            return false; // Table doesn't exist yet, don't lock out
        }
        
        $query = "SELECT lockout_id, unlock_time FROM account_lockouts 
                  WHERE (username = :username OR ip_address = :ip_address) 
                  AND is_active = TRUE 
                  AND (unlock_time IS NULL OR unlock_time > NOW())";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':ip_address', $ip_address);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
        
    } catch (PDOException $exception) {
        error_log("Account Lockout Check Error: " . $exception->getMessage());
        return false; // Fail open
    }
}

/**
 * Record login attempt
 */
function record_login_attempt($username, $ip_address, $success, $failure_reason = null) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if login_attempts table exists
        $table_check = $db->query("SHOW TABLES LIKE 'login_attempts'");
        if ($table_check->rowCount() === 0) {
            return; // Table doesn't exist yet, skip recording
        }
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Record the attempt
        $query = "INSERT INTO login_attempts (username, ip_address, user_agent, success, failure_reason) 
                  VALUES (:username, :ip_address, :user_agent, :success, :failure_reason)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':ip_address', $ip_address);
        $stmt->bindParam(':user_agent', $user_agent);
        $stmt->bindParam(':success', $success, PDO::PARAM_BOOL);
        $stmt->bindParam(':failure_reason', $failure_reason);
        $stmt->execute();
        
        // If failed attempt, check if we need to lock out
        if (!$success) {
            handle_failed_login($username, $ip_address);
        } else {
            // Clear any existing lockouts on successful login
            clear_account_lockouts($username, $ip_address);
        }
        
    } catch (PDOException $exception) {
        error_log("Login Attempt Recording Error: " . $exception->getMessage());
    }
}

/**
 * Handle failed login and potentially lock out account
 */
function handle_failed_login($username, $ip_address) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if login_attempts table exists
        $table_check = $db->query("SHOW TABLES LIKE 'login_attempts'");
        if ($table_check->rowCount() === 0) {
            return; // Table doesn't exist yet, skip
        }
        
        // Count recent failed attempts
        $query = "SELECT COUNT(*) as attempt_count FROM login_attempts 
                  WHERE (username = :username OR ip_address = :ip_address) 
                  AND success = FALSE 
                  AND attempt_time > DATE_SUB(NOW(), INTERVAL :window SECOND)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':ip_address', $ip_address);
        $window = LOGIN_ATTEMPT_WINDOW;
        $stmt->bindParam(':window', $window);
        $stmt->execute();
        
        $attempt_count = $stmt->fetch(PDO::FETCH_ASSOC)['attempt_count'];
        
        // Lock out if threshold reached
        if ($attempt_count >= MAX_LOGIN_ATTEMPTS) {
            lockout_account($username, $ip_address, $attempt_count);
        }
        
    } catch (PDOException $exception) {
        error_log("Failed Login Handling Error: " . $exception->getMessage());
    }
}

/**
 * Lock out an account
 */
function lockout_account($username, $ip_address, $failed_attempts) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if account_lockouts table exists
        $table_check = $db->query("SHOW TABLES LIKE 'account_lockouts'");
        if ($table_check->rowCount() === 0) {
            return; // Table doesn't exist yet, skip
        }
        
        // Get user_id if username exists
        $user_id = null;
        $query = "SELECT user_id FROM users WHERE username = :username";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $user_id = $user['user_id'];
        }
        
        // Calculate unlock time
        $unlock_time = date('Y-m-d H:i:s', time() + ACCOUNT_LOCKOUT_DURATION);
        
        // Insert lockout record
        $query = "INSERT INTO account_lockouts (user_id, username, ip_address, unlock_time, failed_attempts) 
                  VALUES (:user_id, :username, :ip_address, :unlock_time, :failed_attempts)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':ip_address', $ip_address);
        $stmt->bindParam(':unlock_time', $unlock_time);
        $stmt->bindParam(':failed_attempts', $failed_attempts);
        $stmt->execute();
        
        // Log the lockout
        error_log("Account locked: Username: $username, IP: $ip_address, Attempts: $failed_attempts");
        
    } catch (PDOException $exception) {
        error_log("Account Lockout Error: " . $exception->getMessage());
    }
}

/**
 * Clear account lockouts on successful login
 */
function clear_account_lockouts($username, $ip_address) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if account_lockouts table exists
        $table_check = $db->query("SHOW TABLES LIKE 'account_lockouts'");
        if ($table_check->rowCount() === 0) {
            return; // Table doesn't exist yet, skip
        }
        
        $query = "UPDATE account_lockouts 
                  SET is_active = FALSE 
                  WHERE (username = :username OR ip_address = :ip_address) AND is_active = TRUE";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':ip_address', $ip_address);
        $stmt->execute();
        
    } catch (PDOException $exception) {
        error_log("Clear Lockouts Error: " . $exception->getMessage());
    }
}

/**
 * Validate password against security policy
 */
function validate_password_strength($password) {
    $errors = [];
    
    // Minimum length
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters long";
    }
    
    // Uppercase requirement
    if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    // Lowercase requirement
    if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    // Number requirement
    if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    // Special character requirement
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

/**
 * Set security HTTP headers
 */
function set_security_headers(array $options = []) {
    $allow_camera = !empty($options['camera']);

    // Prevent clickjacking
    header('X-Frame-Options: DENY');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // XSS Protection
    header('X-XSS-Protection: "1; mode=block"');
    
    // Content Security Policy
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;");
    
    // Strict Transport Security (HTTPS only)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Permissions Policy
    header('Permissions-Policy: geolocation=(), microphone=(), camera=' . ($allow_camera ? '(self)' : '()'));
}

/**
 * Get remaining lockout time in minutes
 */
function get_lockout_remaining_time($username, $ip_address) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if account_lockouts table exists
        $table_check = $db->query("SHOW TABLES LIKE 'account_lockouts'");
        if ($table_check->rowCount() === 0) {
            return 0; // Table doesn't exist yet, no lockout
        }
        
        $query = "SELECT unlock_time FROM account_lockouts 
                  WHERE (username = :username OR ip_address = :ip_address) 
                  AND is_active = TRUE 
                  AND unlock_time > NOW() 
                  ORDER BY unlock_time DESC LIMIT 1";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':ip_address', $ip_address);
        $stmt->execute();
        
        if ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unlock_time = new DateTime($result['unlock_time']);
            $now = new DateTime();
            $interval = $now->diff($unlock_time);
            return $interval->i + ($interval->h * 60) + ($interval->d * 24 * 60);
        }
        
        return 0;
        
    } catch (PDOException $exception) {
        error_log("Lockout Time Check Error: " . $exception->getMessage());
        return 0;
    }
}

/**
 * Clean up old login attempts and expired lockouts
 */
function cleanup_security_logs() {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Delete login attempts older than 30 days
        $query = "DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        // Deactivate expired lockouts
        $query = "UPDATE account_lockouts SET is_active = FALSE 
                  WHERE is_active = TRUE AND unlock_time < NOW()";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
    } catch (PDOException $exception) {
        error_log("Security Cleanup Error: " . $exception->getMessage());
    }
}

/**
 * Generate a strong random password that meets security requirements
 */
function generate_strong_random_password($length = 12) {
    // Character sets
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $special = '!@#$%^&*(),.?":{}|<>-_=+';
    
    // Ensure at least one character from each required set
    $password = '';
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $special[random_int(0, strlen($special) - 1)];
    
    // Fill the rest with random characters from all sets
    $all_chars = $uppercase . $lowercase . $numbers . $special;
    $remaining_length = $length - 4;
    
    for ($i = 0; $i < $remaining_length; $i++) {
        $password .= $all_chars[random_int(0, strlen($all_chars) - 1)];
    }
    
    // Shuffle the password to randomize character positions
    return str_shuffle($password);
}
