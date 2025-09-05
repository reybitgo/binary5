<?php
// god_mode.php - Admin god mode to view any user's dashboard
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    redirect('login.php');
}

$adminId = $_SESSION['user_id'];

// Admin check
try {
    $stmt = $pdo->prepare("SELECT role, username FROM users WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || $admin['role'] !== 'admin') {
        http_response_code(403);
        die('<div class="error">Admin access only</div>');
    }
} catch (PDOException $e) {
    error_log("Admin check error: " . $e->getMessage());
    die('<div class="error">Database error</div>');
}

// Handle god mode access request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'god_mode_access') {
    
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        showError('Invalid CSRF token', 'Security violation detected. This action has been logged.');
        exit;
    }
    
    $targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $preserveParams = isset($_POST['preserve_params']) ? $_POST['preserve_params'] : '';
    
    if ($targetUserId <= 0) {
        http_response_code(400);
        showError('Invalid user ID', 'The requested user ID is invalid.');
        exit;
    }
    
    // Prevent admin from god-moding into another admin account
    if ($targetUserId === $adminId) {
        http_response_code(400);
        showError('Cannot Access Own Account', 'You cannot use god mode on your own account.');
        exit;
    }
    
    try {
        // Get target user info
        $stmt = $pdo->prepare("SELECT id, username, role, status FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$targetUser) {
            http_response_code(404);
            showError('User Not Found', 'The requested user does not exist.');
            exit;
        }
        
        // Additional security: Prevent god mode access to other admin accounts
        if ($targetUser['role'] === 'admin') {
            http_response_code(403);
            showError('Cannot Access Admin Account', 'God mode cannot be used to access other administrator accounts for security reasons.');
            exit;
        }
        
        // Log the god mode access
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logDetails = "Admin {$admin['username']} accessed god mode for user {$targetUser['username']} (ID: $targetUserId)";
        if ($preserveParams) {
            $logDetails .= " with preserved params: $preserveParams";
        }
        $logStmt->execute([$adminId, 'god_mode_access', $logDetails, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
        
        // Store god mode session data
        $_SESSION['god_mode'] = [
            'active' => true,
            'admin_id' => $adminId,
            'admin_username' => $admin['username'],
            'target_user_id' => $targetUserId,
            'target_username' => $targetUser['username'],
            'started_at' => time()
        ];
        
        // Temporarily override user session for dashboard access
        $originalUserId = $_SESSION['user_id'];
        $_SESSION['user_id'] = $targetUserId;
        $_SESSION['original_admin_id'] = $originalUserId;
        
        // Build redirect URL
        $redirectUrl = 'dashboard.php';
        
        // Parse and preserve any additional parameters (like affiliate links)
        if ($preserveParams) {
            parse_str($preserveParams, $params);
            
            // Remove admin-specific params that shouldn't be preserved
            unset($params['page']); // We'll use overview as default for god mode
            unset($params['csrf_token']);
            unset($params['p']); // Remove pagination
            unset($params['per_page']); // Remove pagination settings
            unset($params['sort']); // Remove sorting
            unset($params['order']); // Remove sort order
            unset($params['q']); // Remove search query
            unset($params['role']); // Remove role filter
            unset($params['status']); // Remove status filter
            
            // Build query string if there are params to preserve (like affiliate links)
            if (!empty($params)) {
                $redirectUrl .= '?' . http_build_query($params);
            }
        }
        
        // Add page parameter for default view
        $separator = strpos($redirectUrl, '?') === false ? '?' : '&';
        $redirectUrl .= $separator . 'page=overview';
        
        // Redirect to dashboard with preserved parameters
        redirect($redirectUrl, "God mode activated for user: {$targetUser['username']}");
        
    } catch (PDOException $e) {
        error_log("God mode access error: " . $e->getMessage());
        http_response_code(500);
        showError('Database Error', 'A database error occurred while processing the request.');
        exit;
    }
}

// Function to display error page
function showError($title, $message, $showReturnButton = true) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>God Mode - <?= htmlspecialchars($title) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-lg max-w-md w-full">
            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 text-red-600">
                    <svg class="w-full h-full" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-4"><?= htmlspecialchars($title) ?></h1>
                <p class="text-gray-600 mb-6"><?= htmlspecialchars($message) ?></p>
                
                <?php if ($showReturnButton): ?>
                <div class="flex space-x-3 justify-center">
                    <a href="dashboard.php?page=users" 
                       class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                        Return to User Management
                    </a>
                    <a href="dashboard.php" 
                       class="bg-gray-600 text-white px-6 py-2 rounded-lg hover:bg-gray-700 transition-colors">
                        Dashboard
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="mt-4 pt-4 border-t border-gray-200 text-xs text-gray-500">
                    <p>Security Notice: All god mode access attempts are logged for audit purposes.</p>
                </div>
            </div>
        </div>
        
        <!-- Auto-close after 10 seconds if opened in new tab -->
        <script>
        setTimeout(function() {
            if (window.opener && !window.opener.closed) {
                window.close();
            }
        }, 10000);
        
        // Close window if ESC is pressed
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && window.opener && !window.opener.closed) {
                window.close();
            }
        });
        </script>
    </body>
    </html>
    <?php
}

// If we get here without POST, show error
http_response_code(405);
showError('Method Not Allowed', 'God mode access must be initiated from the user management interface.');
?>