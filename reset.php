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
    echo "<pre>Using hardcoded password for development...</pre>";
} else {
    $adminPlain = bin2hex(random_bytes(6)); // 12-char random hex string
    echo "<pre>Generated random password...</pre>";
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

echo "<pre>Reading clean schema.sql...</pre>";

try {
    // Connect to MySQL server
    $dsn = 'mysql:host=localhost;charset=utf8mb4';
    $pdo = new PDO($dsn, 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    echo "<pre>Executing database schema...</pre>";
    
    // Execute the entire SQL file
    $pdo->exec($sql);
    
    echo "<pre>Schema created successfully!</pre>";
    
    // Switch to the new database
    $pdo->exec("USE binary5_db");
    
    // Create admin user
    echo "<pre>Creating admin user...</pre>";
    $stmt = $pdo->prepare("INSERT INTO users (username, password, sponsor_name, upline_id, position) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(['admin', $adminHash, 'root', null, null]);
    
    $adminId = $pdo->lastInsertId();
    echo "<pre>Admin user created with ID: $adminId</pre>";
    
    // Create wallet for admin user
    $stmt = $pdo->prepare("INSERT INTO wallets (user_id, balance) VALUES (?, ?)");
    $stmt->execute([$adminId, 0.00]);
    echo "<pre>Admin wallet created...</pre>";
    
    echo "<pre>=== DATABASE RESET SUCCESSFUL ===</pre>";
    echo "<pre>Admin username: admin</pre>";
    echo "<pre>Admin password: {$adminPlain}</pre>";
    echo "<pre>You can now log in with these credentials.</pre>";
    
} catch (PDOException $e) {
    echo "<pre>Database Error: " . $e->getMessage() . "</pre>";
    exit(1);
} catch (Exception $e) {
    echo "<pre>Error: " . $e->getMessage() . "</pre>";
    exit(1);
}

// Verify everything was created successfully
try {
    // Check admin user
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE username = 'admin'");
    $stmt->execute();
    
    if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<pre>✓ Admin user verified (ID: {$user['id']})</pre>";
        
        // Check wallet
        $stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        
        if ($wallet = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<pre>✓ Admin wallet verified (Balance: $" . number_format($wallet['balance'], 2) . ")</pre>";
        } else {
            echo "<pre>⚠ Warning: Admin wallet not found</pre>";
        }
        
        // Test password
        if (password_verify($adminPlain, $adminHash)) {
            echo "<pre>✓ Password verification successful</pre>";
        } else {
            echo "<pre>⚠ Warning: Password verification failed</pre>";
        }
        
        // Check packages
        $stmt = $pdo->query("SELECT COUNT(*) FROM packages");
        $packageCount = $stmt->fetchColumn();
        echo "<pre>✓ Packages loaded: $packageCount</pre>";
        
    } else {
        echo "<pre>⚠ Warning: Admin user not found</pre>";
    }
    
} catch (PDOException $e) {
    echo "<pre>Note: Could not verify setup: " . $e->getMessage() . "</pre>";
}

echo "<pre>=== READY TO USE ===</pre>";
?>