<?php
require_once 'config.php';
require_once 'functions.php';

/**
 * Mentor bonus with package-based limitations
 * When ancestor earns binary bonus, descendants get mentor bonus
 * BUT the bonus amount is capped by descendant's own package investment
 */
function calc_leadership_reverse(int $ancestorId, float $pairBonus, PDO $pdo): void
{
    if ($pairBonus <= 0) return;

    /* Build descendants 1-5 levels deep */
    $descendants = [];
    $current = [$ancestorId];
    for ($level = 1; $level <= 5; $level++) {
        if (!$current) break;

        $placeholders = implode(',', array_fill(0, count($current), '?'));
        $sql = "SELECT id FROM users WHERE sponsor_id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($current);

        $next = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $next[] = (int)$row['id'];
            $descendants[] = [
                'id' => (int)$row['id'],
                'lvl' => $level
            ];
        }
        $current = $next;
    }

    /* Process each descendant with package-based limitations */
    foreach ($descendants as $desc) {
        $descId = $desc['id'];
        $level = $desc['lvl'];

        // Get DESCENDANT'S highest price package for mentor settings AND limitations
        $descendantPkgStmt = $pdo->prepare("
            SELECT p.id as package_id, p.name, p.price,
                   pms.level, pms.pvt_required, pms.gvt_required, pms.rate
            FROM packages p
            JOIN wallet_tx wt ON wt.package_id = p.id
            JOIN package_mentor_schedule pms ON p.id = pms.package_id
            WHERE wt.user_id = ? AND wt.type='package' AND pms.level = ?
            ORDER BY p.price DESC, wt.id DESC
            LIMIT 1
        ");
        $descendantPkgStmt->execute([$descId, $level]);
        $descendantPackage = $descendantPkgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$descendantPackage) {
            // Descendant has no package or no mentor schedule for this level
            error_log("Mentor: Descendant ID {$descId} has no package/schedule for level {$level}, skipping");
            continue;
        }

        $needPVT = $descendantPackage['pvt_required'];
        $needGVT = $descendantPackage['gvt_required'];
        $rate = $descendantPackage['rate'];
        $descendantPackagePrice = $descendantPackage['price'];

        // Get descendant's actual volumes
        $pvt = getPersonalVolume($descId, $pdo);
        $gvt = getGroupVolume($descId, $pdo, 0);

        // Calculate base bonus from the pair bonus
        $baseBonus = $pairBonus * $rate;

        // Apply package-based limitation
        // The descendant can't earn more mentor bonus than what their own package would generate
        $maxMentorFromOwnPackage = $descendantPackagePrice * $rate;
        
        // Get how much mentor bonus this descendant has already earned today for this level
        $todayEarnedStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) 
            FROM wallet_tx 
            WHERE user_id = ? AND type = 'leadership_reverse_bonus' 
            AND DATE(created_at) = CURDATE()
        ");
        $todayEarnedStmt->execute([$descId]);
        $todayEarned = (float)$todayEarnedStmt->fetchColumn();

        // Calculate remaining capacity
        $remainingCapacity = max(0, $maxMentorFromOwnPackage - $todayEarned);
        
        // Cap the bonus by remaining capacity
        $cappedBonus = min($baseBonus, $remainingCapacity);

        // Check for previously flushed amount for THIS DESCENDANT
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM mentor_flush_log
             WHERE descendant_id = ? AND ancestor_id = ? AND level = ? AND flushed_on = CURDATE()'
        );
        $stmt->execute([$descId, $ancestorId, $level]);
        $flushed = (float)$stmt->fetchColumn();

        $netBonus = max(0, $cappedBonus - $flushed);
        
        if ($netBonus <= 0) {
            continue;
        }

        // Check if descendant meets requirements AND has remaining capacity
        if ($pvt >= $needPVT && $gvt >= $needGVT && $remainingCapacity > 0) {
            // CREDIT DESCENDANT with the net bonus
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $descId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount, package_id)
                 VALUES (?, "leadership_reverse_bonus", ?, ?)'
            )->execute([$descId, $netBonus, $descendantPackage['package_id']]);

            // Log successful mentor payment
            error_log(sprintf(
                "Mentor Bonus Paid: Descendant ID %d received $%.2f " .
                "(Level: %d, Package: %s $%.2f, Rate: %.1f%%, Base: $%.2f, Capped: $%.2f, Today Earned: $%.2f, Capacity: $%.2f)",
                $descId,
                $netBonus,
                $level,
                $descendantPackage['name'],
                $descendantPackagePrice,
                $rate * 100,
                $baseBonus,
                $cappedBonus,
                $todayEarned,
                $remainingCapacity
            ));
        } else {
            // Determine flush reason
            $flushReason = 'mentor_requirements_not_met';
            if ($remainingCapacity <= 0) {
                $flushReason = 'mentor_capacity_exceeded';
                $netBonus = $baseBonus; // Flush the full amount if capacity exceeded
            }
            
            // FLUSH from DESCENDANT'S potential earnings
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$descId, $netBonus, $flushReason]);

            // Log flush for DESCENDANT
            $pdo->prepare(
                'INSERT INTO mentor_flush_log
                   (ancestor_id, descendant_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE
                   amount = amount + VALUES(amount)'
            )->execute([$ancestorId, $descId, $level, $netBonus]);

            // Log flush details
            error_log(sprintf(
                "Mentor Bonus Flushed: Descendant ID %d flushed $%.2f " .
                "(Level: %d, Reason: %s, PVT: %.2f/%.2f, GVT: %.2f/%.2f, Capacity: $%.2f)",
                $descId,
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
    }
}

/**
 * Helper function to get user's mentor package settings for a specific level
 * 
 * @param int $userId
 * @param int $level
 * @param PDO $pdo
 * @return array|null Package settings or null if no package/level found
 */
function getUserMentorPackageSettings(int $userId, int $level, PDO $pdo): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.price, pms.pvt_required, pms.gvt_required, pms.rate
        FROM packages p
        JOIN wallet_tx wt ON p.id = wt.package_id
        JOIN package_mentor_schedule pms ON p.id = pms.package_id
        WHERE wt.user_id = ? AND wt.type = 'package' AND pms.level = ?
        ORDER BY p.price DESC, wt.id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $level]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ?: null;
}

/**
 * Helper function to check if user can receive mentor bonuses for a specific level
 * 
 * @param int $userId
 * @param int $level
 * @param PDO $pdo
 * @return bool
 */
function canReceiveMentorBonus(int $userId, int $level, PDO $pdo): bool
{
    return getUserMentorPackageSettings($userId, $level, $pdo) !== null;
}

/**
 * Function to get mentor capacity and earnings status for a user
 * 
 * @param int $userId
 * @param PDO $pdo
 * @return array
 */
function getUserMentorStatus(int $userId, PDO $pdo): array
{
    $status = [];
    
    for ($level = 1; $level <= 5; $level++) {
        $packageSettings = getUserMentorPackageSettings($userId, $level, $pdo);
        
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
            WHERE user_id = ? AND type = 'leadership_reverse_bonus' 
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

/**
 * Function to get all descendants who can receive mentor bonuses from a specific ancestor
 * 
 * @param int $ancestorId
 * @param PDO $pdo
 * @return array
 */
function getEligibleMentorDescendants(int $ancestorId, PDO $pdo): array
{
    $eligibleDescendants = [];
    
    // Build descendants 1-5 levels deep
    $current = [$ancestorId];
    for ($level = 1; $level <= 5; $level++) {
        if (!$current) break;

        $placeholders = implode(',', array_fill(0, count($current), '?'));
        $sql = "SELECT id FROM users WHERE sponsor_id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($current);

        $next = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $descId = (int)$row['id'];
            $next[] = $descId;
            
            // Check if this descendant can receive mentor bonuses for this level
            if (canReceiveMentorBonus($descId, $level, $pdo)) {
                $status = getUserMentorStatus($descId, $pdo);
                $eligibleDescendants[] = [
                    'descendant_id' => $descId,
                    'level' => $level,
                    'status' => $status[$level] ?? null
                ];
            }
        }
        $current = $next;
    }
    
    return $eligibleDescendants;
}
?>