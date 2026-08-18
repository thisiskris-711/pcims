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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'resend') {
        enforceRateLimit('resend_verify', 3, 300);
        $stmt = $db->prepare("SELECT email FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $userEmail = $stmt->fetchColumn();
        
        if ($userEmail) {
            $token = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE users SET email_verification_token = ? WHERE username = ?")->execute([$token, $username]);
            
            $verifyLink = 'http://' . $_SERVER['HTTP_HOST'] . APP_URL . "/verify-email";
            sendEmail($userEmail, 'Verify Your Email Address', "Your email verification PIN is: <strong>$token</strong><br><br>Please enter this PIN on the verification page: <a href='$verifyLink'>$verifyLink</a>");
            $message = "A new verification PIN has been sent to your email.";
            $messageType = 'info';
        }
    } else {
        $pin = trim($_POST['pin'] ?? '');
        
        if (!empty($pin)) {
            $stmt = $db->prepare("SELECT * FROM users WHERE email_verification_token = ? AND username = ?");
            $stmt->execute([$pin, $username]);
            $user = $stmt->fetch();
            
            if ($user) {
                $db->prepare("UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL WHERE id = ?")->execute([$user['id']]);
                
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
                header('Location: ' . APP_URL . '/');
                exit;
            } else {
                $message = "Invalid or expired verification PIN.";
            }
        } else {
            $message = "Please enter your 6-digit PIN.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Email - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
</head>
<body>
    <div class="login-page">
        <div class="login-container">
            <div class="login-card" style="text-align: center;">
                <h2 style="margin-bottom: 10px;">Email Verification</h2>
                
                <p class="text-secondary" style="margin-bottom: 20px; font-size: 0.95rem;">
                    We've sent a 6-digit PIN to the email associated with <br><strong><?= htmlspecialchars($username) ?></strong>.
                </p>
                
                <?php if ($message): ?>
                    <div style="background: rgba(255, 255, 255, 0.15); color: #ffffff; padding: 12px; border-radius: 6px; margin-bottom: 20px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" class="login-form">
                    <div class="form-group form-floating">
                        <input type="text" class="form-control" id="pin" name="pin" 
                               placeholder="6-Digit PIN" required minlength="6" maxlength="6" pattern="\d{6}" title="Please enter exactly 6 digits" autofocus style="text-align: center; letter-spacing: 5px; font-size: 1.2rem;">
                        <label class="form-label" for="pin">6-Digit PIN</label>
                    </div>
                    <button type="submit" class="btn btn-primary login-btn">
                        Verify & Login
                    </button>
                </form>
                
                <form method="POST" style="margin-top: 15px;">
                    <input type="hidden" name="action" value="resend">
                    <button type="submit" class="btn login-btn-secondary">
                        Resend PIN
                    </button>
                </form>
                
                <div style="margin-top: 20px;">
                    <a href="<?= APP_URL ?>/login" class="text-muted" style="font-size: 0.9rem; text-decoration: none;">Cancel & Back to Login</a>
                </div>
            </div>
        </div>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>
