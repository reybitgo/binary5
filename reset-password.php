<?php
// reset-password.php - Handle password reset with token
require 'config.php';

$errors = [];
$success = false;
$validToken = false;
$token = $_GET['token'] ?? $_POST['token'] ?? '';

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
                        
                        // Log the password reset
                        $stmt = $pdo->prepare("
                            INSERT INTO login_logs (user_id, ip_address, user_agent, status, created_at)
                            VALUES (?, ?, ?, 'password_reset', NOW())
                        ");
                        $stmt->execute([$resetRequest['user_id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
                        
                        $pdo->commit();
                        $success = true;
                        
                        // Set success message for login page
                        $_SESSION['flash'] = 'Your password has been successfully reset. Please login with your new password.';
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log('Password reset error: ' . $e->getMessage());
                        $errors[] = 'An error occurred. Please try again.';
                    }
                }
            }
        }
    } else {
        $errors[] = 'This password reset link is invalid or has expired.';
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
    <link href="styles.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        /* Override specific styles with blue/green scheme */
        body {
            background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(34, 197, 94) 100%);
        }
        .reset-header {
            background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(34, 197, 94) 100%);
        }
        .form-floating > .form-control:focus ~ label {
            color: rgb(59, 130, 246);
        }
        .form-control:focus {
            border-color: rgb(59, 130, 246);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }
        .password-toggle:hover {
            color: rgb(59, 130, 246);
        }
        .btn-reset {
            background: linear-gradient(135deg, rgb(59, 130, 246) 0%, rgb(34, 197, 94) 100%);
        }
        .btn-reset:hover {
            box-shadow: 0 5px 20px rgba(59, 130, 246, 0.4);
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
                                           autocomplete="new-password">
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
                                           autocomplete="new-password">
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
                                    <a href="login.php" class="text-muted text-decoration-none">
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
                                    <a href="login.php" class="text-muted text-decoration-none">
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

            // Reset classes
            strengthBar.className = 'password-strength';
            reqLength.classList.remove('met');
            reqUppercase.classList.remove('met');
            reqLowercase.classList.remove('met');
            reqNumber.classList.remove('met');

            let strength = 0;
            if (password.length >= 8) {
                strength++;
                reqLength.classList.add('met');
                reqLength.querySelector('i').classList.replace('bi-circle', 'bi-check-circle-fill');
            }
            if (/[A-Z]/.test(password)) {
                strength++;
                reqUppercase.classList.add('met');
                reqUppercase.querySelector('i').classList.replace('bi-circle', 'bi-check-circle-fill');
            }
            if (/[a-z]/.test(password)) {
                strength++;
                reqLowercase.classList.add('met');
                reqLowercase.querySelector('i').classList.replace('bi-circle', 'bi-check-circle-fill');
            }
            if (/[0-9]/.test(password)) {
                strength++;
                reqNumber.classList.add('met');
                reqNumber.querySelector('i').classList.replace('bi-circle', 'bi-check-circle-fill');
            }

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

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
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