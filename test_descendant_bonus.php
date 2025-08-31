<?php
// test_descendant_bonus.php
require_once 'config.php';

function testDescendantBonus($ancestorId, PDO $pdo) {
    echo "Testing descendant bonuses for ancestor $ancestorId:\n";
    
    // Get descendants
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.sponsor_id, 1 as level
        FROM users u
        WHERE u.sponsor_id = ?
    ");
    $stmt->execute([$ancestorId]);
    $descendants = $stmt->fetchAll();
    
    foreach ($descendants as $desc) {
        echo "\n👤 Descendant: {$desc['username']} (ID: {$desc['id']})\n";
        
        // Check descendant's highest package
        $stmt = $pdo->prepare("
            SELECT p.name, p.price, pms.level, pms.rate
            FROM package_mentor_schedule pms
            JOIN (
                SELECT p.id, p.price, p.name
                FROM packages p
                JOIN wallet_tx wt ON wt.package_id = p.id
                WHERE wt.user_id = ? AND wt.type='package'
                ORDER BY p.price DESC, wt.id DESC
                LIMIT 1
            ) hp ON hp.id = pms.package_id
            WHERE pms.level = 1
        ");
        $stmt->execute([$desc['id']]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($package) {
            echo "📦 Highest package: {$package['name']} ($" . number_format($package['price'], 2) . ") - Rate: {$package['rate']}\n";
        } else {
            echo "❌ No package found\n";
        }
    }
}

// Test with ancestor ID 1
testDescendantBonus(1, $pdo);
?>