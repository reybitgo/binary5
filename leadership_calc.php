<?php
require_once 'config.php';
require_once 'functions.php';   // getPersonalVolume() & getGroupVolume()

/**
 * Leadership bonus with requirement-based flushing.
 * If an ancestor fails PVT/GVT for its level, the unpaid bonus
 * is flushed with reason = 'leadership_requirements_not_met'.
 * A ledger table prevents the same (ancestor, level, downline) pair
 * from ever being paid again.
 */
function calc_leadership(int $earnerId, float $pairBonus, PDO $pdo): void
{
    if ($pairBonus <= 0) return;

    /* ---------- Level schedule ---------- */
    $schedule = [
        1 => ['pvt' => 100,  'gvt' =>   500,  'rate' => 0.05],
        2 => ['pvt' => 200,  'gvt' =>  1000,  'rate' => 0.04],
        3 => ['pvt' => 300,  'gvt' =>  2500,  'rate' => 0.03],
        4 => ['pvt' => 500,  'gvt' =>  5000,  'rate' => 0.02],
        5 => ['pvt' => 1000, 'gvt' => 10000,  'rate' => 0.01],
    ];

    $currentId = $earnerId;

    for ($level = 1; $level <= 5; $level++) {

        /* 1️⃣  Find ancestor for this level */
        $stmt = $pdo->prepare(
            'SELECT sponsor_id FROM users WHERE id = ?'
        );
        $stmt->execute([$currentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (empty($row['sponsor_id'])) break;

        $stmt = $pdo->prepare(
            'SELECT id FROM users WHERE id = ?'
        );
        $stmt->execute([$row['sponsor_id']]);
        $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sponsor) break;

        $ancestorId = (int) $sponsor['id'];

        /* 2️⃣  Check unlock */
        $needPVT = $schedule[$level]['pvt'];
        $needGVT = $schedule[$level]['gvt'];
        $rate    = $schedule[$level]['rate'];

        $pvt = getPersonalVolume($ancestorId, $pdo);
        $gvt = getGroupVolume($ancestorId, $pdo, 0);   // all levels

        $grossBonus = $pairBonus * $rate;

        /* 3️⃣  Subtract any previously-flushed amount for this (ancestor, level, downline) */
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM leadership_flush_log
             WHERE ancestor_id = ?
               AND downline_id = ?
               AND level = ?'
        );
        $stmt->execute([$ancestorId, $earnerId, $level]);
        $flushed = (float) $stmt->fetchColumn();

        $netBonus = max(0, $grossBonus - $flushed);
        if ($netBonus <= 0) {
            $currentId = $ancestorId;
            continue;   // fully flushed – nothing to do
        }

        /* 4️⃣  Pay or flush */
        if ($pvt >= $needPVT && $gvt >= $needGVT) {
            /* ✅  Pay the bonus */
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $ancestorId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount)
                 VALUES (?, "leadership_bonus", ?)'
            )->execute([$ancestorId, $netBonus]);
        } else {
            /* ❌  Flush the remaining eligible amount */
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$ancestorId, $netBonus, 'leadership_requirements_not_met']);

            /* 📝  Log the flushed amount so it’s never paid again */
            $pdo->prepare(
                'INSERT INTO leadership_flush_log
                   (ancestor_id, downline_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())'
            )->execute([$ancestorId, $earnerId, $level, $netBonus]);
        }

        /* Move up one sponsorship level */
        $currentId = $ancestorId;
    }
}