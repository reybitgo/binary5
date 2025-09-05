<?php
// test_consolidated_improved.php - Complete testing for ALL bonuses: referral, binary, leadership, and mentor
// This script simulates realistic package purchases that trigger all bonus calculations

require_once 'config.php';
require_once 'binary_calc.php';
require_once 'leadership_calc.php';
require_once 'leadership_reverse_calc.php';
require_once 'referral_calc.php';
require_once 'functions.php';

echo "<pre>"; // For better output formatting

// Initialize comprehensive test data
function setupComprehensiveTestData(PDO $pdo) {
    echo "🧪 Setting up comprehensive test data for all bonuses...\n";
    
    // Clear existing test data
    $pdo->exec("DELETE FROM wallet_tx WHERE user_id > 0");
    $pdo->exec("DELETE FROM wallets WHERE user_id > 0");
    $pdo->exec("DELETE FROM users WHERE id > 0");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
    
    // Create hierarchical test users with proper sponsor relationships
    $users = [
        ['admin', 'admin123', null, null, null, 'admin'],           // ID: 1, Root
        ['sponsor1', 'pass123', 1, 1, 'left', 'user'],            // ID: 2, Level 1 (left leg)
        ['buyer1', 'pass123', 2, 2, 'left', 'user'],              // ID: 3, Level 2 (sponsored by sponsor1)
        ['buyer2', 'pass123', 2, 2, 'right', 'user'],             // ID: 4, Level 2 (sponsored by sponsor1)
        ['deep1', 'pass123', 3, 3, 'left', 'user'],               // ID: 5, Level 3 (sponsored by buyer1)
        ['deep2', 'pass123', 3, 3, 'right', 'user'],              // ID: 6, Level 3 (sponsored by buyer1)
        ['deep3', 'pass123', 4, 4, 'left', 'user'],               // ID: 7, Level 3 (sponsored by buyer2)
        ['deep4', 'pass123', 4, 4, 'right', 'user'],              // ID: 8, Level 3 (sponsored by buyer2)
        ['sponsor2', 'pass123', 1, 1, 'right', 'user'],           // ID: 9, Level 1 (right leg)
        ['buyer3', 'pass123', 9, 9, 'left', 'user'],              // ID: 10, Level 2 (sponsored by sponsor2)
        ['buyer4', 'pass123', 9, 9, 'right', 'user'],             // ID: 11, Level 2 (sponsored by sponsor2)
        ['deep5', 'pass123', 10, 10, 'left', 'user'],             // ID: 12, Level 3 (sponsored by buyer3)
        ['deep6', 'pass123', 10, 10, 'right', 'user']             // ID: 13, Level 3 (sponsored by buyer3)
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
    
    // Create wallets for all users with sufficient balance
    for ($i = 1; $i <= 13; $i++) {
        $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 5000.00)")->execute([$i]);
    }
    
    // Update packages with more favorable settings for testing
    $pdo->exec("DELETE FROM packages");
    $pdo->exec("ALTER TABLE packages AUTO_INCREMENT = 1");
    $packages = [
        ['Starter', 25.00, 25, 50, 0.2000, 0.1000],   // ID:1, higher daily_max for more pairing
        ['Pro', 50.00, 50, 100, 0.2000, 0.1000],      // ID:2
        ['Elite', 100.00, 100, 200, 0.2000, 0.1000]   // ID:3, high daily_max for testing
    ];
    foreach ($packages as $pkg) {
        $pdo->prepare(
            "INSERT INTO packages (name, price, pv, daily_max, pair_rate, referral_rate) 
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute($pkg);
    }
    
    // Update leadership schedules with lower requirements for easier testing
    $pdo->exec("DELETE FROM package_leadership_schedule");
    $leadership_schedules = [
        // Starter (ID:1) - Very low requirements for testing
        [1, 1, 25, 50, 0.050], [1, 2, 50, 100, 0.040], [1, 3, 75, 200, 0.030], [1, 4, 100, 400, 0.020], [1, 5, 150, 800, 0.010],
        // Pro (ID:2) - Low requirements
        [2, 1, 50, 100, 0.060], [2, 2, 100, 200, 0.050], [2, 3, 150, 400, 0.030], [2, 4, 200, 800, 0.020], [2, 5, 300, 1600, 0.010],
        // Elite (ID:3) - Medium requirements
        [3, 1, 100, 200, 0.070], [3, 2, 200, 400, 0.060], [3, 3, 300, 800, 0.050], [3, 4, 400, 1600, 0.040], [3, 5, 600, 3200, 0.030]
    ];
    foreach ($leadership_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    // Update mentor schedules with lower requirements
    $pdo->exec("DELETE FROM package_mentor_schedule");
    $mentor_schedules = [
        // Starter (ID:1) - Very low requirements
        [1, 1, 25, 50, 0.020], [1, 2, 50, 100, 0.018], [1, 3, 75, 200, 0.015], [1, 4, 100, 400, 0.012], [1, 5, 150, 800, 0.010],
        // Pro (ID:2) - Low requirements  
        [2, 1, 50, 100, 0.030], [2, 2, 100, 200, 0.025], [2, 3, 150, 400, 0.020], [2, 4, 200, 800, 0.015], [2, 5, 300, 1600, 0.010],
        // Elite (ID:3) - Medium requirements
        [3, 1, 100, 200, 0.040], [3, 2, 200, 400, 0.035], [3, 3, 300, 800, 0.030], [3, 4, 400, 1600, 0.025], [3, 5, 600, 3200, 0.020]
    ];
    foreach ($mentor_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    echo "✅ Comprehensive test data ready (13 users, optimized packages, and schedules)\n";
    
    // Display hierarchy
    echo "\n👥 User Hierarchy:\n";
    $stmt = $pdo->query("SELECT id, username, sponsor_id, upline_id, position FROM users ORDER BY id");
    foreach ($stmt->fetchAll() as $user) {
        $sponsor = $user['sponsor_id'] ? "Sponsor:{$user['sponsor_id']}" : "Root";
        $upline = $user['upline_id'] ? "Upline:{$user['upline_id']}" : "Root";
        echo "├── {$user['username']} (ID:{$user['id']}) - {$sponsor}, {$upline}, Pos:{$user['position']}\n";
    }
}

// Enhanced package purchase function that triggers ALL bonus calculations
function simulateComprehensivePackagePurchase(PDO $pdo, int $userId, int $packageId) {
    $stmt = $pdo->prepare("SELECT price, pv FROM packages WHERE id = ?");
    $stmt->execute([$packageId]);
    $pkg = $stmt->fetch();
    if (!$pkg) {
        echo "❌ Package ID $packageId not found\n";
        return;
    }
    
    $price = $pkg['price'];
    $pv = $pkg['pv'];
    $amount = -$price; // Negative for purchase
    
    // Record transaction with package_id
    $pdo->prepare(
        "INSERT INTO wallet_tx (user_id, package_id, type, amount) 
         VALUES (?, ?, 'package', ?)"
    )->execute([$userId, $packageId, $amount]);
    
    // Update wallet (deduct)
    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")->execute([$amount, $userId]);
    
    // 1. Calculate referral bonus first (direct sponsor gets commission)
    calc_referral($userId, $price, $pdo);
    
    // 2. Calculate binary bonus (which internally calls leadership and mentor bonuses)
    calc_binary($userId, $pv, $pdo);
    
    $username = getUsernameById($userId, $pdo);
    echo "💸 {$username} (ID:{$userId}) bought package {$packageId} (PV: {$pv}, Price: \${$price})\n";
}

// Comprehensive test scenario to trigger ALL bonus types
function testAllBonusTypesScenario(PDO $pdo) {
    echo "\n🎯 Testing Comprehensive Scenario: ALL Bonus Types\n";
    echo "================================================================\n";
    
    // Reset everything
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('package', 'pair_bonus', 'referral_bonus', 'leadership_bonus', 'leadership_reverse_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("UPDATE wallets SET balance = 5000.00"); // Reset balances
    
    echo "\n📈 Phase 1: Building qualification volumes...\n";
    
    // Admin builds up volume
    echo "Admin building qualification:\n";
    for ($i = 0; $i < 3; $i++) {
        simulateComprehensivePackagePurchase($pdo, 1, 3); // Elite packages
    }
    
    // Sponsors build up volume 
    echo "\nSponsors building qualification:\n";
    for ($i = 0; $i < 3; $i++) {
        simulateComprehensivePackagePurchase($pdo, 2, 3); // sponsor1 Elite
        simulateComprehensivePackagePurchase($pdo, 9, 3); // sponsor2 Elite
    }
    
    echo "\n💰 Phase 2: Triggering referral bonuses...\n";
    
    // Buyers purchase packages (their sponsors get referral bonuses)
    simulateComprehensivePackagePurchase($pdo, 3, 2); // buyer1 buys Pro (sponsor1 gets referral)
    simulateComprehensivePackagePurchase($pdo, 4, 2); // buyer2 buys Pro (sponsor1 gets referral)
    simulateComprehensivePackagePurchase($pdo, 10, 2); // buyer3 buys Pro (sponsor2 gets referral)
    simulateComprehensivePackagePurchase($pdo, 11, 2); // buyer4 buys Pro (sponsor2 gets referral)
    
    echo "\n⚖️ Phase 3: Building binary tree balance...\n";
    
    // Left leg purchases (under sponsor1)
    simulateComprehensivePackagePurchase($pdo, 5, 1); // deep1 Starter
    simulateComprehensivePackagePurchase($pdo, 6, 1); // deep2 Starter 
    simulateComprehensivePackagePurchase($pdo, 7, 1); // deep3 Starter
    simulateComprehensivePackagePurchase($pdo, 8, 1); // deep4 Starter
    
    echo "\n🚀 Phase 4: Triggering binary bonuses (and leadership/mentor)...\n";
    
    // Right leg purchases to balance and trigger binary bonuses
    simulateComprehensivePackagePurchase($pdo, 12, 2); // deep5 Pro (triggers buyer3 binary)
    simulateComprehensivePackagePurchase($pdo, 13, 2); // deep6 Pro (triggers buyer3 binary)
    
    // Additional purchases to trigger more binary bonuses up the tree
    simulateComprehensivePackagePurchase($pdo, 3, 1); // buyer1 additional Starter
    simulateComprehensivePackagePurchase($pdo, 4, 1); // buyer2 additional Starter
    
    echo "\n🎆 Phase 5: Final purchases to maximize all bonuses...\n";
    
    // More strategic purchases to ensure all bonus types are triggered
    simulateComprehensivePackagePurchase($pdo, 5, 2); // deep1 upgrades to Pro
    simulateComprehensivePackagePurchase($pdo, 12, 1); // deep5 additional Starter
    
    // Display comprehensive results
    displayComprehensiveResults($pdo);
}

// Enhanced results display function
function displayComprehensiveResults(PDO $pdo) {
    echo "\n📊 COMPREHENSIVE RESULTS - All Bonus Types\n";
    echo "=" . str_repeat("=", 60) . "\n";
    
    // User balances and bonus counts
    echo "\n💰 User Balances and Bonus Summary:\n";
    $stmt = $pdo->query(
        "SELECT u.id, u.username, w.balance,
                (SELECT COUNT(*) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'referral_bonus') as referral_count,
                (SELECT COUNT(*) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'pair_bonus') as pair_count,
                (SELECT COUNT(*) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_bonus') as leadership_count,
                (SELECT COUNT(*) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_reverse_bonus') as mentor_count,
                (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'referral_bonus') as referral_total,
                (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'pair_bonus') as pair_total,
                (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_bonus') as leadership_total,
                (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_reverse_bonus') as mentor_total
         FROM users u JOIN wallets w ON w.user_id = u.id
         ORDER BY u.id"
    );
    
    $usersWithAllBonuses = [];
    foreach ($stmt->fetchAll() as $result) {
        $hasReferral = $result['referral_count'] > 0;
        $hasPair = $result['pair_count'] > 0;
        $hasLeadership = $result['leadership_count'] > 0;
        $hasMentor = $result['mentor_count'] > 0;
        
        $bonusTypes = [];
        if ($hasReferral) $bonusTypes[] = "Referral";
        if ($hasPair) $bonusTypes[] = "Binary";
        if ($hasLeadership) $bonusTypes[] = "Leadership";
        if ($hasMentor) $bonusTypes[] = "Mentor";
        
        $bonusString = empty($bonusTypes) ? "None" : implode(", ", $bonusTypes);
        
        echo "├── {$result['username']} (ID:{$result['id']}) - Balance: $" . number_format($result['balance'], 2) . "\n";
        echo "│   └── Bonuses: {$bonusString}\n";
        echo "│   └── Totals: Ref:\$" . number_format($result['referral_total'], 2) . 
             " | Bin:\$" . number_format($result['pair_total'], 2) . 
             " | Lead:\$" . number_format($result['leadership_total'], 2) . 
             " | Mentor:\$" . number_format($result['mentor_total'], 2) . "\n";
        
        // Track users with all bonus types
        if ($hasReferral && $hasPair && $hasLeadership && $hasMentor) {
            $usersWithAllBonuses[] = $result;
        }
    }
    
    // Detailed bonus transactions
    echo "\n🎁 Detailed Bonus Transactions (Latest 20):\n";
    $stmt = $pdo->query(
        "SELECT u.username, wt.type, wt.amount, wt.created_at
         FROM wallet_tx wt JOIN users u ON u.id = wt.user_id
         WHERE wt.type IN ('referral_bonus', 'pair_bonus', 'leadership_bonus', 'leadership_reverse_bonus')
         ORDER BY wt.created_at DESC
         LIMIT 20"
    );
    foreach ($stmt->fetchAll() as $tx) {
        $typeLabel = str_replace('_', ' ', ucwords($tx['type'], '_'));
        echo "├── {$tx['username']}: {$typeLabel} $" . number_format($tx['amount'], 2) . " at {$tx['created_at']}\n";
    }
    
    // Users with ALL four bonus types
    echo "\n⭐ USERS WHO EARNED ALL FOUR BONUS TYPES:\n";
    if (empty($usersWithAllBonuses)) {
        echo "└── No users earned all four bonus types in this scenario.\n";
        echo "    (This may require additional purchases or adjusting requirements)\n";
    } else {
        foreach ($usersWithAllBonuses as $user) {
            echo "├── 🏆 {$user['username']} (ID:{$user['id']}) - COMPLETE BONUS SET!\n";
            echo "│   ├── Final Balance: $" . number_format($user['balance'], 2) . "\n";
            echo "│   ├── Referral Bonus: $" . number_format($user['referral_total'], 2) . " ({$user['referral_count']} payments)\n";
            echo "│   ├── Binary Bonus: $" . number_format($user['pair_total'], 2) . " ({$user['pair_count']} payments)\n";
            echo "│   ├── Leadership Bonus: $" . number_format($user['leadership_total'], 2) . " ({$user['leadership_count']} payments)\n";
            echo "│   └── Mentor Bonus: $" . number_format($user['mentor_total'], 2) . " ({$user['mentor_count']} payments)\n";
        }
    }
    
    // Volume summary for key users
    echo "\n📈 Volume Summary (PVT/GVT) for Key Users:\n";
    $keyUsers = [1, 2, 3, 4, 9, 10, 11];
    foreach ($keyUsers as $uid) {
        $pvt = getPersonalVolume($uid, $pdo);
        $gvt = getGroupVolume($uid, $pdo);
        $username = getUsernameById($uid, $pdo);
        echo "├── {$username} (ID:{$uid}): PVT=\${$pvt}, GVT=\${$gvt}\n";
    }
    
    // Flush summary
    echo "\n🚽 Flush Summary:\n";
    $stmt = $pdo->query("SELECT COUNT(*) as flush_count, SUM(amount) as flush_total FROM flushes");
    $flushData = $stmt->fetch();
    if ($flushData['flush_count'] > 0) {
        echo "├── Total Flushes: {$flushData['flush_count']} worth $" . number_format($flushData['flush_total'], 2) . "\n";
        
        $stmt = $pdo->query(
            "SELECT u.username, f.amount, f.reason, f.flushed_on
             FROM flushes f JOIN users u ON u.id = f.user_id
             ORDER BY f.created_at DESC LIMIT 10"
        );
        foreach ($stmt->fetchAll() as $flush) {
            echo "├── {$flush['username']}: Flushed $" . number_format($flush['amount'], 2) . 
                 " ({$flush['reason']}) on {$flush['flushed_on']}\n";
        }
    } else {
        echo "└── No flushes recorded.\n";
    }
}

// Run the comprehensive scenario
try {
    setupComprehensiveTestData($pdo);
    testAllBonusTypesScenario($pdo);
    
    echo "\n🎉 COMPREHENSIVE TESTING COMPLETE!\n";
    echo "Check the results above to verify all bonus types are working correctly.\n";
    echo "Users marked with 🏆 earned all four bonus types successfully.\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . " in " . $e->getFile() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>