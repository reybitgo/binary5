<?php
// login.php - Enhanced login with affiliate-link redirect
require 'config.php';

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ---------- Persist affiliate id through login ---------- */
if (isset($_GET['aff']) && ($aff = (int)$_GET['aff']) > 0) {
    $_SESSION['after_login_aff'] = $aff;
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

/* ---------- Rate-limit helpers ---------- */
function checkRateLimit($identifier) {
    global $pdo;
    $maxAttempts = 5;
    $timeWindow = 900; // 15 minutes

    try {
        // Clean old attempts
        $pdo->prepare("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL ? SECOND)")
            ->execute([$timeWindow]);

        // Check current attempts
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$identifier, $timeWindow]);
        
        if ($stmt->fetchColumn() >= $maxAttempts) {
            return false;
        }

        // Record this attempt
        $pdo->prepare("INSERT INTO login_attempts (identifier, attempt_time) VALUES (?, NOW())")
            ->execute([$identifier]);
        
        return true;
    } catch (PDOException $e) {
        error_log('Rate limit check error: ' . $e->getMessage());
        return true; // Allow login attempt if rate limiting fails
    }
}

function clearRateLimit($identifier) {
    global $pdo;
    try {
        $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$identifier]);
    } catch (PDOException $e) {
        error_log('Clear rate limit error: ' . $e->getMessage());
    }
}

/* ---------- Form handling ---------- */
$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        // Basic validation
        if (empty($username)) $errors[] = 'Username is required.';
        if (empty($password)) $errors[] = 'Password is required.';

        if (empty($errors)) {
            $identifier = $_SERVER['REMOTE_ADDR'] . ':' . $username;
            
            if (!checkRateLimit($identifier)) {
                $errors[] = 'Too many login attempts. Please try again in 15 minutes.';
            } else {
                try {
                    // Find user by username or email
                    $stmt = $pdo->prepare("
                        SELECT id, username, password, email, status, last_login, failed_attempts
                        FROM users
                        WHERE username = ? OR email = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$username, $username]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        if ($user['status'] === 'suspended') {
                            $errors[] = 'Your account has been suspended. Please contact support.';
                        } elseif (password_verify($password, $user['password'])) {
                            /* ---------- LOGIN SUCCESS ---------- */
                            clearRateLimit($identifier);

                            // Regenerate session ID for security
                            session_regenerate_id(true);
                            
                            // Set session variables
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['login_time'] = time();
                            $_SESSION['last_activity'] = time();
                            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';

                            // Update user login info
                            $pdo->prepare("UPDATE users SET last_login = NOW(), failed_attempts = 0, last_ip = ? WHERE id = ?")
                                ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]);

                            // Log successful login
                            try {
                                $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, status, attempted_username, created_at) VALUES (?, ?, ?, 'success', ?, NOW())")
                                    ->execute([
                                        $user['id'],
                                        $_SERVER['REMOTE_ADDR'] ?? '',
                                        $_SERVER['HTTP_USER_AGENT'] ?? '',
                                        $username
                                    ]);
                            } catch (PDOException $e) {
                                error_log('Login log error: ' . $e->getMessage());
                            }

                            /* ---------- Remember-me cookie ---------- */
                            if ($remember) {
                                try {
                                    $token = bin2hex(random_bytes(32));
                                    $hash = hash('sha256', $token);
                                    $exp = time() + (30 * 24 * 60 * 60); // 30 days
                                    
                                    $pdo->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))")
                                        ->execute([$user['id'], $hash, $exp]);
                                    
                                    setcookie(
                                        'remember_token',
                                        $user['id'] . ':' . $token,
                                        $exp,
                                        '/',
                                        '',
                                        isset($_SERVER['HTTPS']), // Secure flag
                                        true // HttpOnly flag
                                    );
                                } catch (Exception $e) {
                                    error_log('Remember token error: ' . $e->getMessage());
                                }
                            }

                            /* ---------- Redirect logic ---------- */
                            $redirectTo = $_SESSION['after_login_redirect'] ?? 'dashboard.php';
                            unset($_SESSION['after_login_redirect']);

                            if ($user['status'] === 'inactive') {
                                // Allow redirect to product store for inactive users
                                if (strpos($redirectTo, 'page=product_store') !== false) {
                                    redirect($redirectTo, 'Welcome back, ' . htmlspecialchars($user['username']) . '!');
                                } else {
                                    redirect('dashboard.php?page=store', 'Welcome! Your account is inactive. Purchase a package to unlock all features.');
                                }
                            } else {
                                redirect($redirectTo, 'Welcome back, ' . htmlspecialchars($user['username']) . '!');
                            }

                        } else {
                            /* ---------- Invalid password ---------- */
                            $errors[] = 'Invalid username or password.';
                            
                            // Increment failed attempts
                            try {
                                $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?")
                                    ->execute([$user['id']]);
                            } catch (PDOException $e) {
                                error_log('Failed attempt update error: ' . $e->getMessage());
                            }

                            // Log failed login
                            try {
                                $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, status, attempted_username, created_at) VALUES (?, ?, ?, 'failed', ?, NOW())")
                                    ->execute([
                                        $user['id'],
                                        $_SERVER['REMOTE_ADDR'] ?? '',
                                        $_SERVER['HTTP_USER_AGENT'] ?? '',
                                        $username
                                    ]);
                            } catch (PDOException $e) {
                                error_log('Failed login log error: ' . $e->getMessage());
                            }
                        }
                    } else {
                        $errors[] = 'Invalid username or password.';
                        
                        // Log failed login attempt (no user found)
                        try {
                            $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, user_agent, status, attempted_username, created_at) VALUES (NULL, ?, ?, 'failed', ?, NOW())")
                                ->execute([
                                    $_SERVER['REMOTE_ADDR'] ?? '',
                                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                                    $username
                                ]);
                        } catch (PDOException $e) {
                            error_log('Failed login log error: ' . $e->getMessage());
                        }
                    }
                } catch (PDOException $e) {
                    error_log('Login database error: ' . $e->getMessage());
                    $errors[] = 'An error occurred. Please try again later.';
                }
            }
        }
    }
}

// Generate CSRF token
try {
    $csrfToken = generateCSRFToken();
} catch (Exception $e) {
    error_log('CSRF token generation error: ' . $e->getMessage());
    $csrfToken = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Shoppe Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: url('images/login-bg.jpg') no-repeat center center / cover;
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container { 
            background: white; 
            border-radius: 20px; 
            box-shadow: 0 20px 60px rgba(0,0,0,.3); 
            max-width: 400px; 
            width: 100%; 
            margin: auto; 
            overflow: hidden; 
        }
        .login-header { 
            background: linear-gradient(135deg, #3b82f6, #2f68c5); 
            color: white; 
            padding: 2rem; 
            text-align: center; 
        }
        .login-body { 
            padding: 2rem; 
        }
        .btn-login { 
            background: linear-gradient(135deg, #3b82f6, #2f68c5); 
            border: none; 
            font-weight: 600; 
            letter-spacing: .5px; 
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
        }
        .copyright { 
            color: white; 
            text-align: center; 
            margin-top: 1rem; 
        }
        .form-floating > .form-control:focus ~ label {
            color: #3b82f6;
        }
        .form-floating > .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }
    </style>
</head>
<body>
    <div class="container d-flex flex-column align-items-center min-vh-100">
        <div class="login-container">
            <div class="login-header">
                <i class="bi bi-shield-lock-fill" style="font-size:3rem;"></i>
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

                <?php if ($errors): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php foreach ($errors as $e): ?>
                            <?= htmlspecialchars($e) ?><br>
                        <?php endforeach; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="username" name="username" placeholder="Username or Email" value="<?= htmlspecialchars($username) ?>" required>
                        <label for="username"><i class="bi bi-person-fill me-1"></i>Username or Email</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                        <label for="password"><i class="bi bi-lock-fill me-1"></i>Password</label>

                        <button type="button"
                                class="btn position-absolute top-50 translate-middle-y border-0 bg-transparent p-0"
                                style="right: 0.75rem; height: 1.125rem; line-height: 1; z-index: 5;"
                                onclick="togglePassword()"
                                aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="toggleIcon" style="font-size: 1.125rem;"></i>
                        </button>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        <?php if (file_exists('forgot-password.php')): ?>
                            <a href="forgot-password.php" class="link-primary">Forgot password?</a>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-login w-100 text-white">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                    </button>

                    <div class="text-center mt-3">
                        <?php if (file_exists('register.php')): ?>
                            <a href="register.php" class="link-primary">Create an account</a><br>
                        <?php endif; ?>
                        <?php if (file_exists('index.php')): ?>
                            <a href="index.php" class="link-secondary text-decoration-none mt-2 d-inline-block">
                                <i class="bi bi-arrow-left me-1"></i>Back to Home
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="copyright">
            <small>&copy; <?= date('Y') ?> Shoppe Club. All Rights Reserved.</small>
        </div>
    </div>

    <!-- Bootstrap JS with fallback -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" 
            onerror="console.error('Bootstrap JS failed to load from CDN')"></script>
    
    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordField && toggleIcon) {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    toggleIcon.className = 'bi bi-eye-slash';
                } else {
                    passwordField.type = 'password';
                    toggleIcon.className = 'bi bi-eye';
                }
            }
        }

        // Auto-focus username field
        document.addEventListener('DOMContentLoaded', function() {
            const usernameField = document.getElementById('username');
            if (usernameField) {
                usernameField.focus();
            }
        });

        // Form validation
        document.getElementById('loginForm')?.addEventListener('submit', function(e) {
            const username = document.getElementById('username')?.value.trim();
            const password = document.getElementById('password')?.value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                return false;
            }
        });
    </script>
</body>
</html>