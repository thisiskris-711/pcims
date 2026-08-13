<?php
/**
 * Login Page
 */
session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: /antigravitytest/index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
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
            
            session_regenerate_id(true);
            header('Location: /antigravitytest/index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="InventoryPro - Login to your inventory management system">
    <title>Sign In - InventoryPro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/antigravitytest/assets/css/style.css">
</head>
<body>
    <div class="login-page">
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSNfTh_LuBglCEZy1s4gsEvMOL5sqDUhu2QSiXQZfjELMCHt7lnQfU7S1U&s=10" alt="Logo" style="height: 48px; object-fit: contain;">
                    <span class="logo-text">InventoryPro</span>
                </div>
                
                <h2 class="login-title">Welcome back</h2>
                <p class="login-subtitle">Sign in to manage your inventory</p>
                
                <?php if ($error): ?>
                    <div class="login-error">
                        <i data-lucide="alert-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="login-form" id="loginForm">
                    <div class="form-group">
                        <label class="form-label" for="username">Username</label>
                        <input type="text" class="form-control" id="username" name="username" 
                               placeholder="Enter your username" autocomplete="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Enter your password" autocomplete="current-password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary login-btn" id="loginBtn">
                        <i data-lucide="log-in" style="width:18px;height:18px;"></i>
                        Sign In
                    </button>
                </form>
                
                <div style="text-align:center; margin-top:24px; color:var(--text-muted); font-size:0.78rem;">
                    Default: <strong style="color:var(--text-secondary);">admin</strong> / <strong style="color:var(--text-secondary);">password</strong>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        lucide.createIcons();
        
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;"></span> Signing in...';
        });
    </script>
</body>
</html>
