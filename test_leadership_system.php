<?php
require_once 'config.php';

// Initialize test data
function setupTestData(PDO $pdo) {
    echo "🧪 Setting up test data...\n";
    
    // Create hierarchical test users with proper position values
    $users = [
        ['admin', 'admin123', null, null, null, 'admin'],  // Root has no position
        ['leader', 'pass123', 1, 1, 'left', 'user'],       // Level 1
        ['child1', 'pass123', 2, 2, 'left', 'user'],       // Level 2
        ['child2', 'pass123', 2, 2, 'right', 'user'],      // Level 2
        ['grand1', 'pass123', 3, 3, 'left', 'user'],       // Level 3
        ['grand2', 'pass123', 3, 3, 'right', 'user']       // Level 3
    ];
    
    foreach ($users as $user) {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO users (username, password, sponsor_id, upline_id, position, role) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user[0], 
            password_hash($user[1], PASSWORD_DEFAULT), 
            $user[2], 
            $user[3], 
            $user[4], 
            $user[5]
        ]);
    }
    
    // Ensure wallets exist
    for ($i = 1; $i <= 6; $i++) {
        $pdo->prepare("INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)")->execute([$i]);
    }
    
    echo "✅ Test data ready (6 users with proper hierarchy)\n";
}

// Test leadership reverse specifically
function testLeadershipReverse(PDO $pdo) {
    echo "\n🎯 Testing Leadership Reverse (Mentor Bonus):\n";
    echo "==========================================\n";
    
    // Reset system
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('leadership_bonus', 'leadership_reverse_bonus', 'pair_bonus')");
    $pdo->exec("DELETE FROM flushes");
    
    // Test 1: Simple hierarchy test
    echo "\n1️⃣ Simple Hierarchy Test:\n";
    echo "├── Leader (user2) as ancestor\n";
    echo "├── Child1 (user3) as descendant with Elite package\n";
    echo "└── Child2 (user4) as descendant with Starter package\n";
    
    // Assign packages
    $packages = [
        [2, 2, -50.00],  // leader - Pro (50 USD)
        [3, 3, -100.00], // child1 - Elite (100 USD - highest)
        [4, 1, -25.00],  // child2 - Starter (25 USD)
    ];
    
    foreach ($packages as $assign) {
        $pdo->prepare("
            INSERT IGNORE INTO wallet_tx (user_id, type, amount, package_id) 
            VALUES (?, 'package', ?, ?)
        ")->execute([$assign[0], $assign[1], $assign[2]]);
    }
    
    // Trigger mentor bonus calculation
    calc_leadership_reverse(2, 30.00, $pdo);
    
    // Check results
    echo "\n📊 Mentor Bonus Results:\n";
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, w.balance,
               (SELECT MAX(p.price) FROM packages p 
                JOIN wallet_tx wt ON wt.package_id = p.id 
                WHERE wt.user_id = u.id AND wt.type='package') as max_package_price
        FROM users u
        JOIN wallets w ON w.user_id = u.id
        WHERE u.sponsor_id = 2
        ORDER BY u.id
    ");
    $stmt->execute([]);
    $descendants = $stmt->fetchAll();
    
    foreach ($descendants as $desc) {
        echo "├── {$desc['username']} (ID:{$desc['id']}) - Max Package: $" . 
             number_format($desc['max_package_price'], 2) . " - Current Balance: $" . 
             number_format($desc['balance'], 2) . "\n";
    }
    
    // Check actual bonuses
    $stmt = $pdo->prepare("
        SELECT u.username, wt.amount, wt.type, p.name as package_name, p.price
        FROM wallet_tx wt
        JOIN users u ON u.id = wt.user_id
        JOIN packages p ON p.id = wt.package_id
        WHERE wt.type = 'leadership_reverse_bonus'
        ORDER BY wt.id DESC
    ");
    $stmt->execute([]);
    $bonuses = $stmt->fetchAll();
    
    echo "\n💰 Mentor Bonuses Paid:\n";
    foreach ($bonuses as $bonus) {
        echo "├── {$bonus['username']} earned {$bonus['type']} $" . 
             number_format($bonus['amount'], 2) . 
             " (using {$bonus['package_name']} package: $" . 
             number_format($bonus['price'], 2) . ")\n";
    }
}

// Quick verification queries
function verifySystem(PDO $pdo) {
    echo "\n🔍 System Verification:\n";
    echo "======================\n";
    
    // Check package assignments
    echo "\n📦 Current Package Assignments:\n";
    $stmt = $pdo->query("
        SELECT u.username, p.name, p.price
        FROM users u
        JOIN wallet_tx wt ON wt.user_id = u.id AND wt.type='package'
        JOIN packages p ON p.id = wt.package_id
        ORDER BY u.id
    ");
    foreach ($stmt->fetchAll() as $pkg) {
        echo "├── {$pkg['username']}: {$pkg['name']} (\${$pkg['price']})\n";
    }
    
    // Test simple verification
    echo "\n✅ System ready for testing!\n";
}

// Run tests
setupTestData($pdo);
verifySystem($pdo);
testLeadershipReverse($pdo);

// Quick verification
echo "\n💡 Quick Manual Test:\n";
echo "1. Login as admin (user1)\n";
echo "2. User2 buys package → User1 gets leadership bonus\n";
echo "3. User3 buys package → User2 gets mentor bonus\n";
echo "4. Check wallet balances and transactions\n";
?>