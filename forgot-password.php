<?php
// forgot-password.php - Password recovery system with PHPMailer and debugging
// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if config.php exists
if (!file_exists('config.php')) {
    error_log('config.php not found in ' . __DIR__);
    die('Server configuration error. Please contact support.');
}
require 'config.php';

// Check if vendor/autoload.php exists
if (!file_exists('vendor/autoload.php')) {
    error_log('vendor/autoload.php not found in ' . __DIR__);
    die('Server configuration error. Please contact support.');
}
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$errors = [];
$success = false;
$email = '';

// Check if required functions exist
if (!function_exists('generateCSRFToken') || !function_exists('validateCSRFToken')) {
    error_log('CSRF functions missing in config.php');
    $errors[] = 'Server configuration error. Please try again later.';
}

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request' && empty($errors)) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $errors[] = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            try {
                // Check rate limiting (max 3 requests per hour per email)
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM password_resets 
                    WHERE user_id = (SELECT id FROM users WHERE email = ? LIMIT 1) 
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ");
                $stmt->execute([$email]);
                $recentRequests = $stmt->fetchColumn();
                
                if ($recentRequests >= 3) {
                    $errors[] = 'Too many password reset requests. Please try again later.';
                } else {
                    // Look up user by email
                    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ? AND status = 'active'");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        // Generate secure token
                        $token = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $token);
                        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
                        
                        // Invalidate any existing tokens for this user
                        $stmt = $pdo->prepare("UPDATE password_resets SET used = TRUE WHERE user_id = ? AND used = FALSE");
                        $stmt->execute([$user['id']]);
                        
                        // Store token in database
                        $stmt = $pdo->prepare("
                            INSERT INTO password_resets (user_id, token_hash, expires_at) 
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$user['id'], $tokenHash, $expiresAt]);
                        
                        // Create reset link
                        $resetLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") 
                            . "://$_SERVER[HTTP_HOST]" 
                            . dirname($_SERVER['REQUEST_URI']) 
                            . "/reset-password.php?token=" . $token;
                        
                        // Send email using PHPMailer
                        $mail = new PHPMailer(true);
                        try {
                            // Server settings (replace with your SMTP details)
                            $mail->isSMTP();
                            $mail->Host = 'smtp.example.com'; // e.g., smtp.gmail.com
                            $mail->SMTPAuth = true;
                            $mail->Username = 'support@rixile.org'; // Replace with your SMTP username
                            $mail->Password = 'your_smtp_password'; // Replace with your SMTP password
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port = 587; // e.g., 587 for TLS, 465 for SSL
                            
                            // Sender and recipient
                            $mail->setFrom('support@rixile.org', 'Rixile Support');
                            $mail->addReplyTo('support@rixile.org', 'Rixile Support');
                            $mail->addAddress($user['email'], $user['username']);
                            
                            // Content
                            $mail->isHTML(true);
                            $mail->Subject = 'Rixile Password Reset Request';
                            $mail->Body = "
                                <html>
                                <body style='font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; color: #333;'>
                                    <h2>Password Reset Request</h2>
                                    <p>Hello {$user['username']},</p>
                                    <p>We received a request to reset your password. Click the button below to set a new password:</p>
                                    <p style='margin: 20px 0;'>
                                        <a href='$resetLink' style='background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: 600;'>Reset Password</a>
                                    </p>
                                    <p>This link will expire in 1 hour. If you did not request a reset, please ignore this email.</p>
                                    <p>Best regards,<br>Rixile Team</p>
                                </body>
                                </html>
                            ";
                            $mail->AltBody = "Hello {$user['username']},\n\nWe received a request to reset your password. Click the link below to set a new password:\n$resetLink\n\nThis link will expire in 1 hour. If you did not request a reset, please ignore this email.\n\nBest regards,\nRixile Team";
                            
                            $mail->send();
                            $success = true;
                        } catch (Exception $e) {
                            error_log("Failed to send reset email to {$user['email']}: {$mail->ErrorInfo}");
                            $success = true; // Don't reveal email failure to user
                        }
                    } else {
                        // Don't reveal if email exists for security
                        $success = true;
                    }
                }
            } catch (Exception $e) {
                error_log("Database error in forgot-password.php: " . $e->getMessage());
                $errors[] = 'A server error occurred. Please try again later.';
            }
        }
    }
}

$csrfToken = empty($errors) && function_exists('generateCSRFToken') ? generateCSRFToken() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Rixile</title>
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
            max-width: 450px;
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
        .info-box {
            background: #e9ecef;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.95rem;
            color: #495057;
        }
        .small {
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6 col-lg-5">
                <div class="reset-container">
                    <div class="reset-header">
                        <i class="bi bi-shield-lock-fill" style="font-size: 3rem;"></i>
                        <h2 class="mt-3">Forgot Password</h2>
                        <p>Enter your email to receive a password reset link</p>
                    </div>
                    <div class="reset-body">
                        <?php if ($success): ?>
                            <!-- Success Message -->
                            <div class="text-center">
                                <i class="bi bi-check-circle-fill success-icon mb-3"></i>
                                <h4 class="mb-3">Reset Link Sent!</h4>
                                <p class="text-muted mb-4">
                                    If an account exists with the provided email, a password reset link has been sent. Please check your inbox (and spam folder).
                                </p>
                                <div class="mt-4">
                                    <a href="login.php" class="btn btn-reset w-100">
                                        <i class="bi bi-arrow-left me-2"></i>
                                        Back to Login
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Request Form -->
                            <div class="info-box">
                                <i class="bi bi-info-circle-fill me-2"></i>
                                Enter the email address associated with your account and we'll send you a link to reset your password.
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
                                <input type="hidden" name="action" value="request">
                                
                                <div class="form-floating mb-4">
                                    <input type="email" 
                                           class="form-control" 
                                           id="email" 
                                           name="email" 
                                           placeholder="your@email.com"
                                           value="<?= htmlspecialchars($email) ?>"
                                           required
                                           autocomplete="email">
                                    <label for="email">
                                        <i class="bi bi-envelope-fill me-1"></i>
                                        Email Address
                                    </label>
                                    <div class="invalid-feedback">
                                        Please enter a valid email address.
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-reset w-100 mb-3">
                                    <i class="bi bi-send-fill me-2"></i>
                                    Send Reset Link
                                </button>
                                
                                <div class="text-center">
                                    <p class="mb-2">Remember your password?</p>
                                    <a href="login.php" class="link-primary">
                                        <i class="bi bi-box-arrow-in-right me-1"></i>
                                        Back to Login
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <div class="text-white">
                        <p class="mb-2">
                            <i class="bi bi-shield-check me-2"></i>
                            Secure Password Recovery
                        </p>
                        <small>
                            Reset links expire after 1 hour • Maximum 3 requests per hour
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const email = document.getElementById('email');
            const emailValue = email.value.trim();
            
            if (!emailValue) {
                e.preventDefault();
                email.classList.add('is-invalid');
                return false;
            }
            
            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(emailValue)) {
                e.preventDefault();
                email.classList.add('is-invalid');
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
        });
        
        // Remove invalid class on input
        document.getElementById('email')?.addEventListener('input', function() {
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