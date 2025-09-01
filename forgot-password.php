<?php
// forgot-password.php - Password recovery system with PHPMailer and localhost fallback
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!file_exists('config.php')) {
    error_log('config.php not found in ' . __DIR__);
    die('Server configuration error. Please contact support.');
}
require 'config.php';

// Check if PHPMailer is available
$phpmailerAvailable = file_exists('vendor/autoload.php');
if ($phpmailerAvailable) {
    require 'vendor/autoload.php';
}

$errors = [];
$success = false;
$email = '';
$debugInfo = [];

// Configuration for localhost testing
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']) || 
               strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost:') === 0;

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request') {
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
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'];
                        $path = dirname($_SERVER['REQUEST_URI']);
                        $resetLink = "$protocol://$host$path/reset-password.php?token=" . urlencode($token);
                        
                        // Attempt to send email
                        $emailSent = false;
                        $emailError = '';
                        
                        if ($phpmailerAvailable && !$isLocalhost) {
                            // Try PHPMailer first
                            try {
                                $mail = new PHPMailer(true);
                                
                                // SMTP Configuration - Update these with your actual settings
                                $mail->isSMTP();
                                $mail->Host = 'smtp.hostinger.com'; // Change to your SMTP server
                                $mail->SMTPAuth = true;
                                $mail->Username = 'support@rixile.org'; // Change to your email
                                $mail->Password = '-----'; // Change to your app password
                                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                                $mail->Port = 587;
                                
                                // Recipients
                                $mail->setFrom('noreply@rixile.org', 'Rixile Support');
                                $mail->addAddress($user['email'], $user['username']);
                                $mail->addReplyTo('support@rixile.org', 'Rixile Support');
                                
                                // Content
                                $mail->isHTML(true);
                                $mail->Subject = 'Rixile Password Reset Request';
                                $mail->Body = generateEmailHTML($user['username'], $resetLink);
                                $mail->AltBody = generateEmailText($user['username'], $resetLink);
                                
                                $mail->send();
                                $emailSent = true;
                            } catch (Exception $e) {
                                $emailError = $mail->ErrorInfo;
                                error_log("PHPMailer failed: " . $emailError);
                            }
                        }
                        
                        // Fallback to PHP mail() function or localhost debug
                        if (!$emailSent) {
                            if ($isLocalhost) {
                                // For localhost testing - store reset link in session for debugging
                                $_SESSION['debug_reset_link'] = $resetLink;
                                $_SESSION['debug_user'] = $user['username'];
                                $debugInfo[] = "Reset link generated for testing: $resetLink";
                                $emailSent = true;
                            } else {
                                // Try PHP's built-in mail function as fallback
                                $subject = 'Rixile Password Reset Request';
                                $message = generateEmailText($user['username'], $resetLink);
                                $headers = [
                                    'From: noreply@rixile.org',
                                    'Reply-To: support@rixile.org',
                                    'X-Mailer: PHP/' . phpversion(),
                                    'Content-Type: text/plain; charset=UTF-8'
                                ];
                                
                                if (mail($user['email'], $subject, $message, implode("\r\n", $headers))) {
                                    $emailSent = true;
                                } else {
                                    error_log("PHP mail() function failed for {$user['email']}");
                                }
                            }
                        }
                        
                        if ($emailSent || $isLocalhost) {
                            $success = true;
                        } else {
                            error_log("All email methods failed for {$user['email']}");
                            // Don't reveal the failure to the user for security
                            $success = true;
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

function generateEmailHTML($username, $resetLink) {
    return "
        <html>
        <body style='font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; color: #333; line-height: 1.6;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #667eea;'>Password Reset Request</h2>
                <p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p>
                <p>We received a request to reset your password for your Rixile account. Click the button below to set a new password:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . htmlspecialchars($resetLink) . "' 
                       style='background: linear-gradient(135deg, #667eea, #764ba2); 
                              color: white; 
                              padding: 12px 30px; 
                              text-decoration: none; 
                              border-radius: 5px; 
                              font-weight: 600;
                              display: inline-block;'>
                        Reset My Password
                    </a>
                </div>
                <p>If the button doesn't work, copy and paste this link into your browser:</p>
                <p style='word-break: break-all; color: #667eea;'>" . htmlspecialchars($resetLink) . "</p>
                <p><strong>Important:</strong> This link will expire in 1 hour for security reasons.</p>
                <p>If you did not request a password reset, please ignore this email. Your account remains secure.</p>
                <hr style='margin: 30px 0; border: none; height: 1px; background: #eee;'>
                <p style='color: #666; font-size: 14px;'>
                    Best regards,<br>
                    The Rixile Team<br>
                    <a href='mailto:support@rixile.org' style='color: #667eea;'>support@rixile.org</a>
                </p>
            </div>
        </body>
        </html>
    ";
}

function generateEmailText($username, $resetLink) {
    return "Password Reset Request\n\n" .
           "Hello " . $username . ",\n\n" .
           "We received a request to reset your password for your Rixile account.\n\n" .
           "Click the link below to set a new password:\n" .
           $resetLink . "\n\n" .
           "This link will expire in 1 hour for security reasons.\n\n" .
           "If you did not request a password reset, please ignore this email. Your account remains secure.\n\n" .
           "Best regards,\n" .
           "The Rixile Team\n" .
           "support@rixile.org";
}

$csrfToken = generateCSRFToken();
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
            background: url('images/forgot-bg.jpg') no-repeat center center / cover;
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
        .debug-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .debug-info h6 {
            color: #856404;
            margin-bottom: 0.5rem;
        }
        .debug-link {
            word-break: break-all;
            background: white;
            padding: 0.5rem;
            border-radius: 5px;
            border: 1px solid #ffeaa7;
            margin-top: 0.5rem;
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
                                
                                <?php if ($isLocalhost && !empty($debugInfo)): ?>
                                    <div class="debug-info text-start">
                                        <h6><i class="bi bi-bug-fill me-2"></i>Development Mode - Debug Information</h6>
                                        <p><strong>User:</strong> <?= htmlspecialchars($_SESSION['debug_user'] ?? 'Unknown') ?></p>
                                        <p><strong>Reset Link:</strong></p>
                                        <div class="debug-link">
                                            <a href="<?= htmlspecialchars($_SESSION['debug_reset_link'] ?? '#') ?>" 
                                               target="_blank" class="text-decoration-none">
                                                <?= htmlspecialchars($_SESSION['debug_reset_link'] ?? '') ?>
                                            </a>
                                        </div>
                                        <small class="text-muted d-block mt-2">
                                            <i class="bi bi-info-circle me-1"></i>
                                            This debug information is only shown on localhost
                                        </small>
                                    </div>
                                <?php endif; ?>
                                
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
                            
                            <?php if ($isLocalhost): ?>
                                <div class="debug-info">
                                    <h6><i class="bi bi-laptop me-2"></i>Development Mode Active</h6>
                                    <p class="mb-1">You're running on localhost. Reset links will be displayed on this page for testing.</p>
                                    <small>PHPMailer Available: <?= $phpmailerAvailable ? 'Yes' : 'No' ?></small>
                                </div>
                            <?php endif; ?>
                            
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