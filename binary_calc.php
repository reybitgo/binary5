<?php
require_once 'config.php';   // now brings in DAILY_MAX and PAIR_RATE

/**
 * Calculate pairing & commission after a package purchase.
 *
 * @param int   $userId  User who bought the package
 * @param int   $pv      Point value of the package
 * @param PDO   $pdo     Active DB connection
 */
function calc_binary(int $userId, int $pv, PDO $pdo): void
{
    /* -------------------------------------------------
     * 1. Propagate leg counts up the tree
     * -------------------------------------------------*/
    $cursor = $userId;
    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id, upline_id, position
             FROM users
             WHERE id = ?'
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
     * 2. Pay commissions upward (BOTTOM TO TOP)
     * -------------------------------------------------*/
    // Build ancestor list (bottom-up order) - include ALL ancestors
    $ancestors = [];
    $cursor = $userId;
    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id, upline_id, left_count, right_count, pairs_today
             FROM users
             WHERE id = ?'
        );
        $stmt->execute([$cursor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            break;
        }

        // Add current user to ancestors if they have an upline OR if they are the root with counts
        if ($row['upline_id'] || ($row['left_count'] > 0 || $row['right_count'] > 0)) {
            $ancestors[] = $row;
        }

        // Stop if no upline (reached root)
        if (!$row['upline_id']) {
            break;
        }

        $cursor = $row['upline_id'];
    }

    // Process ancestors from BOTTOM to TOP (remove array_reverse)
    // This ensures we process the user closest to the purchaser first
    foreach ($ancestors as $anc) {
        // Get fresh counts after previous processing
        $stmt = $pdo->prepare(
            'SELECT left_count, right_count, pairs_today
             FROM users
             WHERE id = ?'
        );
        $stmt->execute([$anc['id']]);
        $freshData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $left  = (int) $freshData['left_count'];
        $right = (int) $freshData['right_count'];
        $alreadyPaid = (int) $freshData['pairs_today'];

        $pairsPossible = min($left, $right);
        $remainingCap  = max(0, DAILY_MAX - $alreadyPaid);
        $pairsToPay    = min($pairsPossible, $remainingCap);

        if ($pairsToPay > 0) {
            // Daily pair value = pairs × (package price / PV)  ... but we only have PV.
            // For simplicity we treat 1 PV = $1.  Adjust as needed.
            $dailyPairValue = $pairsToPay * 1;           // $1 per PV
            $bonus          = $dailyPairValue * PAIR_RATE;

            // Credit wallet
            $pdo->prepare(
                'UPDATE wallets
                 SET balance = balance + ?
                 WHERE user_id = ?'
            )->execute([$bonus, $anc['id']]);

            include 'leadership_calc.php';
            calc_leadership($anc['id'], $bonus, $pdo);   // $bonus is the exact

            include 'leadership_reverse_calc.php';
            calc_leadership_reverse($anc['id'], $bonus, $pdo);

            // Record commission
            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount)
                 VALUES (?, "pair_bonus", ?)'
            )->execute([$anc['id'], $bonus]);

            // Update daily counter
            $pdo->prepare(
                'UPDATE users
                 SET pairs_today = pairs_today + ?
                 WHERE id = ?'
            )->execute([$pairsToPay, $anc['id']]);
        }

        /* Calculate and record flushed pairs */
        $excessPairs = $pairsPossible - $pairsToPay; // Pairs that couldn't be paid due to daily limit
        if ($excessPairs > 0) {
            $flushedValue = $excessPairs * 1; // $1 per flushed pair
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on)
                 VALUES (?, ?, CURDATE())'
            )->execute([$anc['id'], $flushedValue]);
        }

        /* Flush matched counts so they cannot be paid again */
        $flushLeft  = $left  - $pairsPossible;  // Remove ALL matched pairs (paid + flushed)
        $flushRight = $right - $pairsPossible;

        // Update counts (remove paired amounts)
        $pdo->prepare(
            'UPDATE users
             SET left_count = ?, right_count = ?
             WHERE id = ?'
        )->execute([$flushLeft, $flushRight, $anc['id']]);
    }
}