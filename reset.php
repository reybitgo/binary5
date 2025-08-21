<?php
/*  reset.php
 *  1. Reads clean schema.sql 
 *  2. Re-creates the whole DB from the file
 *  3. Creates admin user programmatically with new password
 *  4. Prints the new credentials
 */

// Configuration: Set to true for hardcoded password, false for random
$useHardcodedPassword = true;

// Generate password based on configuration
if ($useHardcodedPassword) {
    $adminPlain = 'admin123'; // Hardcoded password for development/testing
    echo "Using hardcoded password for development\n";
} else {
    $adminPlain = bin2hex(random_bytes(6)); // 12-char random hex string
    echo "Generated random password\n";
}

$adminHash = password_hash($adminPlain, PASSWORD_DEFAULT);

$sqlFile = __DIR__ . '/schema.sql';
if (!file_exists($sqlFile)) {
    die("schema.sql not found\n");
}

$sql = file_get_contents($sqlFile);
if ($sql === false) {
    die("Failed to read schema.sql\n");
}

echo "Reading clean schema.sql...\n";

try {
    // Connect to MySQL server
    $dsn = 'mysql:host=localhost;charset=utf8mb4';
    $pdo = new PDO($dsn, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    echo "Executing database schema...\n";
    
    // Execute the entire SQL file
    $pdo->exec($sql);
    
    echo "Schema created successfully!\n";
    
    // Switch to the new database
    $pdo->exec("USE binary5_db");
    
    // Create admin user
    echo "Creating admin user...\n";
    $stmt = $pdo->prepare("INSERT INTO users (username, password, sponsor_name, upline_id, position) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['admin', $adminHash, 'root', null, null]);
    
    $adminId = $pdo->lastInsertId();
    echo "Admin user created with ID: $adminId\n";
    
    // Create wallet for admin user
    $stmt = $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, ?)");
    $stmt->execute([$adminId, 0.00]);
    echo "Admin wallet created\n";
    
    echo "\n=== DATABASE RESET SUCCESSFUL ===\n";
    echo "Admin username: admin\n";
    echo "Admin password: {$adminPlain}\n";
    echo "\nYou can now log in with these credentials.\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

// Verify everything was created successfully
try {
    // Check admin user
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE username = 'admin'");
    $stmt->execute();
    
    if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "✓ Admin user verified (ID: {$user['id']})\n";
        
        // Check wallet
        $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        
        if ($wallet = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "✓ Admin wallet verified (Balance: $" . number_format($wallet['balance'], 2) . ")\n";
        } else {
            echo "⚠ Warning: Admin wallet not found\n";
        }
        
        // Test password
        if (password_verify($adminPlain, $adminHash)) {
            echo "✓ Password verification successful\n";
        } else {
            echo "⚠ Warning: Password verification failed\n";
        }
        
        // Check packages
        $stmt = $pdo->query("SELECT COUNT(*) FROM packages");
        $packageCount = $stmt->fetchColumn();
        echo "✓ Packages loaded: $packageCount\n";
        
    } else {
        echo "⚠ Warning: Admin user not found\n";
    }
    
} catch (PDOException $e) {
    echo "Note: Could not verify setup: " . $e->getMessage() . "\n";
}

echo "\n=== READY TO USE ===\n";
?>