<?php
ob_start();
// dashboard.php - Main user dashboard with navigation and dynamic content loading
require_once 'config.php';
// require_once 'functions.php';
if (!isset($_SESSION['user_id'])) redirect('login.php');

// Fetch user data
$uid = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$uid]);
$user = $stmt->fetch();
$role = $user['role'];

// Check if user is inactive and redirect them to store or show message
if ($user['status'] === 'inactive' && !in_array($_GET['page'] ?? 'overview', ['overview', 'store', 'wallet', 'profile', 'product_store', 'affiliate'])) {
    redirect('dashboard.php?page=store', 'Your account is inactive. Please purchase a package to activate your account.');
}

// Get current page from URL parameter, default to 'overview'
$page = $_GET['page'] ?? 'overview';
$allowed_pages = ['overview', 'binary', 'referrals', 'leadership', 'mentor', 'wallet', 'store', 'profile', 'product_store', 'affiliate'];

if ($role === 'admin') {
    $allowed_pages[] = 'users'; // Add users page for admins
    $allowed_pages[] = 'settings'; // Add settings page for admins
    $allowed_pages[] = 'manage_products'; // Add manage products for admins
}

// Validate page parameter
if (!in_array($page, $allowed_pages)) {
    $page = 'overview';
}

// User info (with wallet balance)
$user = $pdo->prepare("SELECT u.*, w.balance
                       FROM users u
                       LEFT JOIN wallets w ON w.user_id = u.id
                       WHERE u.id = ?");
$user->execute([$uid]);
$user = $user->fetch();

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

            // Check if user account is active
            if ($user['status'] !== 'active') {
                redirect('dashboard.php?page=product_store', 'Your account must be active to make purchases. Please activate your account first.');
            }

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
    if ($page === 'wallet' && $user['role'] === 'admin' && ($_POST['action'] ?? '')) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rixile Dashboard</title>
    <script src="https://d3js.org/d3.v7.min.js" onload="console.log('D3.js loaded from CDN')" onerror="this.src='/js/d3.v7.min.js';console.error('CDN failed, loading local D3.js')"></script>
    <script src="https://cdn.tailwindcss.com" onload="console.log('Tailwind CSS loaded from CDN')" onerror="this.src='/css/tailwind.min.css';console.error('CDN failed, loading local Tailwind')"></script>
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="bg-gray-100 font-sans">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div id="sidebar" class="bg-white w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 md:relative md:translate-x-0 transition-transform duration-300 ease-in-out">
            <div class="px-4">
                <h2 class="text-2xl font-bold text-gray-800">Rixile</h2>
            </div>
            <nav class="space-y-1">
                <a href="dashboard.php?page=overview" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'overview' ? 'bg-blue-500 text-white' : '' ?>">Overview</a>
                <?php if ($role === 'admin'): ?>
                    <a href="dashboard.php?page=users" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'users' ? 'bg-blue-500 text-white' : '' ?>">Users</a>                    
                <?php endif; ?>
                <a href="dashboard.php?page=binary" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'binary' ? 'bg-blue-500 text-white' : '' ?>">Binary Tree</a>
                <a href="dashboard.php?page=referrals" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'referrals' ? 'bg-blue-500 text-white' : '' ?>">Referrals</a>
                <a href="dashboard.php?page=leadership" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'leadership' ? 'bg-blue-500 text-white' : '' ?>">Matched Bonus</a>
                <a href="dashboard.php?page=mentor" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'mentor' ? 'bg-blue-500 text-white' : '' ?>">Mentor Bonus</a>
                <a href="dashboard.php?page=wallet" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'wallet' ? 'bg-blue-500 text-white' : '' ?>">Wallet</a>
                <a href="dashboard.php?page=store" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'store' ? 'bg-blue-500 text-white' : '' ?>">Package Store</a>
                <a href="dashboard.php?page=product_store" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'product_store' ? 'bg-blue-500 text-white' : '' ?>">Product Store</a>
                <a href="dashboard.php?page=affiliate" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'affiliate' ? 'bg-blue-500 text-white' : '' ?>">Affiliate</a>
                <?php if ($role === 'admin'): ?>
                    <a href="dashboard.php?page=settings" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'settings' ? 'bg-blue-500 text-white' : '' ?>">Settings</a>
                <?php endif; ?>
                <?php if ($role === 'admin'): ?>
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
                    
                    <?php 
                    // Include the appropriate page content
                    if ($page === 'wallet' && $user['role'] === 'admin') {
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