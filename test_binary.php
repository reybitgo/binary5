<?php
// test_binary.php
require_once 'config.php';

echo "Testing binary calculation...\n";

// Test with user who just bought package
$userId = 3; // Package buyer
$pv = 25;    // Package PV

try {
    include 'binary_calc.php';
    
    // Get buyer's package
    $stmt = $pdo->prepare("
        SELECT p.name, p.daily_max, p.pair_rate 
        FROM packages p
        JOIN wallet_tx wt ON wt.package_id = p.id
        WHERE wt.user_id = ? AND wt.type='package'
        ORDER BY wt.id DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $pkg = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($pkg) {
        echo "✅ Using package: {$pkg['name']} (Daily: {$pkg['daily_max']}, Rate: {$pkg['pair_rate']})\n";
        
        calc_binary($userId, $pv, $pdo);
        echo "✅ Binary calculation completed!\n";
        
        // Check ancestor bonuses
        $stmt = $pdo->prepare("
            SELECT u.username, w.balance 
            FROM users u
            JOIN wallets w ON w.user_id = u.id
            WHERE u.id IN (
                SELECT upline_id FROM users WHERE id = ?
            )
        ");
        $stmt->execute([$userId]);
        $ancestors = $stmt->fetchAll();
        
        foreach ($ancestors as $ancestor) {
            echo "💰 {$ancestor['username']} balance: $" . number_format($ancestor['balance'], 2) . "\n";
        }
    } else {
        echo "❌ No package found for user $userId\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>