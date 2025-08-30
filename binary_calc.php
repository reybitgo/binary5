<?php
// binary_calc.php - Fixed binary commission calculation
require_once 'config.php';

function calc_binary(int $userId, int $pv, PDO $pdo): void
{
    /* 1. Propagate leg counts up the tree */
    $cursor = $userId;
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

    /* 2. Pay commissions upward */
    $ancestors = [];
    $cursor = $userId;
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

    foreach ($ancestors as $anc) {
        // Get package-specific rates
        $pkgStmt = $pdo->prepare("SELECT daily_max, pair_rate FROM packages WHERE id = (
            SELECT package_id FROM wallet_tx
            WHERE user_id = ? AND type='package' ORDER BY id DESC LIMIT 1
        )");
        $pkgStmt->execute([$anc['id']]);
        $pkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pkg) {
            // Skip if no package found
            continue;
        }

        $freshData = $pdo->prepare(
            'SELECT left_count, right_count, pairs_today FROM users WHERE id = ?'
        )->execute([$anc['id']]);
        $freshData = $pdo->prepare(
            'SELECT left_count, right_count, pairs_today FROM users WHERE id = ?'
        )->fetch(PDO::FETCH_ASSOC);

        $left = (int) $freshData['left_count'];
        $right = (int) $freshData['right_count'];
        $alreadyPaid = (int) $freshData['pairs_today'];

        $pairsPossible = min($left, $right);
        $remainingCap = max(0, $pkg['daily_max'] - $alreadyPaid);
        $pairsToPay = min($pairsPossible, $remainingCap);

        if ($pairsToPay > 0) {
            $dailyPairValue = $pairsToPay;
            $bonus = $dailyPairValue * $pkg['pair_rate'];

            // Credit wallet
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$bonus, $anc['id']]);

            // Record commission
            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount, package_id)
                 VALUES (?, "pair_bonus", ?, ?)'
            )->execute([$anc['id'], $bonus, $pkg['id']]);

            // Update daily counter
            $pdo->prepare(
                'UPDATE users SET pairs_today = pairs_today + ? WHERE id = ?'
            )->execute([$pairsToPay, $anc['id']]);

            // Call leadership calculations
            require_once 'leadership_calc.php';
            calc_leadership($anc['id'], $bonus, $pdo);

            require_once 'leadership_reverse_calc.php';
            calc_leadership_reverse($anc['id'], $bonus, $pdo);
        }

        // Flush excess pairs
        $excessPairs = $pairsPossible - $pairsToPay;
        if ($excessPairs > 0) {
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on)
                 VALUES (?, ?, CURDATE())'
            )->execute([$anc['id'], $excessPairs]);
        }

        // Reset counts after processing
        $flushLeft = $left - $pairsPossible;
        $flushRight = $right - $pairsPossible;

        $pdo->prepare(
            'UPDATE users SET left_count = ?, right_count = ? WHERE id = ?'
        )->execute([$flushLeft, $flushRight, $anc['id']]);
    }
}
?>