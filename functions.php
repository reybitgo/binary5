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
        $sql = $sql = "
            SELECT id
            FROM users
            WHERE sponsor_id IN ($placeholders)";
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

function getUsernameById(int $id, PDO $pdo): ?string
{
    // Validate ID is positive
    if ($id <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $username = $stmt->fetchColumn();

        return $username !== false ? $username : null;
    } catch (PDOException $e) {
        // Log error (in a production environment, use a proper logging mechanism)
        error_log("Error fetching username for ID $id: " . $e->getMessage());
        return null;
    }
}

/* =================================================================
   Binary-tree placement helper (used by register.php & checkout.php)
================================================================= */
function findBestPlacement(int $sponsorId, PDO $pdo): ?array
{
    try {   
        $queue        = [$sponsorId];
        $visited      = [];
        $maxDepth     = 10;
        $currentDepth = 0;

        while ($queue && $currentDepth < $maxDepth) {
            $levelSize = count($queue);
            for ($i = 0; $i < $levelSize; $i++) {
                $userId = array_shift($queue);
                if (in_array($userId, $visited)) continue;
                $visited[] = $userId;

                // counts of existing children
                $stmt = $pdo->prepare("
                    SELECT username,
                           (SELECT COUNT(*) FROM users WHERE upline_id = ? AND position = 'left')  AS left_count,
                           (SELECT COUNT(*) FROM users WHERE upline_id = ? AND position = 'right') AS right_count
                    FROM   users
                    WHERE  id = ?
                ");
                $stmt->execute([$userId, $userId, $userId]);
                $info = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$info) continue;

                if ($info['left_count'] == 0) {
                    return ['upline_id' => $userId, 'upline_username' => $info['username'], 'position' => 'left'];
                }
                if ($info['right_count'] == 0) {
                    return ['upline_id' => $userId, 'upline_username' => $info['username'], 'position' => 'right'];
                }

                // add children to queue for next level
                $childStmt = $pdo->prepare("SELECT id FROM users WHERE upline_id = ?");
                $childStmt->execute([$userId]);
                while ($c = $childStmt->fetch(PDO::FETCH_ASSOC)) {
                    $queue[] = (int)$c['id'];
                }
            }
            $currentDepth++;
        }

        // fallback: place directly under sponsor (left side)
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$sponsorId]);
        $name = $stmt->fetchColumn();
        return ['upline_id' => $sponsorId, 'upline_username' => $name, 'position' => 'left'];

    } catch (PDOException $e) {
        error_log("Placement finder error: " . $e->getMessage());
        return null;
    }
}