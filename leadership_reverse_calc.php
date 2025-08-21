<?php
require_once 'config.php';

/**
 * Pay leadership REVERSE bonus to descendants (down 1-5 levels)
 * whenever an ancestor earns a pair bonus.
 *
 * @param int   $earnerId   The ancestor who just received the pair bonus
 * @param float $pairBonus  The actual pair_bonus amount credited to him
 * @param PDO   $pdo        Active DB connection
 */
function calc_leadership_reverse(int $earnerId, float $pairBonus, PDO $pdo): void
{
    // Nothing to pay if no bonus
    if ($pairBonus <= 0) return;

    $bonusToPay = $pairBonus * LEADERSHIP_REVERSE_RATE;

    /*-------------------------------------------------
     * Build a list of ALL descendants 1-5 levels deep
     * under the earnerId's sponsorship tree
     *------------------------------------------------*/
    $descendants = [];
    $current = [$earnerId];          // start with the ancestor
    for ($level = 1; $level <= 5; $level++) {
        if (!$current) break;

        $placeholders = implode(',', array_fill(0, count($current), '?'));
        $sql = "
            SELECT id
            FROM users
            WHERE sponsor_name IN (
                  SELECT username FROM users WHERE id IN ($placeholders)
                )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($current);
        $next = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $descendants[] = (int)$row['id'];
            $next[]        = (int)$row['id'];
        }
        $current = $next;
    }

    /*-------------------------------------------------
     * Credit each descendant with the reverse bonus
     *------------------------------------------------*/
    foreach ($descendants as $descId) {
        // Credit wallet
        $pdo->prepare(
            'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
        )->execute([$bonusToPay, $descId]);

        // Record transaction
        $pdo->prepare(
            'INSERT INTO wallet_tx (user_id, type, amount)
             VALUES (?, "leadership_reverse_bonus", ?)'
        )->execute([$descId, $bonusToPay]);
    }
}