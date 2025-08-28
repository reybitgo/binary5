<!-- pages/referrals.php -->
<!-- Referrals Section -->
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