<?php
// debug_referrals.php - Diagnostic script for referrals issues
require_once 'config.php';
require_once 'functions.php';

echo "<h2>Referrals Debugging</h2>";

// Test 1: Check session
echo "<h3>1. Session Check</h3>";
if (!isset($_SESSION['user_id'])) {
    echo "❌ No user_id in session<br>";
    echo "Available session keys: " . implode(', ', array_keys($_SESSION)) . "<br>";
} else {
    $uid = $_SESSION['user_id'];
    echo "✅ user_id found: " . $uid . "<br>";
}

// Test 2: Check if user exists
echo "<h3>2. User Verification</h3>";
try {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✅ User found: ID={$user['id']}, Username={$user['username']}, Role={$user['role']}<br>";
    } else {
        echo "❌ User with ID $uid not found in database<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error checking user: " . $e->getMessage() . "<br>";
}

// Test 3: Check wallet_tx table structure
echo "<h3>3. wallet_tx Table Structure</h3>";
try {
    $stmt = $pdo->query("DESCRIBE wallet_tx");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ wallet_tx table columns:<br>";
    foreach ($columns as $col) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error describing wallet_tx table: " . $e->getMessage() . "<br>";
}

// Test 4: Check transaction types
echo "<h3>4. Available Transaction Types</h3>";
try {
    $stmt = $pdo->query("SELECT DISTINCT type FROM wallet_tx ORDER BY type");
    $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Available transaction types:<br>";
    foreach ($types as $type) {
        echo "- " . $type . "<br>";
    }
    
    // Check if referral_bonus type exists
    if (in_array('referral_bonus', $types)) {
        echo "✅ 'referral_bonus' type exists<br>";
    } else {
        echo "❌ 'referral_bonus' type NOT found - this is likely the issue!<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error checking transaction types: " . $e->getMessage() . "<br>";
}

// Test 5: Test basic referral bonus query
echo "<h3>5. Basic Referral Bonus Query Test</h3>";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM wallet_tx WHERE user_id = ? AND type = 'referral_bonus'");
    $stmt->execute([$uid]);
    $bonusCount = $stmt->fetchColumn();
    echo "✅ Referral bonus transactions for user $uid: " . $bonusCount . "<br>";
    
    if ($bonusCount > 0) {
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM wallet_tx WHERE user_id = ? AND type = 'referral_bonus'");
        $stmt->execute([$uid]);
        $totalBonus = $stmt->fetchColumn();
        echo "✅ Total referral bonus: $" . number_format($totalBonus, 2) . "<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error with referral bonus query: " . $e->getMessage() . "<br>";
}

// Test 6: Check direct referrals
echo "<h3>6. Direct Referrals Check</h3>";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE sponsor_id = ?");
    $stmt->execute([$uid]);
    $referralCount = $stmt->fetchColumn();
    echo "✅ Direct referrals count: " . $referralCount . "<br>";
    
    if ($referralCount > 0) {
        $stmt = $pdo->prepare("SELECT id, username, created_at FROM users WHERE sponsor_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$uid]);
        $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Sample referrals:<br>";
        foreach ($referrals as $ref) {
            echo "- ID: {$ref['id']}, Username: {$ref['username']}, Joined: {$ref['created_at']}<br>";
        }
    }
} catch (PDOException $e) {
    echo "❌ Error checking referrals: " . $e->getMessage() . "<br>";
}

// Test 7: Check original complex query
echo "<h3>7. Original Complex Query Test</h3>";
try {
    $stmt = $pdo->prepare("
        SELECT d.username,
               COALESCE(SUM(rt.amount),0) AS earned
        FROM users d
        JOIN wallet_tx pkg_tx
            ON pkg_tx.user_id = d.id
            AND pkg_tx.type = 'package'
            AND pkg_tx.amount < 0
        JOIN wallet_tx rt
            ON rt.user_id = (SELECT id FROM users WHERE id = d.sponsor_id)
            AND rt.type = 'referral_bonus'
            AND rt.created_at BETWEEN pkg_tx.created_at AND DATE_ADD(pkg_tx.created_at, INTERVAL 1 SECOND)
        WHERE d.sponsor_id = ?
        GROUP BY d.id
        ORDER BY d.username
    ");
    $stmt->execute([$uid]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Original complex query executed successfully<br>";
    echo "Results: " . count($results) . " rows<br>";
    
    if (!empty($results)) {
        echo "Sample result:<br>";
        echo "<pre>" . print_r($results[0], true) . "</pre>";
    }
    
} catch (PDOException $e) {
    echo "❌ Original complex query failed: " . $e->getMessage() . "<br>";
    echo "SQL Error Code: " . $e->getCode() . "<br>";
    
    // This is likely where the error is occurring
    echo "<strong>This query is probably causing your 'Error loading referral data' message.</strong><br>";
}

// Test 8: Check database relationships
echo "<h3>8. Database Relationship Test</h3>";
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_users,
            COUNT(sponsor_id) as users_with_sponsors,
            COUNT(DISTINCT sponsor_id) as unique_sponsors
        FROM users
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "✅ Relationship stats:<br>";
    echo "- Total users: {$stats['total_users']}<br>";
    echo "- Users with sponsors: {$stats['users_with_sponsors']}<br>";
    echo "- Unique sponsors: {$stats['unique_sponsors']}<br>";
    
} catch (PDOException $e) {
    echo "❌ Error checking relationships: " . $e->getMessage() . "<br>";
}

// Test 9: Check for data inconsistencies
echo "<h3>9. Data Consistency Check</h3>";
try {
    // Check for invalid sponsor_id references
    $stmt = $pdo->query("
        SELECT u1.id, u1.username, u1.sponsor_id
        FROM users u1
        LEFT JOIN users u2 ON u2.id = u1.sponsor_id
        WHERE u1.sponsor_id IS NOT NULL AND u2.id IS NULL
        LIMIT 5
    ");
    $orphaned = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($orphaned)) {
        echo "✅ No orphaned sponsor references<br>";
    } else {
        echo "❌ Found orphaned sponsor references:<br>";
        foreach ($orphaned as $user) {
            echo "- User {$user['username']} (ID: {$user['id']}) has invalid sponsor_id: {$user['sponsor_id']}<br>";
        }
    }
} catch (PDOException $e) {
    echo "❌ Error checking consistency: " . $e->getMessage() . "<br>";
}

echo "<h3>Recommendations:</h3>";
echo "<ul>";
echo "<li><strong>Most likely issue:</strong> The original complex JOIN query is failing</li>";
echo "<li>Check if 'referral_bonus' transaction type exists in your wallet_tx table</li>";
echo "<li>Verify that package transactions have negative amounts (amount < 0)</li>";
echo "<li>Check for orphaned sponsor_id references</li>";
echo "<li>Use the simple version first, then gradually add complexity</li>";
echo "<li>Check your error logs for the exact SQL error message</li>";
echo "</ul>";

echo "<h3>Quick Fixes:</h3>";
echo "<ol>";
echo "<li>Use the simple referrals.php version I provided above</li>";
echo "<li>If 'referral_bonus' type is missing, add it to your ENUM</li>";
echo "<li>Clean up any orphaned sponsor_id references</li>";
echo "<li>Test each query component individually</li>";
echo "</ol>";
?>