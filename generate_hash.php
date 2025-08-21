<?php
// Password Hash Generator
// generate_hash.php - Use this to generate password hashes manually

// Set the password you want to hash
$password = "admin123"; // Change this to your desired password

// Generate the hash
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Password Hash Generator</h2>";
echo "<p><strong>Original Password:</strong> " . htmlspecialchars($password) . "</p>";
echo "<p><strong>Generated Hash:</strong> " . htmlspecialchars($hash) . "</p>";

echo "<h3>SQL Update Commands:</h3>";
echo "<p><strong>For admin user:</strong></p>";
echo "<code>UPDATE users SET password = '" . $hash . "' WHERE username = 'admin';</code>";

echo "<p><strong>For any other user (replace 'username'):</strong></p>";
echo "<code>UPDATE users SET password = '" . $hash . "' WHERE username = 'your_username_here';</code>";

// Test the hash
echo "<h3>Hash Verification Test:</h3>";
if (password_verify($password, $hash)) {
    echo "<p style='color: green;'>✓ Hash verification successful!</p>";
} else {
    echo "<p style='color: red;'>✗ Hash verification failed!</p>";
}

// Generate a few different passwords for testing
echo "<h3>Additional Test Passwords:</h3>";
$test_passwords = ['admin123', 'password123', 'test123', 'test1234'];

foreach ($test_passwords as $test_pwd) {
    $test_hash = password_hash($test_pwd, PASSWORD_DEFAULT);
    echo "<p><strong>Password:</strong> $test_pwd</p>";
    echo "<p><strong>Hash:</strong> $test_hash</p>";
    echo "<p><strong>SQL:</strong> <code>UPDATE users SET password = '$test_hash' WHERE username = 'admin';</code></p>";
    echo "<hr>";
}
?>

<style>
    body {
        font-family: Arial, sans-serif;
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
        background: #f5f5f5;
    }

    code {
        background: #e8e8e8;
        padding: 10px;
        display: block;
        margin: 10px 0;
        border-radius: 4px;
        word-break: break-all;
    }

    hr {
        margin: 20px 0;
    }
</style>