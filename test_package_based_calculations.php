<?php
// test_package_based_calculations.php
require_once 'config.php';

function testPackageLookup($userId, PDO $pdo) {
    echo "Testing package lookup for User $userId:\n";
    
    // Leadership (ancestor's package)
    $stmt = $pdo->prepare("
        SELECT p.name, p.daily_max, p.pair_rate 
        FROM packages p
        JOIN wallet_tx wt ON wt.package_id = p.id
        WHERE wt.user_id = ? AND wt.type='package'
        ORDER BY wt.amount DESC, wt.id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($package) {
        echo "✅ Highest package: {$package['name']} (Daily: {$package['daily_max']}, Rate: {$package['pair_rate']})\n";
    } else {
        echo "❌ No package found\n";
    }
}

// Test with user ID 2
testPackageLookup(4, $pdo);
?>