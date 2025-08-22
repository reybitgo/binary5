<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- Binary commission settings ---------- */
define('DAILY_MAX',     10);   // max pairs counted per user per day
define('PAIR_RATE',     0.20); // 20% of the daily pair value
define('REFERRAL_RATE', 0.10); // 10% direct referral commission from package price
define('LEADERSHIP_RATE', 0.05);   // 5 % of every binary pair earned by 1-5 level indirects
define('LEADERSHIP_REVERSE_RATE', 0.05);   // 5 % of every binary pair earned by 1-5 level indirects

define('B2P_CONTRACT', '0xf8ab9ff465c612d5be6a56716adf95c52f8bc72d');
define('USDT_B2P_RATE', 1); // 1 USDT = 1 B2P (change if market differs)

define('DB_HOST', 'localhost');
define('DB_NAME', 'binary5_db');
define('DB_USER', 'root');
define('DB_PASS', '');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Simple helper
function redirect($url, $msg = null)
{
    if ($msg) $_SESSION['flash'] = $msg;
    header("Location: $url");
    exit;
}