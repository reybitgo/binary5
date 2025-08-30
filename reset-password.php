<?php
// reset-password.php - Handle password reset with token
require 'config.php';

$errors = [];
$success = false;
$validToken = false;
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$resetRequest = null;

// Validate token
if ($token) {
    $tokenHash = hash('sha256', $token);
    
    $stmt = $pdo->prepare("
        SELECT pr.*, u.username, u.email 
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token_hash = ? 
        AND pr.expires_at > NOW() 
        AND pr.used = FALSE
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $resetRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($resetRequest) {
        $validToken = true;
        
        // Handle password reset form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset') {
            // CSRF validation
            if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
                $errors[] = 'Invalid security token. Please refresh and try again.';
            } else {
                $newPassword = $_POST['password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                // Validate passwords
                if (empty($newPassword)) {
                    $errors[] = 'Password is required.';
                } elseif (strlen($newPassword) < 8) {
                    $errors[] = 'Password must be at least 8 characters long.';
                } elseif (!preg_match('/[A-Z]/', $newPassword)) {
                    $errors[] = 'Password must contain at least one uppercase letter.';
                } elseif (!preg_match('/[a-z]/', $newPassword)) {
                    $errors[] = 'Password must contain at least one lowercase letter.';
                } elseif (!preg_match('/[0-9]/', $newPassword)) {
                    $errors[] = 'Password must contain at least one number.';
                } elseif ($newPassword !== $confirmPassword) {
                    $errors[] = 'Passwords do not match.';
                }
                
                if (empty($errors)) {
                    try {
                        // Start transaction
                        $pdo->beginTransaction();
                        
                        // Update password
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("
                            UPDATE users 
                            SET password = ?, 
                                failed_attempts = 0,
                                email_verified = TRUE
                            WHERE id = ?
                        ");
                        $stmt->execute([$hashedPassword, $resetRequest['user_id']]);
                        
                        // Mark token as used
                        $stmt = $pdo->prepare("UPDATE password_resets SET used = TRUE WHERE id = ?");
                        $stmt->execute([$resetRequest['id']]);
                        
                        // Clear any other pending reset tokens for this user
                        $stmt = $pdo->prepare("UPDATE password_resets SET used = TRUE WHERE user_id = ? AND used = FALSE");
                        $stmt->execute([$resetRequest['user_id']]);
                        
                        // Log the password reset (create login_logs entry if it doesn't exist)
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO login_logs (user_id, ip_address, user_agent, status, attempted_username, created_at)
                                VALUES (?, ?, ?, 'success', ?, NOW())
                            ");
                            $stmt->execute([
                                $resetRequest['user_id'], 
                                $_SERVER['REMOTE_ADDR'] ?? 'unknown', 
                                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                                $resetRequest['username']
                            ]);
                        } catch (Exception $logError) {
                            // Log error but don't fail the password reset
                            error_log('Failed to log password reset: ' . $logError->getMessage());
                        }
                        
                        $pdo->commit();
                        $success = true;
                        
                        // Clear debug session data if exists
                        unset($_SESSION['debug_reset_link']);
                        unset($_SESSION['debug_user']);
                        
                        // Set success message for login page
                        $_SESSION['flash'] = 'Your password has been successfully reset. Please login with your new password.';
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log('Password reset error: ' . $e->getMessage());
                        $errors[] = 'An error occurred while resetting your password. Please try again.';
                    }
                }
            }
        }
    } else {
        $errors[] = 'This password reset link is invalid or has expired. Please request a new one.';
    }
} else {
    redirect('forgot-password.php', 'No reset token provided.');
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Rixile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .reset-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
        }
        .reset-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .reset-header h2 {
            margin: 0;
            font-weight: 600;
            font-size: 1.8rem;
        }
        .reset-header p {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            font-size: 0.95rem;
        }
        .reset-body {
            padding: 2rem;
        }
        .form-floating {
            position: relative;
        }
        .form-floating > label {
            color: #6c757d;
        }
        .form-floating > .form-control:focus ~ label {
            color: #667eea;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 10;
            padding: 5px;
            transition: color 0.2s;
        }
        .password-toggle:hover {
            color: #667eea;
        }
        .password-strength {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        .password-strength::after {
            content: '';
            display: block;
            height: 100%;
            width: 0%;
            background: #dc3545;
            border-radius: 2px;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        .password-strength.strength-weak::after {
            width: 33%;
            background: #dc3545;
        }
        .password-strength.strength-medium::after {
            width: 66%;
            background: #ffc107;
        }
        .password-strength.strength-strong::after {
            width: 100%;
            background: #28a745;
        }
        .password-requirements {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
        }
        .requirement i {
            margin-right: 8px;
            color: #dc3545;
        }
        .requirement.met i {
            color: #28a745;
        }
        .btn-reset {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: transform 0.2s, box-shadow 0.2s;
            color: white;
        }
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
            color: white;
        }
        .link-primary {
            color: #667eea !important;
            text-decoration: none;
            font-weight: 500;
        }
        .link-primary:hover {
            color: #764ba2 !important;
            text-decoration: underline;
        }
        .alert {
            border-radius: 10px;
            border: none;
        }
        .success-icon {
            font-size: 4rem;
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6 col-lg-5">
                <div class="reset-container">
                    <div class="reset-header">
                        <i class="bi bi-shield-lock-fill" style="font-size: 3rem;"></i>
                        <h2 class="mt-3">Set New Password</h2>
                        <p>Create a strong password for your account</p>
                    </div>
                    
                    <div class="reset-body">
                        <?php if ($success): ?>
                            <!-- Success Message -->
                            <div class="text-center">
                                <i class="bi bi-check-circle-fill success-icon mb-3"></i>
                                <h4 class="mb-3">Password Reset Successful!</h4>
                                <p class="text-muted mb-4">
                                    Your password has been successfully updated. You can now login with your new password.
                                </p>
                                <a href="login.php" class="btn btn-reset w-100">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>
                                    Go to Login
                                </a>
                            </div>
                        <?php elseif ($validToken): ?>
                            <!-- Reset Form -->
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-person-circle me-2"></i>
                                Resetting password for: <strong><?= htmlspecialchars($resetRequest['username']) ?></strong>
                            </div>
                            
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <?php foreach ($errors as $error): ?>
                                        <?= htmlspecialchars($error) ?><br>
                                    <?php endforeach; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="" id="resetForm" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="action" value="reset">
                                
                                <div class="form-floating mb-3 position-relative">
                                    <input type="password" 
                                           class="form-control" 
                                           id="password" 
                                           name="password" 
                                           placeholder="New Password"
                                           required
                                           autocomplete="new-password"
                                           style="padding-right: 45px;">
                                    <label for="password">
                                        <i class="bi bi-lock-fill me-1"></i>
                                        New Password
                                    </label>
                                    <button type="button" class="password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                                        <i class="bi bi-eye" id="toggleIcon1"></i>
                                    </button>
                                    <div class="password-strength" id="passwordStrength"></div>
                                </div>
                                
                                <div class="password-requirements mb-3">
                                    <div class="requirement" id="req-length">
                                        <i class="bi bi-circle"></i> At least 8 characters
                                    </div>
                                    <div class="requirement" id="req-uppercase">
                                        <i class="bi bi-circle"></i> One uppercase letter
                                    </div>
                                    <div class="requirement" id="req-lowercase">
                                        <i class="bi bi-circle"></i> One lowercase letter
                                    </div>
                                    <div class="requirement" id="req-number">
                                        <i class="bi bi-circle"></i> One number
                                    </div>
                                </div>
                                
                                <div class="form-floating mb-4 position-relative">
                                    <input type="password" 
                                           class="form-control" 
                                           id="confirm_password" 
                                           name="confirm_password" 
                                           placeholder="Confirm Password"
                                           required
                                           autocomplete="new-password"
                                           style="padding-right: 45px;">
                                    <label for="confirm_password">
                                        <i class="bi bi-lock-fill me-1"></i>
                                        Confirm Password
                                    </label>
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                                        <i class="bi bi-eye" id="toggleIcon2"></i>
                                    </button>
                                </div>
                                
                                <button type="submit" class="btn btn-reset w-100 mb-3">
                                    <i class="bi bi-shield-check me-2"></i>
                                    Reset Password
                                </button>
                                
                                <div class="text-center">
                                    <a href="login.php" class="link-primary">
                                        <i class="bi bi-arrow-left me-1"></i>
                                        Back to Login
                                    </a>
                                </div>
                            </form>
                        <?php else: ?>
                            <!-- Invalid Token -->
                            <div class="text-center">
                                <i class="bi bi-x-circle-fill text-danger" style="font-size: 4rem;"></i>
                                <h4 class="mt-3 mb-3">Invalid or Expired Link</h4>
                                <p class="text-muted mb-4">
                                    This password reset link is invalid or has expired. 
                                    Please request a new password reset link.
                                </p>
                                <a href="forgot-password.php" class="btn btn-reset w-100 mb-3">
                                    <i class="bi bi-arrow-clockwise me-2"></i>
                                    Request New Link
                                </a>
                                <div class="text-center">
                                    <a href="login.php" class="link-primary">
                                        <i class="bi bi-arrow-left me-1"></i>
                                        Back to Login
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password toggle functionality
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }

        // Password strength indicator
        document.getElementById('password')?.addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('passwordStrength');
            const reqLength = document.getElementById('req-length');
            const reqUppercase = document.getElementById('req-uppercase');
            const reqLowercase = document.getElementById('req-lowercase');
            const reqNumber = document.getElementById('req-number');

            // Reset classes and icons
            strengthBar.className = 'password-strength';
            [reqLength, reqUppercase, reqLowercase, reqNumber].forEach(req => {
                if (req) {
                    req.classList.remove('met');
                    const icon = req.querySelector('i');
                    icon.className = 'bi bi-circle';
                }
            });

            let strength = 0;
            
            // Check length
            if (password.length >= 8) {
                strength++;
                reqLength?.classList.add('met');
                const icon = reqLength?.querySelector('i');
                if (icon) icon.className = 'bi bi-check-circle-fill';
            }
            
            // Check uppercase
            if (/[A-Z]/.test(password)) {
                strength++;
                reqUppercase?.classList.add('met');
                const icon = reqUppercase?.querySelector('i');
                if (icon) icon.className = 'bi bi-check-circle-fill';
            }
            
            // Check lowercase
            if (/[a-z]/.test(password)) {
                strength++;
                reqLowercase?.classList.add('met');
                const icon = reqLowercase?.querySelector('i');
                if (icon) icon.className = 'bi bi-check-circle-fill';
            }
            
            // Check number
            if (/[0-9]/.test(password)) {
                strength++;
                reqNumber?.classList.add('met');
                const icon = reqNumber?.querySelector('i');
                if (icon) icon.className = 'bi bi-check-circle-fill';
            }

            // Set strength indicator
            if (strength === 0) {
                strengthBar.style.width = '0%';
            } else if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
            } else if (strength === 3) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        });

        // Form validation
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const submitBtn = this.querySelector('button[type="submit"]');

            // Basic validation
            if (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password)) {
                e.preventDefault();
                return;
            }

            if (password !== confirmPassword) {
                e.preventDefault();
                return;
            }

            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-danger, .alert-success');
            alerts.forEach(function(alert) {
                if (bootstrap.Alert.getOrCreateInstance) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
    </script>
</body>
</html>