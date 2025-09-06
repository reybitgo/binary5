<?php
/*  checkout.php  â€"  Guest checkout for non-members  */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/* ----------------------------------------------------------
   1.  Basic guards
---------------------------------------------------------- */
if (isset($_SESSION['user_id'])) {                       // members use internal checkout
    redirect('dashboard.php?page=checkout');
}
if (empty($_SESSION['cart']) || empty($_SESSION['cart_products'])) {
    redirect('dashboard.php?page=product_store', 'Your cart is empty. Please add some products first.');
}

/* ----------------------------------------------------------
   2.  Affiliate handling
---------------------------------------------------------- */
$affiliate_id = isset($_SESSION['aff']) ? (int)$_SESSION['aff'] :
                (isset($_GET['aff'])   ? (int)$_GET['aff']   : null);
$referral_sponsor = null;
$auto_upline = $auto_position = null;

if ($affiliate_id) {
    $stmt = $pdo->prepare("SELECT id, username, status FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$affiliate_id]);
    $referral_sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($referral_sponsor) {
        $_SESSION['aff'] = $affiliate_id;
        $placement = findBestPlacement($affiliate_id, $pdo);
        if ($placement) {
            $auto_upline   = $placement['upline_username'];
            $auto_position = $placement['position'];
        }
    } else {
        unset($_SESSION['aff']);
        $affiliate_id = null;
    }
}

/* ----------------------------------------------------------
   3.  Cart totals
---------------------------------------------------------- */
$cart_items   = [];
$cart_total   = 0;
$total_items  = 0;

foreach ($_SESSION['cart'] as $product_id => $quantity) {
    if (isset($_SESSION['cart_products'][$product_id])) {
        $product      = $_SESSION['cart_products'][$product_id];
        $final_price  = $product['price'] * (1 - $product['discount'] / 100);
        $item_total   = $final_price * $quantity;

        $cart_items[] = [
            'product'     => $product,
            'quantity'    => $quantity,
            'final_price' => $final_price,
            'item_total'  => $item_total,
        ];
        $cart_total  += $item_total;
        $total_items += $quantity;
    }
}

/* ----------------------------------------------------------
   4.  Form processing
---------------------------------------------------------- */
$errors     = [];
$old_values = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'complete_checkout') {

    /* ---- 4a.  CSRF ---- */
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {

        /* ---- 4b.  Gather input ---- */
        $username         = trim($_POST['username']         ?? '');
        $email            = trim($_POST['email']            ?? '');
        $password         = $_POST['password']              ?? '';
        $confirm_password = $_POST['confirm_password']      ?? '';
        $sponsor_name     = trim($_POST['sponsor_name']     ?? '');
        $upline_name      = trim($_POST['upline_username']  ?? '');
        $position         = $_POST['position']              ?? '';

        $old_values = [
            'username'        => $username,
            'email'           => $email,
            'sponsor_name'    => $sponsor_name,
            'upline_username' => $upline_name,
            'position'        => $position,
        ];

        /* ---- 4c.  Basic validation ---- */
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters long.';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
            $errors[] = 'Username can only contain letters, numbers, underscore and hyphen.';
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $errors[] = 'Username already exists. Please choose another one.';
            }
        }

        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'Email already registered. Please use a different address.';
            }
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirm_password) {
            $errors[] = 'Passwords do not match.';
        }

        /* ---- 4d.  Sponsor / upline ---- */
        $sponsorId = $uplineId = null;

        if ($sponsor_name === '') {
            $errors[] = 'Sponsor username is required.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$sponsor_name]);
            $row = $stmt->fetch();
            if (!$row) {
                $errors[] = 'Sponsor username not found.';
            } else {
                $sponsorId = (int)$row['id'];
            }
        }

        if ($upline_name === '') {
            $errors[] = 'Upline username is required.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$upline_name]);
            $row = $stmt->fetch();
            if (!$row) {
                $errors[] = 'Upline username not found.';
            } else {
                $uplineId = (int)$row['id'];
                if (strcasecmp($upline_name, $username) === 0) {
                    $errors[] = 'You cannot be your own upline.';
                }
            }
        }

        if (!in_array($position, ['left', 'right'], true)) {
            $errors[] = 'Please select a binary position.';
        } elseif ($uplineId) {
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE upline_id = ? AND position = ?");
            $stmt->execute([$uplineId, $position]);
            if ($stmt->fetch()) {
                $errors[] = 'Chosen position is already occupied for this upline.';
            }
        }

        /* ----------------------------------------------------------
           4e.  CREATE ACCOUNT + SAVE ORDERS  (only if zero errors)
        ---------------------------------------------------------- */
        if (!$errors && $sponsorId && $uplineId) {
            $transactionStarted = false;
            
            try {
                // Validate cart items exist before starting transaction
                if (empty($cart_items)) {
                    throw new Exception('No items in cart to process.');
                }

                // Start transaction
                $pdo->beginTransaction();
                $transactionStarted = true;

                /* ---- user row ---- */
                $hash  = password_hash($password, PASSWORD_DEFAULT);
                $stmt  = $pdo->prepare("
                    INSERT INTO users (username, email, password, sponsor_id, upline_id, position, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'inactive')
                ");
                $result = $stmt->execute([$username, $email, $hash, $sponsorId, $uplineId, $position]);
                if (!$result) {
                    throw new Exception('Failed to create user account');
                }
                
                $newUserId = $pdo->lastInsertId();
                if (!$newUserId) {
                    throw new Exception('Failed to get new user ID');
                }

                /* ---- wallet ---- */
                $walletStmt = $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)");
                if (!$walletStmt->execute([$newUserId])) {
                    throw new Exception('Failed to create wallet');
                }

                /* ---- upline counters ---- */
                $side = ($position === 'left') ? 'left_count' : 'right_count';
                $uplineStmt = $pdo->prepare("UPDATE users SET `$side` = `$side` + 1 WHERE id = ?");
                if (!$uplineStmt->execute([$uplineId])) {
                    throw new Exception('Failed to update upline counters');
                }

                /* ---- insert orders ---- */
                $ins = $pdo->prepare("
                    INSERT INTO pending_orders
                           (user_id, product_id, quantity, unit_price, total_amount, affiliate_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $ordersInserted = 0;
                foreach ($cart_items as $item) {
                    $result = $ins->execute([
                        $newUserId,
                        $item['product']['id'],
                        $item['quantity'],
                        $item['final_price'],
                        $item['item_total'],
                        $affiliate_id
                    ]);
                    
                    if (!$result) {
                        throw new Exception('Failed to insert order for product: ' . $item['product']['name']);
                    }
                    $ordersInserted++;
                }

                if ($ordersInserted === 0) {
                    throw new Exception('No orders were created');
                }

                // Commit transaction
                $pdo->commit();
                $transactionStarted = false;

                /* ---- Clean up cart and set up session ---- */
                unset($_SESSION['cart'], $_SESSION['cart_products']);
                
                // Regenerate session ID for security
                session_regenerate_id(true);
                
                // Set user session variables
                $_SESSION['user_id']  = $newUserId;
                $_SESSION['username'] = $username;
                $_SESSION['login_time'] = time();

                // Set flash message for success banner
                $_SESSION['flash'] = 'Account created successfully! Please top up your wallet to complete your orders.';
                $_SESSION['flash_type'] = 'success';

                // Redirect to pending orders page
                header('Location: dashboard.php?page=pending_orders');
                exit();

            } catch (Throwable $e) {
                // Only rollback if transaction was actually started
                if ($transactionStarted && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                
                // Log the error for debugging
                error_log('Checkout error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                
                // Add user-friendly error message
                $errors[] = 'Checkout failed: ' . $e->getMessage();
            }
        }
    }
}

/* ----------------------------------------------------------
   5.  Helper: form sticky values
---------------------------------------------------------- */
function old($field, $default = '')
{
    global $old_values;
    return isset($old_values[$field])
        ? htmlspecialchars($old_values[$field], ENT_QUOTES, 'UTF-8')
        : $default;
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Shoppe Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body{
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .checkout-container{
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
            overflow: hidden;
        }
        .order-summary{
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-left: 1px solid #e2e8f0;
        }
        .product-item{border-bottom:1px solid #f1f5f9;padding:1rem 0}
        .product-item:last-child{border-bottom:none}
        .referral-badge{
            background: linear-gradient(45deg, #10b981, #34d399);
            color: #fff; font-weight: 600; padding: .75rem 1rem;
            border-radius: 10px; margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(16,185,129,.3);
        }
        .auto-filled{background-color:#f0f9ff;border-color:#0ea5e9}
        .btn-complete-order{
            background: linear-gradient(135deg, #059669, #10b981);
            border: none; font-weight: 600; letter-spacing: .5px;
            padding: .75rem 2rem;
        }
        .btn-complete-order:hover{
            background: linear-gradient(135deg, #047857, #059669);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(5,150,105,.3);
        }
        .step-indicator{display:flex;align-items:center;margin-bottom:2rem}
        .step{flex:1;text-align:center;position:relative}
        .step-circle{width:40px;height:40px;border-radius:50%;background:#e5e7eb;color:#6b7280;display:flex;align-items:center;justify-content:center;font-weight:600;margin:0 auto .5rem}
        .step.active .step-circle{background:#3b82f6;color:#fff}
        .step.completed .step-circle{background:#10b981;color:#fff}
        .step-line{position:absolute;top:20px;left:50%;right:-50%;height:2px;background:#e5e7eb;z-index:-1}
        .step.completed .step-line{background:#10b981}
    </style>
</head>
<body>
<div class="container py-5">
    <div class="checkout-container row g-0 mx-auto" style="max-width: 1200px;">
        <!-- ==========  ORDER SUMMARY  ========== -->
        <div class="col-lg-5 order-summary p-4">
            <h3 class="mb-4 text-center">Order Summary</h3>

            <?php if ($referral_sponsor): ?>
                <div class="referral-badge text-center">
                    <div class="d-flex align-items-center justify-content-center">
                        <i class="bi bi-gift me-2"></i>
                        <div>
                            <div class="fw-bold">Referred by <?= htmlspecialchars($referral_sponsor['username']) ?></div>
                            <small class="opacity-75">You'll get special bonuses!</small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="step-indicator mb-4">
                <div class="step completed"><div class="step-circle">1</div><div class="text-xs">Cart</div><div class="step-line"></div></div>
                <div class="step active">  <div class="step-circle">2</div><div class="text-xs">Account</div><div class="step-line"></div></div>
                <div class="step">        <div class="step-circle">3</div><div class="text-xs">Payment</div></div>
            </div>

            <div class="mb-4">
                <?php foreach ($cart_items as $item): ?>
                    <div class="product-item">
                        <div class="d-flex">
                            <img src="<?= htmlspecialchars($item['product']['image_url'] ?: '/images/placeholder.jpg') ?>"
                                 alt="<?= htmlspecialchars($item['product']['name']) ?>"
                                 class="rounded me-3" style="width:60px;height:60px;object-fit:cover;">
                            <div class="flex-grow-1">
                                <h6 class="mb-1"><?= htmlspecialchars($item['product']['name']) ?></h6>
                                <div class="text-muted small mb-1">$<?= number_format($item['final_price'],2) ?> × <?= $item['quantity'] ?></div>
                                <?php if ($item['product']['discount']>0): ?>
                                    <span class="badge bg-danger small"><?= $item['product']['discount'] ?>% OFF</span>
                                <?php endif; ?>
                                <?php if ($item['product']['affiliate_rate']>0): ?>
                                    <span class="badge bg-purple text-white small"><?= $item['product']['affiliate_rate'] ?>% Commission</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-end"><div class="fw-bold">$<?= number_format($item['item_total'],2) ?></div></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="border-top pt-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span>Items (<?= $total_items ?>):</span><span>$<?= number_format($cart_total,2) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="fw-bold fs-5">Total:</span><span class="fw-bold fs-4 text-success">$<?= number_format($cart_total,2) ?></span>
                </div>
                <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i><strong>Next Step:</strong> After account creation, top-up your wallet to complete the purchase.</div>
            </div>
        </div><!-- /order-summary -->

        <!-- ==========  CHECKOUT FORM  ========== -->
        <div class="col-lg-7 p-5">
            <div class="text-center mb-4">
                <h2 class="fw-bold">Create Your Account</h2>
                <p class="text-muted">Complete your order by creating your Shoppe Club account</p>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <strong>Please correct the following errors:</strong>
                    <ul class="mb-0 mt-2"><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul>
                </div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="complete_checkout">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="form-floating">
                            <input type="text" class="form-control" id="username" name="username" placeholder="Username"
                                   value="<?= old('username') ?>" required pattern="[a-zA-Z0-9_-]+" minlength="3" maxlength="30">
                            <label for="username">Username *</label>
                            <div class="form-text">3-30 characters, letters, numbers, underscore, hyphen only</div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="form-floating">
                            <input type="email" class="form-control" id="email" name="email" placeholder="Email"
                                   value="<?= old('email') ?>" required>
                            <label for="email">Email Address *</label>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="form-floating">
                            <input type="password" class="form-control" id="password" name="password" placeholder="Password" required minlength="6">
                            <label for="password">Password *</label>
                            <div id="passwordStrength" class="form-text"></div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="form-floating">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required minlength="6">
                            <label for="confirm_password">Confirm Password *</label>
                            <div id="passwordMatch" class="form-text"></div>
                        </div>
                    </div>
                </div>

                <!-- Network Placement -->
                <div class="border rounded p-3 mb-4 bg-light">
                    <h6 class="mb-3"><i class="bi bi-diagram-3 me-2"></i>Network Placement
                        <?php if ($referral_sponsor): ?><span class="badge bg-success ms-2">Auto-configured</span><?php endif; ?>
                    </h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control <?= $referral_sponsor ? 'auto-filled' : '' ?>" id="sponsor_name" name="sponsor_name"
                                       placeholder="Sponsor Username" value="<?= old('sponsor_name', $referral_sponsor['username'] ?? '') ?>"
                                       <?= $referral_sponsor ? 'readonly' : '' ?> required>
                                <label for="sponsor_name">Sponsor Username *</label>
                                <div class="form-text"><?= $referral_sponsor ? 'Pre-filled from referral link' : 'Username of your sponsor' ?></div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control <?= $auto_upline ? 'auto-filled' : '' ?>" id="upline_username" name="upline_username"
                                       placeholder="Upline Username" value="<?= old('upline_username', $auto_upline) ?>"
                                       <?= $auto_upline ? 'readonly' : '' ?> required>
                                <label for="upline_username">Upline Username *</label>
                                <div class="form-text"><?= $auto_upline ? 'Best available upline selected' : 'Your direct upline in the binary tree' ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-floating">
                                <select class="form-select <?= $auto_position ? 'auto-filled' : '' ?>" id="position" name="position" <?= $auto_position ? 'disabled' : '' ?> required>
                                    <option value="">Select Position</option>
                                    <option value="left"  <?= old('position', $auto_position) === 'left'  ? 'selected' : '' ?>>Left Side</option>
                                    <option value="right" <?= old('position', $auto_position) === 'right' ? 'selected' : '' ?>>Right Side</option>
                                </select>
                                <label for="position">Position *</label>
                                <?php if ($auto_position): ?>
                                    <input type="hidden" name="position" value="<?= htmlspecialchars($auto_position) ?>">
                                    <div class="form-text">Optimal position selected for fastest growth</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($auto_upline || $auto_position): ?>
                            <div class="col-md-6 mb-3 d-flex align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="manualPlacement">
                                    <label class="form-check-label" for="manualPlacement">Manual placement</label>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div><!-- /network-placement -->

                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="agreeTerms" required>
                        <label class="form-check-label" for="agreeTerms">
                            I agree to the <a href="#" class="text-decoration-none">Terms of Service</a> and
                            <a href="#" class="text-decoration-none">Privacy Policy</a> *
                        </label>
                    </div>
                </div>

                <div class="text-center">
                    <button type="submit" class="btn btn-complete-order text-white btn-lg px-5">
                        <i class="bi bi-check-circle me-2"></i>Create Account & Place Order
                    </button>
                    <div class="mt-3">
                        <a href="dashboard.php?page=product_store" class="text-muted text-decoration-none">
                            <i class="bi bi-arrow-left me-1"></i>Back to Cart
                        </a>
                        <span class="mx-3 text-muted">|</span>
                        <a href="login.php<?= $affiliate_id ? '?aff=' . $affiliate_id : '' ?>" class="text-decoration-none">Already have an account? Sign in</a>
                    </div>
                </div>
            </form>
        </div><!-- /checkout-form -->
    </div><!-- /row -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ----  manual-placement toggle  ---- */
<?php if ($auto_upline || $auto_position): ?>
document.getElementById('manualPlacement')?.addEventListener('change', function () {
    const uplineInp   = document.getElementById('upline_username');
    const posSel      = document.getElementById('position');
    let   hiddenPos   = document.querySelector('input[name="position"][type="hidden"]');

    if (this.checked) {                               // enable manual
        uplineInp.readOnly = false; uplineInp.classList.remove('auto-filled'); uplineInp.value = '';
        posSel.disabled    = false; posSel.classList.remove('auto-filled');    posSel.value    = '';
        if (hiddenPos) hiddenPos.remove();
    } else {                                          // restore auto
        uplineInp.readOnly = true;  uplineInp.classList.add('auto-filled'); uplineInp.value = '<?= htmlspecialchars($auto_upline) ?>';
        posSel.disabled    = true;  posSel.classList.add('auto-filled');    posSel.value    = '<?= htmlspecialchars($auto_position) ?>';
        if (!hiddenPos) {
            hiddenPos = document.createElement('input'); hiddenPos.type = 'hidden'; hiddenPos.name = 'position';
            hiddenPos.value = '<?= htmlspecialchars($auto_position) ?>'; posSel.parentNode.appendChild(hiddenPos);
        }
    }
});
<?php endif; ?>

/* ----  password strength & match  ---- */
document.getElementById('password')?.addEventListener('input', function () {
    const val = this.value,  div = document.getElementById('passwordStrength');
    if (!val) { div.textContent = ''; return; }
    let s = 0, fb = [];
    if (val.length >= 8) s++; else fb.push('at least 8 chars');
    if (/[a-z]/.test(val)) s++; else fb.push('lowercase');
    if (/[A-Z]/.test(val)) s++; else fb.push('uppercase');
    if (/\d/.test(val)) s++; else fb.push('number');
    if (/[^a-zA-Z\d]/.test(val)) s++; else fb.push('special char');
    div.className = s < 2 ? 'form-text text-danger' : s < 4 ? 'form-text text-warning' : 'form-text text-success';
    div.textContent = s < 2 ? 'Weak. Add: '+fb.slice(0,2).join(', ') : s < 4 ? 'Medium. Add: '+fb[0] : 'Strong password!';
});
document.getElementById('confirm_password')?.addEventListener('input', function () {
    const matchDiv = document.getElementById('passwordMatch');
    const ok = document.getElementById('password').value === this.value;
    matchDiv.className = ok ? 'form-text text-success' : 'form-text text-danger';
    matchDiv.textContent = ok ? 'Passwords match!' : 'Passwords do not match';
});
</script>
</body>
</html>