<?php
// dashboard.php - Main user dashboard with navigation and dynamic content loading
require 'config.php';
if (!isset($_SESSION['user_id'])) redirect('login.php');

$uid = $_SESSION['user_id'];

// Get current page from URL parameter, default to 'overview'
$page = $_GET['page'] ?? 'overview';
$allowed_pages = ['overview', 'binary', 'referrals', 'leadership', 'mentor', 'wallet', 'store', 'settings'];

// Validate page parameter
if (!in_array($page, $allowed_pages)) {
    $page = 'overview';
}

// User info
$user = $pdo->prepare("SELECT u.*, w.balance
                       FROM users u
                       JOIN wallets w ON w.user_id = u.id
                       WHERE u.id = ?");
$user->execute([$uid]);
$user = $user->fetch();

// Handle actions (moved from individual page files)
if ($_POST['action'] ?? '') {
    switch ($_POST['action']) {
        case 'request_topup':
            $amt = max(0, (float) $_POST['usdt_amount']);
            if ($amt <= 0) redirect('dashboard.php', 'Invalid amount');
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
            $b2p  = $amt * USDT_B2P_RATE;
            $pdo->prepare(
                "INSERT INTO ewallet_requests (user_id,type,usdt_amount,b2p_amount,wallet_address,status)
                 VALUES (?,'withdraw',?,?,?,'pending')"
            )->execute([$uid,$amt,$b2p,$addr]);
            redirect('dashboard.php?page=wallet','Withdrawal request submitted');
            break;

        case 'transfer':
            $toUser = $_POST['to_username'];
            $amt = (float) $_POST['amount'];
            if ($amt > $user['balance']) redirect('dashboard.php?page=wallet', 'Insufficient balance');
            $to = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $to->execute([$toUser]);
            $to = $to->fetch();
            if (!$to) redirect('dashboard.php?page=wallet', 'Recipient not found');
            $tid = $to['id'];
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")->execute([$amt,$uid]);
            $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")->execute([$amt,$tid]);
            $pdo->prepare("INSERT INTO wallet_tx (user_id,type,amount) VALUES (?,'transfer_out',?)")->execute([$uid,-$amt]);
            $pdo->prepare("INSERT INTO wallet_tx (user_id,type,amount) VALUES (?,'transfer_in',?)")->execute([$tid,$amt]);
            $pdo->commit();
            redirect('dashboard.php?page=wallet', 'Transfer completed');
            break;

        case 'buy_package':
            $pid = (int) $_POST['package_id'];
            $pkg = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
            $pkg->execute([$pid]);
            $pkg = $pkg->fetch();
            if (!$pkg) redirect('dashboard.php?page=store', 'Package not found');
            if ($user['balance'] < $pkg['price']) redirect('dashboard.php?page=store', 'Insufficient balance');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")
                ->execute([$pkg['price'],$uid]);
            $pdo->prepare("INSERT INTO wallet_tx (user_id,type,amount) VALUES (?,'package',?)")
                ->execute([$uid,-$pkg['price']]);
            include 'binary_calc.php';
            calc_binary($uid, $pkg['pv'], $pdo);
            include 'referral_calc.php';
            calc_referral($uid, $pkg['price'], $pdo);
            $pdo->commit();
            redirect('dashboard.php?page=store', 'Package purchased & commissions calculated');
            break;
    }
}

function flash() {
    if (isset($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return "<div class='bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4' role='alert'>$msg</div>";
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
                <a href="dashboard.php?page=binary" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'binary' ? 'bg-blue-500 text-white' : '' ?>">Binary Tree</a>
                <a href="dashboard.php?page=referrals" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'referrals' ? 'bg-blue-500 text-white' : '' ?>">Referrals</a>
                <a href="dashboard.php?page=leadership" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'leadership' ? 'bg-blue-500 text-white' : '' ?>">Matched Bonus</a>
                <a href="dashboard.php?page=mentor" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'mentor' ? 'bg-blue-500 text-white' : '' ?>">Mentor Bonus</a>
                <a href="dashboard.php?page=wallet" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'wallet' ? 'bg-blue-500 text-white' : '' ?>">Wallet</a>
                <a href="dashboard.php?page=store" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'store' ? 'bg-blue-500 text-white' : '' ?>">Package Store</a>
                <a href="dashboard.php?page=settings" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md <?= $page === 'settings' ? 'bg-blue-500 text-white' : '' ?>">Settings</a>
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
                        <h1 class="text-xl font-semibold text-gray-800 ml-4">Dashboard - <?=htmlspecialchars($user['username'])?></h1>
                    </div>
                    <div class="flex items-center space-x-4">
                        <a href="dashboard.php?page=settings" class="text-gray-600 hover:text-blue-500">Settings</a>
                        <a href="logout.php" class="text-gray-600 hover:text-blue-500">Logout</a>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <main class="flex-1 overflow-y-auto bg-gray-100 p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl mx-auto">
                    <?=flash()?>
                    
                    <?php 
                    // Include the appropriate page content
                    $page_file = "pages/{$page}.php";
                    if (file_exists($page_file)) {
                        require $page_file;
                    } else {
                        echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded'>Page not found.</div>";
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
            initBinaryChart();
        } else if (currentPage === 'leadership') {
            initSponsorChart();
        }
    });

    // Chart initialization will be handled by individual page scripts
    </script>
</body>
</html>