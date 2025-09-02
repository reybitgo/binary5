<?php
// ajax/check_username.php - Check username availability
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$username = trim($_POST['username'] ?? '');

if (empty($username)) {
    echo json_encode(['available' => false, 'error' => 'Username is required']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
    echo json_encode(['available' => false, 'error' => 'Invalid username format']);
    exit;
}

if (strlen($username) < 3 || strlen($username) > 30) {
    echo json_encode(['available' => false, 'error' => 'Username must be 3-30 characters']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $exists = $stmt->fetch();
    
    echo json_encode(['available' => !$exists]);
    
} catch (PDOException $e) {
    error_log("Username check error: " . $e->getMessage());
    echo json_encode(['available' => false, 'error' => 'Database error']);
}
?>