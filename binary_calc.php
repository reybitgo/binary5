<?php
// binary_calc.php - FIXED package lookup logic with package-based limits
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
     * 2. Pay commissions upward based on each ancestor's package
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
     * 3. Process each ancestor with their own package limits
     * -------------------------------------------------*/
    foreach ($ancestors as $ancestor) {
        $ancestorId = $ancestor['id'];
        
        // Get ancestor's highest purchased package
        $ancestorPkgStmt = $pdo->prepare("
            SELECT p.daily_max, p.pair_rate, p.id as package_id, p.name, p.price
            FROM packages p
            JOIN wallet_tx wt ON wt.package_id = p.id
            WHERE wt.user_id = ? AND wt.type='package'
            ORDER BY p.price DESC LIMIT 1
        ");
        $ancestorPkgStmt->execute([$ancestorId]);
        $ancestorPackage = $ancestorPkgStmt->fetch(PDO::FETCH_ASSOC);

        // Skip if ancestor has no package
        if (!$ancestorPackage) {
            error_log("Binary: Ancestor ID {$ancestorId} has no package, skipping binary bonus");
            continue;
        }
        
        // Get fresh counts for this ancestor
        $stmt = $pdo->prepare(
            'SELECT left_count, right_count, pairs_today FROM users WHERE id = ?'
        );
        $stmt->execute([$ancestorId]);
        $freshData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $left = (int) $freshData['left_count'];
        $right = (int) $freshData['right_count'];
        $alreadyPaid = (int) $freshData['pairs_today'];

        // Use ancestor's package settings for calculations
        $pairsPossible = min($left, $right);
        $remainingCap = max(0, $ancestorPackage['daily_max'] - $alreadyPaid);
        $pairsToPay = min($pairsPossible, $remainingCap);

        if ($pairsToPay > 0) {
            $dailyPairValue = $pairsToPay;
            $bonus = $dailyPairValue * $ancestorPackage['pair_rate'];

            // Credit ancestor's wallet
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$bonus, $ancestorId]);

            // Record commission with ancestor's package ID
            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount, package_id)
                 VALUES (?, "pair_bonus", ?, ?)'
            )->execute([$ancestorId, $bonus, $ancestorPackage['package_id']]);

            // Update daily counter
            $pdo->prepare(
                'UPDATE users SET pairs_today = pairs_today + ? WHERE id = ?'
            )->execute([$pairsToPay, $ancestorId]);

            // Log the binary bonus calculation
            error_log(sprintf(
                "Binary Bonus Paid: Ancestor ID %d received $%.2f " .
                "(Package: %s, Rate: %.1f%%, Pairs: %d, Daily Max: %d) from buyer ID %d",
                $ancestorId,
                $bonus,
                $ancestorPackage['name'],
                $ancestorPackage['pair_rate'] * 100,
                $pairsToPay,
                $ancestorPackage['daily_max'],
                $packageBuyerId
            ));

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
            $flushReason = ($pairsToPay == 0) ? 'binary_overflow' : 'binary_overflow';
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, reason, flushed_on)
                 VALUES (?, ?, ?, CURDATE())'
            )->execute([$ancestorId, $excessPairs, $flushReason]);

            // Log flushed pairs
            error_log(sprintf(
                "Binary Pairs Flushed: Ancestor ID %d flushed %d pairs " .
                "(Package: %s, Daily Cap: %d, Already Paid: %d)",
                $ancestorId,
                $excessPairs,
                $ancestorPackage['name'] ?? 'No Package',
                $ancestorPackage['daily_max'] ?? 0,
                $alreadyPaid
            ));
        }

        // Reset counts
        $flushLeft = $left - $pairsPossible;
        $flushRight = $right - $pairsPossible;

        $pdo->prepare(
            'UPDATE users SET left_count = ?, right_count = ? WHERE id = ?'
        )->execute([$flushLeft, $flushRight, $ancestorId]);
    }
}

/**
 * Helper function to get user's binary package settings
 * 
 * @param int $userId
 * @param PDO $pdo
 * @return array|null Package settings or null if no package found
 */
function getUserBinaryPackageSettings(int $userId, PDO $pdo): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, p.daily_max, p.pair_rate, p.pv
        FROM packages p
        JOIN wallet_tx wt ON p.id = wt.package_id
        WHERE wt.user_id = ? AND wt.type = 'package' 
        ORDER BY p.price DESC 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ?: null;
}

/**
 * Helper function to check if user can receive binary bonuses
 * 
 * @param int $userId
 * @param PDO $pdo
 * @return bool
 */
function canReceiveBinaryBonus(int $userId, PDO $pdo): bool
{
    return getUserBinaryPackageSettings($userId, $pdo) !== null;
}

/**
 * Function to get current binary status for a user
 * 
 * @param int $userId
 * @param PDO $pdo
 * @return array
 */
function getUserBinaryStatus(int $userId, PDO $pdo): array
{
    $packageSettings = getUserBinaryPackageSettings($userId, $pdo);
    
    $stmt = $pdo->prepare(
        'SELECT left_count, right_count, pairs_today FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        return [
            'has_package' => false,
            'left_count' => 0,
            'right_count' => 0,
            'pairs_today' => 0,
            'daily_max' => 0,
            'pairs_possible' => 0,
            'pairs_remaining' => 0
        ];
    }
    
    $left = (int)$userData['left_count'];
    $right = (int)$userData['right_count'];
    $pairsToday = (int)$userData['pairs_today'];
    $dailyMax = $packageSettings ? (int)$packageSettings['daily_max'] : 0;
    $pairsPossible = min($left, $right);
    $pairsRemaining = max(0, $dailyMax - $pairsToday);
    
    return [
        'has_package' => $packageSettings !== null,
        'package_name' => $packageSettings['name'] ?? null,
        'left_count' => $left,
        'right_count' => $right,
        'pairs_today' => $pairsToday,
        'daily_max' => $dailyMax,
        'pairs_possible' => $pairsPossible,
        'pairs_remaining' => min($pairsPossible, $pairsRemaining),
        'pair_rate' => $packageSettings ? (float)$packageSettings['pair_rate'] : 0
    ];
}
?>