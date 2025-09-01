<!-- pages/overview.php -->
<!-- Overview Section -->
<h2 class="text-2xl font-bold text-gray-800 mb-4">Welcome back, <?=htmlspecialchars($user['username'])?>!</h2>
<p class="text-gray-600 mb-6">Here's what's happening with your MLM business today.</p>

<?php if ($role === 'admin'): ?>
    <!-- Admin Snapshot -->
    <section class="bg-white shadow rounded-lg p-4 mb-6">
        <h3 class="text-lg font-bold mb-2 text-gray-700">Admin Snapshot</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <?php
            // 1. Total Sales (product + package purchases)
            $salesStmt = $pdo->prepare(
                "SELECT SUM(ABS(amount)) AS total
                FROM wallet_tx
                WHERE type IN ('product_purchase', 'package')"
            );
            $salesStmt->execute();
            $totalSales = (float) $salesStmt->fetchColumn();

            // 2. Withdrawals Approved
            $withdrawStmt = $pdo->prepare(
                "SELECT SUM(ABS(amount)) AS total
                FROM wallet_tx
                WHERE type = 'withdraw'"
            );
            $withdrawStmt->execute();
            $totalWithdrawn = (float) $withdrawStmt->fetchColumn();

            // 3. Total Paid Income (all bonus types)
            $incomeTypes = [
                'referral_bonus',
                'pair_bonus',
                'leadership_bonus',
                'leadership_reverse_bonus',
                'affiliate_bonus'
            ];
            $placeholders = implode(',', array_fill(0, count($incomeTypes), '?'));
            $incomeStmt = $pdo->prepare(
                "SELECT SUM(amount) AS total
                FROM wallet_tx
                WHERE type IN ($placeholders)"
            );
            $incomeStmt->execute($incomeTypes);
            $totalIncome = (float) $incomeStmt->fetchColumn();

            // Build per-type breakdown
            $breakdown = [];
            foreach ($incomeTypes as $type) {
                $stmt = $pdo->prepare("SELECT SUM(amount) AS subtotal FROM wallet_tx WHERE type = ?");
                $stmt->execute([$type]);
                $breakdown[$type] = (float) $stmt->fetchColumn();
            }
            ?>
            <div class="bg-green-50 border border-green-200 p-3 rounded">
                <div class="text-sm text-green-600">Total Sales</div>
                <div class="text-xl font-bold text-green-700">$<?= number_format($totalSales, 2) ?></div>
            </div>
            <div class="bg-red-50 border border-red-200 p-3 rounded">
                <div class="text-sm text-red-600">Withdrawals Approved</div>
                <div class="text-xl font-bold text-red-700">$<?= number_format($totalWithdrawn, 2) ?></div>
            </div>
            <div class="bg-purple-50 border border-purple-200 p-3 rounded col-span-full">
                <div class="text-sm text-purple-600">Total Paid Income</div>
                <div class="text-xl font-bold text-purple-700">$<?= number_format($totalIncome, 2) ?></div>
            </div>
        </div>

        <!-- Income Breakdown Table -->
        <h4 class="text-md font-bold text-gray-800 mb-2">Income Breakdown (All Users)</h4>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="border-b">
                    <tr class="text-gray-700">
                        <th class="p-2">Type</th>
                        <th class="p-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($breakdown as $type => $amount): ?>
                        <tr class="border-b">
                            <td class="p-2 capitalize"><?= str_replace('_', ' ', $type) ?></td>
                            <td class="p-2 text-right font-semibold">$<?= number_format($amount, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">        
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
                <h3 class="text-lg font-semibold text-gray-700">Personal Volume</h3>
                <p class="text-2xl text-blue-500">
                    <?php
                    require_once 'functions.php';
                    $volume = getPersonalVolume($uid, $pdo, 0);
                    echo '$'.number_format($volume, 2);
                    ?>
                </p>
            </div>
        </a>
        <a href="dashboard.php?page=leadership">
            <div class="bg-white shadow rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-700">Team Volume</h3>
                <p class="text-2xl text-blue-500">
                    <?php
                    require_once 'functions.php';
                    $volume = getGroupVolume($uid, $pdo, 0);
                    echo '$'.number_format($volume, 2);
                    ?>
                </p>
            </div>
        </a>
    </div>

    <section class="mt-6 bg-white shadow rounded-lg p-4">
        <h3 class="text-lg font-bold mb-2 text-gray-700">Quick Actions</h3>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Buy Package -->
            <a href="dashboard.php?page=store"
            class="bg-green-50 border border-green-200 rounded p-3 text-center hover:bg-green-100 transition">
            <div class="text-sm text-green-600 font-semibold">Buy Package</div>
            </a>

            <!-- Top Up -->
            <a href="dashboard.php?page=wallet"
            class="bg-blue-50 border border-blue-200 rounded p-3 text-center hover:bg-blue-100 transition">
            <div class="text-sm text-blue-600 font-semibold">Top Up</div>
            </a>

            <!-- Withdraw -->
            <a href="dashboard.php?page=wallet"
            class="bg-yellow-50 border border-yellow-200 rounded p-3 text-center hover:bg-yellow-100 transition">
            <div class="text-sm text-yellow-600 font-semibold">Withdraw</div>
            </a>

            <!-- Transfer -->
            <a href="dashboard.php?page=wallet"
            class="bg-purple-50 border border-purple-200 rounded p-3 text-center hover:bg-purple-100 transition">
            <div class="text-sm text-purple-600 font-semibold">Transfer</div>
            </a>
        </div>
    </section>

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
<?php endif; ?>