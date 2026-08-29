<?php
/**
 * Reset Password Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';

$error = '';
$success = '';
$cooldown = 0;

if (isset($_SESSION['reset_msg'])) {
    $cooldown = 60; // 60 seconds cooldown after requesting a PIN
}

$limiter = new RateLimiter(getDB());
$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateStatus = $limiter->getStatus($ip, 'reset_password', 5, 300);
$lockout_remaining = 0;
if (!$rateStatus['allowed']) {
    $lockout_remaining = $rateStatus['remaining'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? null)) {
        $error = "Invalid or expired session. Please try again.";
    } else if ($lockout_remaining > 0) {
        // Error handled by lockout UI
    } else if (!$limiter->check($ip, 'reset_password', 5, 300)) {
        $rateStatus = $limiter->getStatus($ip, 'reset_password', 5, 300);
        $lockout_remaining = $rateStatus['remaining'];
    } else {
        $pin = trim($_POST['pin'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($pin) || empty($password) || empty($confirmPassword)) {
            $error = 'Please fill in all fields.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 8 || !preg_match('/[0-9\W]/', $password)) {
            $error = 'Password must be at least 8 characters and contain at least one number or symbol.';
        } else {
            $email = $_SESSION['reset_email'] ?? null;
            if (!$email) {
                $error = "Your session has expired or no email was provided. Please request a new PIN.";
            } else {
                $db = getDB();
                $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND reset_token IS NOT NULL AND reset_token_expires > NOW()");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    if ($user['reset_token'] === $pin) {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL, reset_token_attempts = 0 WHERE id = ?")->execute([$hash, $user['id']]);
                        $success = "Your password has been successfully reset.";
                        unset($_SESSION['reset_email']);
                    } else {
                        $attempts = (int)$user['reset_token_attempts'] + 1;
                        if ($attempts >= 3) {
                            $db->prepare("UPDATE users SET reset_token = NULL, reset_token_expires = NULL, reset_token_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);
                            $error = "You have entered an incorrect PIN too many times. The PIN has been invalidated. Please request a new one.";
                        } else {
                            $db->prepare("UPDATE users SET reset_token_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);
                            $error = "Invalid or expired reset PIN. You have " . (3 - $attempts) . " attempt(s) remaining.";
                        }
                    }
                } else {
                    $error = "Invalid or expired reset PIN.";
                }
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
    <title>Reset Password - <?= htmlspecialchars(APP_NAME) ?></title>
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
        .req-item { display:flex; align-items:center; gap:6px; color: var(--text-muted); font-size: 0.8rem; margin-bottom: 4px; }
        .req-item.valid { color: var(--success-color); }
        .req-item.invalid { color: var(--danger-color); }
        .req-icon { width: 14px; height: 14px; }
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
                
                <h2 class="login-title">Reset Password</h2>
                <p class="login-subtitle">Enter the 6-digit PIN sent to your email and create a new password.</p>
                
                <?php if ($error): ?>
                    <div class="login-error" style="margin-bottom: 20px;" id="loginError">
                        <i data-lucide="alert-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <span id="loginErrorText"><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>
                
                <div id="lockoutTimer" class="login-error" style="display: <?= $lockout_remaining > 0 ? 'block' : 'none' ?>; margin-bottom: 20px;">
                    <i data-lucide="alert-triangle" style="width:16px;height:16px;display:block;margin:0 auto 6px auto;"></i>
                    <strong style="display: block; margin-bottom: 4px; font-size: 0.95rem;">Too many reset attempts</strong>
                    Please try again in <span id="countdownText" style="font-weight: 700; font-variant-numeric: tabular-nums;"><?= floor($lockout_remaining / 60) . ':' . str_pad((string)($lockout_remaining % 60), 2, '0', STR_PAD_LEFT) ?></span>.
                </div>
                
                <?php 
                if (isset($_SESSION['reset_msg'])) {
                    echo '<div style="background: rgba(14, 165, 233, 0.1); border: 1px solid rgba(14, 165, 233, 0.2); color: #0284c7; padding: 12px; border-radius: var(--border-radius-sm); margin-bottom: 20px; text-align: center; font-size: 0.85rem;"><i data-lucide="info" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>' . htmlspecialchars($_SESSION['reset_msg']) . '</div>';
                    unset($_SESSION['reset_msg']);
                }
                ?>
                
                <?php if ($success): ?>
                    <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #059669; padding: 16px; border-radius: var(--border-radius-sm); margin-bottom: 24px; text-align: center; font-size: 0.95rem; font-weight: 500;">
                        <i data-lucide="check-circle" style="width:24px;height:24px;display:block;margin:0 auto 8px auto;color:#10b981;"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                    <div style="text-align: center;">
                        <a href="<?= APP_URL ?>/login" class="btn btn-primary login-btn">Return to Sign In</a>
                    </div>
                <?php else: ?>
                    <form method="POST" class="login-form" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <div class="form-group form-floating" style="position: relative;">
                            <input type="text" class="form-control" id="pin" name="pin" 
                                   placeholder="6-Digit PIN" required minlength="6" maxlength="6" pattern="\d{6}" 
                                   title="Please enter exactly 6 digits" autofocus 
                                   style="text-align: center; letter-spacing: 8px; font-size: 1.5rem; font-family: monospace; font-weight: 700; color: #9A0002;"
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);"
                                   <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                            <label class="form-label" for="pin">6-Digit PIN</label>
                        </div>
                        
                        <div class="form-group form-floating" style="position: relative; margin-bottom: 8px;">
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="New Password" required style="padding-right: 40px;"
                                   <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                            <label class="form-label" for="password">New Password</label>
                            <button type="button" class="password-toggle" data-target="password" tabindex="-1" title="Toggle password visibility" <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                                <i data-lucide="eye-off" style="width:20px;height:20px;"></i>
                            </button>
                        </div>
                        
                        <div id="password-reqs" style="margin-bottom: 16px; padding-left: 4px;">
                            <div class="req-item" id="req-length"><i data-lucide="x" class="req-icon"></i> At least 8 characters</div>
                            <div class="req-item" id="req-num"><i data-lucide="x" class="req-icon"></i> Contains a number</div>
                            <div class="req-item" id="req-spec"><i data-lucide="x" class="req-icon"></i> Contains a special character</div>
                        </div>

                        <div class="form-group form-floating" style="position: relative; margin-bottom: 8px;">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                   placeholder="Confirm Password" required style="padding-right: 40px;"
                                   <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                            <button type="button" class="password-toggle" data-target="confirm_password" tabindex="-1" title="Toggle password visibility" <?= $lockout_remaining > 0 ? 'disabled' : '' ?>>
                                <i data-lucide="eye-off" style="width:20px;height:20px;"></i>
                            </button>
                        </div>
                        <div id="match-msg" style="font-size: 0.8rem; margin-bottom: 24px; padding-left: 4px; display:none; align-items:center; gap:6px;">
                        </div>
                        
                        <button type="submit" class="btn btn-primary login-btn" id="resetBtn" disabled>
                            Reset Password
                        </button>
                    </form>
                    
                    <div style="text-align: center; margin-top: 16px; display: flex; flex-direction: column; gap: 12px; align-items: center;">
                        <a href="<?= APP_URL ?>/forgot-password" id="reqLinkBtn" class="btn btn-secondary login-btn-secondary" style="text-decoration: none; display: block;">Request New Reset Link</a>
                        <a href="<?= APP_URL ?>/login" style="font-size: 0.9rem; color: #9A0002; text-decoration: none; font-weight: 500;">&larr; Back to Sign In</a>
                    </div>
                <?php endif; ?>
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
                    
                    document.getElementById('pin').disabled = false;
                    document.getElementById('password').disabled = false;
                    document.getElementById('confirm_password').disabled = false;
                    document.querySelectorAll('.password-toggle').forEach(btn => btn.disabled = false);
                    validateForm();
                    
                    const errorDiv = document.getElementById('loginError');
                    const errorText = document.getElementById('loginErrorText');
                    if (errorDiv && errorText && errorText.innerText.includes('Too many reset attempts')) {
                        errorDiv.style.display = 'none';
                    }
                }
            }, 1000);
        }
        
        let cooldown = <?= (int)$cooldown ?>;
        const reqLinkBtn = document.getElementById('reqLinkBtn');
        if (reqLinkBtn && cooldown > 0) {
            reqLinkBtn.style.pointerEvents = 'none';
            reqLinkBtn.style.opacity = '0.6';
            
            const originalText = reqLinkBtn.innerText;
            const cooldownInterval = setInterval(function() {
                cooldown--;
                
                const m = Math.floor(cooldown / 60);
                const s = String(cooldown % 60).padStart(2, '0');
                reqLinkBtn.innerText = `Request a new PIN in ${m}:${s}`;
                
                if (cooldown <= 0) {
                    clearInterval(cooldownInterval);
                    reqLinkBtn.innerText = originalText;
                    reqLinkBtn.style.pointerEvents = 'auto';
                    reqLinkBtn.style.opacity = '1';
                }
            }, 1000);
        }

        const pinInput = document.getElementById('pin');
        const passInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm_password');
        const resetBtn = document.getElementById('resetBtn');
        
        const reqLength = document.getElementById('req-length');
        const reqNum = document.getElementById('req-num');
        const reqSpec = document.getElementById('req-spec');
        const matchMsg = document.getElementById('match-msg');
        
        function updateReqState(el, isValid) {
            if (!el) return;
            el.className = isValid ? 'req-item valid' : 'req-item invalid';
            const icon = el.querySelector('i');
            if (icon) {
                icon.setAttribute('data-lucide', isValid ? 'check' : 'x');
                icon.style.color = isValid ? 'var(--success-color)' : 'var(--danger-color)';
            }
        }
        
        function validateForm() {
            if (!passInput || lockoutRemaining > 0) return;
            
            const val = passInput.value;
            const hasLength = val.length >= 8;
            const hasNum = /\d/.test(val);
            const hasSpec = /[!@#$%^&*(),.?":{}|<>]/.test(val);
            
            updateReqState(reqLength, hasLength);
            updateReqState(reqNum, hasNum);
            updateReqState(reqSpec, hasSpec);
            
            const pwValid = hasLength && hasNum && hasSpec;
            
            const confirmVal = confirmInput.value;
            let matchValid = false;
            
            if (confirmVal.length > 0) {
                matchMsg.style.display = 'flex';
                if (val === confirmVal) {
                    matchMsg.innerHTML = '<i data-lucide="check" style="width:14px;height:14px;color:var(--success-color)"></i> <span style="color:var(--success-color)">Passwords match</span>';
                    matchValid = true;
                } else {
                    matchMsg.innerHTML = '<i data-lucide="x" style="width:14px;height:14px;color:var(--danger-color)"></i> <span style="color:var(--danger-color)">Passwords do not match</span>';
                }
            } else {
                matchMsg.style.display = 'none';
            }
            
            lucide.createIcons();
            
            const pinValid = pinInput.value.length === 6;
            
            if (pinValid && pwValid && matchValid) {
                resetBtn.disabled = false;
            } else {
                resetBtn.disabled = true;
            }
        }
        
        if (passInput) {
            passInput.addEventListener('input', validateForm);
            confirmInput.addEventListener('input', validateForm);
            pinInput.addEventListener('input', validateForm);
        }
        
        document.querySelectorAll('.password-toggle').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                
                if (type === 'password') {
                    this.innerHTML = '<i data-lucide="eye-off" style="width:20px;height:20px;"></i>';
                } else {
                    this.innerHTML = '<i data-lucide="eye" style="width:20px;height:20px;"></i>';
                }
                lucide.createIcons();
            });
        });
        
        const form = document.getElementById('resetForm');
        if (form) {
            form.addEventListener('submit', function() {
                if (!resetBtn.disabled) {
                    resetBtn.disabled = true;
                    resetBtn.innerHTML = '<span class="spinner" style="width:18px;height:18px;border-width:2px;border-color:#fff;border-bottom-color:transparent;"></span> Resetting...';
                }
            });
        }
        
        // Initial validation to set up lucide icons inside reqs
        if (passInput) {
            updateReqState(reqLength, false);
            updateReqState(reqNum, false);
            updateReqState(reqSpec, false);
            lucide.createIcons();
        }
    </script>
</body>
</html>
