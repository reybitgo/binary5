<?php
// test_guaranteed_all_bonuses.php - Package-based limitations with guaranteed all bonus types
// Specially designed to ensure at least one user earns all four bonus types including mentor bonus

require_once 'config.php';
require_once 'binary_calc.php';
require_once 'leadership_calc.php';
require_once 'leadership_reverse_calc.php';
require_once 'referral_calc.php';
require_once 'functions.php';

echo "<pre>";

function setupGuaranteedAllBonusesData(PDO $pdo) {
    echo "Setting up GUARANTEED ALL BONUSES test (with package limitations)...\n";
    
    // Clear existing test data
    $pdo->exec("DELETE FROM wallet_tx WHERE user_id > 0");
    $pdo->exec("DELETE FROM wallets WHERE user_id > 0");
    $pdo->exec("DELETE FROM users WHERE id > 0");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
    
    // Strategic hierarchy designed to guarantee mentor bonuses
    // Key insight: User 6 (sponsor2_child1) will be our "golden user" who earns all bonuses
    $users = [
        // Level 0: Root
        ['admin', 'admin123', null, null, null, 'admin'],              // ID: 1
        
        // Level 1: Direct children of admin (will earn binary when admin gets binary)
        ['sponsor1', 'pass123', 1, 1, 'left', 'user'],               // ID: 2
        ['sponsor2', 'pass123', 1, 1, 'right', 'user'],              // ID: 3
        
        // Level 2: Children of sponsors (sponsor2_child1 will be our golden user)
        ['sponsor1_child1', 'pass123', 2, 2, 'left', 'user'],        // ID: 4
        ['sponsor1_child2', 'pass123', 2, 2, 'right', 'user'],       // ID: 5
        ['sponsor2_child1', 'pass123', 3, 3, 'left', 'user'],        // ID: 6 ⭐ GOLDEN USER
        ['sponsor2_child2', 'pass123', 3, 3, 'right', 'user'],       // ID: 7
        
        // Level 3: Children of Level 2 (these will generate binary for Level 2)
        ['deep1_L', 'pass123', 4, 4, 'left', 'user'],                // ID: 8
        ['deep1_R', 'pass123', 4, 4, 'right', 'user'],               // ID: 9
        ['deep2_L', 'pass123', 5, 5, 'left', 'user'],                // ID: 10
        ['deep2_R', 'pass123', 5, 5, 'right', 'user'],               // ID: 11
        ['deep3_L', 'pass123', 6, 6, 'left', 'user'],                // ID: 12
        ['deep3_R', 'pass123', 6, 6, 'right', 'user'],               // ID: 13
        ['deep4_L', 'pass123', 7, 7, 'left', 'user'],                // ID: 14
        ['deep4_R', 'pass123', 7, 7, 'right', 'user'],               // ID: 15
        
        // Level 4: Children of Level 3 (more volume generation)
        ['ultra1', 'pass123', 8, 8, 'left', 'user'],                 // ID: 16
        ['ultra2', 'pass123', 9, 9, 'right', 'user'],                // ID: 17
        ['ultra3', 'pass123', 12, 12, 'left', 'user'],               // ID: 18
        ['ultra4', 'pass123', 13, 13, 'right', 'user'],              // ID: 19
        
        // Level 5: Final level for maximum mentor bonus potential
        ['final1', 'pass123', 18, 18, 'left', 'user'],               // ID: 20
        ['final2', 'pass123', 19, 19, 'right', 'user']               // ID: 21
    ];
    
    foreach ($users as $user) {
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, sponsor_id, upline_id, position, role) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $user[0], 
            password_hash($user[1], PASSWORD_DEFAULT), 
            $user[2], 
            $user[3], 
            $user[4], 
            $user[5]
        ]);
    }
    
    // Create wallets with high balance
    for ($i = 1; $i <= 21; $i++) {
        $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 100000.00)")->execute([$i]);
    }
    
    // Setup packages with balanced settings
    $pdo->exec("DELETE FROM packages");
    $pdo->exec("ALTER TABLE packages AUTO_INCREMENT = 1");
    $packages = [
        ['Starter', 25.00, 25, 100, 0.1500, 0.0800],    // ID:1
        ['Pro', 50.00, 50, 200, 0.2000, 0.1000],        // ID:2  
        ['Elite', 100.00, 100, 400, 0.2500, 0.1200]     // ID:3
    ];
    foreach ($packages as $pkg) {
        $pdo->prepare(
            "INSERT INTO packages (name, price, pv, daily_max, pair_rate, referral_rate) 
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute($pkg);
    }
    
    // ULTRA-LOW leadership requirements to guarantee qualification
    $pdo->exec("DELETE FROM package_leadership_schedule");
    $leadership_schedules = [
        // Starter - Ultra achievable
        [1, 1, 25, 25, 0.030], [1, 2, 25, 50, 0.025], [1, 3, 50, 75, 0.020], [1, 4, 75, 100, 0.015], [1, 5, 100, 125, 0.010],
        // Pro - Very achievable  
        [2, 1, 50, 50, 0.050], [2, 2, 50, 100, 0.040], [2, 3, 100, 150, 0.030], [2, 4, 150, 200, 0.020], [2, 5, 200, 250, 0.010],
        // Elite - Achievable with volume
        [3, 1, 100, 100, 0.070], [3, 2, 100, 200, 0.060], [3, 3, 200, 300, 0.050], [3, 4, 300, 400, 0.040], [3, 5, 400, 500, 0.030]
    ];
    foreach ($leadership_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    // ULTRA-LOW mentor requirements - THE KEY TO SUCCESS
    $pdo->exec("DELETE FROM package_mentor_schedule");
    $mentor_schedules = [
        // Starter - Minimal requirements
        [1, 1, 25, 25, 0.020], [1, 2, 25, 25, 0.018], [1, 3, 25, 50, 0.015], [1, 4, 50, 75, 0.012], [1, 5, 75, 100, 0.010],
        // Pro - Low but achievable
        [2, 1, 50, 50, 0.030], [2, 2, 50, 50, 0.025], [2, 3, 50, 100, 0.020], [2, 4, 100, 150, 0.015], [2, 5, 150, 200, 0.010],
        // Elite - Still reasonable
        [3, 1, 100, 100, 0.040], [3, 2, 100, 150, 0.035], [3, 3, 150, 200, 0.030], [3, 4, 200, 300, 0.025], [3, 5, 300, 400, 0.020]
    ];
    foreach ($mentor_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    echo "Guaranteed all-bonuses data ready: 21 users in strategic 5-level hierarchy\n";
    
    echo "\nStrategic Hierarchy (User 6 is our target for all bonuses):\n";
    $stmt = $pdo->query("SELECT id, username, sponsor_id, upline_id, position FROM users ORDER BY id");
    foreach ($stmt->fetchAll() as $user) {
        $sponsor = $user['sponsor_id'] ? "S:{$user['sponsor_id']}" : "Root";
        $upline = $user['upline_id'] ? "U:{$user['upline_id']}" : "Root";
        $marker = ($user['id'] == 6) ? " ⭐ GOLDEN USER" : "";
        echo "-- {$user['username']} (ID:{$user['id']}) - {$sponsor}, {$upline}, Pos:{$user['position']}{$marker}\n";
    }
}

function simulateStrategicPurchase(PDO $pdo, int $userId, int $packageId, string $purpose = "") {
    $stmt = $pdo->prepare("SELECT name, price, pv FROM packages WHERE id = ?");
    $stmt->execute([$packageId]);
    $pkg = $stmt->fetch();
    if (!$pkg) {
        echo "Package ID $packageId not found\n";
        return;
    }
    
    $price = $pkg['price'];
    $pv = $pkg['pv'];
    $amount = -$price;
    
    // Record transaction with package_id
    $pdo->prepare(
        "INSERT INTO wallet_tx (user_id, package_id, type, amount) 
         VALUES (?, ?, 'package', ?)"
    )->execute([$userId, $packageId, $amount]);
    
    // Update wallet
    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")->execute([$amount, $userId]);
    
    // Calculate all bonuses
    calc_referral($userId, $price, $pdo);
    calc_binary($userId, $pv, $pdo);
    
    $username = getUsernameById($userId, $pdo);
    $purposeStr = $purpose ? " ({$purpose})" : "";
    echo "{$username} (ID:{$userId}) bought {$pkg['name']} \${$price}{$purposeStr}\n";
}

function testGuaranteedAllBonuses(PDO $pdo) {
    echo "\nTesting GUARANTEED All Four Bonus Types (with Package Limitations)\n";
    echo "================================================================\n";
    
    // Reset everything
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('package', 'pair_bonus', 'referral_bonus', 'leadership_bonus', 'leadership_reverse_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("UPDATE wallets SET balance = 100000.00");
    
    echo "\n🎯 PHASE 1: Strategic Foundation - Build User 6's earning potential\n";
    
    // First, establish User 6 (sponsor2_child1) with a good package for earning capacity
    simulateStrategicPurchase($pdo, 6, 2, "Golden User gets Pro package for capacity");
    
    // Build User 6's upline with packages (for referral bonus TO User 6)
    simulateStrategicPurchase($pdo, 3, 2, "sponsor2 gets Pro - will provide referral to User 6");
    simulateStrategicPurchase($pdo, 1, 3, "admin gets Elite - top level qualification");
    
    echo "\n📈 PHASE 2: Build Descendant Volumes (Critical for mentor bonuses)\n";
    
    // Build User 6's descendants with purchases to create their PVT/GVT
    simulateStrategicPurchase($pdo, 12, 1, "User 6's left child - builds User 6's GVT");
    simulateStrategicPurchase($pdo, 13, 1, "User 6's right child - builds User 6's GVT");
    simulateStrategicPurchase($pdo, 18, 1, "Level 4 descendant - more GVT for User 6");
    simulateStrategicPurchase($pdo, 19, 1, "Level 4 descendant - more GVT for User 6");
    simulateStrategicPurchase($pdo, 20, 1, "Level 5 descendant - maximum GVT for User 6");
    simulateStrategicPurchase($pdo, 21, 1, "Level 5 descendant - maximum GVT for User 6");
    
    // Check User 6's volumes after building descendants
    $user6_pvt = getPersonalVolume(6, $pdo);
    $user6_gvt = getGroupVolume(6, $pdo);
    echo "User 6 volumes after descendant building: PVT=\${$user6_pvt}, GVT=\${$user6_gvt}\n";
    
    echo "\n🔥 PHASE 3: Trigger Referral Bonus for User 6\n";
    
    // User 6 makes additional purchase to trigger referral FROM sponsor2 (User 3)
    simulateStrategicPurchase($pdo, 6, 1, "Triggers referral bonus from sponsor2 to User 6");
    
    echo "\n⚡ PHASE 4: Build Binary Tree Balance\n";
    
    // Build other parts of tree to create binary balance
    simulateStrategicPurchase($pdo, 2, 2, "sponsor1 gets Pro package");
    simulateStrategicPurchase($pdo, 4, 1, "sponsor1_child1 - builds left side");
    simulateStrategicPurchase($pdo, 5, 1, "sponsor1_child2 - builds right side");
    simulateStrategicPurchase($pdo, 7, 1, "sponsor2_child2 - balances User 6's side");
    
    // Build Level 3 to create pairs
    simulateStrategicPurchase($pdo, 8, 1, "deep1_L - creates binary opportunities");
    simulateStrategicPurchase($pdo, 9, 1, "deep1_R - creates binary opportunities");
    simulateStrategicPurchase($pdo, 10, 1, "deep2_L - creates binary opportunities");
    simulateStrategicPurchase($pdo, 11, 1, "deep2_R - creates binary opportunities");
    simulateStrategicPurchase($pdo, 14, 1, "deep4_L - creates binary opportunities");
    simulateStrategicPurchase($pdo, 15, 1, "deep4_R - creates binary opportunities");
    
    echo "\n💥 PHASE 5: Generate Binary Bonuses (Prerequisite for Leadership & Mentor)\n";
    
    // Additional purchases to ensure User 6 and ancestors get binary bonuses
    simulateStrategicPurchase($pdo, 16, 1, "ultra1 - generates binary up the tree");
    simulateStrategicPurchase($pdo, 17, 1, "ultra2 - generates binary up the tree");
    
    // More strategic purchases to guarantee binary bonuses
    simulateStrategicPurchase($pdo, 12, 1, "User 6's child additional purchase");
    simulateStrategicPurchase($pdo, 13, 1, "User 6's child additional purchase");
    
    echo "\n🏆 PHASE 6: Final Push - Ensure All Bonus Types\n";
    
    // Final strategic purchases to trigger all remaining bonuses
    simulateStrategicPurchase($pdo, 18, 1, "Level 4 final push");
    simulateStrategicPurchase($pdo, 19, 1, "Level 4 final push");
    simulateStrategicPurchase($pdo, 20, 1, "Level 5 final volume");
    simulateStrategicPurchase($pdo, 21, 1, "Level 5 final volume");
    
    // User 6 makes one more purchase to ensure full bonus activation
    simulateStrategicPurchase($pdo, 6, 1, "User 6 final purchase for maximum bonus potential");
    
    displayGuaranteedResults($pdo);
}

function displayGuaranteedResults(PDO $pdo) {
    echo "\n🎉 GUARANTEED ALL BONUSES RESULTS\n";
    echo "================================\n";
    
    // Detailed analysis focusing on our golden user (User 6)
    $stmt = $pdo->query(
        "SELECT u.id, u.username, w.balance,
                (SELECT p.name FROM packages p 
                 JOIN wallet_tx wt ON p.id = wt.package_id 
                 WHERE wt.user_id = u.id AND wt.type = 'package' 
                 ORDER BY p.price DESC LIMIT 1) as highest_package,
                (SELECT COALESCE(COUNT(*), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'referral_bonus') as referral_count,
                (SELECT COALESCE(COUNT(*), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'pair_bonus') as pair_count,
                (SELECT COALESCE(COUNT(*), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_bonus') as leadership_count,
                (SELECT COALESCE(COUNT(*), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_reverse_bonus') as mentor_count,
                (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'referral_bonus') as referral_total,
                (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'pair_bonus') as pair_total,
                (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_bonus') as leadership_total,
                (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_reverse_bonus') as mentor_total
         FROM users u JOIN wallets w ON w.user_id = u.id
         ORDER BY u.id"
    );
    
    $allBonusUsers = [];
    $goldenUserResult = null;
    
    echo "User Bonus Analysis:\n";
    foreach ($stmt->fetchAll() as $result) {
        $hasReferral = $result['referral_count'] > 0;
        $hasPair = $result['pair_count'] > 0;
        $hasLeadership = $result['leadership_count'] > 0;
        $hasMentor = $result['mentor_count'] > 0;
        
        $bonusTypes = [];
        if ($hasReferral) $bonusTypes[] = "✅ Referral";
        if ($hasPair) $bonusTypes[] = "✅ Binary";  
        if ($hasLeadership) $bonusTypes[] = "✅ Leadership";
        if ($hasMentor) $bonusTypes[] = "✅ Mentor";
        
        $bonusString = empty($bonusTypes) ? "❌ None" : implode(", ", $bonusTypes);
        $packageName = $result['highest_package'] ?? 'No Package';
        
        $marker = "";
        if ($result['id'] == 6) {
            $marker = " ⭐ GOLDEN USER";
            $goldenUserResult = $result;
        }
        
        echo "-- {$result['username']} (ID:{$result['id']}) - Package: {$packageName}{$marker}\n";
        echo "   Balance: \$" . number_format($result['balance'], 2) . "\n";
        echo "   Bonuses: {$bonusString}\n";
        
        if ($hasReferral || $hasPair || $hasLeadership || $hasMentor) {
            echo "   Amounts: Ref:\$" . number_format($result['referral_total'], 2) . 
                 " | Bin:\$" . number_format($result['pair_total'], 2) . 
                 " | Lead:\$" . number_format($result['leadership_total'], 2) . 
                 " | Mentor:\$" . number_format($result['mentor_total'], 2) . "\n";
        }
        
        if ($hasReferral && $hasPair && $hasLeadership && $hasMentor) {
            $allBonusUsers[] = $result;
        }
        echo "\n";
    }
    
    echo "🎯 GOLDEN USER (User 6) DETAILED ANALYSIS:\n";
    if ($goldenUserResult) {
        $user6_pvt = getPersonalVolume(6, $pdo);
        $user6_gvt = getGroupVolume(6, $pdo);
        
        echo "-- Final Volumes: PVT=\${$user6_pvt}, GVT=\${$user6_gvt}\n";
        echo "-- Package: {$goldenUserResult['highest_package']}\n";
        echo "-- Final Balance: \$" . number_format($goldenUserResult['balance'], 2) . "\n";
        echo "-- Referral: {$goldenUserResult['referral_count']} payments, \$" . number_format($goldenUserResult['referral_total'], 2) . "\n";
        echo "-- Binary: {$goldenUserResult['pair_count']} payments, \$" . number_format($goldenUserResult['pair_total'], 2) . "\n";
        echo "-- Leadership: {$goldenUserResult['leadership_count']} payments, \$" . number_format($goldenUserResult['leadership_total'], 2) . "\n";
        echo "-- Mentor: {$goldenUserResult['mentor_count']} payments, \$" . number_format($goldenUserResult['mentor_total'], 2) . "\n";
        
        $allFourBonuses = ($goldenUserResult['referral_count'] > 0 && 
                         $goldenUserResult['pair_count'] > 0 && 
                         $goldenUserResult['leadership_count'] > 0 && 
                         $goldenUserResult['mentor_count'] > 0);
        
        if ($allFourBonuses) {
            echo "🏆 SUCCESS: Golden User earned ALL FOUR bonus types!\n";
        } else {
            echo "❌ Golden User missing some bonus types - need to adjust strategy\n";
        }
    }
    
    echo "\n🏅 USERS WITH ALL FOUR BONUS TYPES:\n";
    if (empty($allBonusUsers)) {
        echo "❌ No users earned all four bonus types yet\n";
        
        // Debug analysis
        echo "\n🔍 DEBUG ANALYSIS:\n";
        
        // Check mentor bonus specifically
        $stmt = $pdo->query("SELECT user_id, amount, created_at FROM wallet_tx WHERE type = 'leadership_reverse_bonus'");
        $mentorBonuses = $stmt->fetchAll();
        
        echo "Mentor Bonuses Paid: " . count($mentorBonuses) . "\n";
        foreach ($mentorBonuses as $bonus) {
            $username = getUsernameById($bonus['user_id'], $pdo);
            echo "-- {$username} (ID:{$bonus['user_id']}): \$" . number_format($bonus['amount'], 2) . "\n";
        }
        
        // Check binary bonuses (prerequisite for mentor)
        $stmt = $pdo->query("SELECT user_id, COUNT(*) as count, SUM(amount) as total FROM wallet_tx WHERE type = 'pair_bonus' GROUP BY user_id");
        $binaryBonuses = $stmt->fetchAll();
        
        echo "\nBinary Bonuses Summary:\n";
        foreach ($binaryBonuses as $bonus) {
            $username = getUsernameById($bonus['user_id'], $pdo);
            echo "-- {$username} (ID:{$bonus['user_id']}): {$bonus['count']} payments, \$" . number_format($bonus['total'], 2) . "\n";
        }
        
    } else {
        echo "🎉 SUCCESS! Users who earned ALL FOUR bonus types:\n";
        foreach ($allBonusUsers as $user) {
            echo "-- {$user['username']} (ID:{$user['id']}) - {$user['highest_package']} package\n";
            echo "   Total Earned: \$" . number_format($user['referral_total'] + $user['pair_total'] + $user['leadership_total'] + $user['mentor_total'], 2) . "\n";
        }
    }
    
    // Show package limitation effects
    echo "\n📊 PACKAGE LIMITATION EFFECTS:\n";
    $stmt = $pdo->query("SELECT user_id, reason, COUNT(*) as count, SUM(amount) as total FROM flushes GROUP BY user_id, reason");
    $flushes = $stmt->fetchAll();
    
    if (empty($flushes)) {
        echo "-- No bonuses were flushed due to limitations\n";
    } else {
        echo "-- Flushes due to package limitations:\n";
        foreach ($flushes as $flush) {
            $username = getUsernameById($flush['user_id'], $pdo);
            echo "   {$username}: {$flush['reason']} - {$flush['count']} times, \${$flush['total']}\n";
        }
    }
    
    echo "\n📈 FINAL SUCCESS METRICS:\n";
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $usersWithReferral = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM wallet_tx WHERE type = 'referral_bonus'")->fetchColumn();
    $usersWithBinary = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM wallet_tx WHERE type = 'pair_bonus'")->fetchColumn();
    $usersWithLeadership = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM wallet_tx WHERE type = 'leadership_bonus'")->fetchColumn();
    $usersWithMentor = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM wallet_tx WHERE type = 'leadership_reverse_bonus'")->fetchColumn();
    
    echo "-- Total Users: {$totalUsers}\n";
    echo "-- Users with Referral: {$usersWithReferral}\n";
    echo "-- Users with Binary: {$usersWithBinary}\n";
    echo "-- Users with Leadership: {$usersWithLeadership}\n";
    echo "-- Users with Mentor: {$usersWithMentor} ⭐\n";
    echo "-- Users with ALL FOUR: " . count($allBonusUsers) . "\n";
}

// Run the guaranteed all bonuses test
try {
    setupGuaranteedAllBonusesData($pdo);
    testGuaranteedAllBonuses($pdo);
    
    echo "\n🎯 GUARANTEED ALL BONUSES TEST COMPLETE!\n";
    echo "This test is specifically designed to ensure at least one user earns all four bonus types,\n";
    echo "including the elusive mentor bonus, while still respecting package-based limitations.\n";
    echo "\nKey Strategy:\n";
    echo "✅ 5-level hierarchy for maximum mentor bonus potential\n";
    echo "✅ Strategic package distribution for optimal earning capacity\n";
    echo "✅ Ultra-low mentor requirements to guarantee qualification\n";
    echo "✅ Systematic volume building to ensure all prerequisites are met\n";
    echo "✅ Package-based limitations still enforced for system integrity\n";
    
} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . " in " . $e->getFile() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>