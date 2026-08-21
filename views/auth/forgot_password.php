<?php
/**
 * Forgot Password Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';

$error = '';
$success = '';

$limiter = new RateLimiter(getDB());
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateStatus = $limiter->getStatus($ip, 'forgot_password', 3, 300);
$lockout_remaining = 0;
if (!$rateStatus['allowed']) {
    $lockout_remaining = $rateStatus['remaining'];
}

$cooldown = 0;
if (isset($_SESSION['last_reset_time'])) {
    $elapsed = time() - $_SESSION['last_reset_time'];
    if ($elapsed < 60) {
        $cooldown = 60 - $elapsed;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lockout_remaining > 0 || $cooldown > 0) {
        // Handled by UI
    } else if (!$limiter->check($ip, 'forgot_password', 1, 60)) {
        $rateStatus = $limiter->getStatus($ip, 'forgot_password', 1, 60);
        $lockout_remaining = $rateStatus['remaining'];
    } else if (!$limiter->check($ip, 'forgot_password_daily', 5, 86400)) {
        $error = 'You have reached the maximum number of password reset requests for today. Please try again tomorrow.';
    } else {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                $token = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                // Set expiration to 10 minutes from now
                $db->prepare("UPDATE users SET reset_token = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE), reset_token_attempts = 0 WHERE id = ?")->execute([$token, $user['id']]);
                
                $resetLink = 'http://' . $_SERVER['HTTP_HOST'] . APP_URL . "/reset-password";
                $subject = 'Password Reset Request - ' . date('M d, H:i:s');
                sendEmail($email, $subject, "You requested a password reset. Your 6-digit reset PIN is: <strong>$token</strong><br><br>Please enter this PIN on the reset page: <a href='$resetLink'>$resetLink</a>. This PIN is valid for 10 minutes.");
            }
            
            $_SESSION['last_reset_time'] = time();
            $_SESSION['reset_email'] = $email;
            $_SESSION['reset_msg'] = "Check your email. If an account with that email exists, we've sent you a password reset link.";
            header('Location: ' . APP_URL . '/reset-password');
            exit;
        }
    }
}

$total_wait = max($lockout_remaining, $cooldown);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    
    <!-- PWA Manifest & Icons -->
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/icon.png">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/icon-192x192.png">
    <meta name="theme-color" content="#4f46e5">
    <style>
        .validation-msg { display: none; color: var(--danger-color); font-size: 0.8rem; margin-top: 4px; padding-left: 4px; }
        .form-control.invalid { border-color: var(--danger-color); }
    </style>
</head>
<body>
    <div class="login-page">
        <div class="login-container">
            <div class="login-card">
                
                <div class="login-logo">
                    <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcSNfTh_LuBglCEZy1s4gsEvMOL5sqDUhu2QSiXQZfjELMCHt7lnQfU7S1U&s=10" alt="Logo" class="logo-img">
                    <span class="logo-text"><?= htmlspecialchars(APP_NAME) ?></span>
                </div>
                
                <h2 class="login-title">Forgot Password</h2>
                <p class="login-subtitle">Enter your email to receive a reset link.</p>
                
                <?php if ($error): ?>
                    <div class="login-error" style="margin-bottom: 20px;">
                        <i data-lucide="alert-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <div id="waitTimer" class="login-error" style="display: <?= $total_wait > 0 ? 'block' : 'none' ?>; margin-bottom: 20px;">
                    <i data-lucide="clock" style="width:16px;height:16px;display:block;margin:0 auto 6px auto;"></i>
                    <strong style="display: block; margin-bottom: 4px; font-size: 0.95rem;">Please wait</strong>
                    Request again in <span id="countdownText" style="font-weight: 700; font-variant-numeric: tabular-nums;"><?= floor($total_wait / 60) . ':' . str_pad((string)($total_wait % 60), 2, '0', STR_PAD_LEFT) ?></span>.
                </div>
                
                <form method="POST" class="login-form" id="forgotForm">
                    <div class="form-group form-floating" style="position: relative;">
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="Email Address" required autofocus style="padding-right: 40px;"
                               <?= $total_wait > 0 ? 'disabled' : '' ?>>
                        <label class="form-label" for="email">Email Address</label>
                        <i data-lucide="mail" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--text-muted); width:20px; height:20px;"></i>
                        <div class="validation-msg" id="emailError">Please enter a valid email address.</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary login-btn" id="submitBtn" <?= $total_wait > 0 ? 'disabled' : '' ?>>
                        Send Reset Link
                    </button>
                </form>
                
                <div style="text-align: center; margin-top: 24px;">
                    <a href="<?= APP_URL ?>/login" style="font-size: 0.9rem; color: #9A0002; text-decoration: none; font-weight: 500;">&larr; Back to Sign In</a>
                </div>
            </div>
        </div>
    </div>
    <script>
        lucide.createIcons();
        
        let waitRemaining = <?= (int)$total_wait ?>;
        
        if (waitRemaining > 0) {
            const countdownEl = document.getElementById('countdownText');
            const timerInterval = setInterval(function() {
                waitRemaining--;
                
                const m = Math.floor(waitRemaining / 60);
                const s = String(waitRemaining % 60).padStart(2, '0');
                countdownEl.innerText = `${m}:${s}`;
                
                if (waitRemaining <= 0) {
                    clearInterval(timerInterval);
                    document.getElementById('waitTimer').style.display = 'none';
                    document.getElementById('email').disabled = false;
                    
                    validateEmail(); // Will enable button if valid
                }
            }, 1000);
        }

        const emailInput = document.getElementById('email');
        const submitBtn = document.getElementById('submitBtn');
        const emailError = document.getElementById('emailError');
        
        function validateEmail() {
            if (waitRemaining > 0) return;
            
            const val = emailInput.value.trim();
            const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
            
            if (val.length > 0 && !isValid) {
                emailInput.classList.add('invalid');
                emailError.style.display = 'block';
                submitBtn.disabled = true;
            } else {
                emailInput.classList.remove('invalid');
                emailError.style.display = 'none';
                if (val.length > 0) {
                    submitBtn.disabled = false;
                } else {
                    submitBtn.disabled = true;
                }
            }
        }
        
        if (emailInput) {
            emailInput.addEventListener('input', validateEmail);
            emailInput.addEventListener('blur', validateEmail);
            
            // Initial state
            if (emailInput.value.trim().length === 0) {
                submitBtn.disabled = true;
            }
        }
        
        const form = document.getElementById('forgotForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (waitRemaining > 0 || submitBtn.disabled) {
                    e.preventDefault();
                    return;
                }
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;border-color:#fff;border-bottom-color:transparent;"></span> Sending...';
            });
        }
    </script>
</body>
</html>
