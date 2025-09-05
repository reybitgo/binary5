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

/* 2️⃣ Persist the requested URL before redirecting to login */
if (!isset($_SESSION['user_id'])) {
    // Build the redirect URL with all current parameters
    $redirectParams = $_GET;
    
    // Ensure affiliate ID is included in redirect if it exists in session
    if (isset($_SESSION['aff']) && !isset($redirectParams['aff'])) {
        $redirectParams['aff'] = $_SESSION['aff'];
    }
    
    $_SESSION['after_login_redirect'] = 'dashboard.php?' . http_build_query($redirectParams);
    redirect('login.php');
}

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
if ($user['status'] === 'inactive' && !in_array($_GET['page'] ?? 'overview', ['overview', 'store', 'wallet', 'profile', 'product_store', 'affiliate'])) {
    redirect('dashboard.php?page=store', 'Your account is inactive. Please purchase a package to activate your account.');
}

// Get current page from URL parameter, default to 'overview'
$page = $_GET['page'] ?? 'overview';

// Get the true admin role (either current user or original admin in god mode)
$effectiveRole = $isGodMode ? 'admin' : $role;
$trueRole = $user['role']; // Current viewed user's role

// Standard pages available to all users
$allowed_pages = ['overview', 'binary', 'referrals', 'leadership', 'mentor', 'wallet', 'store', 'profile', 'product_store', 'affiliate'];

// Admin-only pages (only available to actual admins, not when in god mode viewing users)
if ($effectiveRole === 'admin' && !$isGodMode) {
    $allowed_pages[] = 'users'; // Add users page for admins
    $allowed_pages[] = 'export_users'; // Export users page for admins
    $allowed_pages[] = 'settings'; // Add settings page for admins
    $allowed_pages[] = 'manage_products'; // Add manage products for admins
}

// Special case: If admin is in god mode and tries to access admin pages, redirect to overview
if ($isGodMode && in_array($page, ['users', 'export_users', 'settings', 'manage_products'])) {
    redirect('dashboard.php?page=overview', 'Admin pages not available in god mode');
}

// Validate page parameter
if (!in_array($page, $allowed_pages)) {
    $page = 'overview';
}

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

// Handle actions (moved from individual page files)
if ($_POST['action'] ?? '') {
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

                // Process affiliate commission if applicable
                if ($aff && $aff !== $uid && $prod['affiliate_rate'] > 0) {
                    // Validate affiliate user exists and is active
                    $aff_user = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
                    $aff_user->execute([$aff]);
                    $aff_user = $aff_user->fetch();
                    
                    if ($aff_user && $aff_user['status'] === 'active') {
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

    /* ---------- Admin wallet actions (approve / reject) ---------- */
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

function validateAffiliateLink($ref_id, $pdo) {
    if (!$ref_id || $ref_id <= 0) return false;
    
    $stmt = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
    $stmt->execute([$ref_id]);
    $user = $stmt->fetch();
    
    return $user && $user['status'] === 'active';
}

function getProductEarnings($user_id, $pdo) {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN type = 'affiliate_bonus' THEN amount ELSE 0 END) as total_affiliate,
            COUNT(CASE WHEN type = 'affiliate_bonus' THEN 1 END) as total_sales,
            MAX(created_at) as last_sale
        FROM wallet_tx 
        WHERE user_id = ? AND type = 'affiliate_bonus'
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function getTopAffiliateProducts($pdo, $limit = 5) {
    return $pdo->prepare("
        SELECT p.*, 
            COUNT(wt.id) as sales_count,
            SUM(ABS(wt.amount)) as total_sales_value
        FROM products p
        LEFT JOIN wallet_tx wt ON wt.type = 'product_purchase' 
            AND ABS(wt.amount) = p.price * (1 - p.discount/100)
        WHERE p.active = 1 AND p.affiliate_rate > 0
        GROUP BY p.id
        ORDER BY sales_count DESC, total_sales_value DESC
        LIMIT ?
    ")->execute([$limit])->fetchAll();
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
    <title>Shoppe Club Dashboard</title>
    <script src="https://d3js.org/d3.v7.min.js" onload="console.log('D3.js loaded from CDN')" onerror="this.src='/js/d3.v7.min.js';console.error('CDN failed, loading local D3.js')"></script>
    <script src="https://cdn.tailwindcss.com" onload="console.log('Tailwind CSS loaded from CDN')" onerror="this.src='/css/tailwind.min.css';console.error('CDN failed, loading local Tailwind')"></script>
    <link href="css/style.css" rel="stylesheet">
    <script>
        /* ===================================================================
           Kill “unsaved changes” popup for internal links while in god-mode
           =================================================================== */
        <?php if ($isGodMode): ?>
        document.addEventListener('click', function (e) {
            const link = e.target.closest('a[href]');
            if (!link) return;                       // not a link
            if (link.hostname !== location.hostname) return; // external – leave warning
            if (link.target === '_blank') return;    // new tab – leave warning

            // Remove the beforeunload handler that dashboard.php installed
            window.removeEventListener('beforeunload', window._godModeUnload);
        });

        // Keep a reference to the handler so we can remove it
        window._godModeUnload = window.onbeforeunload;
        <?php endif; ?>
    </script>
</head>
<body class="bg-gray-100 font-sans">
    
    <!-- 🔥 GOD MODE BANNER -->
    <?php if ($isGodMode): ?>
    <div class="bg-gradient-to-r from-red-600 to-red-800 text-white shadow-lg border-b-4 border-red-900 sticky top-0 z-50">
        <div class="container mx-auto px-4 py-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <!-- God Mode Icon -->
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 text-yellow-300">
                            <svg class="w-full h-full animate-pulse" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                            </svg>
                        </div>
                        <span class="font-bold text-lg">GOD MODE ACTIVE</span>
                    </div>
                    
                    <!-- Session Info -->
                    <div class="hidden md:flex flex-col text-sm">
                        <div class="flex items-center space-x-1">
                            <span class="opacity-90">Admin:</span>
                            <span class="font-semibold"><?= htmlspecialchars($godModeData['admin_username']) ?></span>
                        </div>
                        <div class="flex items-center space-x-1">
                            <span class="opacity-90">Viewing:</span>
                            <span class="font-semibold"><?= htmlspecialchars($godModeData['target_username']) ?></span>
                            <span class="text-xs opacity-75">(ID: <?= $godModeData['target_user_id'] ?>)</span>
                        </div>
                    </div>
                    
                    <!-- Time Indicator -->
                    <div class="hidden lg:flex items-center text-sm opacity-90">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span id="god-mode-timer">
                            Active for <?= gmdate('i:s', time() - $godModeData['started_at']) ?>
                        </span>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="flex items-center space-x-3">
                    <!-- Warning -->
                    <div class="hidden md:flex items-center text-sm opacity-90">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                        <span>All actions are logged</span>
                    </div>
                    
                    <!-- Exit Button -->
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <button type="submit" name="exit_god_mode" value="1" 
                                onclick="return confirm('Exit god mode and return to admin dashboard?')"
                                class="bg-red-700 hover:bg-red-800 px-4 py-2 rounded-lg font-medium text-sm transition-colors flex items-center space-x-1 border border-red-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                            </svg>
                            <span>Exit God Mode</span>
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Mobile Info -->
            <div class="md:hidden mt-2 pt-2 border-t border-red-500 border-opacity-50">
                <div class="flex justify-between text-sm">
                    <div>
                        <span class="opacity-90">Admin:</span>
                        <span class="font-semibold"><?= htmlspecialchars($godModeData['admin_username']) ?></span>
                    </div>
                    <div>
                        <span class="opacity-90">Viewing:</span>
                        <span class="font-semibold"><?= htmlspecialchars($godModeData['target_username']) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="flex h-screen">
        <!-- Sidebar -->
        <div id="sidebar" class="bg-white w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 md:relative md:translate-x-0 transition-transform duration-300 ease-in-out">
            <div class="px-4">
                <h2 class="text-2xl font-bold text-gray-800">Shoppe Club</h2>
            </div>
            <nav class="space-y-1">
                <a href="dashboard.php?page=overview" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'overview' ? 'bg-blue-500 text-white' : '' ?>">Overview</a>
                
                <!-- Admin-only pages (show only for actual admins, not when viewing users in god mode) -->
                <?php if ($effectiveRole === 'admin' && !$isGodMode): ?>
                    <a href="dashboard.php?page=users" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'users' ? 'bg-blue-500 text-white' : '' ?>">Users</a>
                <?php endif; ?>
                
                <!-- Standard user pages (available to everyone, including god mode viewing) -->
                <a href="dashboard.php?page=binary" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'binary' ? 'bg-blue-500 text-white' : '' ?>">Binary Tree</a>
                <a href="dashboard.php?page=referrals" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'referrals' ? 'bg-blue-500 text-white' : '' ?>">Referrals</a>
                <a href="dashboard.php?page=leadership" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'leadership' ? 'bg-blue-500 text-white' : '' ?>">Matched Bonus</a>
                <a href="dashboard.php?page=mentor" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'mentor' ? 'bg-blue-500 text-white' : '' ?>">Mentor Bonus</a>
                <a href="dashboard.php?page=wallet" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'wallet' ? 'bg-blue-500 text-white' : '' ?>">Wallet</a>
                <a href="dashboard.php?page=store" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'store' ? 'bg-blue-500 text-white' : '' ?>">Package Store</a>
                <a href="<?= buildNavLink('product_store', $page) ?>" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'product_store' ? 'bg-blue-500 text-white' : '' ?>">Product Store</a>
                <a href="dashboard.php?page=affiliate" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'affiliate' ? 'bg-blue-500 text-white' : '' ?>">Affiliate</a>
                
                <!-- Admin-only settings and management (only for actual admins, not god mode) -->
                <?php if ($effectiveRole === 'admin' && !$isGodMode): ?>
                    <a href="dashboard.php?page=settings" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'settings' ? 'bg-blue-500 text-white' : '' ?>">Settings</a>
                    <a href="dashboard.php?page=manage_products" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'manage_products' ? 'bg-blue-500 text-white' : '' ?>">Manage Products</a>
                <?php endif; ?>
                
                <a href="logout.php" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Logout</a>
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
                                    <p class="font-bold">Account Inactive</p>
                                    <p class="text-sm">Your account is currently inactive. To activate your account and access all features, please <a href="dashboard.php?page=store" class="underline font-semibold">purchase a package</a>.</p>
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

    <script>
    // God Mode Timer and Security Features
    <?php if ($isGodMode): ?>
    // Update god mode timer every second
    setInterval(function() {
        const startTime = <?= $godModeData['started_at'] ?>;
        const currentTime = Math.floor(Date.now() / 1000);
        const elapsedSeconds = currentTime - startTime;
        const minutes = Math.floor(elapsedSeconds / 60);
        const seconds = elapsedSeconds % 60;
        
        const timerElement = document.getElementById('god-mode-timer');
        if (timerElement) {
            timerElement.textContent = `Active for ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        
        // Auto-refresh after 55 minutes to prevent session timeout
        if (elapsedSeconds >= 3300) { // 55 minutes
            window.location.reload();
        }
    }, 1000);

    // keep a reference so we can detach it for internal links
    window._godModeUnload = function (e) {
        const message = 'You are currently in God Mode. Are you sure you want to leave?';
        e.returnValue = message;
        return message;
    };
    window.addEventListener('beforeunload', window._godModeUnload);

    // Add visual indicator to distinguish god mode
    document.addEventListener('DOMContentLoaded', function() {
        // Add red border to body to indicate god mode
        document.body.style.borderTop = '4px solid #dc2626';
        
        // Add god mode class to body
        document.body.classList.add('god-mode-active');
        
        // Add subtle animation to remind user they're in god mode
        const style = document.createElement('style');
        style.textContent = `
            .god-mode-active {
                position: relative;
            }
            .god-mode-active::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, #dc2626, #ef4444, #dc2626);
                background-size: 200% 100%;
                animation: god-mode-pulse 3s ease-in-out infinite;
                z-index: 9999;
                pointer-events: none;
            }
            @keyframes god-mode-pulse {
                0%, 100% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
            }
        `;
        document.head.appendChild(style);
    });
    <?php endif; ?>

    // Sidebar toggle for mobile
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mainContent = document.querySelector('main');

    sidebarToggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('open');
    });

    // Close sidebar when clicking outside on mobile
    mainContent?.addEventListener('click', (e) => {
        if (window.innerWidth <= 640 && sidebar?.classList.contains('open')) {
            sidebar.classList.remove('open');
        }
    });

    // Initialize charts based on current page
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

    // Chart initialization will be handled by individual page scripts
    </script>
</body>
</html>