<?php
require_once 'config.php';
require_once 'functions.php';

/**
 * Pay MENTOR (reverse-leadership) bonus to descendants
 * when an ancestor earns a pair bonus.
 * Each descendant level must satisfy its own PVT & GVT targets to unlock.
 *
 * @param int   $ancestorId  The ancestor who just received the pair bonus
 * @param float $pairBonus   The actual pair_bonus amount credited to him
 * @param PDO   $pdo         Active DB connection
 */
function calc_leadership_reverse(int $ancestorId, float $pairBonus, PDO $pdo): void
{
    if ($pairBonus <= 0) return;

    /* -------------------------------------------------
     * 1.  Mentor schedule (descendant levels 1-5)
     * -------------------------------------------------*/
    $schedule = [
        1 => ['pvt' => 100,  'gvt' =>   500,  'rate' => 0.03],
        2 => ['pvt' => 200,  'gvt' =>  1000,  'rate' => 0.025],
        3 => ['pvt' => 300,  'gvt' =>  2500,  'rate' => 0.02],
        4 => ['pvt' => 500,  'gvt' =>  5000,  'rate' => 0.015],
        5 => ['pvt' => 1000, 'gvt' => 10000,  'rate' => 0.01],
    ];

    /* -------------------------------------------------
     * 2.  Build list of all descendants 1-5 levels deep
     * -------------------------------------------------*/
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

    /* -------------------------------------------------
     * 3.  Pay each eligible descendant
     * -------------------------------------------------*/
    foreach ($descendants as $desc) {
        $descId   = (int)$desc['id'];
        $level    = (int)$desc['lvl'];

        if (!isset($schedule[$level])) continue;

        $needPVT = $schedule[$level]['pvt'];
        $needGVT = $schedule[$level]['gvt'];
        $rate    = $schedule[$level]['rate'];

        $pvt = (float) getPersonalVolume($descId, $pdo);
        $gvt = (float) getGroupVolume($descId, $pdo, 0);   // unlimited depth

        if ($pvt < $needPVT || $gvt < $needGVT) continue;

        $bonus = $pairBonus * $rate;

        /* credit wallet & log */
        $pdo->prepare(
            'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
        )->execute([$bonus, $descId]);

        $pdo->prepare(
            'INSERT INTO wallet_tx (user_id, type, amount)
             VALUES (?, "leadership_reverse_bonus", ?)'
        )->execute([$descId, $bonus]);
    }
}