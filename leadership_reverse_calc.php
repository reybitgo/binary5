<?php
// leadership_reverse_calc.php - Leadership reverse bonus calculation with requirement-based flushing
require_once 'config.php';
require_once 'functions.php';   // getPersonalVolume(), getGroupVolume()

/**
 * Mentor (reverse-leadership) bonus with requirement-based flushing.
 * Uses its own mentor_flush_log table to prevent double-payments.
 */
function calc_leadership_reverse(int $ancestorId, float $pairBonus, PDO $pdo): void
{
    if ($pairBonus <= 0) return;

    /* ---------- Mentor schedule (levels 1-5 below ancestor) ---------- */
    $schedule = [
        1 => ['pvt' => 100,  'gvt' =>   500,  'rate' => 0.03],
        2 => ['pvt' => 200,  'gvt' =>  1000,  'rate' => 0.025],
        3 => ['pvt' => 300,  'gvt' =>  2500,  'rate' => 0.02],
        4 => ['pvt' => 500,  'gvt' =>  5000,  'rate' => 0.015],
        5 => ['pvt' => 1000, 'gvt' => 10000,  'rate' => 0.01],
    ];

    /* 1️⃣  Build every descendant 1-5 levels deep */
    $descendants = [];
    $current = [$ancestorId];
    for ($level = 1; $level <= 5; $level++) {
        if (!$current) break;

        $placeholders = implode(',', array_fill(0, count($current), '?'));  
        $sql = "
            SELECT id, username
            FROM users
            WHERE sponsor_name IN (
                  SELECT username FROM users WHERE id IN ($placeholders)
                )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($current);

        $next = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['lvl'] = $level;
            $descendants[] = $row;
            $next[]        = (int)$row['id'];
        }
        $current = $next;
    }

    /* 2️⃣  Process each descendant */
    foreach ($descendants as $desc) {
        $descId = (int)$desc['id'];
        $level  = (int)$desc['lvl'];

        if (!isset($schedule[$level])) continue;

        $needPVT = $schedule[$level]['pvt'];
        $needGVT = $schedule[$level]['gvt'];
        $rate    = $schedule[$level]['rate'];

        $pvt = getPersonalVolume($descId, $pdo);
        $gvt = getGroupVolume($descId, $pdo, 0); // unlimited depth

        $grossBonus = $pairBonus * $rate;

        /* 3️⃣  Subtract any previously-flushed mentor amount */
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM mentor_flush_log
             WHERE ancestor_id = ?
               AND descendant_id = ?
               AND level = ?'
        );
        $stmt->execute([$ancestorId, $descId, $level]);
        $flushed = (float) $stmt->fetchColumn();

        $netBonus = max(0, $grossBonus - $flushed);
        if ($netBonus <= 0) continue;

        /* 4️⃣  Pay or flush */
        if ($pvt >= $needPVT && $gvt >= $needGVT) {
            /* ✅ Pay mentor bonus */
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $descId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount)
                 VALUES (?, "leadership_reverse_bonus", ?)'
            )->execute([$descId, $netBonus]);
        } else {
            /* ❌ Flush remaining eligible amount */
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$descId, $netBonus, 'mentor_requirements_not_met']);

            /* 📝 Log to prevent re-payment */
            $pdo->prepare(
                'INSERT INTO mentor_flush_log
                   (ancestor_id, descendant_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())'
            )->execute([$ancestorId, $descId, $level, $netBonus]);
        }
    }
}