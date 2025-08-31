<?php
// binary_calc.php - FIXED package lookup logic
require_once 'config.php';

function calc_binary(int $packageBuyerId, int $pv, PDO $pdo): void
{
    if ($pv <= 0) return;

    /* -------------------------------------------------
     * 1. Propagate leg counts up the tree
     * -------------------------------------------------*/
    $cursor = $packageBuyerId;
    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id, upline_id, position FROM users WHERE id = ?'
        );
        $stmt->execute([$cursor]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$node || !$node['upline_id']) {
            break;
        }

        $side = ($node['position'] === 'left') ? 'left_count' : 'right_count';
        $pdo->prepare("UPDATE users SET {$side} = {$side} + ? WHERE id = ?")
            ->execute([$pv, $node['upline_id']]);

        $cursor = $node['upline_id'];
    }

    /* -------------------------------------------------
     * 2. Pay commissions upward (use package buyer's package)
     * -------------------------------------------------*/
    $ancestors = [];
    $cursor = $packageBuyerId;
    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id, upline_id, left_count, right_count, pairs_today FROM users WHERE id = ?'
        );
        $stmt->execute([$cursor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) break;
        if ($row['upline_id'] || ($row['left_count'] > 0 || $row['right_count'] > 0)) {
            $ancestors[] = $row;
        }
        if (!$row['upline_id']) break;
        $cursor = $row['upline_id'];
    }

    /* -------------------------------------------------
     * 3. Get package details from the actual package buyer
     * -------------------------------------------------*/
    $pkgStmt = $pdo->prepare("
        SELECT p.daily_max, p.pair_rate, p.id as package_id
        FROM packages p
        JOIN wallet_tx wt ON wt.package_id = p.id
        WHERE wt.user_id = ? AND wt.type='package'
        ORDER BY wt.id DESC LIMIT 1
    ");
    $pkgStmt->execute([$packageBuyerId]);
    $buyerPackage = $pkgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$buyerPackage) {
        // No package found for buyer - skip all calculations
        return;
    }

    /* -------------------------------------------------
     * 4. Apply package rules to all ancestors
     * -------------------------------------------------*/
    foreach ($ancestors as $ancestor) {
        $ancestorId = $ancestor['id'];
        
        // Get fresh counts for this ancestor
        $stmt = $pdo->prepare(
            'SELECT left_count, right_count, pairs_today FROM users WHERE id = ?'
        );
        $stmt->execute([$ancestorId]);
        $freshData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $left = (int) $freshData['left_count'];
        $right = (int) $freshData['right_count'];
        $alreadyPaid = (int) $freshData['pairs_today'];

        $pairsPossible = min($left, $right);
        $remainingCap = max(0, $buyerPackage['daily_max'] - $alreadyPaid);
        $pairsToPay = min($pairsPossible, $remainingCap);

        if ($pairsToPay > 0) {
            $dailyPairValue = $pairsToPay;
            $bonus = $dailyPairValue * $buyerPackage['pair_rate'];

            // Credit ancestor's wallet
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$bonus, $ancestorId]);

            // Record commission
            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount, package_id)
                 VALUES (?, "pair_bonus", ?, ?)'
            )->execute([$ancestorId, $bonus, $buyerPackage['package_id']]);

            // Update daily counter
            $pdo->prepare(
                'UPDATE users SET pairs_today = pairs_today + ? WHERE id = ?'
            )->execute([$pairsToPay, $ancestorId]);

            // Call leadership calculations
            if (file_exists('leadership_calc.php')) {
                require_once 'leadership_calc.php';
                calc_leadership($ancestorId, $bonus, $pdo);
            }
            if (file_exists('leadership_reverse_calc.php')) {
                require_once 'leadership_reverse_calc.php';
                calc_leadership_reverse($ancestorId, $bonus, $pdo);
            }
        }

        // Flush excess pairs
        $excessPairs = $pairsPossible - $pairsToPay;
        if ($excessPairs > 0) {
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on)
                 VALUES (?, ?, CURDATE())'
            )->execute([$ancestorId, $excessPairs]);
        }

        // Reset counts
        $flushLeft = $left - $pairsPossible;
        $flushRight = $right - $pairsPossible;

        $pdo->prepare(
            'UPDATE users SET left_count = ?, right_count = ? WHERE id = ?'
        )->execute([$flushLeft, $flushRight, $ancestorId]);
    }
}
?>