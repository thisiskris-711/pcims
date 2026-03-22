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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Validate CSRF token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
        record_login_attempt($username, $ip_address, false, 'Invalid CSRF token');
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
        record_login_attempt($username, $ip_address, false, 'Empty credentials');
    } elseif (is_rate_limited($username, $ip_address)) {
        $error = 'Too many login attempts. Please try again later.';
        record_login_attempt($username, $ip_address, false, 'Rate limited');
    } elseif (is_account_locked($username, $ip_address)) {
        $remaining_minutes = get_lockout_remaining_time($username, $ip_address);
        $error = "Account is temporarily locked. Please try again in {$remaining_minutes} minutes.";
        record_login_attempt($username, $ip_address, false, 'Account locked');
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();

            $query = "SELECT user_id, username, password, full_name, email, role, status
                      FROM users WHERE username = :username";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    $error = 'Your account is inactive. Please contact administrator.';
                    record_login_attempt($username, $ip_address, false, 'Inactive account');
                } else {
                    // Record successful login
                    record_login_attempt($username, $ip_address, true);

                    // Prevent session fixation after a successful login.
                    session_regenerate_id(true);

                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['profile_image'] = '';
                    $_SESSION['last_activity'] = time();

                    // Log activity
                    log_activity($user['user_id'], 'login', 'User logged in from IP: ' . $ip_address);

                    // Create login notification for admin users
                    if ($user['role'] === 'admin') {
                        add_notification(
                            $user['user_id'], // Personal notification for the admin
                            'Login Successful',
                            "You have successfully logged into the system from IP: " . $ip_address,
                            'info',
                            'system'
                        );
                    }

                    // Redirect to dashboard
                    header('Location: dashboard.php');
                    exit();
                }
            } else {
                $error = 'Invalid username or password.';
                record_login_attempt($username, $ip_address, false, 'Invalid credentials');
            }
        } catch (PDOException $exception) {
            error_log("Login Database Error: " . $exception->getMessage());
            error_log("Login Database Trace: " . $exception->getTraceAsString());
            $error = 'Database error. Please try again.';
            record_login_attempt($username, $ip_address, false, 'Database error: ' . $exception->getMessage());
        } catch (Exception $exception) {
            error_log("Login Unexpected Error: " . $exception->getMessage());
            error_log("Login Error Trace: " . $exception->getTraceAsString());
            $error = 'An unexpected error occurred. Please try again.';
            record_login_attempt($username, $ip_address, false, 'Unexpected error: ' . $exception->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    
    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#007bff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="PCIMS">
    <meta name="application-name" content="PCIMS">
    <meta name="description" content="Personal Collection Inventory Management System">
    <meta name="msapplication-TileColor" content="#007bff">
    <meta name="msapplication-config" content="/pcims/browserconfig.xml">
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="/pcims/manifest.json">
    
    <!-- Apple Touch Icons -->
    <link rel="apple-touch-icon" href="/pcims/images/pc-logo-2.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/pcims/images/pc-logo-2.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/pcims/images/pc-logo-2.png">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="/pcims/images/pc-logo-2.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/pcims/images/pc-logo-2.png">
    
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

        /* Animated background elements */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
            z-index: -1;
        }

        body::after {
            content: '';
            position: fixed;
            bottom: -50%;
            left: -10%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            animation: float 8s ease-in-out infinite reverse;
            z-index: -1;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(30px);
            }
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


        .form-section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2d3436;
            margin-bottom: 28px;
            text-align: center;
            letter-spacing: -0.3px;
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

        .form-control:-webkit-autofill,
        .form-control:-webkit-autofill:hover,
        .form-control:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px white inset !important;
            -webkit-text-fill-color: #2d3436 !important;
        }

        .btn-login {
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

        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(231, 74, 59, 0.4);
        }

        .btn-login:active {
            transform: translateY(-1px);
        }

        .btn-login:disabled {
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

        .alert-info {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border-left: 4px solid #36b9cc;
        }

        .alert .btn-close {
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .alert .btn-close:hover {
            opacity: 1;
        }

        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 0.87rem;
            color: #a0a0a0;
        }

        .footer-text a {
            color: #e74a3b;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .footer-text a:hover {
            color: #c0392b;
        }

        .credentials-hint {
            background: linear-gradient(135deg, rgba(231, 74, 59, 0.05) 0%, rgba(192, 57, 43, 0.05) 100%);
            border: 1px solid rgba(231, 74, 59, 0.15);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 0.85rem;
            color: #5a5c69;
            margin-top: 20px;
            text-align: center;
        }

        .credentials-hint strong {
            color: #e74a3b;
            font-weight: 700;
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

        .forgot-password-link {
            display: block;
            text-align: right;
            margin-bottom: 20px;
            color: #e74a3b;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .forgot-password-link:hover {
            color: #c0392b;
            text-decoration: underline;
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

            .form-section-title {
                font-size: 1.3rem;
                margin-bottom: 22px;
            }

            .btn-login {
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

            .form-section-title {
                font-size: 1.2rem;
                margin-bottom: 18px;
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

            <!-- Right Side - Login Form -->
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
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="form-group">
                        <label for="username" class="form-label">
                            <i class="fas fa-user me-2" style="color: #e74a3b;"></i>Username
                        </label>
                        <div class="input-group">
                            <i class="fas fa-user input-icon"></i>
                            <input
                                type="text"
                                class="form-control"
                                id="username"
                                name="username"
                                placeholder="Enter your username"
                                value="<?php echo htmlspecialchars($username ?? ''); ?>"
                                required
                                autofocus>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-2" style="color: #e74a3b;"></i>Password
                        </label>
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
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

                    <a href="forgot_password.php" class="forgot-password-link">
                        Forgot password?
                    </a>

                    <button type="submit" class="btn btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i>Sign In
                    </button>
                </form>

                <div class="footer-text">
                    <p style="margin: 0;">© 2026 PCIMS. All rights reserved.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');
        const passwordIcon = document.getElementById('passwordIcon');

        if (passwordToggle && passwordInput && passwordIcon) {
            passwordToggle.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Update icon
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
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
            }
        });

        // PWA Service Worker Registration
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('/pcims/sw.js')
                    .then(function(registration) {
                        console.log('ServiceWorker registration successful with scope: ', registration.scope);
                    })
                    .catch(function(error) {
                        console.log('ServiceWorker registration failed: ', error);
                    });
            });
        }

        // PWA Install Prompt
        let deferredPrompt;
        const installButton = document.createElement('button');
        installButton.textContent = 'Install App';
        installButton.className = 'btn btn-primary position-fixed';
        installButton.style.cssText = 'bottom: 20px; right: 20px; z-index: 1000; display: none;';
        document.body.appendChild(installButton);

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            installButton.style.display = 'block';
        });

        installButton.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                console.log(`User response to the install prompt: ${outcome}`);
                deferredPrompt = null;
                installButton.style.display = 'none';
            }
        });

        // Hide install button if app is already installed
        window.addEventListener('appinstalled', () => {
            installButton.style.display = 'none';
            console.log('PWA was installed');
        });
    </script>
</body>

</html>
