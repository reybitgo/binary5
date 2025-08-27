<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) redirect('login.php');

$uid = $_SESSION['user_id'];

// user info
$user = $pdo->prepare("SELECT u.*, w.balance
                       FROM users u
                       JOIN wallets w ON w.user_id = u.id
                       WHERE u.id = ?");
$user->execute([$uid]);
$user = $user->fetch();

// packages
$packages = $pdo->query("SELECT * FROM packages")->fetchAll();

// -------------------- BINARY TREE: ROOT = LOGGED-IN USER -------------------
$stmt = $pdo->query(
    "SELECT id, username, upline_id, position
     FROM users
     ORDER BY id ASC"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$map = [];
foreach ($rows as $r) {
    $map[$r['id']] = [
        'id'        => (int)$r['id'],
        'name'      => $r['username'],
        'upline_id' => $r['upline_id'] ? (int)$r['upline_id'] : null,
        'position'  => $r['position'],
        'left'      => null,
        'right'     => null,
    ];
}

// wire children
foreach ($map as $id => &$node) {
    $pid = $node['upline_id'];
    if (isset($map[$pid])) {
        if ($node['position'] === 'left')  $map[$pid]['left']  = &$node;
        if ($node['position'] === 'right') $map[$pid]['right'] = &$node;
    }
}
unset($node);

// force the logged-in user to be the tree root
$binaryRoot = $map[$uid] ?? null;

// -------------------- SPONSOR TREE: ROOT = LOGGED-IN USER -------------------
$stmt = $pdo->query(
    "SELECT id, username, sponsor_name
     FROM users
     ORDER BY id ASC"
);
$sponsorRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sponsorMap = [];
foreach ($sponsorRows as $r) {
    $sponsorMap[$r['id']] = [
        'id'           => (int)$r['id'],
        'name'         => $r['username'],
        'sponsor_name' => $r['sponsor_name'],
        'children'     => [],
    ];
}

// build parent → child links
foreach ($sponsorMap as $id => &$node) {
    $sponsorName = $node['sponsor_name'];
    if ($sponsorName) {
        foreach ($sponsorMap as $pid => &$parent) {
            if ($parent['name'] === $sponsorName) {
                $parent['children'][] = &$node;
                break;
            }
        }
    }
}
unset($node, $parent);

// force the logged-in user to be the root
$sponsorRoot = $sponsorMap[$uid] ?? null;

// Convert to D3-friendly format
function toD3Binary($node) {
    if (!$node) return null;
    $children = [];
    if ($node['left'])  $children[] = toD3Binary($node['left']);
    if ($node['right']) $children[] = toD3Binary($node['right']);
    return [
        'id'       => $node['id'],
        'name'     => $node['name'],
        'position' => $node['position'] ?: 'root',
        'treeType' => 'binary',
        'children' => $children
    ];
}

function toD3Sponsor($node) {
    if (!$node) return null;
    $children = [];
    foreach ($node['children'] as $child) {
        $children[] = toD3Sponsor($child);
    }
    return [
        'id'       => $node['id'],
        'name'     => $node['name'],
        'position' => 'sponsor',
        'treeType' => 'sponsor',
        'children' => $children
    ];
}

$binaryTreeJson = json_encode(toD3Binary($binaryRoot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sponsorTreeJson = json_encode(toD3Sponsor($sponsorRoot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function flash() {
    if (isset($_SESSION['flash'])) {
        $msg = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return "<div class='bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4' role='alert'>$msg</div>";
    }
    return '';
}

// Handle actions
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
            redirect('dashboard.php', 'Top-up request submitted');
            break;

        case 'request_withdraw':
            $amt = max(0, (float) $_POST['usdt_amount']);
            if ($amt <= 0) redirect('dashboard.php','Invalid amount');
            if ($amt > $user['balance']) redirect('dashboard.php','Insufficient balance');
            $addr = trim($_POST['wallet_address']);
            $b2p  = $amt * USDT_B2P_RATE;
            $pdo->prepare(
                "INSERT INTO ewallet_requests (user_id,type,usdt_amount,b2p_amount,wallet_address,status)
                 VALUES (?,'withdraw',?,?,?,'pending')"
            )->execute([$uid,$amt,$b2p,$addr]);
            redirect('dashboard.php','Withdrawal request submitted');
            break;

        case 'transfer':
            $toUser = $_POST['to_username'];
            $amt = (float) $_POST['amount'];
            if ($amt > $user['balance']) redirect('dashboard.php', 'Insufficient balance');
            $to = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $to->execute([$toUser]);
            $to = $to->fetch();
            if (!$to) redirect('dashboard.php', 'Recipient not found');
            $tid = $to['id'];
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")->execute([$amt,$uid]);
            $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")->execute([$amt,$tid]);
            $pdo->prepare("INSERT INTO wallet_tx (user_id,type,amount) VALUES (?,'transfer_out',?)")->execute([$uid,-$amt]);
            $pdo->prepare("INSERT INTO wallet_tx (user_id,type,amount) VALUES (?,'transfer_in',?)")->execute([$tid,$amt]);
            $pdo->commit();
            redirect('dashboard.php', 'Transfer completed');
            break;

        case 'buy_package':
            $pid = (int) $_POST['package_id'];
            $pkg = $pdo->prepare("SELECT * FROM packages WHERE id = ?");
            $pkg->execute([$pid]);
            $pkg = $pkg->fetch();
            if (!$pkg) redirect('dashboard.php', 'Package not found');
            if ($user['balance'] < $pkg['price']) redirect('dashboard.php', 'Insufficient balance');
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
            redirect('dashboard.php', 'Package purchased & commissions calculated');
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rixile</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
    <style>
    /* Org Chart Styles */
    :root {
        --bg: #ffffff;
        --panel: #f8f9fa;
        --stroke: #007bff;
        --stroke-faint: #6c757d;
        --text: #212529;
        --muted: #6c757d;
    }
    #orgChart, #sponsorChart {
        position: relative;
        height: 500px;
        width: 100%;
        background: var(--bg);
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid #dee2e6;
    }
    .link {
        fill: none;
        stroke: var(--stroke-faint);
        stroke-opacity: .85;
        stroke-width: 1.5px;
    }
    .node rect {
        fill: var(--panel);
        stroke-width: 1.25px;
        rx: 12px;
        ry: 12px;
        filter: drop-shadow(0 2px 4px rgba(0,0,0,.3));
    }
    .node.has-children rect {
        stroke: #007bff;
    }
    .node.no-children rect {
        stroke: #6c757d;
    }
    .node text {
        fill: var(--text);
        font-size: 13px;
        font-weight: 600;
        dominant-baseline: middle;
        text-anchor: middle;
    }
    .badge {
        fill: #e9ecef;
        stroke: var(--stroke);
        stroke-width: 1px;
    }
    .badge-text {
        fill: var(--muted);
        font-size: 10px;
        font-weight: 700;
    }
    .node:hover rect {
        stroke: #9db1ff;
        cursor: pointer;
    }
    .chart-toolbar {
        position: absolute;
        right: 12px;
        top: 12px;
        display: flex;
        gap: 8px;
        z-index: 10;
    }
    .chart-btn {
        background: #ffffff;
        color: #495057;
        border: 1px solid #ced4da;
        border-radius: 6px;
        padding: 6px 10px;
        cursor: pointer;
        font-weight: 600;
        user-select: none;
        font-size: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .chart-btn:hover {
        border-color: var(--stroke);
        background: #f8f9fa;
    }
    .chart-hint {
        position: absolute;
        left: 12px;
        bottom: 10px;
        background: rgba(248,249,250,0.95);
        padding: 8px 10px;
        border: 1px solid #ced4da;
        border-radius: 6px;
        font-size: 12px;
        color: #495057;
        user-select: none;
        z-index: 10;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    /* Sidebar toggle for mobile */
    #sidebarToggle {
        display: none;
    }
    #sidebar {
        z-index: 1000; /* High z-index to ensure sidebar is above charts */
    }
    @media (max-width: 640px) {
        #sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
        }
        #sidebar.open {
            transform: translateX(0);
            box-shadow: 2px 0 5px rgba(0,0,0,0.2); /* Add shadow for better visibility */
        }
        #sidebarToggle {
            display: block;
        }
    }
  </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <div id="sidebar" class="bg-white w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 md:relative md:translate-x-0 transition-transform duration-300 ease-in-out">
            <div class="px-4">
                <h2 class="text-2xl font-bold text-gray-800">Rixile</h2>
            </div>
            <nav class="space-y-1">
                <a href="#overview" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Overview</a>
                <a href="#binary" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Binary Tree</a>
                <a href="#referrals" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Referrals</a>
                <a href="#leadership" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Leadership</a>
                <a href="#mentor" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Mentor Bonus</a>
                <a href="#wallet" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Wallet</a>
                <a href="#store" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Package Store</a>
                <a href="#settings" class="block px-4 py-2 text-gray-600 hover:bg-blue-500 hover:text-white rounded-md">Settings</a>
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
                        <a href="#profile" class="text-gray-600 hover:text-blue-500">Profile</a>
                        <a href="#settings" class="text-gray-600 hover:text-blue-500">Settings</a>
                        <a href="logout.php" class="text-gray-600 hover:text-blue-500">Logout</a>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <main class="flex-1 overflow-y-auto bg-gray-100 p-4 sm:p-6 lg:p-8">
                <div class="max-w-7xl mx-auto">
                    <?=flash()?>

                    <!-- Overview Section -->
                    <section id="overview" class="mb-8">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Welcome back, <?=htmlspecialchars($user['username'])?>!</h2>
                        <p class="text-gray-600 mb-6">Here's what's happening with your MLM business today.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="bg-white shadow rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-700">Wallet Balance</h3>
                                <p class="text-2xl text-blue-500">$<?=number_format($user['balance'], 2)?></p>
                            </div>
                            <div class="bg-white shadow rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-700">Total Referrals</h3>
                                <p class="text-2xl text-blue-500">
                                    <?php
                                    $directs = $pdo->prepare("SELECT COUNT(*) FROM users WHERE sponsor_name = ?");
                                    $directs->execute([$user['username']]);
                                    echo $directs->fetchColumn();
                                    ?>
                                </p>
                            </div>
                            <div class="bg-white shadow rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-700">Pairs Today</h3>
                                <p class="text-2xl text-blue-500"><?=$user['pairs_today']?>/10</p>
                            </div>
                            <div class="bg-white shadow rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-700">Team Volume</h3>
                                <p class="text-2xl text-blue-500">
                                    <?php
                                    include 'functions.php';
                                    $volume = getGroupVolume($uid, $pdo, 0);
                                    echo '$'.number_format($volume, 2);
                                    ?>
                                </p>
                            </div>
                        </div>
                        <div class="mt-6 bg-white shadow rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">Quick Actions</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                <form method="post" class="flex"><input type="hidden" name="action" value="buy_package"><button type="submit" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600">Buy Package</button></form>
                                <form method="post" class="flex"><input type="hidden" name="action" value="request_topup"><button type="submit" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600">Top Up</button></form>
                                <form method="post" class="flex"><input type="hidden" name="action" value="request_withdraw"><button type="submit" class="w-full bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600">Withdraw</button></form>
                                <form method="post" class="flex"><input type="hidden" name="action" value="transfer"><button type="submit" class="w-full bg-blue-400 text-white py-2 rounded-lg hover:bg-blue-500">Transfer</button></form>
                            </div>
                        </div>
                        <div class="mt-6 bg-white shadow rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">Recent Transactions</h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="text-gray-600">
                                            <th class="p-2">Type</th>
                                            <th class="p-2">Amount</th>
                                            <th class="p-2">Date</th>
                                            <th class="p-2">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $tx = $pdo->prepare("SELECT * FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 20");
                                        $tx->execute([$uid]);
                                        foreach ($tx as $t) {
                                            echo "<tr class='border-t'>
                                                    <td class='p-2'>" . htmlspecialchars($t['type']) . "</td>
                                                    <td class='p-2'>" . ($t['amount'] >= 0 ? '+' : '') . '$' . number_format(abs($t['amount']), 2) . "</td>
                                                    <td class='p-2'>" . $t['created_at'] . "</td>
                                                    <td class='p-2'>Completed</td>
                                                  </tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- Binary Tree Section -->
                    <section id="binary" class="mb-8 hidden">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Binary Tree Structure</h2>
                        <p class="text-gray-600 mb-6">View your binary organization and pair statistics</p>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                            <div class="bg-white shadow rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-700">Left Count</h3>
                                <p class="text-2xl text-blue-500"><?=$user['left_count']?></p>
                            </div>
                            <div class="bg-white shadow rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-700">Right Count</h3>
                                <p class="text-2xl text-blue-500"><?=$user['right_count']?></p>
                            </div>
                            <div class="bg-white shadow rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-700">Pairs Today</h3>
                                <p class="text-2xl text-blue-500"><?=$user['pairs_today']?>/10</p>
                            </div>
                        </div>
                        <div class="bg-white shadow rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">Binary Organization Chart</h3>
                            <div id="orgChart">
                                <div class="chart-toolbar">
                                    <div class="chart-btn" id="resetZoom">Reset</div>
                                    <div class="chart-btn" id="expandAll">Expand All</div>
                                    <div class="chart-btn" id="collapseAll">Collapse All</div>
                                </div>
                                <div class="chart-hint">scroll to zoom • drag background to pan • click a node to collapse/expand</div>
                            </div>
                        </div>
                    </section>

                    <!-- Referrals Section -->
                    <section id="referrals" class="mb-8 hidden">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Referral Network</h2>
                        <p class="text-gray-600 mb-6">Manage your direct referrals and track commissions</p>
                        <div class="bg-white shadow rounded-lg p-6 mb-6">
                            <h3 class="text-lg font-semibold text-gray-700">Total Referral Bonus Earned</h3>
                            <p class="text-2xl text-blue-500">$<?php
                                $tot = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_tx WHERE user_id = ? AND type = 'referral_bonus'");
                                $tot->execute([$uid]);
                                echo number_format((float)$tot->fetchColumn(), 2);
                            ?></p>
                        </div>
                        <div class="bg-white shadow rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">Direct Referrals & Earnings</h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="text-gray-600">
                                            <th class="p-2">Direct</th>
                                            <th class="p-2">Referral Earned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $directs = $pdo->prepare("
                                            SELECT d.username,
                                                   COALESCE(SUM(rt.amount),0) AS earned
                                            FROM users d
                                            JOIN wallet_tx pkg_tx
                                                ON pkg_tx.user_id = d.id
                                                AND pkg_tx.type = 'package'
                                                AND pkg_tx.amount < 0
                                            JOIN wallet_tx rt
                                                ON rt.user_id = (SELECT id FROM users WHERE username = d.sponsor_name)
                                                AND rt.type = 'referral_bonus'
                                                AND rt.created_at BETWEEN pkg_tx.created_at AND DATE_ADD(pkg_tx.created_at, INTERVAL 1 SECOND)
                                            WHERE d.sponsor_name = (SELECT username FROM users WHERE id = ?)
                                            GROUP BY d.id
                                            ORDER BY d.username
                                        ");
                                        $directs->execute([$uid]);
                                        foreach ($directs as $d) {
                                            echo "<tr class='border-t'>
                                                    <td class='p-2'>" . htmlspecialchars($d['username']) . "</td>
                                                    <td class='p-2'>$" . number_format((float)$d['earned'], 2) . "</td>
                                                  </tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- Leadership Section -->
                    <section id="leadership" class="mb-8 hidden">
                        <?php
                        function getIndirects(PDO $pdo, int $rootId, int $maxLevel = 5): array {
                            $allRows = [];
                            $current = [$rootId];
                            for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
                                if (!$current) break;
                                $placeholders = implode(',', array_fill(0, count($current), '?'));
                                $sql = "SELECT id, username FROM users WHERE sponsor_name IN (SELECT username FROM users WHERE id IN ($placeholders))";
                                $stmt = $pdo->prepare($sql);
                                $stmt->execute($current);
                                $next = [];
                                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $row['lvl'] = $lvl;
                                    $allRows[] = $row;
                                    $next[] = (int)$row['id'];
                                }
                                $current = $next;
                            }
                            return $allRows;
                        }
                        $indirects = getIndirects($pdo, $uid);
                        ?>
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Leadership</h2>
                        <div class="bg-white shadow rounded-lg p-6 mb-6">
                            <h3 class="text-lg font-semibold text-gray-700">Total Matched Bonus Earned</h3>
                            <p class="text-2xl text-blue-500">$<?php
                                $tot = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_tx WHERE user_id = ? AND type = 'leadership_bonus'");
                                $tot->execute([$uid]);
                                echo number_format((float)$tot->fetchColumn(), 2);
                            ?></p>
                        </div>
                        <div class="bg-white shadow rounded-lg p-6 mb-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">Sponsorship Tree</h3>
                            <div id="sponsorChart">
                                <div class="chart-toolbar">
                                    <div class="chart-btn" id="resetZoomSponsor">Reset</div>
                                    <div class="chart-btn" id="expandAllSponsor">Expand All</div>
                                    <div class="chart-btn" id="collapseAllSponsor">Collapse All</div>
                                </div>
                                <div class="chart-hint">scroll to zoom • drag background to pan • click a node to collapse/expand</div>
                            </div>
                        </div>
                        <div class="bg-white shadow rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">Indirect Down-lines & Leadership Paid</h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="text-gray-600">
                                            <th class="p-2">Indirect</th>
                                            <th class="p-2">Level</th>
                                            <th class="p-2">Leadership Earned</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($indirects as $ind) {
                                            $stmt = $pdo->prepare(
                                                "SELECT COALESCE(SUM(amount),0)
                                                 FROM wallet_tx
                                                 WHERE user_id = ?
                                                   AND type = 'leadership_bonus'
                                                   AND created_at >= (
                                                       SELECT MIN(created_at)
                                                       FROM wallet_tx
                                                       WHERE user_id = ?
                                                         AND type = 'pair_bonus'
                                                   )"
                                            );
                                            $stmt->execute([$uid, $ind['id']]);
                                            $earned = (float)$stmt->fetchColumn();
                                            echo "<tr class='border-t'>
                                                    <td class='p-2'>" . htmlspecialchars($ind['username']) . "</td>
                                                    <td class='p-2'>L-" . $ind['lvl'] . "</td>
                                                    <td class='p-2'>$" . number_format($earned, 2) . "</td>
                                                  </tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- Mentor Section -->
                    <section id="mentor" class="mb-8 hidden">
                        <?php
                        function getAncestors(PDO $pdo, int $rootId, int $maxLevel = 5): array {
                            $allRows = [];
                            $currentId = $rootId;
                            for ($lvl = 1; $lvl <= $maxLevel; $lvl++) {
                                $stmt = $pdo->prepare('SELECT upline_id, username FROM users WHERE id = ?');
                                $stmt->execute([$currentId]);
                                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                                if (!$row || !$row['upline_id']) break;
                                $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
                                $stmt->execute([$row['upline_id']]);
                                $ancestor = $stmt->fetch(PDO::FETCH_ASSOC);
                                if (!$ancestor) break;
                                $ancestor['lvl'] = $lvl;
                                $allRows[] = $ancestor;
                                $currentId = $ancestor['id'];
                            }
                            return $allRows;
                        }
                        $ancestors = getAncestors($pdo, $uid);
                        ?>
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Mentor Bonus</h2>
                        <div class="bg-white shadow rounded-lg p-6 mb-6">
                            <h3 class="text-lg font-semibold text-gray-700">Total Mentor Bonus Received</h3>
                            <p class="text-2xl text-blue-500">$<?php
                                $tot = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_tx WHERE user_id = ? AND type = 'leadership_reverse_bonus'");
                                $tot->execute([$uid]);
                                echo number_format((float)$tot->fetchColumn(), 2);
                            ?></p>
                        </div>
                        <div class="bg-white shadow rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">Ancestors & Mentor Bonus Received</h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="text-gray-600">
                                            <th class="p-2">Ancestor</th>
                                            <th class="p-2">Level</th>
                                            <th class="p-2">Mentor Bonus Received</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($ancestors as $anc) {
                                            $stmt = $pdo->prepare(
                                                "SELECT COALESCE(SUM(amount),0)
                                                 FROM wallet_tx
                                                 WHERE user_id = ?
                                                   AND type = 'leadership_reverse_bonus'
                                                   AND created_at >= (
                                                       SELECT MIN(created_at)
                                                       FROM wallet_tx
                                                       WHERE user_id = ?
                                                         AND type = 'pair_bonus'
                                                   )"
                                            );
                                            $stmt->execute([$uid, $anc['id']]);
                                            $received = (float)$stmt->fetchColumn();
                                            echo "<tr class='border-t'>
                                                    <td class='p-2'>" . htmlspecialchars($anc['username']) . "</td>
                                                    <td class='p-2'>L-" . $anc['lvl'] . "</td>
                                                    <td class='p-2'>$" . number_format($received, 2) . "</td>
                                                  </tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- Wallet Section -->
                    <section id="wallet" class="mb-8 hidden">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Wallet</h2>
                        <div class="bg-white shadow rounded-lg p-6 mb-6">
                            <h3 class="text-lg font-semibold text-gray-700">Balance: $<?=number_format($user['balance'], 2)?></h3>
                            <div class="mt-4 space-y-4">
                                <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <input type="hidden" name="action" value="request_topup">
                                    <input type="number" step="0.01" class="border rounded-lg p-2" name="usdt_amount" placeholder="USDT amount" required>
                                    <input type="text" class="border rounded-lg p-2" name="tx_hash" placeholder="Blockchain TX Hash (optional)">
                                    <button class="bg-green-500 text-white py-2 rounded-lg hover:bg-green-600">Request Top-up</button>
                                </form>
                                <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <input type="hidden" name="action" value="request_withdraw">
                                    <input type="number" step="0.01" class="border rounded-lg p-2" name="usdt_amount" placeholder="USDT amount" required>
                                    <input type="text" class="border rounded-lg p-2" name="wallet_address" placeholder="USDT TRC-20 Address" required>
                                    <button class="bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600">Request Withdraw</button>
                                </form>
                                <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <input type="text" class="border rounded-lg p-2" name="to_username" placeholder="Username" required>
                                    <input type="number" step="0.01" class="border rounded-lg p-2" name="amount" placeholder="Amount" required>
                                    <button class="bg-blue-400 text-white py-2 rounded-lg hover:bg-blue-500" name="action" value="transfer">Transfer</button>
                                </form>
                            </div>
                            <?php
                            $pend = $pdo->prepare("SELECT * FROM ewallet_requests WHERE user_id = ? AND status='pending' ORDER BY id DESC");
                            $pend->execute([$uid]);
                            if ($pend->rowCount()):
                            ?>
                            <div class="mt-6">
                                <h4 class="text-md font-semibold text-gray-700 mb-2">Pending Requests</h4>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left">
                                        <thead>
                                            <tr class="text-gray-600">
                                                <th class="p-2">Type</th>
                                                <th class="p-2">Amount</th>
                                                <th class="p-2">Status</th>
                                                <th class="p-2">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($pend as $r): ?>
                                            <tr class="border-t">
                                                <td class="p-2"><?=ucfirst($r['type'])?></td>
                                                <td class="p-2"><?=$r['usdt_amount']?> USDT</td>
                                                <td class="p-2"><span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Pending</span></td>
                                                <td class="p-2"><?=$r['created_at']?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="bg-white shadow rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-700 mb-4">Transactions</h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="text-gray-600">
                                            <th class="p-2">Type</th>
                                            <th class="p-2">Amount</th>
                                            <th class="p-2">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $tx = $pdo->prepare("SELECT * FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 20");
                                        $tx->execute([$uid]);
                                        foreach ($tx as $t) {
                                            echo "<tr class='border-t'>
                                                    <td class='p-2'>" . htmlspecialchars($t['type']) . "</td>
                                                    <td class='p-2'>" . ($t['amount'] >= 0 ? '+' : '') . '$' . number_format(abs($t['amount']), 2) . "</td>
                                                    <td class='p-2'>" . $t['created_at'] . "</td>
                                                  </tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- Package Store Section -->
                    <section id="store" class="mb-8 hidden">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Package Store</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($packages as $p): ?>
                            <div class="bg-white shadow rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-700"><?=htmlspecialchars($p['name'])?></h3>
                                <p class="text-2xl text-blue-500">$<?=number_format($p['price'], 2)?></p>
                                <p class="text-gray-600"><?=$p['pv']?> PV</p>
                                <form method="post" class="mt-4">
                                    <input type="hidden" name="package_id" value="<?=$p['id']?>">
                                    <button class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600" name="action" value="buy_package">Buy Now</button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- Settings Section (Placeholder) -->
                    <section id="settings" class="mb-8 hidden">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Settings</h2>
                        <div class="bg-white shadow rounded-lg p-6">
                            <p class="text-gray-600">Settings functionality is not implemented yet.</p>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </div>

    <script>
    // Sidebar toggle for mobile
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mainContent = document.querySelector('main');

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
    });

    // Close sidebar when clicking outside on mobile
    mainContent.addEventListener('click', (e) => {
        if (window.innerWidth <= 640 && sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
        }
    });

    // Section navigation
    document.querySelectorAll('nav a').forEach(link => {
        link.addEventListener('click', (e) => {
            // Skip section toggle for logout link
            if (link.getAttribute('href') === 'logout.php') {
                return; // Allow default navigation to logout.php
            }

            e.preventDefault();
            const targetId = link.getAttribute('href').substring(1);
            document.querySelectorAll('main section').forEach(section => {
                section.classList.add('hidden');
            });
            document.getElementById(targetId).classList.remove('hidden');
            document.querySelectorAll('nav a').forEach(nav => {
                nav.classList.remove('bg-blue-500', 'text-white');
                nav.classList.add('text-gray-600');
            });
            link.classList.add('bg-blue-500', 'text-white');
            link.classList.remove('text-gray-600');

            // Close sidebar on link click in mobile view
            if (window.innerWidth <= 640) {
                sidebar.classList.remove('open');
            }

            // Initialize charts for specific sections
            if (targetId === 'binary') {
                initBinaryChart();
            } else if (targetId === 'leadership') {
                initSponsorChart();
            }
        });
    });

    // Initialize Overview section by default
    document.getElementById('overview').classList.remove('hidden');
    document.querySelector('a[href="#overview"]').classList.add('bg-blue-500', 'text-white');
    document.querySelector('a[href="#overview"]').classList.remove('text-gray-600');

    // D3.js Chart Logic
    const binaryData = <?php echo $binaryTreeJson ?: 'null'; ?>;
    const sponsorData = <?php echo $sponsorTreeJson ?: 'null'; ?>;

    let binaryChartInitialized = false;
    let sponsorChartInitialized = false;

    function initBinaryChart() {
        if (binaryChartInitialized || !binaryData || !binaryData.id) {
            return;
        }
        binaryChartInitialized = true;
        renderOrgChart(binaryData, 'orgChart', 'resetZoom', 'expandAll', 'collapseAll');
    }

    function initSponsorChart() {
        if (sponsorChartInitialized || !sponsorData || !sponsorData.id) {
            return;
        }
        sponsorChartInitialized = true;
        renderOrgChart(sponsorData, 'sponsorChart', 'resetZoomSponsor', 'expandAllSponsor', 'collapseAllSponsor');
    }

    function renderOrgChart(rootData, containerId, resetId, expandId, collapseId) {
        const container = document.getElementById(containerId);
        let width = container.clientWidth;
        let height = container.clientHeight;

        d3.select(`#${containerId} svg`).remove();

        const svg = d3.select(`#${containerId}`).append('svg')
            .attr('width', width)
            .attr('height', height)
            .attr('viewBox', [0, 0, width, height].join(' '))
            .style('display', 'block');

        const g = svg.append('g');
        const linkGroup = g.append('g').attr('class', 'links');
        const nodeGroup = g.append('g').attr('class', 'nodes');

        const duration = 300;
        const nodeWidth = 160;
        const nodeHeight = 42;
        const levelGapY = 95;
        const siblingGapX = 26;

        const root = d3.hierarchy(rootData, d => d.children);
        root.x0 = width / 2;
        root.y0 = 40;
        if (root.children) root.children.forEach(collapse);

        const tree = d3.tree()
            .nodeSize([nodeWidth + siblingGapX, levelGapY])
            .separation((a, b) => a.parent === b.parent ? 1 : 1.2);

        const zoom = d3.zoom()
            .scaleExtent([0.3, 2.5])
            .on('zoom', (event) => g.attr('transform', event.transform));
        svg.call(zoom);

        update(root);
        centerOnRoot();

        document.getElementById(resetId).onclick = () => { centerOnRoot(); };
        document.getElementById(expandId).onclick = () => { expandAll(root); update(root); };
        document.getElementById(collapseId).onclick = () => { collapseAll(root); update(root); };

        window.addEventListener('resize', () => {
            width = container.clientWidth;
            height = container.clientHeight;
            svg.attr('width', width).attr('height', height).attr('viewBox', [0,0,width,height].join(' '));
            centerOnRoot();
        });

        function centerOnRoot() {
            const currentTransform = d3.zoomTransform(svg.node());
            const scale = 0.9;
            const tx = width / 2 - (root.x0 ?? root.x) * scale;
            const ty = 60 - (root.y0 ?? root.y) * scale;
            svg.transition().duration(300).call(zoom.transform, d3.zoomIdentity.translate(tx, ty).scale(scale));
        }

        function update(source) {
            tree(root);
            root.each(d => {
                d.y = d.depth * levelGapY + 80;
            });

            const link = linkGroup.selectAll('path.link')
                .data(root.links(), d => d.target.data.id);

            link.enter().append('path')
                .attr('class', 'link')
                .attr('d', d => elbow({source: source, target: source}))
                .merge(link)
                .transition().duration(duration)
                .attr('d', d => elbow(d));

            link.exit()
                .transition().duration(duration)
                .attr('d', d => elbow({source: source, target: source}))
                .remove();

            const node = nodeGroup.selectAll('g.node')
                .data(root.descendants(), d => d.data.id);

            const nodeEnter = node.enter().append('g')
                .attr('class', d => {
                    const hasChildren = (d.children && d.children.length > 0) || (d._children && d._children.length > 0);
                    return hasChildren ? 'node has-children' : 'node no-children';
                })
                .attr('transform', d => `translate(${source.x0 ?? source.x},${source.y0 ?? source.y})`)
                .on('click', (event, d) => {
                    toggle(d);
                    update(d);
                });

            nodeEnter.append('rect')
                .attr('width', nodeWidth)
                .attr('height', nodeHeight)
                .attr('x', -nodeWidth/2)
                .attr('y', -nodeHeight/2);

            nodeEnter.append('text')
                .attr('dy', 3)
                .text(d => d.data.name);

            const isBinaryTree = rootData.treeType === 'binary';
            if (isBinaryTree) {
                const badgeW = 20, badgeH = 16;
                nodeEnter.append('rect')
                    .attr('class', 'badge')
                    .attr('width', badgeW).attr('height', badgeH)
                    .attr('x', -nodeWidth/2 + 8)
                    .attr('y', -nodeHeight/2 + 6)
                    .attr('rx', 6).attr('ry', 6);

                nodeEnter.append('text')
                    .attr('class', 'badge-text')
                    .attr('x', -nodeWidth/2 + 8 + badgeW/2)
                    .attr('y', -nodeHeight/2 + 6 + badgeH/2 + 1)
                    .attr('text-anchor', 'middle')
                    .text(d => d.depth === 0 ? '' : (d.data.position === 'left' ? 'L' : 'R'));
            }

            const nodeUpdate = nodeEnter.merge(node);
            nodeUpdate.attr('class', d => {
                const hasChildren = (d.children && d.children.length > 0) || (d._children && d._children.length > 0);
                return hasChildren ? 'node has-children' : 'node no-children';
            });
            nodeUpdate.transition().duration(duration)
                .attr('transform', d => `translate(${d.x},${d.y})`);

            node.exit()
                .transition().duration(duration)
                .attr('transform', d => `translate(${source.x},${source.y})`)
                .remove();

            root.each(d => { d.x0 = d.x; d.y0 = d.y; });
        }

        function elbow(d) {
            const sx = d.source.x, sy = d.source.y;
            const tx = d.target.x, ty = d.target.y;
            const my = (sy + ty) / 2;
            return `M${sx},${sy} C${sx},${my} ${tx},${my} ${tx},${ty}`;
        }

        function toggle(d) {
            if (d.children) {
                d._children = d.children;
                d.children = null;
            } else {
                d.children = d._children;
                d._children = null;
            }
        }
        function collapse(d) {
            if (d.children) {
                d._children = d.children;
                d._children.forEach(collapse);
                d.children = null;
            }
        }
        function expand(d) {
            if (d._children) {
                d.children = d._children;
                d._children = null;
            }
            if (d.children) d.children.forEach(expand);
        }
        function collapseAll(d) {
            d.children && d.children.forEach(collapseAll);
            if (d !== root) collapse(d);
        }
        function expandAll(d) {
            expand(d);
        }
    }
</script>
</body>
</html>