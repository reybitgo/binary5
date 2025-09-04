<?php
require_once 'config.php';
require_once 'functions.php';

/**
 * Leadership bonus uses ANCESTOR'S highest price package
 * When a downline earns binary bonus, ancestors get leadership bonus
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

        // Get ANCESTOR'S highest price package for leadership settings
        $stmt = $pdo->prepare("
            SELECT pls.level, pls.pvt_required, pls.gvt_required, pls.rate
            FROM package_leadership_schedule pls
            JOIN (
                SELECT p.id, p.price
                FROM packages p
                JOIN wallet_tx wt ON wt.package_id = p.id
                WHERE wt.user_id = ? AND wt.type='package'
                ORDER BY p.price DESC, wt.id DESC
                LIMIT 1
            ) highest_package ON highest_package.id = pls.package_id
            WHERE pls.level = ?
        ");
        $stmt->execute([$ancestorId, $level]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$schedule) {
            $currentId = $ancestorId;
            continue;
        }

        $needPVT = $schedule['pvt_required'];
        $needGVT = $schedule['gvt_required'];
        $rate = $schedule['rate'];

        $pvt = getPersonalVolume($ancestorId, $pdo);
        $gvt = getGroupVolume($ancestorId, $pdo, 0);

        $grossBonus = $pairBonus * $rate;

        // Check for previously flushed amount for this ancestor
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM leadership_flush_log
             WHERE ancestor_id = ? AND downline_id = ? AND level = ?'
        );
        $stmt->execute([$ancestorId, $earnerId, $level]);
        $flushed = (float)$stmt->fetchColumn();

        $netBonus = max(0, $grossBonus - $flushed);
        if ($netBonus <= 0) {
            $currentId = $ancestorId;
            continue;
        }

        if ($pvt >= $needPVT && $gvt >= $needGVT) {
            // Pay to ANCESTOR
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $ancestorId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount)
                 VALUES (?, "leadership_bonus", ?)'
            )->execute([$ancestorId, $netBonus]);
        } else {
            // Flush from ANCESTOR's potential earnings
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$ancestorId, $netBonus, 'leadership_requirements_not_met']);

            // Log flush for ANCESTOR - use INSERT IGNORE or ON DUPLICATE KEY UPDATE
            $pdo->prepare(
                'INSERT IGNORE INTO leadership_flush_log
                   (ancestor_id, downline_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE
                   amount = amount + VALUES(amount)'
            )->execute([$ancestorId, $earnerId, $level, $netBonus]);
        }

        $currentId = $ancestorId;
    }
}
?>