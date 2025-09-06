<?php
require_once 'config.php';
require_once 'functions.php';

/**
 * Leadership bonus with package-based limitations
 * When a downline earns binary bonus, ancestors get leadership bonus
 * BUT the bonus amount is capped by ancestor's own package investment
 */
function calc_leadership(int $earnerId, float $pairBonus, PDO $pdo): void
{
    if ($pairBonus <= 0) return;

    $currentId = $earnerId;

    for ($level = 1; $level <= 5; $level++) {
        // Find ancestor for this level
        $stmt = $pdo->prepare('SELECT sponsor_id FROM users WHERE id = ?');
        $stmt->execute([$currentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (empty($row['sponsor_id'])) break;

        $ancestorId = (int)$row['sponsor_id'];

        // Get ANCESTOR'S highest price package for leadership settings AND limitations
        $ancestorPkgStmt = $pdo->prepare("
            SELECT p.id as package_id, p.name, p.price, 
                   pls.level, pls.pvt_required, pls.gvt_required, pls.rate
            FROM packages p
            JOIN wallet_tx wt ON wt.package_id = p.id
            JOIN package_leadership_schedule pls ON p.id = pls.package_id
            WHERE wt.user_id = ? AND wt.type='package' AND pls.level = ?
            ORDER BY p.price DESC, wt.id DESC
            LIMIT 1
        ");
        $ancestorPkgStmt->execute([$ancestorId, $level]);
        $ancestorPackage = $ancestorPkgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ancestorPackage) {
            // Ancestor has no package or no leadership schedule for this level
            error_log("Leadership: Ancestor ID {$ancestorId} has no package/schedule for level {$level}, skipping");
            $currentId = $ancestorId;
            continue;
        }

        $needPVT = $ancestorPackage['pvt_required'];
        $needGVT = $ancestorPackage['gvt_required'];
        $rate = $ancestorPackage['rate'];
        $ancestorPackagePrice = $ancestorPackage['price'];

        // Get ancestor's actual volumes
        $pvt = getPersonalVolume($ancestorId, $pdo);
        $gvt = getGroupVolume($ancestorId, $pdo, 0);

        // Calculate base bonus from the pair bonus
        $baseBonus = $pairBonus * $rate;

        // Apply package-based limitation
        // The ancestor can't earn more leadership bonus than what their own package would generate
        $maxLeadershipFromOwnPackage = $ancestorPackagePrice * $rate;
        
        // Get how much leadership bonus this ancestor has already earned today for this level
        $todayEarnedStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM wallet_tx 
            WHERE user_id = ? AND type = 'leadership_bonus' 
            AND DATE(created_at) = CURDATE()
        ");
        $todayEarnedStmt->execute([$ancestorId]);
        $todayEarned = (float)$todayEarnedStmt->fetchColumn();

        // Calculate remaining capacity
        $remainingCapacity = max(0, $maxLeadershipFromOwnPackage - $todayEarned);
        
        // Cap the bonus by remaining capacity
        $cappedBonus = min($baseBonus, $remainingCapacity);

        // Check for previously flushed amount for this ancestor
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM leadership_flush_log
             WHERE ancestor_id = ? AND downline_id = ? AND level = ? AND flushed_on = CURDATE()'
        );
        $stmt->execute([$ancestorId, $earnerId, $level]);
        $flushed = (float)$stmt->fetchColumn();

        $netBonus = max(0, $cappedBonus - $flushed);
        
        if ($netBonus <= 0) {
            $currentId = $ancestorId;
            continue;
        }

        // Check if ancestor meets requirements AND has remaining capacity
        if ($pvt >= $needPVT && $gvt >= $needGVT && $remainingCapacity > 0) {
            // Pay the net bonus to ANCESTOR
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $ancestorId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount, package_id)
                 VALUES (?, "leadership_bonus", ?, ?)'
            )->execute([$ancestorId, $netBonus, $ancestorPackage['package_id']]);

            // Log successful leadership payment
            error_log(sprintf(
                "Leadership Bonus Paid: Ancestor ID %d received $%.2f " .
                "(Level: %d, Package: %s $%.2f, Rate: %.1f%%, Base: $%.2f, Capped: $%.2f, Today Earned: $%.2f, Capacity: $%.2f)",
                $ancestorId,
                $netBonus,
                $level,
                $ancestorPackage['name'],
                $ancestorPackagePrice,
                $rate * 100,
                $baseBonus,
                $cappedBonus,
                $todayEarned,
                $remainingCapacity
            ));
        } else {
            // Determine flush reason
            $flushReason = 'leadership_requirements_not_met';
            if ($remainingCapacity <= 0) {
                $flushReason = 'leadership_capacity_exceeded';
                $netBonus = $baseBonus; // Flush the full amount if capacity exceeded
            }
            
            // Flush from ANCESTOR's potential earnings
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$ancestorId, $netBonus, $flushReason]);

            // Log flush for ANCESTOR
            $pdo->prepare(
                'INSERT INTO leadership_flush_log
                   (ancestor_id, downline_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE
                   amount = amount + VALUES(amount)'
            )->execute([$ancestorId, $earnerId, $level, $netBonus]);

            // Log flush details
            error_log(sprintf(
                "Leadership Bonus Flushed: Ancestor ID %d flushed $%.2f " .
                "(Level: %d, Reason: %s, PVT: %.2f/%.2f, GVT: %.2f/%.2f, Capacity: $%.2f)",
                $ancestorId,
                $netBonus,
                $level,
                $flushReason,
                $pvt,
                $needPVT,
                $gvt,
                $needGVT,
                $remainingCapacity
            ));
        }

        $currentId = $ancestorId;
    }
}

/**
 * Helper function to get user's leadership package settings for a specific level
 * 
 * @param int $userId
 * @param int $level
 * @param PDO $pdo
 * @return array|null Package settings or null if no package/level found
 */
function getUserLeadershipPackageSettings(int $userId, int $level, PDO $pdo): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, pls.pvt_required, pls.gvt_required, pls.rate
        FROM packages p
        JOIN wallet_tx wt ON p.id = wt.package_id
        JOIN package_leadership_schedule pls ON p.id = pls.package_id
        WHERE wt.user_id = ? AND wt.type = 'package' AND pls.level = ?
        ORDER BY p.price DESC, wt.id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $level]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ?: null;
}

/**
 * Helper function to check if user can receive leadership bonuses for a specific level
 * 
 * @param int $userId
 * @param int $level
 * @param PDO $pdo
 * @return bool
 */
function canReceiveLeadershipBonus(int $userId, int $level, PDO $pdo): bool
{
    return getUserLeadershipPackageSettings($userId, $level, $pdo) !== null;
}

/**
 * Function to get leadership capacity and earnings status for a user
 * 
 * @param int $userId
 * @param PDO $pdo
 * @return array
 */
function getUserLeadershipStatus(int $userId, PDO $pdo): array
{
    $status = [];
    
    for ($level = 1; $level <= 5; $level++) {
        $packageSettings = getUserLeadershipPackageSettings($userId, $level, $pdo);
        
        if (!$packageSettings) {
            $status[$level] = [
                'has_package' => false,
                'can_earn' => false,
                'daily_capacity' => 0,
                'today_earned' => 0,
                'remaining_capacity' => 0
            ];
            continue;
        }
        
        // Calculate daily capacity based on package price and rate
        $dailyCapacity = $packageSettings['price'] * $packageSettings['rate'];
        
        // Get today's earnings
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM wallet_tx 
            WHERE user_id = ? AND type = 'leadership_bonus' 
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$userId]);
        $todayEarned = (float)$stmt->fetchColumn();
        
        // Get volumes
        $pvt = getPersonalVolume($userId, $pdo);
        $gvt = getGroupVolume($userId, $pdo, 0);
        
        $meetsRequirements = ($pvt >= $packageSettings['pvt_required'] && 
                            $gvt >= $packageSettings['gvt_required']);
        
        $status[$level] = [
            'has_package' => true,
            'package_name' => $packageSettings['name'],
            'can_earn' => $meetsRequirements,
            'daily_capacity' => $dailyCapacity,
            'today_earned' => $todayEarned,
            'remaining_capacity' => max(0, $dailyCapacity - $todayEarned),
            'pvt_required' => $packageSettings['pvt_required'],
            'gvt_required' => $packageSettings['gvt_required'],
            'pvt_actual' => $pvt,
            'gvt_actual' => $gvt,
            'rate' => $packageSettings['rate']
        ];
    }
    
    return $status;
}
?>