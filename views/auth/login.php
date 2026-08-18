<?php
/**
 * Login Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '');
    exit;
}

$error = '';
$unverified = false;
$unverified_username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'resend_verification') {
        enforceRateLimit('resend_verify', 3, 300);
        $username = trim($_POST['username'] ?? '');
        $db = getDB();
        $stmt = $db->prepare("SELECT email, email_verified_at FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && empty($user['email_verified_at'])) {
            $token = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE users SET email_verification_token = ? WHERE username = ?")->execute([$token, $username]);
            $verifyLink = 'http://' . $_SERVER['HTTP_HOST'] . APP_URL . "/verify-email";
            sendEmail($user['email'], 'Verify Your Email Address', "Your email verification PIN is: <strong>$token</strong><br><br>Please enter this PIN on the verification page: <a href='$verifyLink'>$verifyLink</a>");
            $error = "Verification email sent. Please check your inbox.";
        } else {
            $error = "Invalid request or email already verified.";
        }
    } else {
        // Strict rate limiting: Max 5 login attempts per 5 minutes (300 seconds)
        enforceRateLimit('login', 5, 300);

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            $result = attemptLogin($username, $password);
            
            if ($result['success']) {
                header('Location: ' . APP_URL . '');
                exit;
            } else {
                if (!empty($result['unverified'])) {
                    $db = getDB();
                    $stmt = $db->prepare("SELECT email FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $userEmail = $stmt->fetchColumn();
                    
                    if ($userEmail) {
                        $token = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                        $db->prepare("UPDATE users SET email_verification_token = ? WHERE username = ?")->execute([$token, $username]);
                        
                        $verifyLink = 'http://' . $_SERVER['HTTP_HOST'] . APP_URL . "/verify-email";
                        sendEmail($userEmail, 'Verify Your Email Address', "Your email verification PIN is: <strong>$token</strong><br><br>Please enter this PIN on the verification page: <a href='$verifyLink'>$verifyLink</a>");
                    }
                    
                    $_SESSION['unverified_username'] = $username;
                    header('Location: ' . APP_URL . '/verify-email');
                    exit;
                }
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars(APP_NAME) ?> - Login to your inventory management system">
    <title>Sign In - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
    <div class="login-page">
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSNfTh_LuBglCEZy1s4gsEvMOL5sqDUhu2QSiXQZfjELMCHt7lnQfU7S1U&s=10" alt="Logo" style="height: 48px; object-fit: contain;">
                    <span class="logo-text"><?= htmlspecialchars(APP_NAME) ?></span>
                </div>
                
                <h2 class="login-title">Welcome back</h2>
                <p class="login-subtitle">Sign in to manage your inventory</p>
                
                <?php if ($error): ?>
                    <div class="login-error" style="margin-bottom: 20px;">
                        <i data-lucide="alert-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="login-form" id="loginForm">
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" id="username" name="username" 
                               placeholder="Username" autocomplete="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                        <label class="form-label" for="username">Username</label>
                    </div>
                    
                    <div class="form-group form-floating" style="position: relative;">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Password" autocomplete="current-password" required style="padding-right: 40px;">
                        <label class="form-label" for="password">Password</label>
                        <button type="button" id="togglePassword" class="password-toggle" tabindex="-1" title="Toggle password visibility">
                            <i data-lucide="eye-off" style="width:20px;height:20px;"></i>
                        </button>
                    </div>
                    
                    <div style="text-align: right; margin-bottom: 20px; margin-top: -10px;">
                        <a href="<?= APP_URL ?>/forgot-password" style="font-size: 0.85rem; color: var(--primary-color); text-decoration: none;">Forgot Password?</a>
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
        
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Lucide replaces <i> with <svg>, so we reset the innerHTML to a fresh <i> tag
                if (type === 'password') {
                    this.innerHTML = '<i data-lucide="eye-off" style="width:20px;height:20px;"></i>';
                } else {
                    this.innerHTML = '<i data-lucide="eye" style="width:20px;height:20px;"></i>';
                }
                
                // Re-initialize lucide icons
                lucide.createIcons();
            });
        }
    </script>
</body>
</html>
