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
    
    if (empty($user['email_verified_at'])) {
        return ['success' => false, 'message' => 'Please verify your email address before logging in.', 'unverified' => true, 'username' => $username];
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
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    // Check session timeout (15 minutes = 900 seconds)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 900)) {
        logout();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
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
 * Check if current user has a specific permission
 */
function hasPermission(string $permission): bool {
    if (!isLoggedIn()) return false;
    
    static $userPerms = null;
    static $userRole = null;
    
    if ($userPerms === null) {
        $db = getDB();
        $stmt = $db->prepare("SELECT role, permissions FROM users WHERE id = ? AND status = 'active'");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // User not found or inactive, log them out
            logout();
            return false;
        }
        
        $userRole = $user['role'];
        if ($user['permissions'] === null) {
            // Inherit from role presets
            $roleStmt = $db->prepare("SELECT permissions FROM roles WHERE name = ?");
            $roleStmt->execute([$userRole]);
            $roleData = $roleStmt->fetch();
            $userPerms = $roleData && $roleData['permissions'] ? json_decode($roleData['permissions'], true) : [];
        } else {
            // Use custom overrides
            $userPerms = json_decode($user['permissions'], true);
        }
        
        if (!is_array($userPerms)) {
            $userPerms = [];
        }
    }
    
    if ($userRole === ROLE_ADMIN) {
        return true;
    }
    
    return in_array($permission, $userPerms);
}

/**
 * Get a list of all available system permissions
 */
function getAllPermissions(): array {
    return [
        'manage_users' => 'Manage Users',
        'manage_roles' => 'Manage Roles',
        'manage_products' => 'Manage Products',
        'manage_inventory' => 'Manage Inventory',
        'manage_dealers' => 'Manage Dealers',
        'manage_suppliers' => 'Manage Suppliers',
        'view_sales' => 'View Sales',
        'create_sales' => 'Create Sales',
        'approve_sales' => 'Approve Sales',
        'view_reports' => 'View Reports',
        'manage_backups' => 'Manage Backups'
    ];
}

/**
 * Require a specific permission (or admin role)
 */
function requirePermission(string $permission): void {
    requireLogin();
    
    if (!hasPermission($permission)) {
        if (isAjaxRequest()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied: Missing permission ' . $permission]);
            exit;
        }
        flashMessage('You do not have permission to access this page.', 'error');
        redirect(APP_URL . '');
    }
}

/**
 * Check if request is AJAX
 */
function isAjaxRequest(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Check if current user has ANY of the specified permissions
 */
function hasAnyPermission(string ...$permissions): bool {
    foreach ($permissions as $p) {
        if (hasPermission($p)) return true;
    }
    return false;
}

/**
 * Require ANY of the specified permissions
 */
function requireAnyPermission(string ...$permissions): void {
    if (!hasAnyPermission(...$permissions)) {
        if (isAjaxRequest()) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied: Missing required permission']);
            exit;
        }
        flashMessage('You do not have permission to perform this action.', 'error');
        redirect(APP_URL . '/');
    }
}
