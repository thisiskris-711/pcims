<?php
/**
 * Forgot Password Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceRateLimit('forgot_password', 3, 300);

    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $token = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            // Set expiration to 1 hour from now
            $db->prepare("UPDATE users SET reset_token = ?, reset_token_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([$token, $user['id']]);
            
            $resetLink = 'http://' . $_SERVER['HTTP_HOST'] . APP_URL . "/reset-password";
            $subject = 'Password Reset Request - ' . date('M d, H:i:s');
            sendEmail($email, $subject, "You requested a password reset. Your 6-digit reset PIN is: <strong>$token</strong><br><br>Please enter this PIN on the reset page: <a href='$resetLink'>$resetLink</a>. This PIN is valid for 1 hour.");
        }
        
        $_SESSION['reset_msg'] = 'If an account with that email exists, we have sent a 6-digit reset PIN.';
        header('Location: ' . APP_URL . '/reset-password');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body>
    <div class="login-page">
        <div class="login-container">
            <div class="login-card">
                <h2 class="login-title">Forgot Password</h2>
                <p class="login-subtitle">Enter your email to receive a reset link</p>
                
                <?php if ($error): ?>
                    <div class="login-error">
                        <i data-lucide="alert-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div style="background: var(--success-light); color: var(--success-color); padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center;">
                        <i data-lucide="check-circle" style="width:16px;height:16px;display:inline;vertical-align:middle;margin-right:6px;"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="login-form">
                    <div class="form-group form-floating">
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="Email Address" required autofocus>
                        <label class="form-label" for="email">Email Address</label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary login-btn">
                        Send Reset Link
                    </button>
                </form>
                
                <div style="text-align: center; margin-top: 20px;">
                    <a href="<?= APP_URL ?>/login" style="font-size: 0.9rem; color: var(--primary-color); text-decoration: none;">Back to Login</a>
                </div>
            </div>
        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>
