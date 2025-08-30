<?php
// leadership_reverse_calc.php - Fixed mentor bonus calculation
require_once 'config.php';
require_once 'functions.php';

function calc_leadership_reverse(int $ancestorId, float $pairBonus, PDO $pdo): void
{
    if ($pairBonus <= 0) return;

    $stmt = $pdo->prepare("
        SELECT level, pvt_required, gvt_required, rate
        FROM package_mentor_schedule
        WHERE package_id = (
            SELECT package_id FROM wallet_tx
            WHERE user_id = ? AND type='package' ORDER BY id DESC LIMIT 1
        )
    ");
    $stmt->execute([$ancestorId]);
    $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);
    if (!$schedule) return;

    // Build descendants 1-5 levels deep
    $descendants = [];
    $current = [$ancestorId];
    for ($level = 1; $level <= 5; $level++) {
        if (!$current) break;

        $placeholders = implode(',', array_fill(0, count($current), '?'));
        $sql = "
            SELECT id, username
            FROM users
            WHERE sponsor_id IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($current);

        $next = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['lvl'] = $level;
            $descendants[] = $row;
            $next[] = (int)$row['id'];
        }
        $current = $next;
    }

    // Process each descendant
    foreach ($descendants as $desc) {
        $descId = (int)$desc['id'];
        $level = (int)$desc['lvl'];

        if (!isset($schedule[$level])) continue;

        $needPVT = $schedule[$level]['pvt_required'];
        $needGVT = $schedule[$level]['gvt_required'];
        $rate = $schedule[$level]['rate'];

        $pvt = getPersonalVolume($descId, $pdo);
        $gvt = getGroupVolume($descId, $pdo, 0);

        $grossBonus = $pairBonus * $rate;

        // Check for previously flushed mentor amount
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM mentor_flush_log
             WHERE ancestor_id = ? AND descendant_id = ? AND level = ?'
        );
        $stmt->execute([$ancestorId, $descId, $level]);
        $flushed = (float)$stmt->fetchColumn();

        $netBonus = max(0, $grossBonus - $flushed);
        if ($netBonus <= 0) continue;

        if ($pvt >= $needPVT && $gvt >= $needGVT) {
            // Pay mentor bonus
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $descId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount)
                 VALUES (?, "leadership_reverse_bonus", ?)'
            )->execute([$descId, $netBonus]);
        } else {
            // Flush remaining amount
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$descId, $netBonus, 'mentor_requirements_not_met']);

            // Log to prevent re-payment
            $pdo->prepare(
                'INSERT INTO mentor_flush_log
                   (ancestor_id, descendant_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())'
            )->execute([$ancestorId, $descId, $level, $netBonus]);
        }
    }
}
?>