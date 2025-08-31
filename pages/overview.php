<!-- pages/overview.php -->
<!-- Overview Section -->
<h2 class="text-2xl font-bold text-gray-800 mb-4">Welcome back, <?=htmlspecialchars($user['username'])?>!</h2>
<p class="text-gray-600 mb-6">Here's what's happening with your MLM business today.</p>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <a href="dashboard.php?page=wallet">
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-700">Wallet Balance</h3>
            <p class="text-2xl text-blue-500">$<?=number_format($user['balance'], 2)?></p>
        </div>
    </a>
    <a href="dashboard.php?page=referrals">    
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-700">Total Referrals</h3>
            <p class="text-2xl text-blue-500">
                <?php
                $directs = $pdo->prepare("SELECT COUNT(*) FROM users WHERE sponsor_id = ?");
                $directs->execute([$user['username']]);
                echo $directs->fetchColumn();
                ?>
            </p>
        </div>
    </a>
    <a href="dashboard.php?page=binary">
        <div class="bg-white shadow rounded-lg p-6">
            <h3 class="text-lg font-semibold text-gray-700">Pairs Today</h3>
            <p class="text-2xl text-blue-500"><?=$user['pairs_today']?>/10</p>
        </div>
    </a>
    <a href="dashboard.php?page=leadership">
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
    </a>
</div>

<div class="mt-6 bg-white shadow rounded-lg p-6">
    <h3 class="text-lg font-semibold text-gray-700 mb-4">Quick Actions</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <a href="dashboard.php?page=store" class="w-full bg-blue-500 text-white py-2 px-4 rounded-lg hover:bg-blue-600 text-center">Buy Package</a>
        <a href="dashboard.php?page=wallet" class="w-full bg-green-500 text-white py-2 px-4 rounded-lg hover:bg-green-600 text-center">Top Up</a>
        <a href="dashboard.php?page=wallet" class="w-full bg-yellow-500 text-white py-2 px-4 rounded-lg hover:bg-yellow-600 text-center">Withdraw</a>
        <a href="dashboard.php?page=wallet" class="w-full bg-blue-400 text-white py-2 px-4 rounded-lg hover:bg-blue-500 text-center">Transfer</a>
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