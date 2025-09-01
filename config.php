<?php
// config.php - Configuration settings and DB connection
date_default_timezone_set('Asia/Manila');

if (!ob_get_level()) ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- Binary commission settings ---------- */
// define('DAILY_MAX',     10);
// define('PAIR_RATE',     0.20);
// define('REFERRAL_RATE', 0.10);
// define('LEADERSHIP_RATE', 0.05);
// define('LEADERSHIP_REVERSE_RATE', 0.05);

define('B2P_CONTRACT', '0xf8ab9ff465c612d5be6a56716adf95c52f8bc72d');
define('USDT_B2P_RATE', 1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'binary5_db_test');
define('DB_USER', 'root');
define('DB_PASS', '');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Set MySQL timezone to Asia/Manila
$pdo->exec("SET time_zone = '+08:00'");

// Simple helper
function redirect($url, $msg = null)
{
    if ($msg) $_SESSION['flash'] = $msg;
    header("Location: $url");
    exit;
}

function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken(string $token): bool {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>