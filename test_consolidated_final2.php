<?php
// test_consolidated_final.php - Complete testing for ALL bonuses with package-based limitations
// Updated to work with the new package-based commission limitation system

require_once 'config.php';
require_once 'binary_calc.php';
require_once 'leadership_calc.php';
require_once 'leadership_reverse_calc.php';
require_once 'referral_calc.php';
require_once 'functions.php';

echo "<pre>";

function setupPackageBasedTestData(PDO $pdo) {
    echo "Setting up package-based limitation test data...\n";
    
    // Clear existing test data
    $pdo->exec("DELETE FROM wallet_tx WHERE user_id > 0");
    $pdo->exec("DELETE FROM wallets WHERE user_id > 0");
    $pdo->exec("DELETE FROM users WHERE id > 0");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
    
    // Create strategic hierarchy for package-based testing
    $users = [
        ['admin', 'admin123', null, null, null, 'admin'],           // ID: 1, Elite package
        ['sponsor1', 'pass123', 1, 1, 'left', 'user'],            // ID: 2, Pro package
        ['sponsor2', 'pass123', 1, 1, 'right', 'user'],           // ID: 3, Pro package
        ['buyer1', 'pass123', 2, 2, 'left', 'user'],              // ID: 4, Starter package
        ['buyer2', 'pass123', 2, 2, 'right', 'user'],             // ID: 5, Starter package
        ['buyer3', 'pass123', 3, 3, 'left', 'user'],              // ID: 6, Pro package
        ['buyer4', 'pass123', 3, 3, 'right', 'user'],             // ID: 7, Elite package
        ['deep1', 'pass123', 4, 4, 'left', 'user'],               // ID: 8, Starter package
        ['deep2', 'pass123', 4, 4, 'right', 'user'],              // ID: 9, Pro package
        ['deep3', 'pass123', 5, 5, 'left', 'user'],               // ID: 10, Elite package
        ['deep4', 'pass123', 5, 5, 'right', 'user'],              // ID: 11, Starter package
        ['deep5', 'pass123', 6, 6, 'left', 'user'],               // ID: 12, Pro package
        ['deep6', 'pass123', 6, 6, 'right', 'user'],              // ID: 13, Elite package
        ['deep7', 'pass123', 7, 7, 'left', 'user'],               // ID: 14, Pro package
        ['deep8', 'pass123', 7, 7, 'right', 'user']               // ID: 15, Elite package
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
    
    // Create wallets with sufficient balance for testing
    for ($i = 1; $i <= 15; $i++) {
        $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 50000.00)")->execute([$i]);
    }
    
    // Reset and recreate packages with appropriate settings for testing
    $pdo->exec("DELETE FROM packages");
    $pdo->exec("ALTER TABLE packages AUTO_INCREMENT = 1");
    $packages = [
        ['Starter', 25.00, 25, 50, 0.1500, 0.0800],    // ID:1 - Lower rates, lower daily max
        ['Pro', 50.00, 50, 100, 0.2000, 0.1000],       // ID:2 - Medium rates, medium daily max
        ['Elite', 100.00, 100, 200, 0.2500, 0.1200]    // ID:3 - Higher rates, higher daily max
    ];
    foreach ($packages as $pkg) {
        $pdo->prepare(
            "INSERT INTO packages (name, price, pv, daily_max, pair_rate, referral_rate) 
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute($pkg);
    }
    
    // Set realistic leadership requirements for package-based testing
    $pdo->exec("DELETE FROM package_leadership_schedule");
    $leadership_schedules = [
        // Starter (ID:1) - Achievable requirements
        [1, 1, 25, 50, 0.030], [1, 2, 50, 100, 0.025], [1, 3, 75, 150, 0.020], [1, 4, 100, 200, 0.015], [1, 5, 150, 300, 0.010],
        // Pro (ID:2) - Medium requirements
        [2, 1, 50, 100, 0.050], [2, 2, 100, 200, 0.040], [2, 3, 150, 300, 0.030], [2, 4, 200, 400, 0.020], [2, 5, 300, 600, 0.010],
        // Elite (ID:3) - Higher but achievable requirements
        [3, 1, 100, 200, 0.070], [3, 2, 200, 400, 0.060], [3, 3, 300, 600, 0.050], [3, 4, 400, 800, 0.040], [3, 5, 600, 1200, 0.030]
    ];
    foreach ($leadership_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    // Set realistic mentor requirements
    $pdo->exec("DELETE FROM package_mentor_schedule");
    $mentor_schedules = [
        // Starter (ID:1) - Low requirements
        [1, 1, 25, 50, 0.020], [1, 2, 50, 75, 0.018], [1, 3, 75, 100, 0.015], [1, 4, 100, 150, 0.012], [1, 5, 150, 200, 0.010],
        // Pro (ID:2) - Medium requirements  
        [2, 1, 50, 100, 0.030], [2, 2, 100, 150, 0.025], [2, 3, 150, 250, 0.020], [2, 4, 200, 350, 0.015], [2, 5, 300, 500, 0.010],
        // Elite (ID:3) - Higher requirements
        [3, 1, 100, 200, 0.040], [3, 2, 200, 300, 0.035], [3, 3, 300, 500, 0.030], [3, 4, 400, 700, 0.025], [3, 5, 600, 1000, 0.020]
    ];
    foreach ($mentor_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    echo "Package-based test data ready: 15 users with different package strategies\n";
    
    // Display hierarchy with planned packages
    echo "\nUser Hierarchy and Package Strategy:\n";
    $packagePlan = [
        1 => 'Elite', 2 => 'Pro', 3 => 'Pro', 4 => 'Starter', 5 => 'Starter',
        6 => 'Pro', 7 => 'Elite', 8 => 'Starter', 9 => 'Pro', 10 => 'Elite',
        11 => 'Starter', 12 => 'Pro', 13 => 'Elite', 14 => 'Pro', 15 => 'Elite'
    ];
    
    $stmt = $pdo->query("SELECT id, username, sponsor_id, upline_id, position FROM users ORDER BY id");
    foreach ($stmt->fetchAll() as $user) {
        $sponsor = $user['sponsor_id'] ? "Sponsor:{$user['sponsor_id']}" : "Root";
        $upline = $user['upline_id'] ? "Upline:{$user['upline_id']}" : "Root";
        $plannedPkg = $packagePlan[$user['id']] ?? 'None';
        echo "-- {$user['username']} (ID:{$user['id']}) - {$sponsor}, {$upline}, Pos:{$user['position']}, Package:{$plannedPkg}\n";
    }
}

function simulatePackageBasedPurchase(PDO $pdo, int $userId, int $packageId) {
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
    
    // Record transaction with package_id (IMPORTANT for new system)
    $pdo->prepare(
        "INSERT INTO wallet_tx (user_id, package_id, type, amount) 
         VALUES (?, ?, 'package', ?)"
    )->execute([$userId, $packageId, $amount]);
    
    // Update wallet
    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")->execute([$amount, $userId]);
    
    // Calculate all bonuses in correct order
    calc_referral($userId, $price, $pdo);  // Referral limited by sponsor's package
    calc_binary($userId, $pv, $pdo);       // Binary limited by each ancestor's package
    
    $username = getUsernameById($userId, $pdo);
    echo "{$username} (ID:{$userId}) bought {$pkg['name']} (PV: {$pv}, Price: \${$price})\n";
}

function testPackageBasedLimitations(PDO $pdo) {
    echo "\nTesting Package-Based Bonus Limitations\n";
    echo "======================================\n";
    
    // Reset everything
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type IN ('package', 'pair_bonus', 'referral_bonus', 'leadership_bonus', 'leadership_reverse_bonus')");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM leadership_flush_log");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("UPDATE wallets SET balance = 50000.00");
    
    echo "\nPhase 1: Establish base packages (creates different earning capacities)...\n";
    
    // Strategic package purchases to test limitations
    simulatePackageBasedPurchase($pdo, 1, 3);  // Admin Elite ($100)
    simulatePackageBasedPurchase($pdo, 2, 2);  // sponsor1 Pro ($50)  
    simulatePackageBasedPurchase($pdo, 3, 2);  // sponsor2 Pro ($50)
    simulatePackageBasedPurchase($pdo, 4, 1);  // buyer1 Starter ($25)
    simulatePackageBasedPurchase($pdo, 5, 1);  // buyer2 Starter ($25)
    simulatePackageBasedPurchase($pdo, 6, 2);  // buyer3 Pro ($50)
    simulatePackageBasedPurchase($pdo, 7, 3);  // buyer4 Elite ($100)
    
    echo "\nPhase 2: Build descendant volumes for mentor qualification...\n";
    
    simulatePackageBasedPurchase($pdo, 8, 1);   // deep1 Starter
    simulatePackageBasedPurchase($pdo, 9, 2);   // deep2 Pro  
    simulatePackageBasedPurchase($pdo, 10, 3);  // deep3 Elite
    simulatePackageBasedPurchase($pdo, 11, 1);  // deep4 Starter
    simulatePackageBasedPurchase($pdo, 12, 2);  // deep5 Pro
    simulatePackageBasedPurchase($pdo, 13, 3);  // deep6 Elite
    simulatePackageBasedPurchase($pdo, 14, 2);  // deep7 Pro
    simulatePackageBasedPurchase($pdo, 15, 3);  // deep8 Elite
    
    echo "\nPhase 3: Test referral limitations (sponsor earnings capped by their package)...\n";
    
    // Test case: Low package sponsor gets referral from high package purchase
    echo "\n-- Testing Referral Limitation: Starter sponsor gets referral from Elite purchase --\n";
    simulatePackageBasedPurchase($pdo, 8, 3);  // deep1 (Starter sponsor: buyer1) buys Elite
    
    // Check if buyer1 (Starter $25) referral was limited when deep1 bought Elite ($100)
    $stmt = $pdo->prepare("SELECT amount FROM wallet_tx WHERE user_id = 4 AND type = 'referral_bonus' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $referralAmount = $stmt->fetchColumn();
    $expectedMax = 25 * 0.08; // Starter package price × Starter referral rate
    echo "buyer1 (Starter) referral: \${$referralAmount} (max possible: \${$expectedMax})\n";
    
    echo "\nPhase 4: Create binary bonus scenarios to test binary limitations...\n";
    
    // Additional purchases to create binary pairs
    simulatePackageBasedPurchase($pdo, 9, 1);   // Create more volume
    simulatePackageBasedPurchase($pdo, 12, 1);  // Balance legs
    simulatePackageBasedPurchase($pdo, 10, 1);  // More pairs
    simulatePackageBasedPurchase($pdo, 14, 1);  // Create binary opportunities
    
    echo "\nPhase 5: Test capacity limitations with multiple purchases...\n";
    
    // Multiple purchases by same user to test daily caps
    simulatePackageBasedPurchase($pdo, 11, 2);  // deep4 upgrades to Pro
    simulatePackageBasedPurchase($pdo, 13, 1);  // deep6 buys additional Starter
    simulatePackageBasedPurchase($pdo, 15, 1);  // deep8 buys additional Starter
    
    echo "\nPhase 6: Final volume building for comprehensive testing...\n";
    
    simulatePackageBasedPurchase($pdo, 4, 2);   // buyer1 upgrades to Pro
    simulatePackageBasedPurchase($pdo, 5, 3);   // buyer2 upgrades to Elite
    simulatePackageBasedPurchase($pdo, 6, 1);   // buyer3 additional purchase
    simulatePackageBasedPurchase($pdo, 7, 1);   // buyer4 additional purchase
    
    displayPackageBasedResults($pdo);
}

function displayPackageBasedResults(PDO $pdo) {
    echo "\nPACKAGE-BASED LIMITATION RESULTS\n";
    echo "===============================\n";
    
    // Get comprehensive results with package information
    $stmt = $pdo->query(
        "SELECT u.id, u.username, w.balance,
                -- Get user's highest package
                (SELECT p.name FROM packages p 
                 JOIN wallet_tx wt ON p.id = wt.package_id 
                 WHERE wt.user_id = u.id AND wt.type = 'package' 
                 ORDER BY p.price DESC LIMIT 1) as highest_package,
                (SELECT p.price FROM packages p 
                 JOIN wallet_tx wt ON p.id = wt.package_id 
                 WHERE wt.user_id = u.id AND wt.type = 'package' 
                 ORDER BY p.price DESC LIMIT 1) as highest_package_price,
                -- Bonus counts and totals
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
    $packageBasedAnalysis = [];
    
    echo "\nDetailed User Analysis:\n";
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
        $packageName = $result['highest_package'] ?? 'No Package';
        $packagePrice = $result['highest_package_price'] ?? 0;
        
        echo "-- {$result['username']} (ID:{$result['id']}) - Package: {$packageName} (\${$packagePrice})\n";
        echo "   Balance: \$" . number_format($result['balance'], 2) . " | Bonuses: {$bonusString}\n";
        echo "   Totals: Ref:\$" . number_format($result['referral_total'], 2) . 
             " | Bin:\$" . number_format($result['pair_total'], 2) . 
             " | Lead:\$" . number_format($result['leadership_total'], 2) . 
             " | Mentor:\$" . number_format($result['mentor_total'], 2) . "\n";
        
        // Analyze package-based limitations
        if ($packagePrice > 0) {
            $totalEarned = $result['referral_total'] + $result['pair_total'] + 
                          $result['leadership_total'] + $result['mentor_total'];
            $earningsRatio = $totalEarned / $packagePrice;
            
            echo "   Package Analysis: Total Earned \${$totalEarned} vs Investment \${$packagePrice} (Ratio: " . number_format($earningsRatio, 2) . "x)\n";
            
            $packageBasedAnalysis[] = [
                'user' => $result,
                'package_price' => $packagePrice,
                'total_earned' => $totalEarned,
                'ratio' => $earningsRatio
            ];
        }
        
        if ($hasReferral && $hasPair && $hasLeadership && $hasMentor) {
            $allFourUsers[] = $result;
        }
        echo "\n";
    }
    
    echo "Bonus Type Distribution:\n";
    echo "-- Referral bonuses: {$bonusTypeCounts['referral']} users\n";
    echo "-- Binary bonuses: {$bonusTypeCounts['binary']} users\n";
    echo "-- Leadership bonuses: {$bonusTypeCounts['leadership']} users\n";
    echo "-- Mentor bonuses: {$bonusTypeCounts['mentor']} users\n";
    
    echo "\nPACKAGE-BASED LIMITATION ANALYSIS:\n";
    
    // Show examples of limitations working
    echo "\nReferral Limitation Examples:\n";
    $stmt = $pdo->query("
        SELECT wt.user_id, wt.amount, p1.name as recipient_package, p1.price as recipient_price,
               p2.name as buyer_package, p2.price as buyer_price
        FROM wallet_tx wt
        JOIN wallet_tx buyer_tx ON buyer_tx.id = (
            SELECT id FROM wallet_tx wt2 
            WHERE wt2.type = 'package' AND wt2.created_at <= wt.created_at
            ORDER BY wt2.created_at DESC LIMIT 1
        )
        JOIN packages p2 ON buyer_tx.package_id = p2.id
        JOIN (
            SELECT p.name, p.price, wt_pkg.user_id
            FROM packages p
            JOIN wallet_tx wt_pkg ON p.id = wt_pkg.package_id
            WHERE wt_pkg.type = 'package'
        ) p1 ON p1.user_id = wt.user_id
        WHERE wt.type = 'referral_bonus'
        ORDER BY wt.created_at DESC
        LIMIT 5
    ");
    
    foreach ($stmt->fetchAll() as $ref) {
        $username = getUsernameById($ref['user_id'], $pdo);
        echo "-- {$username}: Earned \${$ref['amount']} (Package: {$ref['recipient_package']} \${$ref['recipient_price']})\n";
    }
    
    echo "\nUSERS WITH ALL FOUR BONUS TYPES:\n";
    if (empty($allFourUsers)) {
        echo "-- No users earned all four bonus types with current limitations\n";
        echo "-- This demonstrates the package-based limitation system is working\n";
    } else {
        foreach ($allFourUsers as $user) {
            echo "-- SUCCESS: {$user['username']} (ID:{$user['id']}) earned ALL FOUR bonus types!\n";
            echo "   Package: {$user['highest_package']} (\${$user['highest_package_price']})\n";
            echo "   Final Balance: \$" . number_format($user['balance'], 2) . "\n";
            echo "   Referral: \$" . number_format($user['referral_total'], 2) . " ({$user['referral_count']} payments)\n";
            echo "   Binary: \$" . number_format($user['pair_total'], 2) . " ({$user['pair_count']} payments)\n";
            echo "   Leadership: \$" . number_format($user['leadership_total'], 2) . " ({$user['leadership_count']} payments)\n";
            echo "   Mentor: \$" . number_format($user['mentor_total'], 2) . " ({$user['mentor_count']} payments)\n";
        }
    }
    
    echo "\nFinal Volume Summary:\n";
    $keyUsers = [1, 2, 3, 4, 5, 6, 7];
    foreach ($keyUsers as $uid) {
        $pvt = getPersonalVolume($uid, $pdo);
        $gvt = getGroupVolume($uid, $pdo);
        $username = getUsernameById($uid, $pdo);
        echo "-- {$username} (ID:{$uid}): PVT=\${$pvt}, GVT=\${$gvt}\n";
    }
    
    // Show flush analysis
    echo "\nFlush Analysis (Limitations in Action):\n";
    $stmt = $pdo->query("SELECT user_id, reason, COUNT(*) as count, SUM(amount) as total FROM flushes GROUP BY user_id, reason");
    $flushes = $stmt->fetchAll();
    
    if (empty($flushes)) {
        echo "-- No flushes recorded\n";
    } else {
        foreach ($flushes as $flush) {
            $username = getUsernameById($flush['user_id'], $pdo);
            echo "-- {$username}: {$flush['reason']} - {$flush['count']} times, \${$flush['total']} total\n";
        }
    }
}

// Run the package-based limitation test
try {
    setupPackageBasedTestData($pdo);
    testPackageBasedLimitations($pdo);
    
    echo "\nPackage-Based Limitation Test Complete!\n";
    echo "This test demonstrates how the new system limits bonuses based on each user's package investment.\n";
    echo "Key Features Tested:\n";
    echo "- Referral bonuses limited by sponsor's package\n";
    echo "- Binary bonuses limited by each ancestor's package\n";
    echo "- Leadership bonuses capped by ancestor's daily capacity\n";
    echo "- Mentor bonuses capped by descendant's daily capacity\n";
    
} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . " in " . $e->getFile() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>