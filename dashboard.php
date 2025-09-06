<?php
ob_start();
// dashboard.php - Main user dashboard with navigation and dynamic content loading
require_once 'config.php';
require_once 'functions.php';

/* 1️⃣ Persist affiliate id and product id for the whole session - MOVED TO TOP */
if (isset($_GET['aff']) && (int)$_GET['aff'] > 0) {
    $_SESSION['aff'] = (int)$_GET['aff'];
}

// Also persist product ID for deep-link highlighting
if (isset($_GET['id']) && (int)$_GET['id'] > 0 && ($_GET['page'] ?? '') === 'product_store') {
    $_SESSION['product_id'] = (int)$_GET['id'];
}

// Get current page from URL parameter OR POST parameter, default to 'overview'
$page = $_POST['page'] ?? $_GET['page'] ?? 'overview';

// Define pages that are accessible without login (guest pages)
$guest_allowed_pages = ['product_store', 'checkout'];

$is_guest_page = in_array($page, $guest_allowed_pages);
$is_logged_in = isset($_SESSION['user_id']);

/* 2️⃣ Handle guest access to specific pages */
if (!$is_logged_in && !$is_guest_page) {
    // Build the redirect URL with all current parameters
    $redirectParams = $_GET;
    
    // Ensure affiliate ID is included in redirect if it exists in session
    if (isset($_SESSION['aff']) && !isset($redirectParams['aff'])) {
        $redirectParams['aff'] = $_SESSION['aff'];
    }
    
    $_SESSION['after_login_redirect'] = 'dashboard.php?' . http_build_query($redirectParams);
    redirect('login.php');
}

// Initialize variables for logged-in users
if ($is_logged_in) {
    // Fetch user data
    $uid = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    $role = $user['role'];

    // 🔥 GOD MODE IMPLEMENTATION - Check if we're in god mode
    $isGodMode = isset($_SESSION['god_mode']) && $_SESSION['god_mode']['active'];
    $godModeData = $isGodMode ? $_SESSION['god_mode'] : null;
    $originalAdminId = $isGodMode ? $_SESSION['original_admin_id'] : null;

    // Handle god mode exit
    if (isset($_POST['exit_god_mode']) && $isGodMode) {
        // CSRF token validation
        if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
            $_SESSION['flash'] = 'Invalid request';
            redirect('dashboard.php');
        }
        
        try {
            // Log the god mode exit
            $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $logDetails = "Admin {$godModeData['admin_username']} exited god mode for user {$godModeData['target_username']}";
            $logStmt->execute([$godModeData['admin_id'], 'god_mode_exit', $logDetails, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
            
            // Restore original admin session
            $_SESSION['user_id'] = $_SESSION['original_admin_id'];
            unset($_SESSION['original_admin_id']);
            unset($_SESSION['god_mode']);
            
            // Redirect back to user management
            redirect('dashboard.php?page=users', 'God mode exited successfully');
            
        } catch (PDOException $e) {
            error_log("God mode exit error: " . $e->getMessage());
            $_SESSION['flash'] = 'Error exiting god mode';
        }
    }

    // Auto-expire god mode after 1 hour for security
    if ($isGodMode && (time() - $godModeData['started_at']) > 3600) {
        // Log the auto-expiry
        try {
            $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $logDetails = "God mode auto-expired for admin {$godModeData['admin_username']} viewing user {$godModeData['target_username']}";
            $logStmt->execute([$godModeData['admin_id'], 'god_mode_auto_expire', $logDetails, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        } catch (PDOException $e) {
            error_log("God mode auto-expire log error: " . $e->getMessage());
        }
        
        // Restore original session
        $_SESSION['user_id'] = $_SESSION['original_admin_id'];
        unset($_SESSION['original_admin_id']);
        unset($_SESSION['god_mode']);
        
        redirect('dashboard.php?page=users', 'God mode session expired for security');
    }

    // Check if user account is suspended - prevent login for suspended users (except in god mode)
    if (!$isGodMode && $user['status'] === 'suspended') {
        // Clear session and redirect to login with message
        session_destroy();
        redirect('login.php', 'Your account has been suspended. Please contact support.');
    }

    // Check if user is inactive and redirect them to store or show message
    // Note: product_store is allowed for inactive users
    if ($user['status'] === 'inactive' && !in_array($page, ['overview', 'store', 'wallet', 'profile', 'product_store', 'affiliate', 'checkout', 'pending_orders'])) {
        redirect('dashboard.php?page=store', 'Your account is inactive. Please purchase a package to activate your account.');
    }

    // Get the true admin role (either current user or original admin in god mode)
    $effectiveRole = $isGodMode ? 'admin' : $role;
    $trueRole = $user['role']; // Current viewed user's role

    // User info (with wallet balance)
    $userQuery = $pdo->prepare("SELECT u.*, w.balance
                               FROM users u
                               LEFT JOIN wallets w ON w.user_id = u.id
                               WHERE u.id = ?");
    $userQuery->execute([$uid]);
    $user = $userQuery->fetch();

    // Ensure wallet exists for user
    if ($user['balance'] === null) {
        $pdo->prepare("INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)")
            ->execute([$uid]);
        $user['balance'] = 0.00;
    }

    // Get pending orders count for navigation badge
    $pending_orders_count = 0;
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM pending_orders WHERE user_id = ? AND status = 'pending_payment'");
    $count_stmt->execute([$uid]);
    $pending_orders_count = $count_stmt->fetchColumn() ?? 0;
} else {
    // Set default values for guest users
    $user = null;
    $role = null;
    $isGodMode = false;
    $godModeData = null;
    $effectiveRole = null;
    $trueRole = null;
    $pending_orders_count = 0;
}

// Standard pages available to all users
$allowed_pages = ['overview', 'binary', 'referrals', 'leadership', 'mentor', 'wallet', 'store', 'profile', 'product_store', 'affiliate', 'checkout', 'pending_orders'];

// Admin-only pages (only available to actual admins, not when in god mode viewing users)
if ($is_logged_in && $effectiveRole === 'admin' && !$isGodMode) {
    $allowed_pages[] = 'users'; // Add users page for admins
    $allowed_pages[] = 'export_users'; // Export users page for admins
    $allowed_pages[] = 'settings'; // Add settings page for admins
    $allowed_pages[] = 'manage_products'; // Add manage products for admins
}

// Special case: If admin is in god mode and tries to access admin pages, redirect to overview
if ($is_logged_in && $isGodMode && in_array($page, ['users', 'export_users', 'settings', 'manage_products'])) {
    redirect('dashboard.php?page=overview', 'Admin pages not available in god mode');
}

// For guests, only allow guest pages
if (!$is_logged_in && !in_array($page, $guest_allowed_pages)) {
    $page = 'product_store'; // Default to product store for guests
}

// For logged in users, validate page parameter
if ($is_logged_in && !in_array($page, $allowed_pages)) {
    $page = 'overview';
}

// Handle cart actions for both guests and logged-in users FIRST (before other actions)
if (($_POST['action'] ?? '') && in_array($_POST['action'], ['add_to_cart', 'update_cart'])) {
    
    // Validate CSRF token for POST requests (but allow it to be missing for guests)
    if ($is_logged_in && isset($_POST['csrf_token']) && !validateCSRFToken($_POST['csrf_token'])) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            exit;
        } else {
            redirect('dashboard.php?page=product_store', 'Invalid security token');
        }
    }
    
    switch ($_POST['action']) {
        case 'add_to_cart':
            $product_id = (int)($_POST['product_id'] ?? 0);
            $quantity = max(1, (int)($_POST['quantity'] ?? 1));
            
            if ($product_id <= 0) {
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
                    exit;
                } else {
                    redirect('dashboard.php?page=product_store', 'Invalid product');
                }
            }
            
            try {
                // Validate product exists and is active
                $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND active = 1");
                $stmt->execute([$product_id]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    if (isset($_POST['ajax'])) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Product not found or inactive']);
                        exit;
                    } else {
                        redirect('dashboard.php?page=product_store', 'Product not found');
                    }
                }
                
                // Initialize cart if it doesn't exist
                if (!isset($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }
                if (!isset($_SESSION['cart_products'])) {
                    $_SESSION['cart_products'] = [];
                }
                
                // Add to cart
                if (!isset($_SESSION['cart'][$product_id])) {
                    $_SESSION['cart'][$product_id] = 0;
                }
                $_SESSION['cart'][$product_id] += $quantity;
                
                // Store product details in session for checkout
                $_SESSION['cart_products'][$product_id] = $product;
                
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Product added to cart successfully',
                        'cart_count' => array_sum($_SESSION['cart'])
                    ]);
                    exit;
                } else {
                    redirect('dashboard.php?page=product_store', 'Product added to cart successfully');
                }
                
            } catch (Exception $e) {
                error_log("Add to cart error: " . $e->getMessage());
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
                    exit;
                } else {
                    redirect('dashboard.php?page=product_store', 'Failed to add product to cart');
                }
            }
            break;

        case 'update_cart':
            $product_id = (int)($_POST['product_id'] ?? 0);
            $quantity = max(0, (int)($_POST['quantity'] ?? 0));
            
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
            if (!isset($_SESSION['cart_products'])) {
                $_SESSION['cart_products'] = [];
            }
            
            if ($quantity > 0) {
                $_SESSION['cart'][$product_id] = $quantity;
            } else {
                unset($_SESSION['cart'][$product_id]);
                unset($_SESSION['cart_products'][$product_id]);
            }
            
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'cart_count' => array_sum($_SESSION['cart'] ?? [])
                ]);
                exit;
            } else {
                redirect('dashboard.php?page=product_store', 'Cart updated');
            }
            break;
    }
}

// Handle other actions (moved from individual page files) - ONLY for logged-in users
if (($_POST['action'] ?? '') && !in_array($_POST['action'], ['add_to_cart', 'update_cart'])) {
    // Only allow logged-in users to perform most actions
    if (!$is_logged_in) {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please login to perform this action']);
            exit;
        } else {
            redirect('login.php', 'Please login to perform this action');
        }
    }
    
    switch ($_POST['action']) {
        case 'request_topup':
            $amt = max(0, (float) $_POST['usdt_amount']);
            if ($amt <= 0) redirect('dashboard.php?page=wallet', 'Invalid amount');
            $hash = trim($_POST['tx_hash']) ?: null;
            $b2p = $amt * USDT_B2P_RATE;
            $pdo->prepare(
                "INSERT INTO ewallet_requests (user_id, type, usdt_amount, b2p_amount, tx_hash, status)
                 VALUES (?,'topup', ?, ?, ?, 'pending')"
            )->execute([$uid, $amt, $b2p, $hash]);
            redirect('dashboard.php?page=wallet', 'Top-up request submitted');
            break;

        case 'request_withdraw':
            $amt = max(0, (float) $_POST['usdt_amount']);
            if ($amt <= 0) redirect('dashboard.php?page=wallet','Invalid amount');
            if ($amt > $user['balance']) redirect('dashboard.php?page=wallet','Insufficient balance');
            $addr = trim($_POST['wallet_address']);
            if (empty($addr)) redirect('dashboard.php?page=wallet','Wallet address is required');
            $b2p = $amt * USDT_B2P_RATE;
            $pdo->prepare(
                "INSERT INTO ewallet_requests (user_id,type,usdt_amount,b2p_amount,wallet_address,status)
                 VALUES (?,'withdraw',?,?,?,'pending')"
            )->execute([$uid,$amt,$b2p,$addr]);
            redirect('dashboard.php?page=wallet','Withdrawal request submitted');
            break;

        case 'transfer':
            $toUser = trim($_POST['to_username']);
            $amt = (float) $_POST['amount'];
            
            if (empty($toUser)) redirect('dashboard.php?page=wallet', 'Recipient username is required');
            if ($amt <= 0) redirect('dashboard.php?page=wallet', 'Invalid amount');
            if ($amt > $user['balance']) redirect('dashboard.php?page=wallet', 'Insufficient balance');
            
            $to = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $to->execute([$toUser]);
            $to = $to->fetch();
            if (!$to) redirect('dashboard.php?page=wallet', 'Recipient not found');
            
            $tid = $to['id'];
            if ($tid == $uid) redirect('dashboard.php?page=wallet', 'Cannot transfer to yourself');
            
            try {
                $pdo->beginTransaction();
                
                // Ensure recipient has wallet
                $pdo->prepare("INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)")
                    ->execute([$tid]);
                
                // Update balances
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")
                    ->execute([$amt, $uid]);
                $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")
                    ->execute([$amt, $tid]);
                
                // Record transactions
                $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, 'transfer_out', ?)")
                    ->execute([$uid, -$amt]);
                $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, 'transfer_in', ?)")
                    ->execute([$tid, $amt]);
                
                $pdo->commit();
                redirect('dashboard.php?page=wallet', 'Transfer completed');
            } catch (Exception $e) {
                $pdo->rollBack();
                redirect('dashboard.php?page=wallet', 'Transfer failed: ' . $e->getMessage());
            }
            break;

        case 'buy_package':
            $pid = (int) $_POST['package_id'];
            $pkg = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
            $pkg->execute([$pid]);
            $pkg = $pkg->fetch();
            
            if (!$pkg) redirect('dashboard.php?page=store', 'Package not found');
            if ($user['balance'] < $pkg['price']) redirect('dashboard.php?page=store', 'Insufficient balance');
            
            try {
                $pdo->beginTransaction();
                
                // Check if user is inactive and activate them
                $wasInactive = ($user['status'] === 'inactive');
                
                // Deduct package cost from wallet
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")
                    ->execute([$pkg['price'], $uid]);
                
                // Record package purchase transaction with package_id
                $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, ?, 'package', ?)")
                    ->execute([$uid, $pid, -$pkg['price']]);
                
                // Activate user if they were inactive
                if ($wasInactive) {
                    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")
                        ->execute([$uid]);
                }
                
                // Calculate bonuses
                if (file_exists('binary_calc.php')) {
                    include 'binary_calc.php';
                    if (function_exists('calc_binary')) {
                        calc_binary($uid, $pkg['pv'], $pdo);
                    }
                }
                
                if (file_exists('referral_calc.php')) {
                    include 'referral_calc.php';
                    if (function_exists('calc_referral')) {
                        calc_referral($uid, $pkg['price'], $pdo);
                    }
                }
                
                $pdo->commit();
                
                $message = 'Package purchased & commissions calculated';
                if ($wasInactive) {
                    $message .= '. Your account has been activated!';
                }
                
                redirect('dashboard.php?page=store', $message);
            } catch (Exception $e) {
                $pdo->rollBack();
                redirect('dashboard.php?page=store', 'Purchase failed: ' . $e->getMessage());
            }
            break;
            
        case 'buy_product': 
            $pid = (int)$_POST['product_id'];
            $aff = (int)($_POST['affiliate_id'] ?? 0);
            
            // Validate product exists and is active
            $prod_stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND active = 1");
            $prod_stmt->execute([$pid]);
            $prod = $prod_stmt->fetch();
            
            if (!$prod) {
                redirect('dashboard.php?page=product_store', 'Product not found or inactive');
            }

            // Calculate final price after discount
            $final_price = $prod['price'] * (1 - $prod['discount']/100);
            
            // Check user has sufficient balance
            if ($user['balance'] < $final_price) {
                redirect('dashboard.php?page=product_store', 'Insufficient balance. Need ' . number_format($final_price - $user['balance'], 2) . ' more.');
            }

            // Note: Inactive users are allowed to buy products (unlike packages)

            try {
                $pdo->beginTransaction();
                
                // Deduct from buyer's wallet
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")
                    ->execute([$final_price, $uid]);
                
                // Record purchase transaction
                $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, 'product_purchase', ?)")
                    ->execute([$uid, -$final_price]);

                // Process affiliate commission if applicable (UPDATED: Allow inactive affiliates)
                if ($aff && $aff !== $uid && $prod['affiliate_rate'] > 0) {
                    // Validate affiliate user exists (removed active status requirement)
                    $aff_user = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
                    $aff_user->execute([$aff]);
                    $aff_user = $aff_user->fetch();
                    
                    // Pay commission to any existing user (active or inactive)
                    if ($aff_user) {
                        $commission = $final_price * ($prod['affiliate_rate'] / 100);
                        
                        // Ensure affiliate has a wallet
                        $pdo->prepare("INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)")
                            ->execute([$aff]);
                        
                        // Credit affiliate commission
                        $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")
                            ->execute([$commission, $aff]);
                        
                        // Record affiliate commission transaction
                        $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, 'affiliate_bonus', ?)")
                            ->execute([$aff, $commission]);
                    }
                }
                
                $pdo->commit();
                
                $message = 'Purchase completed successfully!';
                if ($aff && $aff !== $uid && $prod['affiliate_rate'] > 0) {
                    $message .= ' Affiliate commission has been processed.';
                }
                
                redirect('dashboard.php?page=product_store', $message);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                redirect('dashboard.php?page=product_store', 'Purchase failed: ' . $e->getMessage());
            }
            break;
    }

    // Continue with admin wallet actions only for logged-in users
    if ($page === 'wallet' && $effectiveRole === 'admin' && !$isGodMode && ($_POST['action'] ?? '')) {
        $id = (int)($_POST['req_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM ewallet_requests WHERE id = ? AND status = "pending"');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            redirect('dashboard.php?page=wallet', 'No pending request found');
        }

        try {
            $pdo->beginTransaction();

            // Ensure wallet row exists for the target user
            $pdo->prepare('INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)')
                ->execute([$row['user_id']]);

            if ($_POST['action'] === 'approve') {
                /* ----- Withdrawal ----- */
                if ($row['type'] === 'withdraw') {
                    // Deduct from wallet
                    $pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE user_id = ?')
                        ->execute([$row['usdt_amount'], $row['user_id']]);
                    // Ledger entry (with NULL package_id)
                    $pdo->prepare('INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, "withdraw", ?)')
                        ->execute([$row['user_id'], -$row['usdt_amount']]);
                }
                /* ----- Top-up ----- */
                else { // type = topup
                    // Credit wallet
                    $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE user_id = ?')
                        ->execute([$row['usdt_amount'], $row['user_id']]);
                    // Ledger entry (with NULL package_id)
                    $pdo->prepare('INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, "topup", ?)')
                        ->execute([$row['user_id'], $row['usdt_amount']]);
                }

                $pdo->prepare('UPDATE ewallet_requests SET status="approved", updated_at=NOW() WHERE id = ?')
                    ->execute([$id]);

            } elseif ($_POST['action'] === 'reject') {
                // If it's a withdrawal, we need to return the held funds
                if ($row['type'] === 'withdraw') {
                    // Credit the wallet back
                    $pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE user_id = ?')
                        ->execute([$row['usdt_amount'], $row['user_id']]);
                    // Record the reversal (with NULL package_id)
                    $pdo->prepare('INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, "withdraw_reject", ?)')
                        ->execute([$row['user_id'], $row['usdt_amount']]);
                }
                
                // Mark as rejected
                $pdo->prepare('UPDATE ewallet_requests SET status="rejected", updated_at=NOW() WHERE id = ?')
                    ->execute([$id]);
            }

            $pdo->commit();
            redirect('dashboard.php?page=wallet', 'Request updated successfully');
        } catch (Exception $e) {
            $pdo->rollBack();
            redirect('dashboard.php?page=wallet', 'Failed to update request: ' . $e->getMessage());
        }
    }
}

function flash() {
    if (isset($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return "<div class='bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4' role='alert'>" . htmlspecialchars($msg) . "</div>";
    }
    return '';
}

// Helper function to build navigation links with affiliate persistence
function buildNavLink($targetPage, $currentPage = '') {
    $params = ['page' => $targetPage];
    
    // Add affiliate ID if it exists in session and we're going to product_store
    if ($targetPage === 'product_store' && isset($_SESSION['aff'])) {
        $params['aff'] = $_SESSION['aff'];
        
        // Also preserve the product ID if it exists in session
        if (isset($_SESSION['product_id'])) {
            $params['id'] = $_SESSION['product_id'];
        }
    }
    
    return 'dashboard.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_logged_in ? 'Shoppe Club Dashboard' : 'Shoppe Club - Product Store' ?></title>
    <script src="https://d3js.org/d3.v7.min.js" onload="console.log('D3.js loaded from CDN')" onerror="this.src='/js/d3.v7.min.js';console.error('CDN failed, loading local D3.js')"></script>
    <script src="https://cdn.tailwindcss.com" onload="console.log('Tailwind CSS loaded from CDN')" onerror="this.src='/css/tailwind.min.css';console.error('CDN failed, loading local Tailwind')"></script>
    <link href="css/style.css" rel="stylesheet">
    <style>
        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: white;
            font-size: 0.75rem;
            border-radius: 50%;
            height: 20px;
            width: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            z-index: 10;
        }

        /* Mobile responsive navigation improvements */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease-in-out;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .notification-badge {
                height: 16px;
                width: 16px;
                font-size: 0.625rem;
            }
        }
        
        /* Guest layout styles */
        .guest-layout {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .guest-nav {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="<?= $is_logged_in ? 'bg-gray-100 font-sans' : 'guest-layout' ?>">
    
    <?php if ($is_logged_in): ?>
        <!-- Logged-in user layout with full dashboard -->
        
        <!-- 🔥 GOD MODE BANNER -->
        <?php if ($isGodMode): ?>
        <div class="bg-gradient-to-r from-red-600 to-red-800 text-white shadow-lg border-b-4 border-red-900 sticky top-0 z-50">
            <!-- ... (keep existing god mode banner) ... -->
        </div>
        <?php endif; ?>

        <div class="flex h-screen">
            <!-- Sidebar -->
            <div id="sidebar" class="bg-white w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 md:relative md:translate-x-0 transition-transform duration-300 ease-in-out sidebar">
                <div class="px-4">
                    <h2 class="text-2xl font-bold text-gray-800">Shoppe Club</h2>
                </div>
                <nav class="space-y-1">
                    <a href="dashboard.php?page=overview" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'overview' ? 'bg-blue-500 text-white' : '' ?>">
                        Overview
                    </a>
                    
                    <!-- Admin-only pages (show only for actual admins, not when viewing users in god mode) -->
                    <?php if ($effectiveRole === 'admin' && !$isGodMode): ?>
                        <a href="dashboard.php?page=users" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'users' ? 'bg-blue-500 text-white' : '' ?>">
                            Users
                        </a>
                    <?php endif; ?>
                    
                    <!-- Standard user pages -->
                    <a href="dashboard.php?page=binary" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'binary' ? 'bg-blue-500 text-white' : '' ?>">
                        Binary Tree
                    </a>
                    <a href="dashboard.php?page=referrals" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'referrals' ? 'bg-blue-500 text-white' : '' ?>">
                        Referrals
                    </a>
                    <a href="dashboard.php?page=leadership" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'leadership' ? 'bg-blue-500 text-white' : '' ?>">
                        Matched Bonus
                    </a>
                    <a href="dashboard.php?page=mentor" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'mentor' ? 'bg-blue-500 text-white' : '' ?>">
                        Mentor Bonus
                    </a>
                    <a href="dashboard.php?page=wallet" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'wallet' ? 'bg-blue-500 text-white' : '' ?>">
                        Wallet
                    </a>
                    <a href="dashboard.php?page=store" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'store' ? 'bg-blue-500 text-white' : '' ?>">
                        Package Store
                    </a>
                    <a href="<?= buildNavLink('product_store', $page) ?>" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'product_store' ? 'bg-blue-500 text-white' : '' ?>">
                        Product Store
                    </a>
                    
                    <!-- Pending Orders with notification badge -->
                    <a href="dashboard.php?page=pending_orders" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'pending_orders' ? 'bg-blue-500 text-white' : '' ?> relative">
                        Pending Orders
                        <?php if ($pending_orders_count > 0): ?>
                            <span class="notification-badge">
                                <?= min($pending_orders_count, 9) ?><?= $pending_orders_count > 9 ? '+' : '' ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    
                    <a href="dashboard.php?page=affiliate" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'affiliate' ? 'bg-blue-500 text-white' : '' ?>">
                        Affiliate
                    </a>
                    
                    <!-- Admin-only settings and management (only for actual admins, not god mode) -->
                    <?php if ($effectiveRole === 'admin' && !$isGodMode): ?>
                        <a href="dashboard.php?page=settings" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'settings' ? 'bg-blue-500 text-white' : '' ?>">
                            Settings
                        </a>
                        <a href="dashboard.php?page=manage_products" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'manage_products' ? 'bg-blue-500 text-white' : '' ?>">
                            Manage Products
                        </a>
                    <?php endif; ?>
                    
                    <a href="logout.php" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">
                        Logout
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="flex-1 flex flex-col overflow-hidden">
                <!-- Header -->
                <header class="bg-white shadow-sm">
                    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                        <div class="flex items-center">
                            <button id="sidebarToggle" class="md:hidden text-gray-600 hover:text-gray-800 focus:outline-none">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                                </svg>
                            </button>
                            <h1 class="text-xl font-semibold text-gray-800 ml-4">
                                Dashboard - <?=htmlspecialchars($user['username'])?>
                                <?php if ($user['status'] === 'inactive'): ?>
                                    <span class="ml-2 px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full">Inactive</span>
                                <?php elseif ($user['status'] === 'suspended'): ?>
                                    <span class="ml-2 px-2 py-1 text-xs bg-red-200 text-red-900 rounded-full">Suspended</span>
                                <?php endif; ?>
                                <?php if ($isGodMode): ?>
                                    <span class="ml-2 px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded-full">God Mode View</span>
                                <?php endif; ?>
                            </h1>
                        </div>
                        <div class="flex items-center space-x-4">
                            <?php if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])): ?>
                                <?php 
                                $cart_count = array_sum($_SESSION['cart']);
                                if ($cart_count > 0): 
                                ?>
                                    <a href="dashboard.php?page=checkout" class="flex items-center space-x-1 bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm hover:bg-green-200 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m-2.4 8L7 13m0 0l-2.5 5M7 13v6a2 2 0 002 2h6a2 2 0 002-2v-6M9 9v2m4-2v2"></path>
                                        </svg>
                                        <span>Cart (<?= $cart_count ?>)</span>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <span class="text-sm text-gray-600">Balance: $<?= number_format($user['balance'], 2) ?></span>
                            <a href="dashboard.php?page=profile" class="text-gray-600 hover:text-blue-500">Profile</a>
                        </div>
                    </div>
                </header>

                <!-- Main Content Area -->
                <main class="flex-1 overflow-y-auto bg-gray-100 p-4 sm:p-6 lg:p-8">
                    <div class="max-w-7xl mx-auto">
                        <?=flash()?>
                        
                        <!-- Show inactive account notice -->
                        <?php if ($user['status'] === 'inactive'): ?>
                            <div class="bg-orange-100 border-l-4 border-orange-500 text-orange-700 p-4 mb-4" role="alert">
                                <div class="flex">
                                    <div class="py-1">
                                        <svg class="fill-current h-6 w-6 text-orange-500 mr-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                            <path d="M2.93 17.07A10 10 0 1 1 17.07 2.93 10 10 0 0 1 2.93 17.07zm12.73-1.41A8 8 0 1 0 4.34 4.34a8 8 0 0 0 11.32 11.32zM9 11V9h2v6H9v-4zm0-6h2v2H9V5z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="font-bold">Rank Inactive</p>
                                        <p class="text-sm">Your rank is currently inactive. To activate your account and access all features, please <a href="dashboard.php?page=store" class="underline font-semibold">purchase a package</a>.</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Show suspended account notice (only visible in god mode) -->
                        <?php if ($user['status'] === 'suspended' && $isGodMode): ?>
                            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                                <div class="flex">
                                    <div class="py-1">
                                        <svg class="fill-current h-6 w-6 text-red-500 mr-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                                            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="font-bold">Account Suspended</p>
                                        <p class="text-sm">This user's account has been suspended. They cannot log in or perform actions. Only visible in God Mode.</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php 
                        // Include the appropriate page content
                        if ($page === 'wallet' && $effectiveRole === 'admin' && !$isGodMode) {
                            // Only show admin wallet page if actually admin and not in god mode
                            $page_file = 'pages/wallet_admin.php';
                        } else {
                            $page_file = "pages/{$page}.php";
                        }

                        if (file_exists($page_file)) {
                            require $page_file;
                        } else {
                            echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded'>Page not found: " . htmlspecialchars($page_file) . "</div>";
                        }
                        ?>
                    </div>                
                </main>
            </div>
        </div>

    <?php else: ?>
        <!-- Guest layout for product store -->
        
        <!-- Guest Navigation -->
        <nav class="guest-nav">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center">
                        <h1 class="text-xl font-bold text-gray-800">Shoppe Club</h1>
                        <?php if (isset($_SESSION['aff'])): ?>
                            <?php 
                            $aff_user = null;
                            if ($_SESSION['aff']) {
                                $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND status = 'active'");
                                $stmt->execute([$_SESSION['aff']]);
                                $aff_user = $stmt->fetch();
                            }
                            ?>
                            <?php if ($aff_user): ?>
                                <div class="ml-4 px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                                    Referred by <?= htmlspecialchars($aff_user['username']) ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <?php if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])): ?>
                            <?php 
                            $cart_count = array_sum($_SESSION['cart']);
                            if ($cart_count > 0): 
                            ?>
                                <button onclick="showCart()" class="flex items-center space-x-1 bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm hover:bg-green-200 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m-2.4 8L7 13m0 0l-2.5 5M7 13v6a2 2 0 002 2h6a2 2 0 002-2v-6M9 9v2m4-2v2"></path>
                                    </svg>
                                    <span>Cart (<?= $cart_count ?>)</span>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <a href="login.php<?= isset($_SESSION['aff']) ? '?aff=' . $_SESSION['aff'] : '' ?>" class="text-blue-600 hover:text-blue-800 font-medium">Sign In</a>
                        <a href="register.php<?= isset($_SESSION['aff']) ? '?aff=' . $_SESSION['aff'] : '' ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded font-medium">Join Now</a>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Guest Main Content -->
        <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            <div class="bg-white rounded-lg shadow-lg">
                <?= flash() ?>
                
                <?php 
                // Include the appropriate page content for guests
                $page_file = "pages/{$page}.php";
                if (file_exists($page_file)) {
                    require $page_file;
                } else {
                    echo "<div class='p-6'><div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded'>Page not found: " . htmlspecialchars($page_file) . "</div></div>";
                }
                ?>
            </div>
        </main>
    <?php endif; ?>

    <script>
    // God Mode Timer and Security Features (only for logged-in users)
    <?php if ($is_logged_in && $isGodMode): ?>
    // ... (keep existing god mode JavaScript)
    <?php endif; ?>

    // Sidebar toggle for mobile (only for logged-in users)
    <?php if ($is_logged_in): ?>
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mainContent = document.querySelector('main');

    sidebarToggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('open');
    });

    // Close sidebar when clicking outside on mobile
    mainContent?.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && sidebar?.classList.contains('open')) {
            sidebar.classList.remove('open');
        }
    });
    <?php endif; ?>

    // Initialize charts based on current page (only for logged-in users)
    <?php if ($is_logged_in): ?>
    window.addEventListener('load', () => {
        const currentPage = '<?= $page ?>';
        if (currentPage === 'binary') {
            if (typeof initBinaryChart === 'function') {
                initBinaryChart();
            }
        } else if (currentPage === 'leadership') {
            if (typeof initSponsorChart === 'function') {
                initSponsorChart();
            }
        }
    });
    <?php endif; ?>

    // Global cart functions (available for both guests and logged-in users)
    function showCart() {
        // This function should be implemented in the product_store.php page
        if (typeof loadCart === 'function') {
            loadCart();
            document.getElementById('cartModal')?.classList.add('show');
        }
    }
    
    // Chart initialization will be handled by individual page scripts
    </script>
</body>
</html> 