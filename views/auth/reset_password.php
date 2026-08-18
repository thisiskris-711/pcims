<?php
/**
 * Reset Password Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceRateLimit('reset_password', 5, 300);

    $pin = trim($_POST['pin'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($pin) || empty($password) || empty($confirmPassword)) {
        $error = 'Please fill in all fields.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()");
        $stmt->execute([$pin]);
        $user = $stmt->fetch();
        
        if ($user) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?")->execute([$hash, $user['id']]);
            $success = "Your password has been successfully reset.";
        } else {
            $error = "Invalid or expired reset PIN.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body>
    <div class="login-page">
        <div class="login-container">
            <div class="login-card">
                <h2 class="login-title">Reset Password</h2>
                
                <?php if ($error): ?>
                    <div class="login-error" style="margin-bottom: 20px;">
                        <i data-lucide="alert-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php 
                if (isset($_SESSION['reset_msg'])) {
                    echo '<div style="background: var(--info-light); color: var(--info-color); padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center;"><i data-lucide="info" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>' . htmlspecialchars($_SESSION['reset_msg']) . '</div>';
                    unset($_SESSION['reset_msg']);
                }
                ?>
                
                <?php if ($success): ?>
                    <div style="background: var(--success-light); color: var(--success-color); padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center;">
                        <i data-lucide="check-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                    <div style="text-align: center;">
                        <a href="<?= APP_URL ?>/login" class="btn btn-primary">Go to Login</a>
                    </div>
                <?php else: ?>
                    <form method="POST" class="login-form">
                        <div class="form-group form-floating">
                            <input type="text" class="form-control" id="pin" name="pin" 
                                   placeholder="6-Digit PIN" required minlength="6" maxlength="6" pattern="\d{6}" title="Please enter exactly 6 digits" autofocus style="text-align: center; letter-spacing: 5px; font-size: 1.2rem;">
                            <label class="form-label" for="pin">6-Digit PIN</label>
                        </div>
                        <div class="form-group form-floating" style="position: relative;">
                            <input type="password" class="form-control" id="password" name="password" 
                                   placeholder="New Password" required minlength="6">
                            <label class="form-label" for="password">New Password</label>
                        </div>
                        <div class="form-group form-floating" style="position: relative;">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                   placeholder="Confirm Password" required minlength="6">
                            <label class="form-label" for="confirm_password">Confirm Password</label>
                        </div>
                        
                        <button type="submit" class="btn btn-primary login-btn">
                            Reset Password
                        </button>
                    </form>
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="<?= APP_URL ?>/forgot-password" class="btn btn-secondary">Request New Reset Link</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>
