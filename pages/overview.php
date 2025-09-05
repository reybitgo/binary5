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
    <!-- User Dashboard Metrics -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mb-6">        
        <!-- Total Referrals -->
        <a href="dashboard.php?page=referrals">    
            <div class="bg-white shadow rounded-lg p-6 hover:shadow-md transition-shadow">
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

        <!-- Pairs Today -->
        <a href="dashboard.php?page=binary">
            <div class="bg-white shadow rounded-lg p-6 hover:shadow-md transition-shadow">
                <h3 class="text-lg font-semibold text-gray-700">Pairs Today</h3>
                <p class="text-2xl text-blue-500"><?=$user['pairs_today']?>/10</p>
            </div>
        </a>

        <!-- Personal Volume -->
        <a href="dashboard.php?page=leadership">
            <div class="bg-white shadow rounded-lg p-6 hover:shadow-md transition-shadow">
                <h3 class="text-lg font-semibold text-gray-700">Personal Volume</h3>
                <p class="text-2xl text-blue-500">
                    <?php
                    require_once 'functions.php';
                    $volume = getPersonalVolume($uid, $pdo);
                    echo '$'.number_format($volume, 2);
                    ?>
                </p>
            </div>
        </a>

        <!-- Team Volume -->
        <a href="dashboard.php?page=leadership">
            <div class="bg-white shadow rounded-lg p-6 hover:shadow-md transition-shadow">
                <h3 class="text-lg font-semibold text-gray-700">Team Volume</h3>
                <p class="text-2xl text-blue-500">
                    <?php
                    require_once 'functions.php';
                    $volume = getGroupVolume($uid, $pdo);
                    echo '$'.number_format($volume, 2);
                    ?>
                </p>
            </div>
        </a>

        <!-- Affiliate Earnings -->
        <a href="dashboard.php?page=affiliate">
            <div class="bg-white shadow rounded-lg p-6 hover:shadow-md transition-shadow">
                <h3 class="text-lg font-semibold text-gray-700">Affiliate Earnings</h3>
                <p class="text-2xl text-purple-500">
                    <?php
                    $affiliate_earnings = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM wallet_tx WHERE user_id = ? AND type = 'affiliate_bonus'");
                    $affiliate_earnings->execute([$uid]);
                    echo '$'.number_format($affiliate_earnings->fetchColumn(), 2);
                    ?>
                </p>
            </div>
        </a>

        <!-- Matched Bonus (Leadership) -->
        <a href="dashboard.php?page=leadership">
            <div class="bg-white shadow rounded-lg p-6 hover:shadow-md transition-shadow">
                <h3 class="text-lg font-semibold text-gray-700">Matched Bonus</h3>
                <p class="text-2xl text-green-500">
                    <?php
                    $leadership_earnings = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM wallet_tx WHERE user_id = ? AND type = 'leadership_bonus'");
                    $leadership_earnings->execute([$uid]);
                    echo '$'.number_format($leadership_earnings->fetchColumn(), 2);
                    ?>
                </p>
            </div>
        </a>

        <!-- Mentor Bonus -->
        <a href="dashboard.php?page=mentor">
            <div class="bg-white shadow rounded-lg p-6 hover:shadow-md transition-shadow">
                <h3 class="text-lg font-semibold text-gray-700">Mentor Bonus</h3>
                <p class="text-2xl text-orange-500">
                    <?php
                    $mentor_earnings = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM wallet_tx WHERE user_id = ? AND type = 'leadership_reverse_bonus'");
                    $mentor_earnings->execute([$uid]);
                    echo '$'.number_format($mentor_earnings->fetchColumn(), 2);
                    ?>
                </p>
            </div>
        </a>

        <!-- Binary Bonus -->
        <a href="dashboard.php?page=binary">
            <div class="bg-white shadow rounded-lg p-6 hover:shadow-md transition-shadow">
                <h3 class="text-lg font-semibold text-gray-700">Binary Bonus</h3>
                <p class="text-2xl text-indigo-500">
                    <?php
                    $binary_earnings = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM wallet_tx WHERE user_id = ? AND type = 'pair_bonus'");
                    $binary_earnings->execute([$uid]);
                    echo '$'.number_format($binary_earnings->fetchColumn(), 2);
                    ?>
                </p>
            </div>
        </a>
    </div>

    <!-- Earnings Summary Section -->
    <section class="mt-6 bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-bold mb-4 text-gray-700">Earnings Summary</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php
            // Get all earnings by type for this user
            $earningsStmt = $pdo->prepare("
                SELECT 
                    type,
                    SUM(amount) as total,
                    COUNT(*) as count,
                    MAX(created_at) as last_earned
                FROM wallet_tx 
                WHERE user_id = ? 
                    AND type IN ('referral_bonus', 'pair_bonus', 'leadership_bonus', 'leadership_reverse_bonus', 'affiliate_bonus', 'mentor_bonus')
                    AND amount > 0
                GROUP BY type
                ORDER BY total DESC
            ");
            $earningsStmt->execute([$uid]);
            $earnings = $earningsStmt->fetchAll();

            $totalEarnings = 0;
            foreach ($earnings as $earning) {
                $totalEarnings += $earning['total'];
            }
            ?>
            
            <!-- Total Earnings -->
            <div class="bg-gradient-to-r from-green-400 to-green-600 text-white p-4 rounded-lg">
                <h4 class="text-sm font-medium">Total Earnings</h4>
                <p class="text-2xl font-bold">$<?= number_format($totalEarnings, 2) ?></p>
            </div>

            <?php foreach ($earnings as $earning): ?>
                <div class="bg-gray-50 border border-gray-200 p-4 rounded-lg">
                    <h4 class="text-sm font-medium text-gray-600"><?= ucwords(str_replace(['_', 'bonus'], [' ', ''], $earning['type'])) ?></h4>
                    <p class="text-xl font-bold text-gray-800">$<?= number_format($earning['total'], 2) ?></p>
                    <p class="text-xs text-gray-500"><?= $earning['count'] ?> payments</p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

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