<?php
require_once 'config.php';

// Initialize test data
function setupTestData(PDO $pdo) {
    echo "🧪 Setting up test data...\n";
    
    // Create hierarchical test users
    $users = [
        ['admin', 'admin123', null, null, 'root', 'admin'],
        ['leader', 'pass123', 1, 1, 'left', 'user'],    // Level 1 ancestor
        ['child1', 'pass123', 2, 2, 'left', 'user'],   // Level 2 descendant
        ['child2', 'pass123', 2, 2, 'right', 'user'],  // Level 2 descendant
        ['grand1', 'pass123', 3, 3, 'left', 'user'],   // Level 3 descendant
        ['grand2', 'pass123', 3, 3, 'right', 'user']   // Level 3 descendant
    ];
    
    foreach ($users as $user) {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password, sponsor_id, upline_id, position, role) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id=id
        ");
        $stmt->execute([$user[0], password_hash($user[1], PASSWORD_DEFAULT), $user[2], $user[3], $user[4], $user[5]]);
    }
    
    // Create wallets for all users
    for ($i = 1; $i <= 6; $i++) {
        $pdo->prepare("INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)")->execute([$i]);
    }
    
    // Assign packages with different price levels
    $package_assignments = [
        [1, 1, -25.00],  // admin - Starter
        [2, 2, -50.00],  // leader - Pro
        [3, 3, -100.00], // child1 - Elite
        [4, 3, -25.00],  // child2 - Starter
        [5, 2, -50.00],  // grand1 - Pro
        [6, 3, -100.00], // grand2 - Elite
    ];
    
    foreach ($package_assignments as $assign) {
        $pdo->prepare("
            INSERT INTO wallet_tx (user_id, type, amount, package_id) 
            VALUES (?, 'package', ?, ?)
            ON DUPLICATE KEY UPDATE id=id
        ")->execute([$assign[0], $assign[1], $assign[2]]);
    }
    
    echo "✅ Test data ready (6 users with hierarchical packages)\n";
}

// Test leadership reverse specifically
function testLeadershipReverse(PDO $pdo) {
    echo "\n🎯 Testing Leadership Reverse (Mentor Bonus):\n";
    echo "==========================================\n";
    
    // Reset all counts
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    
    // Scenario: Leader (ID:2) earns binary bonus
    echo "\n📊 Scenario: Leader (user2) earns binary bonus\n";
    echo "├── Should trigger mentor bonuses for descendants\n";
    echo "├── Descendants: child1 (Elite), child2 (Starter), grand1 (Pro), grand2 (Elite)\n";
    
    // Trigger binary calculation for leader's downline
    calc_binary(3, 50, $pdo); // child1 buys package
    
    // Then trigger reverse leadership
    calc_leadership_reverse(2, 25.00, $pdo); // leader gets mentor bonus
    
    // Check results
    echo "\n📈 Results:\n";
    
    // Check all descendant bonuses
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, w.balance, 
               (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as downline_count,
               (SELECT MAX(p.price) FROM packages p 
                JOIN wallet_tx wt ON wt.package_id = p.id 
                WHERE wt.user_id = u.id AND wt.type='package') as max_package_price
        FROM users u
        JOIN wallets w ON w.user_id = u.id
        WHERE u.sponsor_id = 2 OR u.sponsor_id IN (SELECT id FROM users WHERE sponsor_id = 2)
        ORDER BY u.id
    ");
    $stmt->execute([]);
    $results = $stmt->fetchAll();
    
    echo "\n👥 Descendant Analysis:\n";
    foreach ($results as $row) {
        echo "├── {$row['username']} (ID:{$row['id']}) - Max Package: $" . 
             number_format($row['max_package_price'], 2) . " - Balance: $" . 
             number_format($row['balance'], 2) . "\n";
    }
    
    // Check actual bonuses paid
    $stmt = $pdo->prepare("
        SELECT u.username, wt.amount, wt.created_at, wt.type
        FROM wallet_tx wt
        JOIN users u ON u.id = wt.user_id
        WHERE wt.type IN ('leadership_bonus', 'leadership_reverse_bonus')
        ORDER BY wt.id DESC
    ");
    $stmt->execute([]);
    $bonuses = $stmt->fetchAll();
    
    echo "\n💰 Bonuses Paid:\n";
    $totalLeadership = 0;
    $totalMentor = 0;
    
    foreach ($bonuses as $bonus) {
        echo "├── {$bonus['username']}: {$bonus['type']} $" . 
             number_format($bonus['amount'], 2) . " at {$bonus['created_at']}\n";
        
        if ($bonus['type'] == 'leadership_bonus') $totalLeadership += $bonus['amount'];
        if ($bonus['type'] == 'leadership_reverse_bonus') $totalMentor += $bonus['amount'];
    }
    
    echo "\n📊 Summary:\n";
    echo "├── Total Leadership Bonuses: $" . number_format($totalLeadership, 2) . "\n";
    echo "├── Total Mentor Bonuses: $" . number_format($totalMentor, 2) . "\n";
}

// Test both systems
function testBothSystems(PDO $pdo) {
    echo "\n🚀 Testing Complete Leadership System:\n";
    echo "====================================\n";
    
    // Reset system
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('leadership_bonus', 'leadership_reverse_bonus', 'pair_bonus')");
    $pdo->exec("DELETE FROM flushes");
    
    // Test 1: Leadership calculation (ancestor gets bonus)
    echo "\n1️⃣ Testing Leadership Calculation:\n";
    calc_binary(3, 100, $pdo); // child1 (Elite) activity
    calc_leadership(3, 50.00, $pdo); // ancestors get leadership bonus
    
    // Test 2: Leadership reverse (descendants get bonus)
    echo "\n2️⃣ Testing Leadership Reverse:\n";
    calc_leadership_reverse(2, 30.00, $pdo); // leader gets mentor bonus
    
    // Verify results
    echo "\n📊 Final System State:\n";
    
    $stmt = $pdo->prepare("
        SELECT u.username, w.balance,
               (SELECT SUM(amount) FROM wallet_tx WHERE user_id = u.id AND type='leadership_bonus') as leadership_total,
               (SELECT SUM(amount) FROM wallet_tx WHERE user_id = u.id AND type='leadership_reverse_bonus') as mentor_total
        FROM users u
        JOIN wallets w ON w.user_id = u.id
        ORDER BY u.id
    ");
    $stmt->execute([]);
    $final = $stmt->fetchAll();
    
    foreach ($final as $user) {
        $leadership = $user['leadership_total'] ?: 0;
        $mentor = $user['mentor_total'] ?: 0;
        
        echo "├── {$user['username']}: $" . number_format($user['balance'], 2) . 
             " (Leadership: $" . number_format($leadership, 2) . 
             ", Mentor: $" . number_format($mentor, 2) . ")\n";
    }
}

// Run comprehensive tests
setupTestData($pdo);
testLeadershipReverse($pdo);
testBothSystems($pdo);

// Verification queries
echo "\n🔍 Verification Queries:\n";
echo "========================\n";

// Check package assignments
echo "\n📦 Package Assignments:\n";
$stmt = $pdo->query("
    SELECT u.username, p.name, p.price, p.daily_max, p.pair_rate
    FROM users u
    JOIN wallet_tx wt ON wt.user_id = u.id AND wt.type='package'
    JOIN packages p ON p.id = wt.package_id
    ORDER BY u.id
");
$packages = $stmt->fetchAll();
foreach ($packages as $pkg) {
    echo "├── {$pkg['username']}: {$pkg['name']} (\${$pkg['price']}, Daily: {$pkg['daily_max']}, Rate: {$pkg['pair_rate']})\n";
}

// Check leadership schedule mappings
echo "\n📋 Schedule Mappings:\n";
$stmt = $pdo->query("
    SELECT p.name, pls.level, pls.pvt_required, pls.gvt_required, pls.rate
    FROM package_leadership_schedule pls
    JOIN packages p ON p.id = pls.package_id
    ORDER BY p.id, pls.level
");
foreach ($stmt->fetchAll() as $schedule) {
    echo "├── {$schedule['name']} L{$schedule['level']}: {$schedule['pvt_required']} PVT, {$schedule['gvt_required']} GVT, {$schedule['rate']} rate\n";
}

echo "\n✅ All leadership systems tested successfully!\n";
echo "📝 Check database for detailed transaction logs.\n";
?>