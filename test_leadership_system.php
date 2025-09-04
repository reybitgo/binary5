<?php
require_once 'leadership_calc.php';
require_once 'leadership_reverse_calc.php';

// Initialize test data
function setupTestData(PDO $pdo) {
    echo "🧪 Setting up test data...<br>";
    
    // Clear existing test data
    $pdo->exec("DELETE FROM wallet_tx WHERE user_id > 0");
    $pdo->exec("DELETE FROM wallets WHERE user_id > 0");
    $pdo->exec("DELETE FROM users WHERE id > 0");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
    
    // Create hierarchical test users with proper position values
    $users = [
        ['admin', 'admin123', null, null, null, 'admin'],  // ID: 1, Root has no position
        ['leader', 'pass123', 1, 1, 'left', 'user'],       // ID: 2, Level 1
        ['child1', 'pass123', 2, 2, 'left', 'user'],       // ID: 3, Level 2
        ['child2', 'pass123', 2, 2, 'right', 'user'],      // ID: 4, Level 2
        ['grand1', 'pass123', 3, 3, 'left', 'user'],       // ID: 5, Level 3 (child of child1)
        ['grand2', 'pass123', 3, 3, 'right', 'user'],      // ID: 6, Level 3 (child of child1)
        ['great1', 'pass123', 5, 5, 'left', 'user'],       // ID: 7, Level 4 (child of grand1)
        ['great2', 'pass123', 5, 5, 'right', 'user']       // ID: 8, Level 4 (child of grand1)
    ];
    
    foreach ($users as $user) {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password, sponsor_id, upline_id, position, role) 
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
    
    // Ensure wallets exist for all users
    for ($i = 1; $i <= 8; $i++) {
        $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)")->execute([$i]);
    }
    
    echo "✅ Test data ready (8 users with proper hierarchy)<br>";
    
    // Show the hierarchy structure
    echo "<br>👥 Created User Hierarchy:<br>";
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.sponsor_id, u.upline_id, u.position
        FROM users u 
        ORDER BY u.id
    ");
    
    foreach ($stmt->fetchAll() as $user) {
        $sponsor = $user['sponsor_id'] ? "Sponsor:{$user['sponsor_id']}" : "Root";
        $upline = $user['upline_id'] ? "Upline:{$user['upline_id']}" : "Root";
        echo "├── {$user['username']} (ID:{$user['id']}) - {$sponsor}, {$upline}, Pos:{$user['position']}<br>";
    }
}

// Test leadership bonus (ancestors earn when descendants get binary bonus)
function testLeadershipBonus(PDO $pdo) {
    echo "<br>🎯 Testing Leadership Bonus (Ancestor earns when descendant gets binary):<br>";
    echo "============================================================<br>";
    
    // Reset system
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('leadership_bonus', 'leadership_reverse_bonus', 'pair_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("UPDATE wallets SET balance = 0.00");
    
    echo "<br>1️⃣ Leadership Bonus Test:<br>";
    echo "├── Admin (user1) - Elite package (highest)<br>";
    echo "├── Leader (user2) - Pro package<br>";
    echo "├── Child1 (user3) - Starter package<br>";
    echo "└── Child1 earns binary bonus → Admin & Leader get leadership bonus<br>";
    
    // Assign packages to establish hierarchy (these users definitely exist)
    $packages = [
        [1, 3, -100.00], // admin - Elite (100 USD - highest)
        [2, 2, -50.00],  // leader - Pro (50 USD)
        [3, 1, -25.00],  // child1 - Starter (25 USD)
    ];
    
    foreach ($packages as $assign) {
        $pdo->prepare("
            INSERT INTO wallet_tx (user_id, package_id, type, amount) 
            VALUES (?, ?, 'package', ?)
        ")->execute([$assign[0], $assign[1], $assign[2]]);
    }
    
    // Simulate child1 earning a binary bonus of $30
    $binaryBonus = 30.00;
    echo "<br>💰 Child1 earns binary bonus: $" . number_format($binaryBonus, 2) . "<br>";
    
    // Credit child1's binary bonus first
    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = 3")->execute([$binaryBonus]);
    $pdo->prepare("INSERT INTO wallet_tx (user_id, type, amount, package_id) VALUES (3, 'pair_bonus', ?, 1)")->execute([$binaryBonus]);
    
    // Trigger leadership calculation (child1 earned bonus, ancestors get leadership bonus)
    calc_leadership(3, $binaryBonus, $pdo);
    
    // Check results
    echo "<br>📊 Leadership Bonus Results:<br>";
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, w.balance,
               (SELECT MAX(p.price) FROM packages p 
                JOIN wallet_tx wt ON wt.package_id = p.id 
                WHERE wt.user_id = u.id AND wt.type='package') as max_package_price,
               (SELECT COUNT(*) FROM wallet_tx wt2 
                WHERE wt2.user_id = u.id AND wt2.type = 'leadership_bonus') as leadership_count
        FROM users u
        JOIN wallets w ON w.user_id = u.id
        WHERE u.id IN (1, 2, 3)
        ORDER BY u.id
    ");
    $stmt->execute();
    $results = $stmt->fetchAll();
    
    foreach ($results as $result) {
        echo "├── {$result['username']} (ID:{$result['id']}) - Package: $" . 
             number_format($result['max_package_price'], 2) . " - Balance: $" . 
             number_format($result['balance'], 2) . " - Leadership bonuses: {$result['leadership_count']}<br>";
    }
    
    // Show detailed leadership bonuses
    $stmt = $pdo->prepare("
        SELECT u.username, wt.amount, wt.type, wt.created_at
        FROM wallet_tx wt
        JOIN users u ON u.id = wt.user_id
        WHERE wt.type = 'leadership_bonus'
        ORDER BY wt.id DESC
    ");
    $stmt->execute(); 
    $bonuses = $stmt->fetchAll();
    
    echo "<br>🏆 Leadership Bonuses Paid:<br>";
    if (empty($bonuses)) {
        echo "├── No leadership bonuses paid (check requirements/logic)<br>";
    } else {
        foreach ($bonuses as $bonus) {
            echo "├── {$bonus['username']} earned $" . 
                 number_format($bonus['amount'], 2) . 
                 " ({$bonus['type']}) at {$bonus['created_at']}<br>";
        }
    }
}

// Test leadership reverse (descendants earn when ancestors get binary bonus)
function testLeadershipReverse(PDO $pdo) {
    echo "<br>🎯 Testing Leadership Reverse (Mentor Bonus):<br>";
    echo "================================================<br>";
    
    // Reset system
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('leadership_bonus', 'leadership_reverse_bonus', 'pair_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("UPDATE wallets SET balance = 0.00");
    
    echo "<br>2️⃣ Mentor Bonus Test:<br>";
    echo "├── Leader (user2) as ancestor<br>";
    echo "├── Child1 (user3) - Elite package (highest)<br>";
    echo "├── Child2 (user4) - Pro package<br>";
    echo "└── Leader earns binary bonus → Children get mentor bonus<br>";
    
    // Assign packages - using existing user IDs
    $packages = [
        [2, 1, -25.00],  // leader - Starter (25 USD)
        [3, 3, -100.00], // child1 - Elite (100 USD - highest)
        [4, 2, -50.00],  // child2 - Pro (50 USD)
    ];
    
    foreach ($packages as $assign) {
        $pdo->prepare("
            INSERT INTO wallet_tx (user_id, package_id, type, amount) 
            VALUES (?, ?, 'package', ?)
        ")->execute([$assign[0], $assign[1], $assign[2]]);
    }
    
    // Simulate leader earning a binary bonus
    $binaryBonus = 40.00;
    echo "<br>💰 Leader earns binary bonus: $" . number_format($binaryBonus, 2) . "<br>";
    
    // Credit leader's binary bonus first
    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = 2")->execute([$binaryBonus]);
    $pdo->prepare("INSERT INTO wallet_tx (user_id, type, amount, package_id) VALUES (2, 'pair_bonus', ?, 1)")->execute([$binaryBonus]);
    
    // Trigger mentor bonus calculation (leader earned bonus, descendants get mentor bonus)
    calc_leadership_reverse(2, $binaryBonus, $pdo);
    
    // Check results
    echo "<br>📊 Mentor Bonus Results:<br>";
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, w.balance,
               (SELECT MAX(p.price) FROM packages p 
                JOIN wallet_tx wt ON wt.package_id = p.id 
                WHERE wt.user_id = u.id AND wt.type='package') as max_package_price,
               (SELECT COUNT(*) FROM wallet_tx wt2 
                WHERE wt2.user_id = u.id AND wt2.type = 'leadership_reverse_bonus') as mentor_count
        FROM users u
        JOIN wallets w ON w.user_id = u.id
        WHERE u.sponsor_id = 2 OR u.id = 2
        ORDER BY u.id
    ");
    $stmt->execute();
    $descendants = $stmt->fetchAll();
    
    foreach ($descendants as $desc) {
        echo "├── {$desc['username']} (ID:{$desc['id']}) - Package: $" . 
             number_format($desc['max_package_price'], 2) . " - Balance: $" . 
             number_format($desc['balance'], 2) . " - Mentor bonuses: {$desc['mentor_count']}<br>";
    }
    
    // Check actual bonuses
    $stmt = $pdo->prepare("
        SELECT u.username, wt.amount, wt.type, wt.created_at
        FROM wallet_tx wt
        JOIN users u ON u.id = wt.user_id
        WHERE wt.type = 'leadership_reverse_bonus'
        ORDER BY wt.id DESC
    ");
    $stmt->execute(); 
    $bonuses = $stmt->fetchAll();
    
    echo "<br>🎁 Mentor Bonuses Paid:<br>";
    if (empty($bonuses)) {
        echo "├── No mentor bonuses paid (check requirements/logic)<br>";
    } else {
        foreach ($bonuses as $bonus) {
            echo "├── {$bonus['username']} earned $" . 
                 number_format($bonus['amount'], 2) . 
                 " ({$bonus['type']}) at {$bonus['created_at']}<br>";
        }
    }
}

// Test both systems together
function testIntegratedSystem(PDO $pdo) {
    echo "<br>🎯 Testing Integrated Leadership System:<br>";
    echo "======================================<br>";
    
    // Reset system
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('leadership_bonus', 'leadership_reverse_bonus', 'pair_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("UPDATE wallets SET balance = 0.00");
    
    echo "<br>3️⃣ Integrated Test (Both Leadership Types):<br>";
    echo "├── Admin (user1) - Elite package<br>";
    echo "├── Leader (user2) - Pro package<br>";
    echo "├── Child1 (user3) - Starter package<br>";
    echo "└── Child1 gets binary → Admin/Leader get leadership, Child1's descendants get mentor<br>";
    
    // Assign packages across hierarchy
    $packages = [
        [1, 3, -100.00], // admin - Elite
        [2, 2, -50.00],  // leader - Pro  
        [3, 1, -25.00],  // child1 - Starter
        [5, 2, -50.00],  // grand1 - Pro (child of child1)
        [6, 1, -25.00],  // grand2 - Starter (child of child1)
    ];
    
    foreach ($packages as $assign) {
        $pdo->prepare("
            INSERT INTO wallet_tx (user_id, package_id, type, amount) 
            VALUES (?, ?, 'package', ?)
        ")->execute([$assign[0], $assign[1], $assign[2]]);
    }
    
    // Simulate child1 earning binary bonus
    $binaryBonus = 50.00;
    echo "<br>💰 Child1 earns binary bonus: $" . number_format($binaryBonus, 2) . "<br>";
    
    // Credit child1's binary bonus first
    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = 3")->execute([$binaryBonus]);
    $pdo->prepare("INSERT INTO wallet_tx (user_id, type, amount, package_id) VALUES (3, 'pair_bonus', ?, 1)")->execute([$binaryBonus]);
    
    // Trigger both calculations
    calc_leadership(3, $binaryBonus, $pdo);           // Ancestors get leadership bonus
    calc_leadership_reverse(3, $binaryBonus, $pdo);  // Descendants get mentor bonus
    
    // Show comprehensive results
    echo "<br>📊 Integrated System Results:<br>";
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, w.balance,
               (SELECT COUNT(*) FROM wallet_tx wt2 WHERE wt2.user_id = u.id AND wt2.type = 'leadership_bonus') as leadership_count,
               (SELECT COUNT(*) FROM wallet_tx wt3 WHERE wt3.user_id = u.id AND wt3.type = 'leadership_reverse_bonus') as mentor_count,
               (SELECT COUNT(*) FROM wallet_tx wt4 WHERE wt4.user_id = u.id AND wt4.type = 'pair_bonus') as pair_count
        FROM users u
        JOIN wallets w ON w.user_id = u.id
        WHERE u.id IN (1,2,3,5,6)
        ORDER BY u.id
    ");
    $stmt->execute();
    $results = $stmt->fetchAll();
    
    foreach ($results as $result) {
        echo "├── {$result['username']} (ID:{$result['id']}) - Balance: $" . 
             number_format($result['balance'], 2) . 
             " [L:{$result['leadership_count']}, M:{$result['mentor_count']}, P:{$result['pair_count']}]<br>";
    }
    
    // Show all bonus transactions
    echo "<br>💎 All Bonus Transactions:<br>";
    $stmt = $pdo->prepare("
        SELECT u.username, wt.type, wt.amount, wt.created_at
        FROM wallet_tx wt
        JOIN users u ON u.id = wt.user_id
        WHERE wt.type IN ('leadership_bonus', 'leadership_reverse_bonus', 'pair_bonus')
        ORDER BY wt.created_at DESC
    ");
    $stmt->execute(); 
    $allBonuses = $stmt->fetchAll();
    
    foreach ($allBonuses as $bonus) {
        $icon = ($bonus['type'] == 'leadership_bonus') ? '🏆' : 
                (($bonus['type'] == 'leadership_reverse_bonus') ? '🎁' : '💰');
        echo "├── {$icon} {$bonus['username']}: $" . 
             number_format($bonus['amount'], 2) . 
             " ({$bonus['type']})<br>";
    }
}

// Diagnostic function
function showSystemState(PDO $pdo) {
    echo "<br>🔍 System Diagnostic:<br>";
    echo "===================<br>";
    
    // Show hierarchy
    echo "<br>👥 User Hierarchy:<br>";
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.sponsor_id, u.upline_id, u.position,
               (SELECT p.name FROM packages p 
                JOIN wallet_tx wt ON wt.package_id = p.id 
                WHERE wt.user_id = u.id AND wt.type='package'
                ORDER BY wt.id DESC LIMIT 1) as package_name
        FROM users u 
        WHERE u.role = 'user' OR u.role = 'admin'
        ORDER BY u.id
    ");
    
    foreach ($stmt->fetchAll() as $user) {
        $sponsor = $user['sponsor_id'] ? "Sponsor:{$user['sponsor_id']}" : "No Sponsor";
        $upline = $user['upline_id'] ? "Upline:{$user['upline_id']}" : "No Upline";
        $package = $user['package_name'] ?: "No Package";
        
        echo "├── {$user['username']} (ID:{$user['id']}) - {$sponsor}, {$upline}, Pos:{$user['position']}, Pkg:{$package}<br>";
    }
    
    // Show leadership/mentor schedules
    echo "<br>📋 Package Leadership Rates:<br>";
    $stmt = $pdo->query("
        SELECT p.name, pls.level, pls.rate
        FROM packages p
        JOIN package_leadership_schedule pls ON pls.package_id = p.id
        WHERE pls.level <= 3
        ORDER BY p.id, pls.level
    ");
    
    $currentPackage = '';
    foreach ($stmt->fetchAll() as $rate) {
        if ($rate['name'] != $currentPackage) {
            if ($currentPackage) echo "<br>";
            echo "├── {$rate['name']} Package:<br>";
            $currentPackage = $rate['name'];
        }
        echo "│   └── Level {$rate['level']}: " . ($rate['rate'] * 100) . "%<br>";
    }
    
    echo "<br>📋 Package Mentor Rates:<br>";
    $stmt = $pdo->query("
        SELECT p.name, pms.level, pms.rate
        FROM packages p
        JOIN package_mentor_schedule pms ON pms.package_id = p.id
        WHERE pms.level <= 3
        ORDER BY p.id, pms.level
    ");
    
    $currentPackage = '';
    foreach ($stmt->fetchAll() as $rate) {
        if ($rate['name'] != $currentPackage) {
            if ($currentPackage) echo "<br>";
            echo "├── {$rate['name']} Package:<br>";
            $currentPackage = $rate['name'];
        }
        echo "│   └── Level {$rate['level']}: " . ($rate['rate'] * 100) . "%<br>";
    }
}

// Run all tests
try {
    setupTestData($pdo);
    showSystemState($pdo);
    testLeadershipBonus($pdo);
    testLeadershipReverse($pdo);
    testIntegratedSystem($pdo);

    // Manual testing guidance
    echo "<br>💡 Manual Testing Guide:<br>";
    echo "========================<br>";
    echo "1. Leadership Bonus: When a user earns binary bonus, their ANCESTORS get leadership bonus<br>";
    echo "   - Bonus rate depends on ANCESTOR'S package level<br>";
    echo "   - Requirements (PVT/GVT) must be met by ANCESTOR<br>";
    echo "<br>";
    echo "2. Mentor Bonus: When a user earns binary bonus, their DESCENDANTS get mentor bonus<br>";
    echo "   - Bonus rate depends on DESCENDANT'S package level<br>";
    echo "   - Requirements (PVT/GVT) must be met by DESCENDANT<br>";
    echo "<br>";
    echo "3. Both systems can work simultaneously for the same binary bonus event<br>";
    echo "4. Check wallet balances and transaction logs for verification<br>";

} catch (Exception $e) {
    echo "<br>❌ Error occurred: " . $e->getMessage() . "<br>";
    echo "Line: " . $e->getLine() . " in " . $e->getFile() . "<br>";
    echo "<br>Stack trace:<br><pre>" . $e->getTraceAsString() . "</pre>";
}

?>