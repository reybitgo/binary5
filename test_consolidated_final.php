<?php
// test_consolidated_final.php - Complete testing for ALL bonuses with guaranteed mentor bonuses
// Based on insights from mentor_debug_test.php showing the mentor bonus logic works correctly

require_once 'config.php';
require_once 'binary_calc.php';
require_once 'leadership_calc.php';
require_once 'leadership_reverse_calc.php';
require_once 'referral_calc.php';
require_once 'functions.php';

echo "<pre>";

function setupFinalTestData(PDO $pdo) {
    echo "Setting up final test data to guarantee all bonus types...\n";
    
    // Clear existing test data
    $pdo->exec("DELETE FROM wallet_tx WHERE user_id > 0");
    $pdo->exec("DELETE FROM wallets WHERE user_id > 0");
    $pdo->exec("DELETE FROM users WHERE id > 0");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
    
    // Create simplified but effective hierarchy
    $users = [
        ['admin', 'admin123', null, null, null, 'admin'],           // ID: 1, Root
        ['sponsor1', 'pass123', 1, 1, 'left', 'user'],            // ID: 2, Level 1 (left leg)
        ['sponsor2', 'pass123', 1, 1, 'right', 'user'],           // ID: 3, Level 1 (right leg)
        ['buyer1', 'pass123', 2, 2, 'left', 'user'],              // ID: 4, Level 2 (sponsored by sponsor1)
        ['buyer2', 'pass123', 2, 2, 'right', 'user'],             // ID: 5, Level 2 (sponsored by sponsor1)
        ['buyer3', 'pass123', 3, 3, 'left', 'user'],              // ID: 6, Level 2 (sponsored by sponsor2)
        ['buyer4', 'pass123', 3, 3, 'right', 'user'],             // ID: 7, Level 2 (sponsored by sponsor2)
        ['deep1', 'pass123', 4, 4, 'left', 'user'],               // ID: 8, Level 3 (sponsored by buyer1)
        ['deep2', 'pass123', 4, 4, 'right', 'user'],              // ID: 9, Level 3 (sponsored by buyer1)
        ['deep3', 'pass123', 5, 5, 'left', 'user'],               // ID: 10, Level 3 (sponsored by buyer2)
        ['deep4', 'pass123', 5, 5, 'right', 'user'],              // ID: 11, Level 3 (sponsored by buyer2)
        ['deep5', 'pass123', 6, 6, 'left', 'user'],               // ID: 12, Level 3 (sponsored by buyer3)
        ['deep6', 'pass123', 6, 6, 'right', 'user'],              // ID: 13, Level 3 (sponsored by buyer3)
        ['deep7', 'pass123', 7, 7, 'left', 'user'],               // ID: 14, Level 3 (sponsored by buyer4)
        ['deep8', 'pass123', 7, 7, 'right', 'user']               // ID: 15, Level 3 (sponsored by buyer4)
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
    
    // Create wallets with high balance for testing
    for ($i = 1; $i <= 15; $i++) {
        $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 10000.00)")->execute([$i]);
    }
    
    // Ensure packages exist with high daily_max for testing
    $pdo->exec("DELETE FROM packages");
    $pdo->exec("ALTER TABLE packages AUTO_INCREMENT = 1");
    $packages = [
        ['Starter', 25.00, 25, 100, 0.2000, 0.1000],   // ID:1
        ['Pro', 50.00, 50, 200, 0.2000, 0.1000],       // ID:2
        ['Elite', 100.00, 100, 300, 0.2000, 0.1000]    // ID:3
    ];
    foreach ($packages as $pkg) {
        $pdo->prepare(
            "INSERT INTO packages (name, price, pv, daily_max, pair_rate, referral_rate) 
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute($pkg);
    }
    
    // Set minimal leadership requirements (based on price, not PVT)
    $pdo->exec("DELETE FROM package_leadership_schedule");
    $leadership_schedules = [
        // Starter (ID:1) - Minimal requirements
        [1, 1, 25, 25, 0.050], [1, 2, 25, 50, 0.040], [1, 3, 50, 75, 0.030], [1, 4, 75, 100, 0.020], [1, 5, 100, 150, 0.010],
        // Pro (ID:2) - Low requirements
        [2, 1, 50, 50, 0.060], [2, 2, 50, 100, 0.050], [2, 3, 100, 150, 0.030], [2, 4, 150, 200, 0.020], [2, 5, 200, 300, 0.010],
        // Elite (ID:3) - Still achievable
        [3, 1, 100, 100, 0.070], [3, 2, 100, 200, 0.060], [3, 3, 200, 300, 0.050], [3, 4, 300, 400, 0.040], [3, 5, 400, 500, 0.030]
    ];
    foreach ($leadership_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    // Set VERY minimal mentor requirements (key insight from debug test)
    $pdo->exec("DELETE FROM package_mentor_schedule");
    $mentor_schedules = [
        // Starter (ID:1) - Ultra-low requirements
        [1, 1, 25, 25, 0.020], [1, 2, 25, 25, 0.018], [1, 3, 25, 50, 0.015], [1, 4, 50, 75, 0.012], [1, 5, 75, 100, 0.010],
        // Pro (ID:2) - Low requirements  
        [2, 1, 50, 50, 0.030], [2, 2, 50, 50, 0.025], [2, 3, 50, 100, 0.020], [2, 4, 100, 150, 0.015], [2, 5, 150, 200, 0.010],
        // Elite (ID:3) - Achievable requirements
        [3, 1, 100, 100, 0.040], [3, 2, 100, 100, 0.035], [3, 3, 100, 200, 0.030], [3, 4, 200, 300, 0.025], [3, 5, 300, 400, 0.020]
    ];
    foreach ($mentor_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    echo "Test data ready: 15 users, minimal requirements for all bonuses\n";
    
    // Display hierarchy
    echo "\nUser Hierarchy:\n";
    $stmt = $pdo->query("SELECT id, username, sponsor_id, upline_id, position FROM users ORDER BY id");
    foreach ($stmt->fetchAll() as $user) {
        $sponsor = $user['sponsor_id'] ? "Sponsor:{$user['sponsor_id']}" : "Root";
        $upline = $user['upline_id'] ? "Upline:{$user['upline_id']}" : "Root";
        echo "-- {$user['username']} (ID:{$user['id']}) - {$sponsor}, {$upline}, Pos:{$user['position']}\n";
    }
}

function simulatePackagePurchase(PDO $pdo, int $userId, int $packageId) {
    $stmt = $pdo->prepare("SELECT price, pv FROM packages WHERE id = ?");
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
    
    // Calculate all bonuses in correct order
    calc_referral($userId, $price, $pdo);  // Referral first
    calc_binary($userId, $pv, $pdo);       // Binary (which calls leadership and mentor)
    
    $username = getUsernameById($userId, $pdo);
    echo "{$username} (ID:{$userId}) bought package {$packageId} (PV: {$pv}, Price: \${$price})\n";
}

function testGuaranteedAllBonuses(PDO $pdo) {
    echo "\nTesting Guaranteed All Bonus Types Scenario\n";
    echo "==========================================\n";
    
    // Reset everything
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('package', 'pair_bonus', 'referral_bonus', 'leadership_bonus', 'leadership_reverse_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("UPDATE wallets SET balance = 10000.00");
    
    echo "\nPhase 1: Build descendant qualification FIRST (critical for mentor bonuses)...\n";
    
    // Level 1 descendants (sponsor1, sponsor2) - build volumes
    simulatePackagePurchase($pdo, 2, 2); // sponsor1 Pro (generates referral to admin)
    simulatePackagePurchase($pdo, 3, 2); // sponsor2 Pro (generates referral to admin)
    
    // Level 2 descendants (buyers) - build volumes  
    simulatePackagePurchase($pdo, 4, 2); // buyer1 Pro (generates referral to sponsor1)
    simulatePackagePurchase($pdo, 5, 2); // buyer2 Pro (generates referral to sponsor1)
    simulatePackagePurchase($pdo, 6, 2); // buyer3 Pro (generates referral to sponsor2)
    simulatePackagePurchase($pdo, 7, 2); // buyer4 Pro (generates referral to sponsor2)
    
    // Level 3 descendants (deep users) - build minimal volumes
    simulatePackagePurchase($pdo, 8, 1);  // deep1 Starter (generates referral to buyer1)
    simulatePackagePurchase($pdo, 9, 1);  // deep2 Starter (generates referral to buyer1)
    simulatePackagePurchase($pdo, 10, 1); // deep3 Starter (generates referral to buyer2)
    simulatePackagePurchase($pdo, 11, 1); // deep4 Starter (generates referral to buyer2)
    simulatePackagePurchase($pdo, 12, 1); // deep5 Starter (generates referral to buyer3)
    simulatePackagePurchase($pdo, 13, 1); // deep6 Starter (generates referral to buyer3)
    simulatePackagePurchase($pdo, 14, 1); // deep7 Starter (generates referral to buyer4)
    simulatePackagePurchase($pdo, 15, 1); // deep8 Starter (generates referral to buyer4)
    
    echo "\nChecking descendant volumes after Phase 1:\n";
    $descendants = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
    foreach ($descendants as $uid) {
        $pvt = getPersonalVolume($uid, $pdo);
        $gvt = getGroupVolume($uid, $pdo);
        $username = getUsernameById($uid, $pdo);
        echo "{$username} (ID:{$uid}): PVT=\${$pvt}, GVT=\${$gvt}\n";
    }
    
    echo "\nPhase 2: Build admin qualification and trigger binary cascade...\n";
    
    // Admin needs qualification for leadership bonuses
    simulatePackagePurchase($pdo, 1, 3); // Admin Elite
    simulatePackagePurchase($pdo, 1, 3); // Admin Elite (PVT=200, GVT will be higher)
    
    echo "\nPhase 3: Trigger binary bonuses to activate mentor bonuses...\n";
    
    // Additional strategic purchases to create binary balance
    simulatePackagePurchase($pdo, 8, 1);  // deep1 additional (left side)
    simulatePackagePurchase($pdo, 12, 1); // deep5 additional (right side)
    simulatePackagePurchase($pdo, 9, 1);  // deep2 additional (left side)
    simulatePackagePurchase($pdo, 13, 1); // deep6 additional (right side)
    
    echo "\nPhase 4: More purchases to ensure all users get binary bonuses...\n";
    
    // Ensure sponsor-level users also get binary bonuses
    simulatePackagePurchase($pdo, 10, 1); // deep3 additional
    simulatePackagePurchase($pdo, 14, 1); // deep7 additional
    simulatePackagePurchase($pdo, 11, 1); // deep4 additional
    simulatePackagePurchase($pdo, 15, 1); // deep8 additional
    
    echo "\nPhase 5: Final purchases to maximize all bonus triggering...\n";
    
    // Additional volume to ensure higher-level users get binary bonuses
    simulatePackagePurchase($pdo, 4, 1); // buyer1 additional
    simulatePackagePurchase($pdo, 6, 1); // buyer3 additional
    simulatePackagePurchase($pdo, 5, 1); // buyer2 additional
    simulatePackagePurchase($pdo, 7, 1); // buyer4 additional
    
    displayFinalResults($pdo);
}

function displayFinalResults(PDO $pdo) {
    echo "\nFINAL RESULTS - All Bonus Types Analysis\n";
    echo "========================================\n";
    
    // Get comprehensive bonus data
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
    
    $allFourUsers = [];
    $bonusTypeCounts = ['referral' => 0, 'binary' => 0, 'leadership' => 0, 'mentor' => 0];
    
    echo "\nUser Bonus Summary:\n";
    foreach ($stmt->fetchAll() as $result) {
        $hasReferral = $result['referral_count'] > 0;
        $hasPair = $result['pair_count'] > 0;
        $hasLeadership = $result['leadership_count'] > 0;
        $hasMentor = $result['mentor_count'] > 0;
        
        if ($hasReferral) $bonusTypeCounts['referral']++;
        if ($hasPair) $bonusTypeCounts['binary']++;
        if ($hasLeadership) $bonusTypeCounts['leadership']++;
        if ($hasMentor) $bonusTypeCounts['mentor']++;
        
        $bonusTypes = [];
        if ($hasReferral) $bonusTypes[] = "Referral";
        if ($hasPair) $bonusTypes[] = "Binary";
        if ($hasLeadership) $bonusTypes[] = "Leadership";
        if ($hasMentor) $bonusTypes[] = "Mentor";
        
        $bonusString = empty($bonusTypes) ? "None" : implode(", ", $bonusTypes);
        
        echo "-- {$result['username']} (ID:{$result['id']}) - Balance: \$" . number_format($result['balance'], 2) . "\n";
        echo "   Bonuses: {$bonusString}\n";
        echo "   Totals: Ref:\$" . number_format($result['referral_total'], 2) . 
             " | Bin:\$" . number_format($result['pair_total'], 2) . 
             " | Lead:\$" . number_format($result['leadership_total'], 2) . 
             " | Mentor:\$" . number_format($result['mentor_total'], 2) . "\n";
        
        if ($hasReferral && $hasPair && $hasLeadership && $hasMentor) {
            $allFourUsers[] = $result;
        }
    }
    
    echo "\nBonus Type Distribution:\n";
    echo "-- Referral bonuses: {$bonusTypeCounts['referral']} users\n";
    echo "-- Binary bonuses: {$bonusTypeCounts['binary']} users\n";
    echo "-- Leadership bonuses: {$bonusTypeCounts['leadership']} users\n";
    echo "-- Mentor bonuses: {$bonusTypeCounts['mentor']} users\n";
    
    echo "\nUSERS WITH ALL FOUR BONUS TYPES:\n";
    if (empty($allFourUsers)) {
        echo "-- No users earned all four bonus types\n";
        
        // Show mentor bonus details specifically
        echo "\nMentor Bonus Analysis:\n";
        $stmt = $pdo->query("SELECT user_id, amount, created_at FROM wallet_tx WHERE type = 'leadership_reverse_bonus' ORDER BY created_at");
        $mentorBonuses = $stmt->fetchAll();
        
        if (empty($mentorBonuses)) {
            echo "-- No mentor bonuses paid\n";
            
            // Check binary bonuses (prerequisites for mentor bonuses)
            $stmt = $pdo->query("SELECT user_id, COUNT(*) as count, SUM(amount) as total FROM wallet_tx WHERE type = 'pair_bonus' GROUP BY user_id");
            $binaryBonuses = $stmt->fetchAll();
            
            if (empty($binaryBonuses)) {
                echo "-- No binary bonuses earned (mentor bonuses require binary bonuses first)\n";
            } else {
                echo "-- Binary bonuses earned by:\n";
                foreach ($binaryBonuses as $bonus) {
                    $username = getUsernameById($bonus['user_id'], $pdo);
                    echo "   {$username} (ID:{$bonus['user_id']}): {$bonus['count']} payments, \$" . number_format($bonus['total'], 2) . "\n";
                }
            }
        } else {
            echo "-- Mentor bonuses paid:\n";
            foreach ($mentorBonuses as $bonus) {
                $username = getUsernameById($bonus['user_id'], $pdo);
                echo "   {$username} (ID:{$bonus['user_id']}): \$" . number_format($bonus['amount'], 2) . " at {$bonus['created_at']}\n";
            }
        }
    } else {
        foreach ($allFourUsers as $user) {
            echo "-- SUCCESS: {$user['username']} (ID:{$user['id']}) earned ALL FOUR bonus types!\n";
            echo "   Final Balance: \$" . number_format($user['balance'], 2) . "\n";
            echo "   Referral: \$" . number_format($user['referral_total'], 2) . " ({$user['referral_count']} payments)\n";
            echo "   Binary: \$" . number_format($user['pair_total'], 2) . " ({$user['pair_count']} payments)\n";
            echo "   Leadership: \$" . number_format($user['leadership_total'], 2) . " ({$user['leadership_count']} payments)\n";
            echo "   Mentor: \$" . number_format($user['mentor_total'], 2) . " ({$user['mentor_count']} payments)\n";
        }
    }
    
    // Volume summary
    echo "\nFinal Volume Summary:\n";
    $keyUsers = [1, 2, 3, 4, 5, 6, 7];
    foreach ($keyUsers as $uid) {
        $pvt = getPersonalVolume($uid, $pdo);
        $gvt = getGroupVolume($uid, $pdo);
        $username = getUsernameById($uid, $pdo);
        echo "-- {$username} (ID:{$uid}): PVT=\${$pvt}, GVT=\${$gvt}\n";
    }
}

// Run the final test
try {
    setupFinalTestData($pdo);
    testGuaranteedAllBonuses($pdo);
    
    echo "\nFinal Test Complete!\n";
    echo "This test should demonstrate all four bonus types working together.\n";
    
} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . " in " . $e->getFile() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>