<?php
// login.php - Enhanced user login with security features
require 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

// Rate limiting - prevent brute force attacks
function checkRateLimit($identifier) {
    global $pdo;
    
    $maxAttempts = 5;
    $timeWindow = 900; // 15 minutes in seconds
    
    // Clean old attempts
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$timeWindow]);
    
    // Count recent attempts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->execute([$identifier, $timeWindow]);
    $attempts = $stmt->fetchColumn();
    
    if ($attempts >= $maxAttempts) {
        return false; // Rate limit exceeded
    }
    
    // Log this attempt
    $stmt = $pdo->prepare("INSERT INTO login_attempts (identifier, attempt_time) VALUES (?, NOW())");
    $stmt->execute([$identifier]);
    
    return true;
}

// Clear rate limit on successful login
function clearRateLimit($identifier) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?");
    $stmt->execute([$identifier]);
}

$errors = [];
$username = '';

// Process login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Sanitize input
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);
        
        // Basic validation
        if (empty($username)) {
            $errors[] = 'Username is required.';
        }
        if (empty($password)) {
            $errors[] = 'Password is required.';
        }
        
        if (empty($errors)) {
            // Check rate limiting (by IP and username)
            $identifier = $_SERVER['REMOTE_ADDR'] . ':' . $username;
            
            if (!checkRateLimit($identifier)) {
                $errors[] = 'Too many login attempts. Please try again in 15 minutes.';
            } else {
                try {
                    // Fetch user with additional fields for security checks
                    $stmt = $pdo->prepare("
                        SELECT id, username, password, email, status, 
                               last_login, failed_attempts
                        FROM users 
                        WHERE username = ? OR email = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$username, $username]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        // Check if account is active
                        if ($user['status'] !== 'active') {
                            $errors[] = 'Your account is not active. Please contact support.';
                        } elseif (password_verify($password, $user['password'])) {
                            // Successful login
                            clearRateLimit($identifier);
                            
                            // Regenerate session ID for security
                            session_regenerate_id(true);
                            
                            // Set session variables
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['login_time'] = time();
                            $_SESSION['last_activity'] = time();
                            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                            
                            // Update last login and reset failed attempts
                            $stmt = $pdo->prepare("
                                UPDATE users 
                                SET last_login = NOW(), 
                                    failed_attempts = 0,
                                    last_ip = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$_SERVER['REMOTE_ADDR'], $user['id']]);
                            
                            // Handle "Remember Me" functionality
                            if ($remember) {
                                $token = bin2hex(random_bytes(32));
                                $hashedToken = hash('sha256', $token);
                                $expires = time() + (30 * 24 * 60 * 60); // 30 days
                                
                                // Store token in database
                                $stmt = $pdo->prepare("
                                    INSERT INTO remember_tokens (user_id, token_hash, expires_at)
                                    VALUES (?, ?, FROM_UNIXTIME(?))
                                ");
                                $stmt->execute([$user['id'], $hashedToken, $expires]);
                                
                                // Set cookie
                                setcookie(
                                    'remember_token',
                                    $user['id'] . ':' . $token,
                                    $expires,
                                    '/',
                                    '',
                                    true,  // Secure (HTTPS only in production)
                                    true   // HttpOnly
                                );
                            }
                            
                            // Log successful login
                            $stmt = $pdo->prepare("
                                INSERT INTO login_logs (user_id, ip_address, user_agent, status, created_at)
                                VALUES (?, ?, ?, 'success', NOW())
                            ");
                            $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                            
                            // Redirect to intended page or dashboard
                            $redirectTo = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
                            unset($_SESSION['redirect_after_login']);
                            redirect($redirectTo, 'Welcome back, ' . htmlspecialchars($user['username']) . '!');
                            
                        } else {
                            // Invalid password
                            $errors[] = 'Invalid username or password.';
                            
                            // Update failed attempts count
                            $stmt = $pdo->prepare("
                                UPDATE users 
                                SET failed_attempts = failed_attempts + 1 
                                WHERE id = ?
                            ");
                            $stmt->execute([$user['id']]);
                            
                            // Log failed attempt
                            $stmt = $pdo->prepare("
                                INSERT INTO login_logs (user_id, ip_address, user_agent, status, created_at)
                                VALUES (?, ?, ?, 'failed', NOW())
                            ");
                            $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                        }
                    } else {
                        // User not found
                        $errors[] = 'Invalid username or password.';
                        
                        // Log failed attempt (no user)
                        $stmt = $pdo->prepare("
                            INSERT INTO login_logs (user_id, ip_address, user_agent, status, attempted_username, created_at)
                            VALUES (NULL, ?, ?, 'failed', ?, NOW())
                        ");
                        $stmt->execute([$_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $username]);
                    }
                } catch (PDOException $e) {
                    error_log('Login error: ' . $e->getMessage());
                    $errors[] = 'An error occurred. Please try again later.';
                }
            }
        }
    }
}

// Generate CSRF token for form
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Binary MLM System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #3b82f6 0%, #2f68c5 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
        }
        .login-header {
            background: linear-gradient(135deg, #3b82f6 0%, #2f68c5 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .login-header h2 {
            margin: 0;
            font-weight: 600;
            font-size: 1.8rem;
        }
        .login-header p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }
        .login-body {
            padding: 2rem;
        }
        .form-floating > label {
            color: #6c757d;
        }
        .form-floating > .form-control:focus ~ label {
            color: #3b82f6;
        }
        .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #3b82f6 0%, #2f68c5 100%);
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: transform 0.2s, box-shadow 0.2s;
            color: white;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(59, 130, 246, 0.4);
            color: white;
        }
        .password-toggle {
            cursor: pointer;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
            color: #6c757d;
            background: white;
            border: none;
            padding: 5px;
        }
        .password-toggle:hover {
            color: #3b82f6;
        }
        .form-check-input:checked {
            background-color: #3b82f6;
            border-color: #3b82f6;
        }
        .link-primary {
            color: #3b82f6 !important;
            text-decoration: none;
            font-weight: 500;
        }
        .link-primary:hover {
            color: #2f68c5 !important;
            text-decoration: underline;
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .divider {
            text-align: center;
            margin: 1.5rem 0;
            position: relative;
        }
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #e0e0e0;
        }
        .divider span {
            background: white;
            padding: 0 1rem;
            position: relative;
            color: #6c757d;
            font-size: 0.9rem;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .shake {
            animation: shake 0.5s;
        }
        .copyright {
            padding: 1rem 0;
            width: 100%;
            text-align: center;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container d-flex flex-column align-items-center min-vh-100">
        <div class="login-container">
            <div class="login-header">
                <i class="bi bi-shield-lock-fill" style="font-size: 3rem;"></i>
                <h2 class="mt-3">Welcome Back</h2>
                <p>Login to access your dashboard</p>
            </div>
            
            <div class="login-body">
                <?php if (isset($_SESSION['flash'])): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <?= htmlspecialchars($_SESSION['flash']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['flash']); ?>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show shake" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php foreach ($errors as $error): ?>
                            <?= htmlspecialchars($error) ?><br>
                        <?php endforeach; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    
                    <div class="form-floating mb-3">
                        <input type="text" 
                               class="form-control" 
                               id="username" 
                               name="username" 
                               placeholder="Username or Email"
                               value="<?= htmlspecialchars($username) ?>"
                               required
                               autocomplete="username">
                        <label for="username">
                            <i class="bi bi-person-fill me-1"></i>
                            Username or Email
                        </label>
                    </div>
                    
                    <div class="form-floating mb-3 position-relative">
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Password"
                               required
                               autocomplete="current-password">
                        <label for="password">
                            <i class="bi bi-lock-fill me-1"></i>
                            Password
                        </label>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   id="remember" 
                                   name="remember">
                            <label class="form-check-label" for="remember">
                                Remember me
                            </label>
                        </div>
                        <a href="forgot-password.php" class="link-primary">
                            Forgot password?
                        </a>
                    </div>
                    
                    <button type="submit" class="btn btn-login w-100 mb-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Sign In
                    </button>
                    
                    <div class="divider">
                        <span>OR</span>
                    </div>
                    
                    <div class="text-center">
                        <p class="mb-2">Don't have an account?</p>
                        <a href="register.php" class="link-primary">
                            <i class="bi bi-person-plus-fill me-1"></i>
                            Create Account
                        </a>
                    </div>
                    
                    <div class="text-center mt-3">
                        <a href="index.php" class="link-secondary text-decoration-none">
                            <i class="bi bi-arrow-left me-1"></i>
                            Back to Home
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="copyright mt-4">
            <small>&copy; <?= date('Y') ?> Binary MLM System. All rights reserved.</small>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('bi-eye');
                toggleIcon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('bi-eye-slash');
                toggleIcon.classList.add('bi-eye');
            }
        }
        
        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                e.stopPropagation();
                
                if (!username) {
                    document.getElementById('username').classList.add('is-invalid');
                }
                if (!password) {
                    document.getElementById('password').classList.add('is-invalid');
                }
            }
        });
        
        // Remove invalid class on input
        document.getElementById('username').addEventListener('input', function() {  
            this.classList.remove('is-invalid');
        });
        
        document.getElementById('password').addEventListener('input', function() {
            this.classList.remove('is-invalid');
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>