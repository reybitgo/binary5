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

<!-- Mentor Section -->
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