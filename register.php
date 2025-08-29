<?php
// register.php - User registration page with enhanced validation
require 'config.php';

// Initialize variables
$errors = [];
$old_values = [];

if ($_POST) {
    // Sanitize and validate inputs
    $username     = trim($_POST['username'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $sponsor      = trim($_POST['sponsor_name'] ?? '');
    $uplineUser   = trim($_POST['upline_username'] ?? '');
    $position     = $_POST['position'] ?? '';

    // Store old values for form repopulation (excluding passwords)
    $old_values = [
        'username' => $username,
        'sponsor_name' => $sponsor,
        'upline_username' => $uplineUser,
        'position' => $position
    ];

    // Validate username
    if (empty($username)) {
        $errors[] = 'Username is required.';
    } elseif (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters long.';
    } elseif (strlen($username) > 30) {
        $errors[] = 'Username must not exceed 30 characters.';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, underscores, and hyphens.';
    } else {
        // Check if username already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $errors[] = 'Username already exists. Please choose a different one.';
        }
    }

    // Validate password
    if (empty($password)) {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    } elseif (strlen($password) > 255) {
        $errors[] = 'Password is too long.';
    }

    // Validate password confirmation
    if (empty($confirmPassword)) {
        $errors[] = 'Password confirmation is required.';
    } elseif ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    // Validate sponsor
    $sponsorId = null;
    if (empty($sponsor)) {
        $errors[] = 'Sponsor name is required.';
    } elseif (strlen($sponsor) > 30) {
        $errors[] = 'Sponsor name is too long.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$sponsor]);
        $sponsorRow = $stmt->fetch();
        if (!$sponsorRow) {
            $errors[] = 'Sponsor username not found.';
        } else {
            $sponsorId = (int)$sponsorRow['id'];
        }
    }

    // Validate upline user
    $uplineId = null;
    if (empty($uplineUser)) {
        $errors[] = 'Upline username is required.';
    } elseif (strlen($uplineUser) > 30) {
        $errors[] = 'Upline username is too long.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$uplineUser]);
        $uplineRow = $stmt->fetch();
        if (!$uplineRow) {
            $errors[] = 'Upline username not found.';
        } else {
            $uplineId = (int)$uplineRow['id'];
            
            // Check if upline user is the same as the new user
            if (strtolower($uplineUser) === strtolower($username)) {
                $errors[] = 'You cannot be your own upline.';
            }
        }
    }

    // Validate position
    if (empty($position)) {
        $errors[] = 'Position selection is required.';
    } elseif (!in_array($position, ['left', 'right'], true)) {
        $errors[] = 'Invalid position selected.';
    } elseif ($uplineId !== null) {
        // Check if chosen position is available
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE upline_id = ? AND position = ?");
        $stmt->execute([$uplineId, $position]);
        if ($stmt->fetch()) {
            $errors[] = 'The chosen position (' . ucfirst($position) . ') is already occupied for this upline.';
        }
    }

    // If no errors, proceed with registration
    if (empty($errors) && $sponsorId !== null && $uplineId !== null) {
        try {
            // Create user with secure password hash
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, sponsor_id, upline_id, position) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$username, $hash, $sponsorId, $uplineId, $position]);
            $uid = $pdo->lastInsertId();

            // Create wallet for the new user
            $stmt = $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)");
            $stmt->execute([$uid]);

            redirect('login.php', 'Registration successful! Please log in with your credentials.');

        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $errors[] = 'Registration failed due to a system error. Please try again.';
        }
    }
}

// Helper function to get old form value
function old($field, $default = '') {
    global $old_values;
    return isset($old_values[$field]) ? htmlspecialchars($old_values[$field], ENT_QUOTES, 'UTF-8') : $default;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Binary MLM System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .password-strength {
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        .strength-weak { color: #dc3545; }
        .strength-medium { color: #ffc107; }
        .strength-strong { color: #198754; }
    </style>
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:600px">
    <div class="card shadow">
        <div class="card-body">
            <h2 class="card-title mb-4 text-center">Create Your Account</h2>
            
            <!-- Display flash message -->
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="alert alert-warning alert-dismissible fade show">
                    <?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>

            <!-- Display validation errors -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong>Please correct the following errors:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" 
                               id="username" name="username" value="<?= old('username') ?>" required
                               pattern="[a-zA-Z0-9_-]+" minlength="3" maxlength="30"
                               title="Only letters, numbers, underscores, and hyphens allowed">
                        <div class="form-text">3-30 characters, letters, numbers, underscore, hyphen only</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="sponsor_name" class="form-label">Sponsor Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="sponsor_name" name="sponsor_name" 
                               value="<?= old('sponsor_name') ?>" required maxlength="30">
                        <div class="form-text">Username of your sponsor</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" 
                               required minlength="6" maxlength="255">
                        <div id="passwordStrength" class="password-strength"></div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               required minlength="6" maxlength="255">
                        <div id="passwordMatch" class="form-text"></div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="upline_username" class="form-label">Upline Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="upline_username" name="upline_username" 
                               value="<?= old('upline_username') ?>" required maxlength="30">
                        <div class="form-text">Username of your direct upline</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="position" class="form-label">Position <span class="text-danger">*</span></label>
                        <select class="form-select" id="position" name="position" required>
                            <option value="">Select Position</option>
                            <option value="left" <?= old('position') === 'left' ? 'selected' : '' ?>>Left Side</option>
                            <option value="right" <?= old('position') === 'right' ? 'selected' : '' ?>>Right Side</option>
                        </select>
                        <div class="form-text">Choose your position under the upline</div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-success btn-lg">Create Account</button>
                    <a href="index.php" class="btn btn-outline-secondary">Back to Home</a>
                </div>
            </form>
        </div>
    </div>

    <div class="text-center mt-4">
        <p>Already have an account? <a href="login.php">Login here</a></p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Password strength checker
document.getElementById('password').addEventListener('input', function() {
    const password = this.value;
    const strengthDiv = document.getElementById('passwordStrength');
    
    if (password.length === 0) {
        strengthDiv.textContent = '';
        return;
    }
    
    let strength = 0;
    let feedback = [];
    
    // Length check
    if (password.length >= 8) strength++;
    else feedback.push('at least 8 characters');
    
    // Character variety checks
    if (/[a-z]/.test(password)) strength++;
    else feedback.push('lowercase letter');
    
    if (/[A-Z]/.test(password)) strength++;
    else feedback.push('uppercase letter');
    
    if (/\d/.test(password)) strength++;
    else feedback.push('number');
    
    if (/[^a-zA-Z\d]/.test(password)) strength++;
    else feedback.push('special character');
    
    // Display strength
    if (strength < 2) {
        strengthDiv.className = 'password-strength strength-weak';
        strengthDiv.textContent = 'Weak password. Add: ' + feedback.slice(0, 2).join(', ');
    } else if (strength < 4) {
        strengthDiv.className = 'password-strength strength-medium';
        strengthDiv.textContent = 'Medium strength. Consider adding: ' + feedback.slice(0, 1).join(', ');
    } else {
        strengthDiv.className = 'password-strength strength-strong';
        strengthDiv.textContent = 'Strong password!';
    }
});

// Password confirmation checker
document.getElementById('confirm_password').addEventListener('input', function() {
    const password = document.getElementById('password').value;
    const confirmPassword = this.value;
    const matchDiv = document.getElementById('passwordMatch');
    
    if (confirmPassword.length === 0) {
        matchDiv.textContent = '';
        matchDiv.className = 'form-text';
        return;
    }
    
    if (password === confirmPassword) {
        matchDiv.textContent = 'Passwords match!';
        matchDiv.className = 'form-text text-success';
    } else {
        matchDiv.textContent = 'Passwords do not match';
        matchDiv.className = 'form-text text-danger';
    }
});

// Username validation
document.getElementById('username').addEventListener('input', function() {
    const username = this.value;
    if (username && !/^[a-zA-Z0-9_-]+$/.test(username)) {
        this.setCustomValidity('Username can only contain letters, numbers, underscores, and hyphens');
    } else {
        this.setCustomValidity('');
    }
});
</script>
</body>
</html>