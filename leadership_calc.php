<?php
require_once 'config.php';
require_once 'functions.php';

function calc_leadership(int $earnerId, float $pairBonus, PDO $pdo): void
{
    if ($pairBonus <= 0) return;

    // Get package-based schedule
    $stmt = $pdo->prepare("
        SELECT level, pvt_required, gvt_required, rate
        FROM package_leadership_schedule
        WHERE package_id = (
            SELECT package_id FROM wallet_tx
            WHERE user_id = ? AND type='package' ORDER BY id DESC LIMIT 1
        )
    ");
    $stmt->execute([$earnerId]);
    $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);
    if (!$schedule) return;

    $currentId = $earnerId;

    for ($level = 1; $level <= 5; $level++) {
        // Find ancestor for this level
        $stmt = $pdo->prepare('SELECT sponsor_id FROM users WHERE id = ?');
        $stmt->execute([$currentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (empty($row['sponsor_id'])) break;

        $ancestorId = (int)$row['sponsor_id'];

        if (!isset($schedule[$level])) {
            $currentId = $ancestorId;
            continue;
        }

        $needPVT = $schedule[$level]['pvt_required'];
        $needGVT = $schedule[$level]['gvt_required'];
        $rate = $schedule[$level]['rate'];

        $pvt = getPersonalVolume($ancestorId, $pdo);
        $gvt = getGroupVolume($ancestorId, $pdo, 0);

        $grossBonus = $pairBonus * $rate;

        // Check for previously flushed amount
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
            // Pay the bonus
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $ancestorId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount)
                 VALUES (?, "leadership_bonus", ?)'
            )->execute([$ancestorId, $netBonus]);
        } else {
            // Flush the amount
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$ancestorId, $netBonus, 'leadership_requirements_not_met']);

            // Log to prevent re-payment
            $pdo->prepare(
                'INSERT INTO leadership_flush_log
                   (ancestor_id, downline_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())'
            )->execute([$ancestorId, $earnerId, $level, $netBonus]);
        }

        $currentId = $ancestorId;
    }
}
?>