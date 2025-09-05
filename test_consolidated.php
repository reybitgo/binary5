<?php
// test_consolidated.php - Consolidated testing for binary, leadership, and mentor bonuses
// This script simulates realistic package purchases that trigger calc_binary(),
// which internally calls calc_leadership() and calc_leadership_reverse() when binary bonuses are earned.

require_once 'config.php';
require_once 'binary_calc.php';
require_once 'leadership_calc.php';
require_once 'leadership_reverse_calc.php';
require_once 'functions.php';

echo "<pre>"; // For better output formatting in browser or CLI

// Initialize test data (adapted from test_leadership_system.php)
function setupTestData(PDO $pdo) {
    echo "🧪 Setting up test data...\n";
    
    // Clear existing test data
    $pdo->exec("DELETE FROM wallet_tx WHERE user_id > 0");
    $pdo->exec("DELETE FROM wallets WHERE user_id > 0");
    $pdo->exec("DELETE FROM users WHERE id > 0");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
    
    // Create hierarchical test users
    $users = [
        ['admin', 'admin123', null, null, null, 'admin'],  // ID: 1, Root
        ['leader', 'pass123', 1, 1, 'left', 'user'],       // ID: 2, Level 1 under admin
        ['child1', 'pass123', 2, 2, 'left', 'user'],       // ID: 3, Level 2 under leader (left)
        ['child2', 'pass123', 2, 2, 'right', 'user'],      // ID: 4, Level 2 under leader (right)
        ['grand1', 'pass123', 3, 3, 'left', 'user'],       // ID: 5, Level 3 under child1 (left)
        ['grand2', 'pass123', 3, 3, 'right', 'user'],      // ID: 6, Level 3 under child1 (right)
        ['great1', 'pass123', 5, 5, 'left', 'user'],       // ID: 7, Level 4 under grand1 (left)
        ['great2', 'pass123', 5, 5, 'right', 'user']       // ID: 8, Level 4 under grand1 (right)
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
    
    // Create wallets for all users
    for ($i = 1; $i <= 8; $i++) {
        $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 0.00)")->execute([$i]);
    }
    
    // Insert packages if not present (assuming IDs 1-3)
    $pdo->exec("DELETE FROM packages");
    $pdo->exec("ALTER TABLE packages AUTO_INCREMENT = 1");
    $packages = [
        ['Starter', 25.00, 25, 10, 0.2000, 0.1000],  // ID:1
        ['Pro', 50.00, 50, 20, 0.2000, 0.1000],      // ID:2 (higher daily_max for testing)
        ['Elite', 100.00, 100, 50, 0.2000, 0.1000]   // ID:3 (higher daily_max for testing)
    ];
    foreach ($packages as $pkg) {
        $pdo->prepare(
            "INSERT INTO packages (name, price, pv, daily_max, pair_rate, referral_rate) 
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute($pkg);
    }
    
    // Insert leadership schedules (from schema.sql)
    $pdo->exec("DELETE FROM package_leadership_schedule");
    $leadership_schedules = [
        // Starter (ID:1)
        [1, 1, 50, 250, 0.050], [1, 2, 100, 500, 0.040], [1, 3, 200, 1000, 0.030], [1, 4, 300, 2000, 0.020], [1, 5, 500, 5000, 0.010],
        // Pro (ID:2)
        [2, 1, 100, 500, 0.060], [2, 2, 200, 1000, 0.050], [2, 3, 300, 2500, 0.030], [2, 4, 500, 5000, 0.020], [2, 5, 1000, 10000, 0.010],
        // Elite (ID:3)
        [3, 1, 200, 1000, 0.070], [3, 2, 400, 2000, 0.060], [3, 3, 600, 5000, 0.050], [3, 4, 1000, 10000, 0.040], [3, 5, 2000, 20000, 0.030]
    ];
    foreach ($leadership_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    // Insert mentor schedules (from schema.sql)
    $pdo->exec("DELETE FROM package_mentor_schedule");
    $mentor_schedules = [
        // Starter (ID:1)
        [1, 1, 25, 150, 0.020], [1, 2, 50, 300, 0.018], [1, 3, 100, 600, 0.015], [1, 4, 200, 1200, 0.012], [1, 5, 300, 2000, 0.010],
        // Pro (ID:2)
        [2, 1, 100, 500, 0.030], [2, 2, 200, 1000, 0.025], [2, 3, 300, 2500, 0.020], [2, 4, 500, 5000, 0.015], [2, 5, 1000, 10000, 0.010],
        // Elite (ID:3)
        [3, 1, 150, 800, 0.040], [3, 2, 300, 1500, 0.035], [3, 3, 500, 3000, 0.030], [3, 4, 800, 6000, 0.025], [3, 5, 1500, 12000, 0.020]
    ];
    foreach ($mentor_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    echo "✅ Test data ready (8 users, packages, and schedules)\n";
    
    // Display hierarchy
    echo "\n👥 User Hierarchy:\n";
    $stmt = $pdo->query("SELECT id, username, sponsor_id, upline_id, position FROM users ORDER BY id");
    foreach ($stmt->fetchAll() as $user) {
        $sponsor = $user['sponsor_id'] ? "Sponsor:{$user['sponsor_id']}" : "Root";
        $upline = $user['upline_id'] ? "Upline:{$user['upline_id']}" : "Root";
        echo "├── {$user['username']} (ID:{$user['id']}) - {$sponsor}, {$upline}, Pos:{$user['position']}\n";
    }
}

// Function to simulate package purchase and trigger calc_binary
function simulatePackagePurchase(PDO $pdo, int $userId, int $packageId) {
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
    
    // Record transaction
    $pdo->prepare(
        "INSERT INTO wallet_tx (user_id, package_id, type, amount) 
         VALUES (?, ?, 'package', ?)"
    )->execute([$userId, $packageId, $amount]);
    
    // Update wallet (deduct)
    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")->execute([$amount, $userId]);
    
    // Trigger binary calculation
    calc_binary($userId, $pv, $pdo);
    
    echo "💸 User $userId bought package $packageId (PV: $pv, Price: $price)\n";
}

// Test realistic leadership bonus (ancestors earn from downline's binary)
function testRealisticLeadershipBonus(PDO $pdo) {
    echo "\n🎯 Testing Realistic Leadership Bonus (via package purchases)\n";
    echo "============================================================\n";
    
    // Reset counts and bonuses
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('pair_bonus', 'leadership_bonus', 'leadership_reverse_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("UPDATE wallets SET balance = 1000.00"); // Give initial balance for purchases
    
    // Assign packages to upper levels (for qualifications)
    simulatePackagePurchase($pdo, 1, 3); // admin - Elite
    simulatePackagePurchase($pdo, 2, 2); // leader - Pro
    simulatePackagePurchase($pdo, 3, 1); // child1 - Starter
    
    // To make child1 earn binary bonus realistically:
    // Have grand1 (left) and grand2 (right) buy packages
    // This adds PV to child1's legs, triggering pairs in calc_binary
    echo "\nSimulating downline purchases to trigger binary for child1:\n";
    simulatePackagePurchase($pdo, 5, 1); // grand1 buys Starter (adds 25 PV to left of child1)
    simulatePackagePurchase($pdo, 6, 1); // grand2 buys Starter (adds 25 PV to right of child1, triggers pair)
    
    // Display results
    displayTestResults($pdo);
}

// Test realistic mentor bonus (descendants earn from ancestor's binary)
function testRealisticMentorBonus(PDO $pdo) {
    echo "\n🎯 Testing Realistic Mentor Bonus (via package purchases)\n";
    echo "============================================================\n";
    
    // Reset
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('pair_bonus', 'leadership_bonus', 'leadership_reverse_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("UPDATE wallets SET balance = 1000.00");
    
    // Assign packages
    simulatePackagePurchase($pdo, 1, 3); // admin - Elite
    simulatePackagePurchase($pdo, 2, 2); // leader - Pro
    simulatePackagePurchase($pdo, 3, 1); // child1 - Starter
    simulatePackagePurchase($pdo, 5, 2); // grand1 - Pro (for mentor qualification)
    simulatePackagePurchase($pdo, 6, 1); // grand2 - Starter
    
    // Trigger binary for child1 (who will earn pair_bonus, triggering mentor to descendants)
    echo "\nSimulating downline purchases to trigger binary for child1:\n";
    simulatePackagePurchase($pdo, 7, 1); // great1 buys (under grand1, left - adds to child1 via propagation)
    simulatePackagePurchase($pdo, 8, 1); // great2 buys (under grand1, right - but for child1, it's left leg since grand1 is left)
    // Note: To balance child1's legs, already have grand2 on right.
    
    // Display results
    displayTestResults($pdo);
}

// Test integrated system
function testRealisticIntegratedSystem(PDO $pdo) {
    echo "\n🎯 Testing Realistic Integrated System\n";
    echo "============================================================\n";
    
    // Reset
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('pair_bonus', 'leadership_bonus', 'leadership_reverse_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("UPDATE wallets SET balance = 1000.00");
    
    // Assign packages to all for full qualifications
    simulatePackagePurchase($pdo, 1, 3); // admin - Elite
    simulatePackagePurchase($pdo, 2, 2); // leader - Pro
    simulatePackagePurchase($pdo, 3, 1); // child1 - Starter
    simulatePackagePurchase($pdo, 4, 1); // child2 - Starter
    simulatePackagePurchase($pdo, 5, 2); // grand1 - Pro
    simulatePackagePurchase($pdo, 6, 1); // grand2 - Starter
    simulatePackagePurchase($pdo, 7, 1); // great1 - Starter (but purchase after to trigger)
    simulatePackagePurchase($pdo, 8, 1); // great2 - Starter (but purchase after)
    
    // Simulate multiple purchases to trigger binaries at different levels
    echo "\nSimulating deep downline purchases to trigger multi-level bonuses:\n";
    simulatePackagePurchase($pdo, 7, 1); // great1 buys again? Or assume initial is purchase.
    // To trigger, buy for deepest, but since already bought, perhaps add more downlines or multiple buys.
    // For simplicity, assume users can buy multiple packages for more PVT/GVT.
    simulatePackagePurchase($pdo, 7, 2); // great1 buys Pro upgrade
    simulatePackagePurchase($pdo, 8, 2); // great2 buys Pro upgrade
    
    // Display results
    displayTestResults($pdo);
}

// Helper to display results (balances, transactions, flushes)
function displayTestResults(PDO $pdo) {
    echo "\n📊 User Balances and Bonus Counts:\n";
    $stmt = $pdo->query(
        "SELECT u.id, u.username, w.balance,
                (SELECT COUNT(*) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'pair_bonus') as pair_count,
                (SELECT COUNT(*) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_bonus') as leadership_count,
                (SELECT COUNT(*) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_reverse_bonus') as mentor_count
         FROM users u JOIN wallets w ON w.user_id = u.id
         ORDER BY u.id"
    );
    foreach ($stmt->fetchAll() as $result) {
        echo "├── {$result['username']} (ID:{$result['id']}) - Balance: $" . number_format($result['balance'], 2) .
             " [Pair:{$result['pair_count']}, Leadership:{$result['leadership_count']}, Mentor:{$result['mentor_count']}]\n";
    }
    
    echo "\n💎 Bonus Transactions:\n";
    $stmt = $pdo->query(
        "SELECT u.username, wt.type, wt.amount, wt.created_at
         FROM wallet_tx wt JOIN users u ON u.id = wt.user_id
         WHERE wt.type IN ('pair_bonus', 'leadership_bonus', 'leadership_reverse_bonus')
         ORDER BY wt.created_at DESC"
    );
    foreach ($stmt->fetchAll() as $tx) {
        echo "├── {$tx['username']}: {$tx['type']} $" . number_format($tx['amount'], 2) . " at {$tx['created_at']}\n";
    }
    
    echo "\n🚽 Flushes:\n";
    $stmt = $pdo->query(
        "SELECT u.username, f.amount, f.reason, f.flushed_on
         FROM flushes f JOIN users u ON u.id = f.user_id
         ORDER BY f.created_at DESC"
    );
    foreach ($stmt->fetchAll() as $flush) {
        echo "├── {$flush['username']}: Flushed {$flush['amount']} ({$flush['reason']}) on {$flush['flushed_on']}\n";
    }
    
    // PVT and GVT for key users
    echo "\n📈 PVT/GVT for Key Users:\n";
    foreach ([1,2,3,5,6] as $uid) {
        $pvt = getPersonalVolume($uid, $pdo);
        $gvt = getGroupVolume($uid, $pdo);
        $username = getUsernameById($uid, $pdo);
        echo "├── $username (ID:$uid): PVT=$pvt, GVT=$gvt\n";
    }
}

// Run all tests
try {
    setupTestData($pdo);
    testRealisticLeadershipBonus($pdo);
    testRealisticMentorBonus($pdo);
    testRealisticIntegratedSystem($pdo);
    
    echo "\n💡 Testing Complete. Check outputs for verification.\n";
    echo "Note: Bonuses are smaller due to PV/daily_max; scale PV/daily_max for larger numbers if needed.\n";
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . " in " . $e->getFile() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>