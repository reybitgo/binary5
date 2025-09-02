<?php
// ajax/check_upline_positions.php - Check available positions under an upline
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$uplineUsername = trim($_POST['upline_username'] ?? '');

if (empty($uplineUsername)) {
    echo json_encode(['success' => false, 'error' => 'Upline username is required']);
    exit;
}

try {
    // Get upline user ID
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$uplineUsername]);
    $uplineUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$uplineUser) {
        echo json_encode(['success' => false, 'error' => 'Upline username not found']);
        exit;
    }
    
    $uplineId = $uplineUser['id'];
    
    // Check occupied positions
    $stmt = $pdo->prepare("
        SELECT position, COUNT(*) as count
        FROM users 
        WHERE upline_id = ? AND position IN ('left', 'right')
        GROUP BY position
    ");
    $stmt->execute([$uplineId]);
    $occupiedPositions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $leftAvailable = !isset($occupiedPositions['left']) || $occupiedPositions['left'] == 0;
    $rightAvailable = !isset($occupiedPositions['right']) || $occupiedPositions['right'] == 0;
    
    echo json_encode([
        'success' => true,
        'upline_id' => $uplineId,
        'positions' => [
            'left_available' => $leftAvailable,
            'right_available' => $rightAvailable
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Position check error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>