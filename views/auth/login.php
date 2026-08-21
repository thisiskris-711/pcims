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

$limiter = new RateLimiter(getDB());
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateStatus = $limiter->getStatus($ip, 'login', 5, 300);
$lockout_remaining = 0;
if (!$rateStatus['allowed']) {
    $lockout_remaining = $rateStatus['remaining'];
}

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
            $db->prepare("UPDATE users SET email_verification_token = ?, email_verification_token_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE), email_verification_attempts = 0 WHERE username = ?")->execute([$token, $username]);
            sendEmail($user['email'], 'Verify Your Email Address', "Your email verification PIN is: <strong>$token</strong>");
            $error = "Verification email sent. Please check your inbox.";
        } else {
            $error = "Invalid request or email already verified.";
        }
    } else {
        // Strict rate limiting: Max 5 login attempts per 5 minutes (300 seconds)
        if ($lockout_remaining > 0) {
            // Error is handled by the lockout timer UI
        } else if (!$limiter->check($ip, 'login', 5, 300)) {
            $rateStatus = $limiter->getStatus($ip, 'login', 5, 300);
            $lockout_remaining = $rateStatus['remaining'];
        } else {
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
                            $db->prepare("UPDATE users SET email_verification_token = ?, email_verification_token_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE), email_verification_attempts = 0 WHERE username = ?")->execute([$token, $username]);

                            sendEmail($userEmail, 'Verify Your Email Address', "Your email verification PIN is: <strong>$token</strong>");
                        }

                        $_SESSION['unverified_username'] = $username;
                        header('Location: ' . APP_URL . '/verify-email');
                        exit;
                    }
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
    
    <!-- PWA Manifest & Icons -->
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/icon.png">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/icon-192x192.png">
    <meta name="theme-color" content="#4f46e5">
</head>

<body>
    <div class="login-page">
        <div class="login-container">
            <div class="login-card">
                <div class="login-logo">
                    <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSNfTh_LuBglCEZy1s4gsEvMOL5sqDUhu2QSiXQZfjELMCHt7lnQfU7S1U&s=10" alt="Logo" class="logo-img">
                    <span class="logo-text"><?= htmlspecialchars(APP_NAME) ?></span>
                </div>

                <h2 class="login-title">Welcome back</h2>
                <p class="login-subtitle">Sign in to manage your inventory</p>

                <?php if ($error): ?>
                    <div class="login-error" style="margin-bottom: 20px;" id="loginError">
                        <i data-lucide="alert-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <span id="loginErrorText"><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <div id="lockoutTimer" class="login-error" style="display: <?= $lockout_remaining > 0 ? 'block' : 'none' ?>; margin-bottom: 20px;">
                    <i data-lucide="alert-triangle" style="width:16px;height:16px;display:block;margin:0 auto 6px auto;"></i>
                    <strong style="display: block; margin-bottom: 4px; font-size: 0.95rem;">Too many login attempts</strong>
                    Please try again in <span id="countdownText" style="font-weight: 700; font-variant-numeric: tabular-nums;"><?= floor($lockout_remaining / 60) . ':' . str_pad((string)($lockout_remaining % 60), 2, '0', STR_PAD_LEFT) ?></span>.
                </div>

                <form method="POST" class="login-form" id="loginForm">
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" id="username" name="username"
                            placeholder="Username" autocomplete="username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus
                            <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                        <label class="form-label" for="username">Username</label>
                    </div>

                    <div class="form-group form-floating" style="position: relative;">
                        <input type="password" class="form-control" id="password" name="password"
                            placeholder="Password" autocomplete="current-password" required style="padding-right: 40px;"
                            <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                        <label class="form-label" for="password">Password</label>
                        <button type="button" id="togglePassword" class="password-toggle" tabindex="-1" title="Toggle password visibility" <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                            <i data-lucide="eye-off" style="width:20px;height:20px;"></i>
                        </button>
                    </div>

                    <div style="text-align: right; margin-bottom: 20px; margin-top: -10px;">
                        <a href="<?= APP_URL ?>/forgot-password" style="font-size: 0.85rem; color: var(--primary-color); text-decoration: none;">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn btn-primary login-btn" id="loginBtn" <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                        <i data-lucide="log-in" style="width:18px;height:18px;"></i>
                        Sign In
                    </button>
                </form>

            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        let lockoutRemaining = <?= (int)$lockout_remaining ?>;

        if (lockoutRemaining > 0) {
            const countdownEl = document.getElementById('countdownText');
            const timerInterval = setInterval(function() {
                lockoutRemaining--;

                const m = Math.floor(lockoutRemaining / 60);
                const s = String(lockoutRemaining % 60).padStart(2, '0');
                countdownEl.innerText = `${m}:${s}`;

                if (lockoutRemaining <= 0) {
                    clearInterval(timerInterval);
                    document.getElementById('lockoutTimer').style.display = 'none';

                    // Enable form elements
                    document.getElementById('username').disabled = false;
                    document.getElementById('password').disabled = false;
                    document.getElementById('loginBtn').disabled = false;
                    document.getElementById('togglePassword').disabled = false;
                }
            }, 1000);
        }

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