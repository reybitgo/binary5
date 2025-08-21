<?php
require_once 'config.php';
require_once 'functions.php';

/**
 * Pay leadership bonus upline (1-5 levels) ONLY if the sponsor
 * has satisfied his/her Personal-Volume-Target (PVT) and
 * Group-Volume-Target (GVT) for that level.
 *
 * @param int   $earnerId  The user who just received the pair bonus
 * @param float $pairBonus The actual pair_bonus amount credited to him
 * @param PDO   $pdo       Active DB connection
 */
function calc_leadership(int $earnerId, float $pairBonus, PDO $pdo): void
{
    if ($pairBonus <= 0) return;

    /* -------------------------------------------------
     * 1.  Pre-defined schedule
     * -------------------------------------------------*/
    $schedule = [
        1 => ['pvt' => 100,  'gvt' =>   500,  'rate' => 0.05],
        2 => ['pvt' => 200,  'gvt' =>  1000,  'rate' => 0.04],
        3 => ['pvt' => 300,  'gvt' =>  2500,  'rate' => 0.03],
        4 => ['pvt' => 500,  'gvt' =>  5000,  'rate' => 0.02],
        5 => ['pvt' => 1000, 'gvt' => 10000,  'rate' => 0.01],
    ];

    /* -------------------------------------------------
     * 2.  Walk 5 sponsorship levels UP
     * -------------------------------------------------*/
    $currentId = $earnerId;
    for ($level = 1; $level <= 5; $level++) {

        /* --- 2a. get sponsor (upline) --- */
        $stmt = $pdo->prepare(
            'SELECT sponsor_name FROM users WHERE id = ?'
        );
        $stmt->execute([$currentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (empty($row['sponsor_name'])) break;

        $stmt = $pdo->prepare(
            'SELECT id FROM users WHERE username = ?'
        );
        $stmt->execute([$row['sponsor_name']]);
        $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sponsor) break;

        $sponsorId = (int) $sponsor['id'];

        /* --- 2b. compute PVT & GVT for this sponsor --- */
        $pvt = (float) getPersonalVolume($sponsorId, $pdo);
        $gvt = (float) getGroupVolume($sponsorId, $pdo, 0);

        /* --- 2c. check unlock --- */
        $needPVT = $schedule[$level]['pvt'];
        $needGVT = $schedule[$level]['gvt'];
        $rate    = $schedule[$level]['rate'];

        if ($pvt < $needPVT || $gvt < $needGVT) {
            // level not unlocked → skip to next level
            $currentId = $sponsorId;
            continue;
        }

        /* --- 2d. pay the bonus --- */
        $bonus = $pairBonus * $rate;

        $pdo->prepare(
            'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
        )->execute([$bonus, $sponsorId]);

        $pdo->prepare(
            'INSERT INTO wallet_tx (user_id, type, amount)
             VALUES (?, "leadership_bonus", ?)'
        )->execute([$sponsorId, $bonus]);

        /* move one level higher */
        $currentId = $sponsorId;
    }
}