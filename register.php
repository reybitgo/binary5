<?php
// register.php - User registration page with referral link support and auto-placement
require 'config.php';

// Initialize variables
$errors = [];
$old_values = [];
$isLeaderRegistration = false;
$leaderKey = null;
$referralSponsor = null;
$autoUpline = null;
$autoPosition = null;

// Check for leader key in URL
if (isset($_GET['leaderkey'])) {
    $leaderKey = trim($_GET['leaderkey']);
    if (!empty($leaderKey)) {
        // Verify leader key exists and hasn't been used
        $keyFile = 'logs/leader_keys.log';
        if (file_exists($keyFile)) {
            $keys = file($keyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($keys as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 3 && $parts[1] === $leaderKey && $parts[2] === 'unused') {
                    $isLeaderRegistration = true;
                    break;
                }
            }
        }
        if (!$isLeaderRegistration) {
            redirect('register.php', 'Invalid or already used leader key.');
        }
    }
}

// Check for referral link (ref parameter)
if (isset($_GET['ref']) && !empty($_GET['ref'])) {
    $referralId = filter_var($_GET['ref'], FILTER_VALIDATE_INT);
    
    if ($referralId && $referralId > 0) {
        try {
            // Get sponsor information
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ? AND status = 'active'");
            $stmt->execute([$referralId]);
            $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sponsor) {
                $referralSponsor = $sponsor;
                
                // Find best placement under this sponsor using binary tree logic
                $placement = findBestPlacement($referralId, $pdo);
                if ($placement) {
                    $autoUpline = $placement['upline_username'];
                    $autoPosition = $placement['position'];
                }
            }
        } catch (PDOException $e) {
            error_log("Referral processing error: " . $e->getMessage());
        }
    }
}

/**
 * Find the best available placement in the binary tree
 * Uses breadth-first search to find the first available position
 */
function findBestPlacement($sponsorId, $pdo) {
    try {
        // Start with the sponsor as potential upline
        $queue = [$sponsorId];
        $visited = [];
        $maxDepth = 10; // Prevent infinite loops
        $currentDepth = 0;
        
        while (!empty($queue) && $currentDepth < $maxDepth) {
            $levelSize = count($queue);
            
            for ($i = 0; $i < $levelSize; $i++) {
                $currentUserId = array_shift($queue);
                
                if (in_array($currentUserId, $visited)) continue;
                $visited[] = $currentUserId;
                
                // Check if this user has available positions
                $stmt = $pdo->prepare("
                    SELECT username,
                           (SELECT COUNT(*) FROM users WHERE upline_id = ? AND position = 'left') as left_count,
                           (SELECT COUNT(*) FROM users WHERE upline_id = ? AND position = 'right') as right_count
                    FROM users WHERE id = ?
                ");
                $stmt->execute([$currentUserId, $currentUserId, $currentUserId]);
                $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($userInfo) {
                    // Check for available position (left first, then right)
                    if ($userInfo['left_count'] == 0) {
                        return [
                            'upline_id' => $currentUserId,
                            'upline_username' => $userInfo['username'],
                            'position' => 'left'
                        ];
                    } elseif ($userInfo['right_count'] == 0) {
                        return [
                            'upline_id' => $currentUserId,
                            'upline_username' => $userInfo['username'],
                            'position' => 'right'
                        ];
                    }
                    
                    // Add children to queue for next level
                    $childStmt = $pdo->prepare("SELECT id FROM users WHERE upline_id = ?");
                    $childStmt->execute([$currentUserId]);
                    while ($child = $childStmt->fetch(PDO::FETCH_ASSOC)) {
                        $queue[] = $child['id'];
                    }
                }
            }
            $currentDepth++;
        }
        
        // If no placement found, default to under sponsor
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$sponsorId]);
        $sponsorUsername = $stmt->fetchColumn();
        
        return [
            'upline_id' => $sponsorId,
            'upline_username' => $sponsorUsername,
            'position' => 'left' // Default to left if no better placement found
        ];
        
    } catch (PDOException $e) {
        error_log("Placement finding error: " . $e->getMessage());
        return null;
    }
}

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
            $pdo->beginTransaction();
            
            // Determine user status based on leader registration
            $userStatus = $isLeaderRegistration ? 'active' : 'inactive';
            
            // Create user with secure password hash
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (username, password, sponsor_id, upline_id, position, status) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$username, $hash, $sponsorId, $uplineId, $position, $userStatus]);
            $newUserId = $pdo->lastInsertId();

            // Create wallet for the new user
            $stmt = $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)");
            $stmt->execute([$newUserId]);

            // Update upline's position counts
            $stmt = $pdo->prepare("
                UPDATE users 
                SET " . ($position === 'left' ? 'left_count' : 'right_count') . " = " . ($position === 'left' ? 'left_count' : 'right_count') . " + 1
                WHERE id = ?
            ");
            $stmt->execute([$uplineId]);

            // If leader registration, mark the key as used
            if ($isLeaderRegistration && !empty($leaderKey)) {
                $keyFile = 'logs/leader_keys.log';
                if (file_exists($keyFile)) {
                    $keys = file($keyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $updatedKeys = [];
                    foreach ($keys as $line) {
                        $parts = explode('|', $line);
                        if (count($parts) >= 3 && $parts[1] === $leaderKey && $parts[2] === 'unused') {
                            $parts[2] = 'used';
                            $parts[3] = date('Y-m-d H:i:s');
                            $parts[4] = $username;
                        }
                        $updatedKeys[] = implode('|', $parts);
                    }
                    file_put_contents($keyFile, implode("\n", $updatedKeys) . "\n");
                }
            }

            $pdo->commit();
            
            $message = $isLeaderRegistration ? 
                'Registration successful! Your account is active. Please log in with your credentials.' :
                'Registration successful! Your account is inactive until you purchase a package. Please log in to continue.';
            
            redirect('login.php', $message);

        } catch (PDOException $e) {
            $pdo->rollBack();
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

// Set default values for referral registration
$defaultSponsor = $referralSponsor ? $referralSponsor['username'] : '';
$defaultUpline = $autoUpline ?? '';
$defaultPosition = $autoPosition ?? '';
$isReferralRegistration = !empty($referralSponsor);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Shoppe Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background: url('images/register-bg.jpg') no-repeat center center / cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .password-strength {
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        .strength-weak { color: #dc3545; }
        .strength-medium { color: #ffc107; }
        .strength-strong { color: #198754; }
        .leader-badge {
            background: linear-gradient(45deg, #ffd700, #ffed4e);
            color: #333;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: bold;
            margin-bottom: 1rem;
            display: inline-block;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .referral-badge {
            background: linear-gradient(45deg, #10b981, #34d399);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: bold;
            margin-bottom: 1rem;
            display: inline-block;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .auto-filled {
            background-color: #f0f9ff;
            border-color: #0ea5e9;
        }
        .placement-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .password-toggle {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            z-index: 5;
            background: transparent;
            border: none;
            color: #3b82f6;
        }
        .password-wrapper { position: relative; }
    </style>
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:700px">
    <div class="card shadow">
        <div class="card-body">
            <?php if ($isLeaderRegistration): ?>
                <div class="leader-badge mb-3">
                    ⭐ Leader Registration - Account will be activated immediately
                </div>
            <?php endif; ?>
            
            <?php if ($isReferralRegistration): ?>
                <div class="referral-badge mb-3">
                    🎯 Referral Registration - Referred by <?= htmlspecialchars($referralSponsor['username']) ?>
                </div>
            <?php endif; ?>
            
            <h2 class="card-title mb-4 text-center">Create Your Account</h2>
            
            <!-- Auto-placement info -->
            <?php if ($autoUpline && $autoPosition): ?>
                <div class="placement-info">
                    <h6 class="mb-2">🎯 Automatic Placement Selected</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Your Upline:</small>
                            <div class="fw-bold text-primary"><?= htmlspecialchars($autoUpline) ?></div>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Your Position:</small>
                            <div class="fw-bold text-success"><?= ucfirst($autoPosition) ?> Side</div>
                        </div>
                    </div>
                    <small class="text-muted">This is the best available position in your sponsor's network.</small>
                </div>
            <?php endif; ?>
            
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
                <?php if ($isLeaderRegistration): ?>
                    <input type="hidden" name="leader_key" value="<?= htmlspecialchars($leaderKey, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                
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
                        <label for="sponsor_name" class="form-label">
                            Sponsor Username <span class="text-danger">*</span>
                            <?php if ($isReferralRegistration): ?>
                                <span class="badge bg-success ms-2">Auto-filled</span>
                            <?php endif; ?>
                        </label>
                        <input type="text" class="form-control <?= $isReferralRegistration ? 'auto-filled' : '' ?>" 
                               id="sponsor_name" name="sponsor_name" 
                               value="<?= old('sponsor_name', $defaultSponsor) ?>" 
                               <?= $isReferralRegistration ? 'readonly' : '' ?>
                               required maxlength="30">
                        <div class="form-text">
                            <?= $isReferralRegistration ? 'Pre-filled from referral link' : 'Username of your sponsor' ?>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Password -->
                    <div class="col-md-6 mb-3">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" id="password" name="password"
                                required minlength="6" maxlength="255">
                            <button type="button" class="password-toggle" onclick="togglePwd('password','icon1')">
                                <i class="bi bi-eye" id="icon1"></i>
                            </button>
                        </div>
                        <div id="passwordStrength" class="password-strength"></div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="col-md-6 mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                required minlength="6" maxlength="255">
                            <button type="button" class="password-toggle" onclick="togglePwd('confirm_password','icon2')">
                                <i class="bi bi-eye" id="icon2"></i>
                            </button>
                        </div>
                        <div id="passwordMatch" class="form-text"></div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="upline_username" class="form-label">
                            Upline Username <span class="text-danger">*</span>
                            <?php if ($autoUpline): ?>
                                <span class="badge bg-info ms-2">Auto-selected</span>
                            <?php endif; ?>
                        </label>
                        <input type="text" class="form-control <?= $autoUpline ? 'auto-filled' : '' ?>" 
                               id="upline_username" name="upline_username" 
                               value="<?= old('upline_username', $defaultUpline) ?>" 
                               <?= $autoUpline ? 'readonly' : '' ?>
                               required maxlength="30">
                        <div class="form-text">
                            <?= $autoUpline ? 'Best available upline selected automatically' : 'Username of your direct upline' ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="position" class="form-label">
                            Position <span class="text-danger">*</span>
                            <?php if ($autoPosition): ?>
                                <span class="badge bg-info ms-2">Auto-selected</span>
                            <?php endif; ?>
                        </label>
                        <select class="form-select <?= $autoPosition ? 'auto-filled' : '' ?>" 
                                id="position" name="position" 
                                <?= $autoPosition ? 'readonly disabled' : '' ?>
                                required>
                            <option value="">Select Position</option>
                            <option value="left" <?= old('position', $defaultPosition) === 'left' ? 'selected' : '' ?>>Left Side</option>
                            <option value="right" <?= old('position', $defaultPosition) === 'right' ? 'selected' : '' ?>>Right Side</option>
                        </select>
                        <?php if ($autoPosition): ?>
                            <input type="hidden" name="position" value="<?= htmlspecialchars($defaultPosition) ?>">
                        <?php endif; ?>
                        <div class="form-text">
                            <?= $autoPosition ? 'Best available position selected automatically' : 'Choose your position under the upline' ?>
                        </div>
                    </div>
                </div>

                <!-- Manual override option for auto-filled fields -->
                <?php if ($autoUpline || $autoPosition): ?>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="manualPlacement">
                            <label class="form-check-label" for="manualPlacement">
                                I want to manually choose my upline and position
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-success btn-lg">
                        <?= $isLeaderRegistration ? 'Create Leader Account' : 'Create Account' ?>
                    </button>
                    <a href="<?= $isReferralRegistration ? 'index.php' : 'index.php' ?>" class="btn btn-outline-secondary">
                        Back to Home
                    </a>
                </div>
            </form>

            <div class="text-center mt-4">
                <p>Already have an account? <a href="login.php" class="fw-bold">Login here</a></p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Manual placement override
<?php if ($autoUpline || $autoPosition): ?>
document.getElementById('manualPlacement').addEventListener('change', function() {
    const uplineInput = document.getElementById('upline_username');
    const positionSelect = document.getElementById('position');
    const hiddenPosition = document.querySelector('input[name="position"][type="hidden"]');
    
    if (this.checked) {
        // Enable manual selection
        uplineInput.readOnly = false;
        uplineInput.classList.remove('auto-filled');
        uplineInput.value = '';
        
        positionSelect.disabled = false;
        positionSelect.classList.remove('auto-filled');
        positionSelect.value = '';
        
        if (hiddenPosition) {
            hiddenPosition.remove();
        }
    } else {
        // Restore auto-selection
        uplineInput.readOnly = true;
        uplineInput.classList.add('auto-filled');
        uplineInput.value = '<?= htmlspecialchars($defaultUpline) ?>';
        
        positionSelect.disabled = true;
        positionSelect.classList.add('auto-filled');
        positionSelect.value = '<?= htmlspecialchars($defaultPosition) ?>';
        
        if (!hiddenPosition) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'position';
            hidden.value = '<?= htmlspecialchars($defaultPosition) ?>';
            positionSelect.parentNode.appendChild(hidden);
        }
    }
});
<?php endif; ?>

// Check upline availability when manually entered
document.getElementById('upline_username').addEventListener('blur', function() {
    const uplineUsername = this.value.trim();
    const positionSelect = document.getElementById('position');
    
    if (uplineUsername && !this.readOnly) {
        // Check available positions for this upline
        fetch('ajax/check_upline_positions.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'upline_username=' + encodeURIComponent(uplineUsername)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update position options based on availability
                const leftOption = positionSelect.querySelector('option[value="left"]');
                const rightOption = positionSelect.querySelector('option[value="right"]');
                
                leftOption.disabled = !data.positions.left_available;
                rightOption.disabled = !data.positions.right_available;
                
                leftOption.textContent = data.positions.left_available ? 'Left Side (Available)' : 'Left Side (Occupied)';
                rightOption.textContent = data.positions.right_available ? 'Right Side (Available)' : 'Right Side (Occupied)';
                
                // Auto-select available position if only one is available
                if (data.positions.left_available && !data.positions.right_available) {
                    positionSelect.value = 'left';
                } else if (!data.positions.left_available && data.positions.right_available) {
                    positionSelect.value = 'right';
                }
            }
        })
        .catch(error => {
            console.log('Position check failed:', error);
        });
    }
});

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

// Real-time username availability check
let usernameTimeout;
document.getElementById('username').addEventListener('input', function() {
    const username = this.value.trim();
    const usernameGroup = this.parentNode;
    
    // Remove any existing feedback
    const existingFeedback = usernameGroup.querySelector('.username-feedback');
    if (existingFeedback) {
        existingFeedback.remove();
    }
    
    if (username.length >= 3) {
        clearTimeout(usernameTimeout);
        usernameTimeout = setTimeout(() => {
            checkUsernameAvailability(username, usernameGroup);
        }, 500);
    }
});

function checkUsernameAvailability(username, container) {
    // Create a simple AJAX request to check username
    const feedback = document.createElement('div');
    feedback.className = 'username-feedback form-text';
    feedback.innerHTML = '<span class="text-muted">Checking availability...</span>';
    container.appendChild(feedback);
    
    // You would implement this endpoint to check username availability
    fetch('ajax/check_username.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'username=' + encodeURIComponent(username)
    })
    .then(response => response.json())
    .then(data => {
        if (data.available) {
            feedback.innerHTML = '<span class="text-success">✓ Username available</span>';
        } else {
            feedback.innerHTML = '<span class="text-danger">✗ Username already taken</span>';
        }
    })
    .catch(error => {
        feedback.innerHTML = '<span class="text-muted">Could not check availability</span>';
    });
}

// Form validation before submit
document.querySelector('form').addEventListener('submit', function(e) {
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const sponsor = document.getElementById('sponsor_name').value.trim();
    const upline = document.getElementById('upline_username').value.trim();
    const position = document.getElementById('position').value;
    
    let hasErrors = false;
    
    // Clear previous custom errors
    document.querySelectorAll('.custom-error').forEach(el => el.remove());
    
    // Username validation
    if (username.length < 3) {
        showFieldError('username', 'Username must be at least 3 characters');
        hasErrors = true;
    }
    
    // Password validation
    if (password.length < 6) {
        showFieldError('password', 'Password must be at least 6 characters');
        hasErrors = true;
    }
    
    // Password match validation
    if (password !== confirmPassword) {
        showFieldError('confirm_password', 'Passwords do not match');
        hasErrors = true;
    }
    
    // Required fields validation
    if (!sponsor) {
        showFieldError('sponsor_name', 'Sponsor username is required');
        hasErrors = true;
    }
    
    if (!upline) {
        showFieldError('upline_username', 'Upline username is required');
        hasErrors = true;
    }
    
    if (!position) {
        showFieldError('position', 'Position selection is required');
        hasErrors = true;
    }
    
    if (hasErrors) {
        e.preventDefault();
        // Scroll to first error
        const firstError = document.querySelector('.custom-error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
});

function showFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    const container = field.parentNode;
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'custom-error text-danger small mt-1';
    errorDiv.textContent = message;
    
    container.appendChild(errorDiv);
    field.classList.add('is-invalid');
}

// Clear custom errors when user starts typing
document.querySelectorAll('input, select').forEach(field => {
    field.addEventListener('input', function() {
        this.classList.remove('is-invalid');
        const container = this.parentNode;
        const customError = container.querySelector('.custom-error');
        if (customError) {
            customError.remove();
        }
    });
});

// Show helpful tooltips for auto-filled fields
<?php if ($isReferralRegistration): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Add tooltip to sponsor field
    const sponsorField = document.getElementById('sponsor_name');
    sponsorField.title = 'This field is pre-filled from your referral link';
    
    <?php if ($autoUpline): ?>
    // Add tooltip to upline field
    const uplineField = document.getElementById('upline_username');
    uplineField.title = 'Best available position found automatically';
    
    // Add tooltip to position field
    const positionField = document.getElementById('position');
    positionField.title = 'Optimal position selected for fastest growth';
    <?php endif; ?>
});
<?php endif; ?>

function togglePwd(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);

    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>