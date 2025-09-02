<?php
// debug_users.php - Diagnostic script to identify user fetch issues
require_once 'config.php';
require_once 'functions.php';

echo "<h2>Database Debugging for Users Table</h2>";

// Test 1: Check if $uid variable exists
echo "<h3>1. Session Check</h3>";
if (!isset($_SESSION['user_id'])) {
    echo "❌ No user_id in session<br>";
    echo "Session contents: " . print_r($_SESSION, true) . "<br>";
} else {
    $uid = $_SESSION['user_id'];
    echo "✅ user_id found: " . $uid . "<br>";
}

// Test 2: Check admin role
echo "<h3>2. Admin Role Check</h3>";
try {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✅ User found: " . print_r($user, true) . "<br>";
        if ($user['role'] === 'admin') {
            echo "✅ User has admin role<br>";
        } else {
            echo "❌ User role is: " . $user['role'] . " (not admin)<br>";
        }
    } else {
        echo "❌ No user found with ID: " . $uid . "<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error checking user role: " . $e->getMessage() . "<br>";
}

// Test 3: Check users table structure
echo "<h3>3. Users Table Structure</h3>";
try {
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ Users table columns:<br>";
    foreach ($columns as $col) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error describing users table: " . $e->getMessage() . "<br>";
}

// Test 4: Check if users exist
echo "<h3>4. User Count Check</h3>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
    $count = $stmt->fetchColumn();
    echo "✅ Total users in database: " . $count . "<br>";
    
    if ($count > 0) {
        // Show first few users
        $stmt = $pdo->query("SELECT id, username, role, position, created_at FROM users LIMIT 5");
        $sampleUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Sample users:<br>";
        foreach ($sampleUsers as $user) {
            echo "- ID: {$user['id']}, Username: {$user['username']}, Role: {$user['role']}<br>";
        }
    }
} catch (PDOException $e) {
    echo "❌ Error counting users: " . $e->getMessage() . "<br>";
}

// Test 5: Test the specific query from users.php
echo "<h3>5. Test Users Query</h3>";
try {
    $sql = "
        SELECT 
            u.id,
            u.username,
            u.position,
            u.role,
            u.status,
            u.created_at,
            s.username AS sponsor_username,
            up.username AS upline_username
        FROM users u
        LEFT JOIN users s ON s.id = u.sponsor_id
        LEFT JOIN users up ON up.id = u.upline_id
        ORDER BY u.id ASC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $testUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Query executed successfully<br>";
    echo "Retrieved " . count($testUsers) . " users<br>";
    
    if (!empty($testUsers)) {
        echo "First user data:<br>";
        echo "<pre>" . print_r($testUsers[0], true) . "</pre>";
    }
    
} catch (PDOException $e) {
    echo "❌ Error with users query: " . $e->getMessage() . "<br>";
    echo "SQL State: " . $e->getCode() . "<br>";
    
    // Try simpler query
    try {
        echo "Trying simpler query...<br>";
        $simpleStmt = $pdo->query("SELECT id, username FROM users LIMIT 5");
        $simpleUsers = $simpleStmt->fetchAll(PDO::FETCH_ASSOC);
        echo "✅ Simple query works. Users: " . count($simpleUsers) . "<br>";
    } catch (PDOException $e2) {
        echo "❌ Even simple query failed: " . $e2->getMessage() . "<br>";
    }
}

// Test 6: Check database connection
echo "<h3>6. Database Connection Test</h3>";
try {
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "✅ Database connected. MySQL version: " . $version . "<br>";
    
    $charset = $pdo->query("SELECT @@character_set_database")->fetchColumn();
    echo "✅ Database charset: " . $charset . "<br>";
    
} catch (PDOException $e) {
    echo "❌ Database connection issue: " . $e->getMessage() . "<br>";
}

// Test 7: Check for specific error patterns
echo "<h3>7. Common Issues Check</h3>";

// Check if status column exists (it might be missing)
try {
    $pdo->query("SELECT status FROM users LIMIT 1");
    echo "✅ Status column exists<br>";
} catch (PDOException $e) {
    echo "❌ Status column missing - this might be the issue!<br>";
    echo "You may need to add: ALTER TABLE users ADD COLUMN status ENUM('active', 'inactive', 'suspended') DEFAULT 'active';<br>";
}

// Check for foreign key issues
try {
    $stmt = $pdo->query("
        SELECT u.id, u.sponsor_id, u.upline_id, 
               s.id as sponsor_exists, 
               up.id as upline_exists
        FROM users u
        LEFT JOIN users s ON s.id = u.sponsor_id
        LEFT JOIN users up ON up.id = u.upline_id
        WHERE (u.sponsor_id IS NOT NULL AND s.id IS NULL) 
           OR (u.upline_id IS NOT NULL AND up.id IS NULL)
        LIMIT 5
    ");
    $orphaned = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($orphaned)) {
        echo "✅ No orphaned sponsor/upline references<br>";
    } else {
        echo "❌ Found orphaned references:<br>";
        foreach ($orphaned as $user) {
            echo "- User ID {$user['id']} has invalid sponsor_id or upline_id<br>";
        }
    }
} catch (PDOException $e) {
    echo "❌ Error checking foreign keys: " . $e->getMessage() . "<br>";
}

echo "<h3>Recommendations:</h3>";
echo "<ul>";
echo "<li>Check the error log for the exact SQL error message</li>";
echo "<li>Ensure the 'status' column exists in your users table</li>";
echo "<li>Verify all foreign key relationships are valid</li>";
echo "<li>Make sure your user has admin role to access this page</li>";
echo "<li>Check if the session variable name matches (\$_SESSION['user_id'] vs \$uid)</li>";
echo "</ul>";
?>