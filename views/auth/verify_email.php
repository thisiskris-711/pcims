<?php

/**
 * Verify Email Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';

if (empty($_SESSION['unverified_username'])) {
    header('Location: ' . APP_URL . '/login');
    exit;
}

$username = $_SESSION['unverified_username'];
$message = '';
$messageType = 'danger';

$db = getDB();

// Fetch email for masking
$stmt = $db->prepare("SELECT email FROM users WHERE username = ?");
$stmt->execute([$username]);
$userEmail = $stmt->fetchColumn();
$maskedEmail = '';
if ($userEmail) {
    $parts = explode('@', $userEmail);
    if (count($parts) === 2) {
        $maskedEmail = substr($parts[0], 0, 1) . '***@' . $parts[1];
    }
}

$limiter = new RateLimiter(getDB());
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateStatus = $limiter->getStatus($ip, 'resend_verify', 3, 300);
$lockout_remaining = 0;
if (!$rateStatus['allowed']) {
    $lockout_remaining = $rateStatus['remaining'];
}

$cooldown = 0;
if (isset($_SESSION['last_resend_time'])) {
    $elapsed = time() - $_SESSION['last_resend_time'];
    if ($elapsed < 60) {
        $cooldown = 60 - $elapsed;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'resend') {
        if ($lockout_remaining > 0 || $cooldown > 0) {
            // UI handled
        } else if (!$limiter->check($ip, 'resend_verify', 1, 60)) {
            $rateStatus = $limiter->getStatus($ip, 'resend_verify', 1, 60);
            $lockout_remaining = $rateStatus['remaining'];
        } else if (!$limiter->check($ip, 'resend_verify_daily', 5, 86400)) {
            $message = 'You have reached the maximum number of verification requests for today.';
            $messageType = 'error';
        } else {
            if ($userEmail) {
                $token = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                $db->prepare("UPDATE users SET email_verification_token = ?, email_verification_token_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE), email_verification_attempts = 0 WHERE username = ?")->execute([$token, $username]);

                sendEmail($userEmail, 'Verify Your Email Address', "Your email verification PIN is: <strong>$token</strong>");
                $message = "A new verification PIN has been sent.";
                $messageType = 'info';

                $_SESSION['last_resend_time'] = time();
                $cooldown = 60;
            }
        }
    } else {
        $pin = trim($_POST['pin'] ?? '');

        if (!empty($pin)) {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND email_verification_token IS NOT NULL AND email_verification_token_expires > NOW()");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user) {
                if ($user['email_verification_token'] === $pin) {
                    $db->prepare("UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL, email_verification_token_expires = NULL, email_verification_attempts = 0 WHERE id = ?")->execute([$user['id']]);

                    // Automatically log them in
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['avatar'] = $user['avatar'];
                    $_SESSION['login_time'] = time();

                    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                    session_regenerate_id(true);

                    unset($_SESSION['unverified_username']);
                    $_SESSION['login_success_msg'] = "Email verified successfully.";
                    header('Location: ' . APP_URL . '/');
                    exit;
                } else {
                    $attempts = (int)$user['email_verification_attempts'] + 1;
                    if ($attempts >= 3) {
                        $db->prepare("UPDATE users SET email_verification_token = NULL, email_verification_token_expires = NULL, email_verification_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);
                        $message = "You have entered an incorrect PIN too many times. The PIN has been invalidated. Please request a new one.";
                    } else {
                        $db->prepare("UPDATE users SET email_verification_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);
                        $message = "The PIN you entered is incorrect. You have " . (3 - $attempts) . " attempt(s) remaining.";
                    }
                }
            } else {
                $message = "The PIN you entered is incorrect or has expired.";
            }
        } else {
            $message = "Please enter your 6-digit PIN.";
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
    <title>Verify Email - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <style>
        .form-control.invalid {
            border-color: var(--danger-color);
        }
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

                <h2 class="login-title">Email Verification</h2>
                <p class="login-subtitle">We've sent a 6-digit PIN to your registered email.<br>
                    <?php if ($maskedEmail): ?>
                        <strong style="color:var(--text-color);"><?= htmlspecialchars($maskedEmail) ?></strong>
                    <?php endif; ?>
                </p>

                <?php if ($message): ?>
                    <?php if ($messageType === 'danger'): ?>
                        <div class="login-error" style="margin-bottom: 20px;">
                            <i data-lucide="alert-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                            <?= htmlspecialchars($message) ?>
                        </div>
                    <?php else: ?>
                        <div style="background: rgba(14, 165, 233, 0.1); border: 1px solid rgba(14, 165, 233, 0.2); color: #0284c7; padding: 12px; border-radius: var(--border-radius-sm); margin-bottom: 20px; text-align: center; font-size: 0.85rem;">
                            <i data-lucide="info" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                            <?= htmlspecialchars($message) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="POST" class="login-form" id="verifyForm">
                    <div class="form-group form-floating">
                        <input type="text" class="form-control <?= $messageType === 'danger' && $message ? 'invalid' : '' ?>" id="pin" name="pin"
                            placeholder="6-Digit PIN" required minlength="6" maxlength="6" pattern="\d{6}"
                            title="Please enter exactly 6 digits" autofocus
                            style="text-align: center; letter-spacing: 8px; font-size: 1.5rem; font-family: monospace; font-weight: 700; color: #9A0002;"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);">
                        <label class="form-label" for="pin">6-Digit PIN</label>
                    </div>

                    <button type="submit" class="btn btn-primary login-btn" id="verifyBtn" disabled>
                        Verify & Login
                    </button>
                </form>

                <form method="POST" id="resendForm" style="margin-top: 16px;">
                    <input type="hidden" name="action" value="resend">
                    <button type="submit" class="btn btn-secondary login-btn-secondary" id="resendBtn" <?= $total_wait > 0 ? 'disabled' : '' ?>>
                        Resend PIN
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
        const resendBtn = document.getElementById('resendBtn');

        if (waitRemaining > 0 && resendBtn) {
            resendBtn.style.pointerEvents = 'none';
            resendBtn.style.opacity = '0.6';

            const originalText = 'Resend PIN';
            const timerInterval = setInterval(function() {
                waitRemaining--;

                const m = Math.floor(waitRemaining / 60);
                const s = String(waitRemaining % 60).padStart(2, '0');
                resendBtn.innerText = `Resend PIN in ${m}:${s}`;

                if (waitRemaining <= 0) {
                    clearInterval(timerInterval);
                    resendBtn.innerText = originalText;
                    resendBtn.style.pointerEvents = 'auto';
                    resendBtn.style.opacity = '1';
                    resendBtn.disabled = false;
                }
            }, 1000);
        }

        const pinInput = document.getElementById('pin');
        const verifyBtn = document.getElementById('verifyBtn');

        function validatePin() {
            const val = pinInput.value.replace(/[^0-9]/g, '');
            if (val.length === 6) {
                verifyBtn.disabled = false;
                pinInput.classList.remove('invalid');
            } else {
                verifyBtn.disabled = true;
            }
        }

        if (pinInput) {
            pinInput.addEventListener('input', validatePin);

            // Paste handling
            pinInput.addEventListener('paste', function(e) {
                e.preventDefault();
                let paste = (e.clipboardData || window.clipboardData).getData('text');
                paste = paste.replace(/[^0-9]/g, '').slice(0, 6);
                pinInput.value = paste;
                validatePin();
            });

            // Initial validation
            validatePin();
        }

        const vForm = document.getElementById('verifyForm');
        if (vForm) {
            vForm.addEventListener('submit', function(e) {
                if (verifyBtn.disabled) {
                    e.preventDefault();
                    return;
                }
                verifyBtn.disabled = true;
                verifyBtn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;border-color:#fff;border-bottom-color:transparent;"></span> Verifying...';
            });
        }

        const rForm = document.getElementById('resendForm');
        if (rForm) {
            rForm.addEventListener('submit', function(e) {
                if (resendBtn.disabled) {
                    e.preventDefault();
                    return;
                }
                resendBtn.disabled = true;
                resendBtn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;border-color:#9A0002;border-bottom-color:transparent;"></span> Sending...';
            });
        }
    </script>
</body>

</html>