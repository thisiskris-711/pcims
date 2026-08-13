<?php
/**
 * Authentication & Session Management
 */

/**
 * Attempt to log in a user
 */
function attemptLogin(string $username, string $password): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['avatar'] = $user['avatar'];
    $_SESSION['login_time'] = time();
    
    // Update last login
    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    // Regenerate session ID for security
    session_regenerate_id(true);
    
    return ['success' => true, 'message' => 'Login successful'];
}

/**
 * Log out the current user
 */
function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user data
 */
function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    
    return [
        'id'        => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role'      => $_SESSION['role'],
        'avatar'    => $_SESSION['avatar'] ?? null,
    ];
}

/**
 * Get current user ID
 */
function getCurrentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Require user to be logged in, redirect to login if not
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        if (isAjaxRequest()) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        redirect(APP_URL . '/login');
    }
}

/**
 * Require a specific role (or higher)
 */
function requireRole(string ...$roles): void {
    requireLogin();
    
    $userRole = $_SESSION['role'] ?? '';
    
    if (!in_array($userRole, $roles)) {
        if (isAjaxRequest()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            exit;
        }
        flashMessage('You do not have permission to access this page.', 'error');
        redirect(APP_URL . '');
    }
}

/**
 * Check if current user has a specific role
 */
function hasRole(string ...$roles): bool {
    return in_array($_SESSION['role'] ?? '', $roles);
}

/**
 * Check if request is AJAX
 */
function isAjaxRequest(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
