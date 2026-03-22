<?php
require_once 'config/config.php';
require_once 'includes/security.php';
require_once 'config/email_config.php';

// Set security headers
set_security_headers();

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';
$generic_reset_message = "If an account with that email exists, a password reset link has been sent.";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    // Validate CSRF token
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Invalid request. Please try again.';
    } elseif (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();

            // Check if email exists in database
            $query = "SELECT user_id, username, full_name FROM users WHERE email = :email AND status = 'active'";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Generate password reset token
                $reset_token = bin2hex(random_bytes(32));
                $expiry_time = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store reset token in database
                $token_query = "UPDATE users SET reset_token = :reset_token, reset_expiry = :reset_expiry WHERE user_id = :user_id";
                $token_stmt = $db->prepare($token_query);
                $token_stmt->bindParam(':reset_token', $reset_token);
                $token_stmt->bindParam(':reset_expiry', $expiry_time);
                $token_stmt->bindParam(':user_id', $user['user_id']);
                $token_stmt->execute();

                // Create reset link
                $reset_link = rtrim(APP_URL, '/') . '/reset_password.php?token=' . urlencode($reset_token);
                
                // Send password reset email
                if (!send_password_reset_email($email, $user['full_name'], $reset_link)) {
                    error_log('Password reset email could not be sent for user ID: ' . $user['user_id']);
                }

                // Log the password reset request
                log_activity($user['user_id'], 'password_reset_request', 'Password reset requested for email: ' . $email);
            }

            $success = $generic_reset_message;
        } catch (PDOException $exception) {
            error_log("Forgot Password Database Error: " . $exception->getMessage());
            $error = 'Database error. Please try again.';
        } catch (Exception $exception) {
            error_log("Forgot Password Unexpected Error: " . $exception->getMessage());
            $error = 'An unexpected error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow-x: hidden;
        }

        .login-wrapper {
            width: 100%;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .login-container {
            display: flex;
            background: rgba(255, 255, 255, 0.98);
            border-radius: 25px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15),
                0 0 1px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            width: 100%;
            max-width: 1000px;
            min-height: 600px;
            backdrop-filter: blur(10px);
            animation: slideUp 0.6s ease-out;
        }

        .login-left {
            flex: 1;
            background: linear-gradient(180deg, #e74a3b 10%, #c0392b 100%);
            color: white;
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-left::before {
            content: '';
            position: absolute;
            top: -2px;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(255, 255, 255, 0.3), transparent);
        }

        .login-right {
            flex: 1;
            padding: 45px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-container {
            margin-bottom: 30px;
            position: relative;
            display: inline-block;
            animation: pulse-logo 2s ease-in-out infinite;
        }

        @keyframes pulse-logo {
            0%, 100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }
        }

        .logo-img {
            width: 120px;
            height: 120px;
            object-fit: contain;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.15));
        }

        .system-name {
            font-size: 2.5rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .tagline {
            font-size: 1.1rem;
            opacity: 0.95;
            font-weight: 400;
            letter-spacing: 0.3px;
            line-height: 1.6;
            max-width: 400px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            font-weight: 600;
            color: #5a5c69;
            font-size: 0.95rem;
            margin-bottom: 8px;
            display: block;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #e74a3b;
            font-size: 1.1rem;
            z-index: 10;
        }

        .form-control {
            border-radius: 12px;
            border: 2px solid #e8eaed;
            padding: 12px 16px 12px 45px;
            font-size: 1rem;
            background: #f8f9fa;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #2d3436;
        }

        .form-control::placeholder {
            color: #a0a0a0;
            font-weight: 400;
        }

        .form-control:focus {
            border-color: #e74a3b;
            background: white;
            box-shadow: 0 0 0 4px rgba(231, 74, 59, 0.1);
            outline: none;
        }

        .btn-submit {
            background: linear-gradient(180deg, #e74a3b 10%, #c0392b 100%);
            border: none;
            border-radius: 12px;
            padding: 13px 24px;
            font-size: 1.05rem;
            font-weight: 700;
            color: white;
            width: 100%;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            box-shadow: 0 10px 25px rgba(231, 74, 59, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(231, 74, 59, 0.4);
        }

        .btn-submit:active {
            transform: translateY(-1px);
        }

        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            border-radius: 12px;
            border: none;
            margin-bottom: 24px;
            padding: 14px 16px;
            font-size: 0.95rem;
            font-weight: 500;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid #e74a3b;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #1cc88a;
        }

        .alert .btn-close {
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .alert .btn-close:hover {
            opacity: 1;
        }

        .back-to-login {
            text-align: center;
            margin-top: 24px;
            font-size: 0.87rem;
            color: #a0a0a0;
        }

        .back-to-login a {
            color: #e74a3b;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .back-to-login a:hover {
            color: #c0392b;
            text-decoration: underline;
        }

        .instructions {
            background: linear-gradient(135deg, rgba(231, 74, 59, 0.05) 0%, rgba(192, 57, 43, 0.05) 100%);
            border: 1px solid rgba(231, 74, 59, 0.15);
            border-radius: 10px;
            padding: 16px;
            font-size: 0.9rem;
            color: #5a5c69;
            margin-bottom: 24px;
            text-align: center;
        }

        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 0.87rem;
            color: #a0a0a0;
        }

        .instructions {
            background: linear-gradient(135deg, rgba(231, 74, 59, 0.05) 0%, rgba(192, 57, 43, 0.05) 100%);
            border: 1px solid rgba(231, 74, 59, 0.15);
            border-radius: 10px;
            padding: 16px;
            font-size: 0.9rem;
            color: #5a5c69;
            margin-bottom: 24px;
            text-align: center;
        }

        .instructions i {
            color: #e74a3b;
            margin-bottom: 8px;
            font-size: 1.5rem;
            display: block;
        }

        /* Responsive Design */
        @media (max-width: 900px) {
            .login-container {
                flex-direction: column;
                max-width: 500px;
                min-height: auto;
            }

            .login-left {
                padding: 40px 30px;
            }

            .login-right {
                padding: 35px 30px;
            }

            .logo-img {
                width: 80px;
                height: 80px;
            }

            .system-name {
                font-size: 1.8rem;
            }

            .tagline {
                font-size: 0.95rem;
            }
        }

        @media (max-width: 600px) {
            .login-container {
                border-radius: 20px;
            }

            .login-left {
                padding: 30px 20px;
            }

            .login-right {
                padding: 25px 20px;
            }

            .logo-img {
                width: 70px;
                height: 70px;
            }

            .system-name {
                font-size: 1.5rem;
            }

            .tagline {
                font-size: 0.85rem;
            }

            .btn-submit {
                padding: 12px 20px;
                font-size: 1rem;
            }
        }

        @media (max-width: 400px) {
            body {
                padding: 10px;
            }

            .login-left {
                padding: 25px 15px;
            }

            .login-right {
                padding: 20px 15px;
            }

            .logo-img {
                width: 60px;
                height: 60px;
            }

            .system-name {
                font-size: 1.3rem;
                gap: 8px;
            }

            .tagline {
                font-size: 0.85rem;
            }

            .form-control {
                font-size: 16px;
            }
        }
    </style>
</head>

<body>
    <div class="login-wrapper">
        <div class="login-container">
            <!-- Left Side - System Information -->
            <div class="login-left">
                <div class="logo-container">
                    <img src="images/pc-logo-2.png" alt="PCIMS Logo" class="logo-img">
                </div>
                <div class="system-name">
                    <?php echo APP_NAME; ?>
                </div>
                <div class="tagline">Personal Collection Inventory Management System</div>
            </div>

            <!-- Right Side - Forgot Password Form -->
            <div class="login-right">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-circle me-3 flex-shrink-0" style="font-size: 1.2rem;"></i>
                        <div class="flex-grow-1">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
                        <i class="fas fa-check-circle me-3 flex-shrink-0" style="font-size: 1.2rem;"></i>
                        <div class="flex-grow-1">
                            <?php echo $success; ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="instructions">
                        <i class="fas fa-info-circle"></i>
                        Enter your email address and we'll send you a link to reset your password.
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">
                            <i class="fas fa-envelope me-2" style="color: #e74a3b;"></i>Email Address
                        </label>
                        <div class="input-group">
                            <i class="fas fa-envelope input-icon"></i>
                            <input
                                type="email"
                                class="form-control"
                                id="email"
                                name="email"
                                placeholder="Enter your email address"
                                value="<?php echo htmlspecialchars($email ?? ''); ?>"
                                required
                                autofocus>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-submit">
                        <i class="fas fa-paper-plane me-2"></i>Send Reset Link
                    </button>
                </form>

                <div class="back-to-login">
                    <p style="margin: 0;">
                        <a href="login.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to Login
                        </a>
                    </p>
                </div>

                <div class="footer-text">
                    <p style="margin: 0;">© 2024 PCIMS. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Error handling to prevent browser extension conflicts
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.error);
            // Prevent extension-related errors from showing
            if (e.message && e.message.includes('message channel closed')) {
                e.preventDefault();
                return false;
            }
        });

        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled Promise Rejection:', e.reason);
            // Prevent extension-related errors
            if (e.reason && e.reason.toString && e.reason.toString().includes('message channel closed')) {
                e.preventDefault();
                return false;
            }
        });

        // Prevent async issues on page unload
        window.addEventListener('beforeunload', function() {
            return null;
        });

        // Add focus animation to form inputs
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.closest('.input-group').style.boxShadow = '0 0 0 3px rgba(231, 74, 59, 0.1)';
            });

            input.addEventListener('blur', function() {
                this.parentElement.closest('.input-group').style.boxShadow = 'none';
            });
        });

        // Prevent form submission if fields are empty
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();

            if (!email) {
                e.preventDefault();
                alert('Please enter your email address');
                return false;
            }
        });

        // Page load complete
        console.log('Forgot password page loaded successfully');
    </script>
</body>

</html>
