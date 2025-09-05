<?php
// mentor_debug_test.php - Debug mentor bonus calculation specifically
require_once 'config.php';
require_once 'functions.php';

echo "<pre>";

// Create a minimal test scenario focused on mentor bonuses
function setupMentorTest(PDO $pdo) {
    echo "Setting up minimal mentor bonus test...\n";
    
    // Clear data
    $pdo->exec("DELETE FROM wallet_tx WHERE user_id > 0");
    $pdo->exec("DELETE FROM wallets WHERE user_id > 0");
    $pdo->exec("DELETE FROM users WHERE id > 0");
    $pdo->exec("DELETE FROM flushes");
    $pdo->exec("DELETE FROM mentor_flush_log");
    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");
    
    // Create simple 3-level hierarchy
    $users = [
        ['admin', 'pass', null, null, null, 'user'],      // ID: 1 (ancestor)
        ['child1', 'pass', 1, 1, 'left', 'user'],        // ID: 2 (level 1 descendant)
        ['child2', 'pass', 1, 1, 'right', 'user'],       // ID: 3 (level 1 descendant)
        ['grandchild1', 'pass', 2, 2, 'left', 'user'],   // ID: 4 (level 2 descendant)
        ['grandchild2', 'pass', 2, 2, 'right', 'user'],  // ID: 5 (level 2 descendant)
    ];
    
    foreach ($users as $user) {
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password, sponsor_id, upline_id, position, role) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute($user);
    }
    
    // Create wallets
    for ($i = 1; $i <= 5; $i++) {
        $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, 1000.00)")->execute([$i]);
    }
    
    // Ensure packages exist with minimal mentor requirements
    $pdo->exec("DELETE FROM package_mentor_schedule WHERE package_id = 2");
    $mentor_schedules = [
        [2, 1, 1, 1, 0.030],  // Level 1: 1 PVT, 1 GVT, 3% rate
        [2, 2, 1, 1, 0.025],  // Level 2: 1 PVT, 1 GVT, 2.5% rate
    ];
    foreach ($mentor_schedules as $sch) {
        $pdo->prepare(
            "INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) 
             VALUES (?, ?, ?, ?, ?)"
        )->execute($sch);
    }
    
    echo "Test hierarchy created:\n";
    echo "1. admin (ancestor)\n";
    echo "├── 2. child1 (L1 descendant)\n";
    echo "│   ├── 4. grandchild1 (L2 descendant)\n";
    echo "│   └── 5. grandchild2 (L2 descendant)\n";
    echo "└── 3. child2 (L1 descendant)\n\n";
}

// Debug version of calc_leadership_reverse with extensive logging
function debug_calc_leadership_reverse(int $ancestorId, float $pairBonus, PDO $pdo): void
{
    echo "=== MENTOR BONUS CALCULATION DEBUG ===\n";
    echo "Ancestor ID: $ancestorId, Pair Bonus: $pairBonus\n";
    
    if ($pairBonus <= 0) {
        echo "❌ Pair bonus is 0 or negative - exiting\n\n";
        return;
    }

    echo "Building descendants list...\n";
    $descendants = [];
    $current = [$ancestorId];
    
    for ($level = 1; $level <= 5; $level++) {
        if (!$current) break;

        $placeholders = implode(',', array_fill(0, count($current), '?'));
        $sql = "SELECT id FROM users WHERE sponsor_id IN ($placeholders)";
        echo "Level $level SQL: $sql with params: " . implode(',', $current) . "\n";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($current);

        $next = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $next[] = (int)$row['id'];
            $descendants[] = [
                'id' => (int)$row['id'],
                'lvl' => $level
            ];
            echo "  Found descendant: ID {$row['id']} at level $level\n";
        }
        $current = $next;
    }
    
    echo "Total descendants found: " . count($descendants) . "\n\n";

    foreach ($descendants as $desc) {
        $descId = $desc['id'];
        $level = $desc['lvl'];
        
        echo "--- Processing descendant ID $descId (Level $level) ---\n";
        
        // Get DESCENDANT'S highest price package
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.price
            FROM packages p
            JOIN wallet_tx wt ON wt.package_id = p.id
            WHERE wt.user_id = ? AND wt.type='package'
            ORDER BY p.price DESC, wt.id DESC
            LIMIT 1
        ");
        $stmt->execute([$descId]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$package) {
            echo "  ❌ No package found for descendant $descId\n";
            continue;
        }
        
        echo "  Package found: {$package['name']} (ID: {$package['id']}, Price: {$package['price']})\n";
        
        // Get mentor schedule for this package and level
        $stmt = $pdo->prepare("
            SELECT level, pvt_required, gvt_required, rate
            FROM package_mentor_schedule 
            WHERE package_id = ? AND level = ?
        ");
        $stmt->execute([$package['id'], $level]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$schedule) {
            echo "  ❌ No mentor schedule found for package {$package['id']} level $level\n";
            continue;
        }
        
        echo "  Schedule found: PVT req={$schedule['pvt_required']}, GVT req={$schedule['gvt_required']}, Rate={$schedule['rate']}\n";

        $needPVT = $schedule['pvt_required'];
        $needGVT = $schedule['gvt_required'];
        $rate = $schedule['rate'];

        $pvt = getPersonalVolume($descId, $pdo);
        $gvt = getGroupVolume($descId, $pdo, 0);
        
        echo "  Descendant volumes: PVT=$pvt (need $needPVT), GVT=$gvt (need $needGVT)\n";

        $grossBonus = $pairBonus * $rate;
        echo "  Gross bonus calculation: $pairBonus * $rate = $grossBonus\n";

        // Check for previously flushed amount
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM mentor_flush_log
             WHERE descendant_id = ? AND ancestor_id = ? AND level = ?'
        );
        $stmt->execute([$descId, $ancestorId, $level]);
        $flushed = (float)$stmt->fetchColumn();
        echo "  Previously flushed: $flushed\n";

        $netBonus = max(0, $grossBonus - $flushed);
        echo "  Net bonus after flush: $netBonus\n";
        
        if ($netBonus <= 0) {
            echo "  ⚠️ Net bonus is 0 - skipping\n";
            continue;
        }

        if ($pvt >= $needPVT && $gvt >= $needGVT) {
            echo "  ✅ DESCENDANT QUALIFIED - Paying mentor bonus\n";
            
            // Credit descendant
            $pdo->prepare(
                'UPDATE wallets SET balance = balance + ? WHERE user_id = ?'
            )->execute([$netBonus, $descId]);

            $pdo->prepare(
                'INSERT INTO wallet_tx (user_id, type, amount)
                 VALUES (?, "leadership_reverse_bonus", ?)'
            )->execute([$descId, $netBonus]);
            
            echo "  💰 Paid $netBonus to descendant $descId\n";
        } else {
            echo "  ❌ DESCENDANT NOT QUALIFIED - Flushing\n";
            
            // Flush from descendant's potential earnings
            $pdo->prepare(
                'INSERT INTO flushes (user_id, amount, flushed_on, reason)
                 VALUES (?, ?, CURDATE(), ?)'
            )->execute([$descId, $netBonus, 'mentor_requirements_not_met']);

            // Log flush
            $pdo->prepare(
                'INSERT IGNORE INTO mentor_flush_log
                   (ancestor_id, descendant_id, level, amount, flushed_on)
                 VALUES (?, ?, ?, ?, CURDATE())
                 ON DUPLICATE KEY UPDATE
                   amount = amount + VALUES(amount)'
            )->execute([$ancestorId, $descId, $level, $netBonus]);
            
            echo "  🚽 Flushed $netBonus from descendant $descId\n";
        }
        echo "\n";
    }
    echo "=== END MENTOR BONUS CALCULATION ===\n\n";
}

// Test mentor bonus specifically
function testMentorBonus(PDO $pdo) {
    echo "Testing mentor bonus calculation...\n\n";
    
    // Reset
    $pdo->exec("UPDATE users SET left_count=0, right_count=0, pairs_today=0");
    $pdo->exec("DELETE FROM wallet_tx WHERE type != 'package'");
    $pdo->exec("UPDATE wallets SET balance = 1000.00");
    
    // Step 1: Descendants buy packages to qualify
    echo "Step 1: Descendants buy packages...\n";
    
    // Child1 buys Pro package
    $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (2, 2, 'package', -50.00)")->execute();
    echo "child1 bought Pro package\n";
    
    // Child2 buys Pro package
    $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (3, 2, 'package', -50.00)")->execute();
    echo "child2 bought Pro package\n";
    
    // Grandchildren buy Starter packages
    $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (4, 1, 'package', -25.00)")->execute();
    $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (5, 1, 'package', -25.00)")->execute();
    echo "grandchildren bought Starter packages\n\n";
    
    // Check volumes
    echo "Checking descendant volumes:\n";
    for ($i = 2; $i <= 5; $i++) {
        $pvt = getPersonalVolume($i, $pdo);
        $gvt = getGroupVolume($i, $pdo);
        $username = getUsernameById($i, $pdo);
        echo "$username (ID:$i): PVT=$pvt, GVT=$gvt\n";
    }
    echo "\n";
    
    // Step 2: Admin earns binary bonus (simulate)
    echo "Step 2: Admin earns binary bonus - this should trigger mentor bonuses...\n";
    
    // Simulate admin earning binary bonus
    $pairBonus = 10.00; // Simulate $10 binary bonus
    
    // Call our debug version
    debug_calc_leadership_reverse(1, $pairBonus, $pdo);
    
    // Check results
    echo "Final results:\n";
    $stmt = $pdo->query("
        SELECT u.username, w.balance,
               (SELECT COUNT(*) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_reverse_bonus') as mentor_count,
               (SELECT COALESCE(SUM(amount), 0) FROM wallet_tx wt WHERE wt.user_id = u.id AND wt.type = 'leadership_reverse_bonus') as mentor_total
        FROM users u JOIN wallets w ON w.user_id = u.id
        ORDER BY u.id
    ");
    
    foreach ($stmt->fetchAll() as $user) {
        echo "{$user['username']}: Balance={$user['balance']}, Mentor bonuses={$user['mentor_count']} (\${$user['mentor_total']})\n";
    }
}

// Run the test
try {
    setupMentorTest($pdo);
    testMentorBonus($pdo);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>