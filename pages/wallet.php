<!-- pages/wallet.php -->
<!-- Wallet Section -->
<h2 class="text-2xl font-bold text-gray-800 mb-4">Wallet</h2>

<div class="bg-white shadow rounded-lg p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-700">Balance: $<?=number_format($user['balance'], 2)?></h3>
    <div class="mt-4 space-y-4">
        <form method="post" action="dashboard.php" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <input type="hidden" name="action" value="request_topup">
            <input type="number" step="0.01" class="border rounded-lg p-2" name="usdt_amount" placeholder="USDT amount" required>
            <input type="text" class="border rounded-lg p-2" name="tx_hash" placeholder="Blockchain TX Hash (optional)">
            <button class="bg-green-500 text-white py-2 rounded-lg hover:bg-green-600">Request Top-up</button>
        </form>
        <form method="post" action="dashboard.php" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <input type="hidden" name="action" value="request_withdraw">
            <input type="number" step="0.01" class="border rounded-lg p-2" name="usdt_amount" placeholder="USDT amount" required>
            <input type="text" class="border rounded-lg p-2" name="wallet_address" placeholder="USDT TRC-20 Address" required>
            <button class="bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600">Request Withdraw</button>
        </form>
        <form method="post" action="dashboard.php" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
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
                $niceNames = [
                    'leadership_bonus'         => 'matched_bonus',
                    'leadership_reverse_bonus' => 'mentor_bonus'
                ];

                $tx = $pdo->prepare("SELECT * FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 20");
                $tx->execute([$uid]);
                foreach ($tx as $t) {
                    $displayType = $niceNames[$t['type']] ?? htmlspecialchars($t['type']);
                    echo "<tr class='border-t'>
                            <td class='p-2'>{$displayType}</td>
                            <td class='p-2'>" . ($t['amount'] >= 0 ? '+' : '') . '$' . number_format(abs($t['amount']), 2) . "</td>
                            <td class='p-2'>" . $t['created_at'] . "</td>
                          </tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>