<?php
/**
 * functions.php
 * Common helper functions used by both leadership_calc.php
 * and leadership_reverse_calc.php
 */

/**
 * Personal Volume (PVT) = total package purchases by the user
 */
function getPersonalVolume(int $userId, PDO $pdo): float
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(p.price),0)
         FROM wallet_tx wt
         JOIN packages p ON ABS(wt.amount) = p.price
         WHERE wt.user_id = ?
           AND wt.type = "package"'
    );
    $stmt->execute([$userId]);
    return (float) $stmt->fetchColumn();
}

/**
 * Group Volume (GVT) = total package purchases by the entire
 * sponsorship tree under $userId.
 *
 * @param int      $userId    Root of the tree
 * @param PDO      $pdo
 * @param int|null $maxLevel  0 or null = unlimited depth
 */
function getGroupVolume(int $userId, PDO $pdo, ?int $maxLevel = 5): float
{
    // 1. Build descendant list (inclusive)
    $descendants = [$userId];
    $current = [$userId];
    $level   = 0;

    while ($current) {
        $level++;
        if ($maxLevel && $level > $maxLevel) break;

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

    if (!$descendants) return 0.0;

    // 2. Sum package purchases
    $placeholders = implode(',', array_fill(0, count($descendants), '?'));
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(p.price),0)
         FROM wallet_tx wt
         JOIN packages p ON ABS(wt.amount) = p.price
         WHERE wt.user_id IN ($placeholders)
           AND wt.type = 'package'"
    );
    $stmt->execute($descendants);
    return (float) $stmt->fetchColumn();
}