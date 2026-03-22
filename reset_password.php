<?php
require_once 'config/config.php';
require_once 'includes/security.php';

// Set security headers
set_security_headers();

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';
$token_valid = false;
$user_data = null;

// Get token from URL
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = 'Invalid reset link. Please request a new password reset.';
} else {
    try {
        $database = new Database();
        $db = $database->getConnection();

        // Check if token exists and is valid
        $query = "SELECT user_id, username, full_name, email, reset_expiry 
                  FROM users 
                  WHERE reset_token = :token AND status = 'active'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();

        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user_data) {
            $error = 'Invalid reset link. Please request a new password reset.';
        } elseif (strtotime($user_data['reset_expiry']) < time()) {
            $error = 'Reset link has expired. Please request a new password reset.';
        } else {
            $token_valid = true;
        }
    } catch (PDOException $exception) {
        error_log("Reset Password Database Error: " . $exception->getMessage());
        $error = 'Database error. Please try again.';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';

    // Validate CSRF token
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Invalid request. Please try again.';
    } elseif (empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $password)) {
        $error = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
    } else {
        try {
            // Hash new password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Update password and clear reset token
            $update_query = "UPDATE users 
                            SET password = :password, reset_token = NULL, reset_expiry = NULL 
                            WHERE user_id = :user_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':password', $hashed_password);
            $update_stmt->bindParam(':user_id', $user_data['user_id']);
            $update_stmt->execute();

            // Log the password reset
            log_activity($user_data['user_id'], 'password_reset', 'Password was reset successfully');

            $success = 'Password has been reset successfully. You can now login with your new password.';
            
            // Redirect to login after 3 seconds
            header('refresh:3;url=login.php');
            
        } catch (PDOException $exception) {
            error_log("Reset Password Update Error: " . $exception->getMessage());
            $error = 'Database error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo APP_NAME; ?></title>
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

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #e74a3b;
            cursor: pointer;
            font-size: 1.1rem;
            z-index: 10;
            padding: 5px;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #c0392b;
        }

        .password-toggle:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(231, 74, 59, 0.2);
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

        .password-requirements {
            background: linear-gradient(135deg, rgba(231, 74, 59, 0.05) 0%, rgba(192, 57, 43, 0.05) 100%);
            border: 1px solid rgba(231, 74, 59, 0.15);
            border-radius: 10px;
            padding: 16px;
            font-size: 0.85rem;
            color: #5a5c69;
            margin-bottom: 24px;
        }

        .password-requirements h6 {
            color: #e74a3b;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .password-requirements ul {
            margin: 0;
            padding-left: 20px;
        }

        .password-requirements li {
            margin-bottom: 5px;
        }

        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 0.87rem;
            color: #a0a0a0;
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

            <!-- Right Side - Reset Password Form -->
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

                <?php if ($token_valid && !$success): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                        <div class="password-requirements">
                            <h6><i class="fas fa-shield-alt me-2"></i>Password Requirements:</h6>
                            <ul>
                                <li>At least 8 characters long</li>
                                <li>One uppercase letter (A-Z)</li>
                                <li>One lowercase letter (a-z)</li>
                                <li>One number (0-9)</li>
                                <li>One special character (@$!%*?&)</li>
                            </ul>
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">
                                <i class="fas fa-lock me-2" style="color: #e74a3b;"></i>New Password
                            </label>
                            <div class="input-group">
                                <i class="fas fa-lock input-icon"></i>
                                <input
                                    type="password"
                                    class="form-control"
                                    id="password"
                                    name="password"
                                    placeholder="Enter your new password"
                                    required>
                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="passwordToggle"
                                    title="Toggle password visibility"
                                    aria-label="Toggle password visibility">
                                    <i class="fas fa-eye" id="passwordIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password" class="form-label">
                                <i class="fas fa-lock me-2" style="color: #e74a3b;"></i>Confirm New Password
                            </label>
                            <div class="input-group">
                                <i class="fas fa-lock input-icon"></i>
                                <input
                                    type="password"
                                    class="form-control"
                                    id="confirm_password"
                                    name="confirm_password"
                                    placeholder="Confirm your new password"
                                    required>
                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="confirmPasswordToggle"
                                    title="Toggle password visibility"
                                    aria-label="Toggle password visibility">
                                    <i class="fas fa-eye" id="confirmPasswordIcon"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-submit">
                            <i class="fas fa-key me-2"></i>Reset Password
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (!$token_valid || $success): ?>
                    <div class="back-to-login">
                        <p style="margin: 0;">
                            <a href="login.php">
                                <i class="fas fa-arrow-left me-1"></i>Back to Login
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="footer-text">
                    <p style="margin: 0;">© 2024 PCIMS. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle for new password
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');
        const passwordIcon = document.getElementById('passwordIcon');

        if (passwordToggle && passwordInput && passwordIcon) {
            passwordToggle.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                if (type === 'text') {
                    passwordIcon.classList.remove('fa-eye');
                    passwordIcon.classList.add('fa-eye-slash');
                    passwordToggle.setAttribute('title', 'Hide password');
                    passwordToggle.setAttribute('aria-label', 'Hide password');
                } else {
                    passwordIcon.classList.remove('fa-eye-slash');
                    passwordIcon.classList.add('fa-eye');
                    passwordToggle.setAttribute('title', 'Show password');
                    passwordToggle.setAttribute('aria-label', 'Show password');
                }
            });
        }

        // Password visibility toggle for confirm password
        const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const confirmPasswordIcon = document.getElementById('confirmPasswordIcon');

        if (confirmPasswordToggle && confirmPasswordInput && confirmPasswordIcon) {
            confirmPasswordToggle.addEventListener('click', function() {
                const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPasswordInput.setAttribute('type', type);
                
                if (type === 'text') {
                    confirmPasswordIcon.classList.remove('fa-eye');
                    confirmPasswordIcon.classList.add('fa-eye-slash');
                    confirmPasswordToggle.setAttribute('title', 'Hide password');
                    confirmPasswordToggle.setAttribute('aria-label', 'Hide password');
                } else {
                    confirmPasswordIcon.classList.remove('fa-eye-slash');
                    confirmPasswordIcon.classList.add('fa-eye');
                    confirmPasswordToggle.setAttribute('title', 'Show password');
                    confirmPasswordToggle.setAttribute('aria-label', 'Show password');
                }
            });
        }

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
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;

                if (!password || !confirmPassword) {
                    e.preventDefault();
                    alert('Please fill in all fields');
                } else if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match');
                }
            });
        }
    </script>
</body>

</html>
