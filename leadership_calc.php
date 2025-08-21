<?php
require_once 'config.php';

/**
 * Pay leadership bonus upline (1-5 levels) whenever a user earns a pair bonus.
 *
 * @param int   $earnerId  The user who just received the pair bonus
 * @param float $pairBonus The actual pair_bonus amount credited to him
 * @param PDO   $pdo       Active DB connection
 */
function calc_leadership(int $earnerId, float $pairBonus, PDO $pdo): void
{
    // Nothing to pay if no bonus
    if ($pairBonus <= 0) return;

    $bonusToPay = $pairBonus * LEADERSHIP_RATE;

    /*-------------------------------------------------
     * Walk 5 levels up the sponsorship tree
     *------------------------------------------------*/
    $currentId = $earnerId;
    for ($level = 1; $level <= 5; $level++) {
        // Get sponsor of current user
        $stmt = $pdo->prepare(
            'SELECT sponsor_name FROM users WHERE id = ?'
        );
        $stmt->execute([$currentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['sponsor_name'])) {
            break;   // No more uplines
        }

        // Resolve sponsor id
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$row['sponsor_name']]);
        $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sponsor) {
            break;
        }

        $sponsorId = (int) $sponsor['id'];

        /*-------------------------------------------------
         * Credit sponsor with leadership bonus
         *------------------------------------------------*/
        $pdo->prepare(
            'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
        )->execute([$bonusToPay, $sponsorId]);

        // Record transaction
        $pdo->prepare(
            'INSERT INTO wallet_tx (user_id, type, amount)
             VALUES (?, "leadership_bonus", ?)'
        )->execute([$sponsorId, $bonusToPay]);

        // Move one level higher
        $currentId = $sponsorId;
    }
}