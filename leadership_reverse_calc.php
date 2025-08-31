<?php
require_once 'config.php';
require_once 'functions.php';

/**
 * Mentor bonus uses DESCENDANT'S highest price package
 * When ancestor earns binary bonus, descendants get mentor bonus
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

    /* Process each descendant */
    foreach ($descendants as $desc) {
        $descId = $desc['id'];
        $level = $desc['lvl'];

        // Get DESCENDANT'S highest price package
        $stmt = $pdo->prepare("
            SELECT pms.level, pms.pvt_required, pms.gvt_required, pms.rate
            FROM package_mentor_schedule pms
            JOIN (
                SELECT p.id, p.price
                FROM packages p
                JOIN wallet_tx wt ON wt.package_id = p.id
                WHERE wt.user_id = ? AND wt.type='package'
                ORDER BY p.price DESC, wt.id DESC
                LIMIT 1
            ) highest_package ON highest_package.id = pms.package_id
            WHERE pms.level = ?
        ");
        $stmt->execute([$descId, $level]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$schedule) continue;

        $needPVT = $schedule['pvt_required'];
        $needGVT = $schedule['gvt_required'];
        $rate = $schedule['rate'];

        $pvt = getPersonalVolume($descId, $pdo);
        $gvt = getGroupVolume($descId, $pdo, 0);

        $grossBonus = $pairBonus * $rate;

        // Check for previously flushed amount for THIS DESCENDANT
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM mentor_flush_log
             WHERE descendant_id = ? AND ancestor_id = ? AND level = ?'
        );
        $stmt->execute([$descId, $ancestorId, $level]);
        $flushed = (float)$stmt->fetchColumn();

        $netBonus = max(0, $grossBonus - $flushed);
        if ($netBonus <= 0) continue;

        if ($pvt >= $needPVT && $gvt >= $needGVT) {
            // CREDIT DESCENDANT
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $descId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount)
                 VALUES (?, "leadership_reverse_bonus", ?)'
            )->execute([$descId, $netBonus]);
        } else {
            // FLUSH from DESCENDANT'S potential earnings
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$descId, $netBonus, 'mentor_requirements_not_met']);

            // Log flush for DESCENDANT
            $pdo->prepare(
                'INSERT INTO mentor_flush_log
                   (ancestor_id, descendant_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())'
            )->execute([$ancestorId, $descId, $level, $netBonus]);
        }
    }
}
?>