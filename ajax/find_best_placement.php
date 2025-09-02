<?php
// ajax/find_best_placement.php - Find optimal placement for a sponsor
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$sponsorId = filter_var($_POST['sponsor_id'] ?? 0, FILTER_VALIDATE_INT);

if (!$sponsorId || $sponsorId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid sponsor ID']);
    exit;
}

try {
    // Verify sponsor exists
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$sponsorId]);
    $sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sponsor) {
        echo json_encode(['success' => false, 'error' => 'Sponsor not found or inactive']);
        exit;
    }
    
    // Find best placement using breadth-first search
    $placement = findOptimalPlacement($sponsorId, $pdo);
    
    if ($placement) {
        echo json_encode([
            'success' => true,
            'placement' => $placement
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No placement found']);
    }
    
} catch (PDOException $e) {
    error_log("Placement finding error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

function findOptimalPlacement($sponsorId, $pdo) {
    $queue = [$sponsorId];
    $visited = [];
    $maxDepth = 10;
    $currentDepth = 0;
    
    while (!empty($queue) && $currentDepth < $maxDepth) {
        $levelSize = count($queue);
        
        for ($i = 0; $i < $levelSize; $i++) {
            $currentUserId = array_shift($queue);
            
            if (in_array($currentUserId, $visited)) continue;
            $visited[] = $currentUserId;
            
            // Check available positions
            $stmt = $pdo->prepare("
                SELECT username,
                       (SELECT COUNT(*) FROM users WHERE upline_id = ? AND position = 'left') as left_count,
                       (SELECT COUNT(*) FROM users WHERE upline_id = ? AND position = 'right') as right_count
                FROM users WHERE id = ?
            ");
            $stmt->execute([$currentUserId, $currentUserId, $currentUserId]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userInfo) {
                // Return first available position (prefer left)
                if ($userInfo['left_count'] == 0) {
                    return [
                        'upline_id' => $currentUserId,
                        'upline_username' => $userInfo['username'],
                        'position' => 'left'
                    ];
                } elseif ($userInfo['right_count'] == 0) {
                    return [
                        'upline_id' => $currentUserId,
                        'upline_username' => $userInfo['username'],
                        'position' => 'right'
                    ];
                }
                
                // Add children to queue
                $childStmt = $pdo->prepare("SELECT id FROM users WHERE upline_id = ?");
                $childStmt->execute([$currentUserId]);
                while ($child = $childStmt->fetch(PDO::FETCH_ASSOC)) {
                    $queue[] = $child['id'];
                }
            }
        }
        $currentDepth++;
    }
    
    return null;
}
?>