<?php
// pages/users.php - Admin-only user monitoring with enhanced pagination, search, god mode, and suspend actions
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    redirect('login.php');
}

$uid = $_SESSION['user_id'];

// Admin check
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $role = $stmt->fetchColumn();
    
    if ($role !== 'admin') {
        http_response_code(403);
        redirect('dashboard.php', 'Admin access only');
    }
} catch (PDOException $e) {
    error_log("Admin check error: " . $e->getMessage());
    redirect('dashboard.php', 'Database error');
}

// Handle suspend/unsuspend actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['flash'] = 'Invalid CSRF token';
        redirect('dashboard.php?page=users');
    }
    
    $targetUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $action = $_POST['action'];
    
    if ($targetUserId > 0 && in_array($action, ['suspend', 'unsuspend'])) {
        try {
            // Get target user info
            $stmt = $pdo->prepare("SELECT username, role, status FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch();
            
            if (!$targetUser) {
                $_SESSION['flash'] = 'User not found';
            } elseif ($targetUser['role'] === 'admin') {
                $_SESSION['flash'] = 'Cannot suspend admin accounts';
            } elseif ($targetUserId === $uid) {
                $_SESSION['flash'] = 'Cannot suspend your own account';
            } else {
                // Perform the action
                $newStatus = ($action === 'suspend') ? 'suspended' : 'active';
                
                $updateStmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $updateStmt->execute([$newStatus, $targetUserId]);
                
                // Log the action
                $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
                $logDetails = "Admin suspended/unsuspended user {$targetUser['username']} (ID: $targetUserId) - Status changed to: $newStatus";
                $logStmt->execute([$uid, 'user_status_change', $logDetails, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
                
                $actionText = ($action === 'suspend') ? 'suspended' : 'reactivated';
                $_SESSION['flash'] = "User {$targetUser['username']} has been {$actionText}";
            }
        } catch (PDOException $e) {
            error_log("User status change error: " . $e->getMessage());
            $_SESSION['flash'] = 'Error updating user status';
        }
    }
    
    redirect('dashboard.php?page=users');
}

// Pagination configuration with proper null coalescing
$perPageOptions = [10, 25, 50, 100];
$requestedPerPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$perPage = in_array($requestedPerPage, $perPageOptions) ? $requestedPerPage : 10;
$requestedPage = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$currentPage = max(1, filter_var($requestedPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1);
$offset = ($currentPage - 1) * $perPage;

// Search and filters with proper null coalescing
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$roleFilter = isset($_GET['role']) ? trim($_GET['role']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$sortBy = isset($_GET['sort']) && in_array($_GET['sort'], ['id', 'username', 'created_at', 'role', 'status']) ? $_GET['sort'] : 'id';
$sortOrder = isset($_GET['order']) && in_array($_GET['order'], ['asc', 'desc']) ? $_GET['order'] : 'asc';

// Build WHERE clause
$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = '(u.username LIKE ? OR u.id = ?)';
    $params[] = "%$search%";
    $params[] = filter_var($search, FILTER_VALIDATE_INT) ?: 0;
}

if ($roleFilter !== '') {
    $whereClauses[] = 'u.role = ?';
    $params[] = $roleFilter;
}

if ($statusFilter !== '') {
    $whereClauses[] = 'u.status = ?';
    $params[] = $statusFilter;
}

$whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Count total users for pagination
$countSql = "SELECT COUNT(*) FROM users u $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalUsers / $perPage));

// Ensure current page doesn't exceed total pages
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset = ($currentPage - 1) * $perPage;
}

// Validate sort column to prevent SQL injection
$validSortColumns = ['id', 'username', 'created_at', 'role', 'status'];
if (!in_array($sortBy, $validSortColumns)) {
    $sortBy = 'id';
}

// Fetch users with sorting
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
    $whereClause
    ORDER BY u.`$sortBy` $sortOrder
    LIMIT ? OFFSET ?
";

try {
    $stmt = $pdo->prepare($sql);
    $paramIndex = 1;
    
    // Bind search/filter parameters
    foreach ($params as $param) {
        $stmt->bindValue($paramIndex++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    
    // Bind pagination parameters
    $stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User fetch error: " . $e->getMessage() . " | SQL: " . $sql . " | Params: " . json_encode($params));
    
    // Fallback to basic query without sorting if there's an issue
    try {
        $basicSql = "SELECT u.id, u.username, u.position, u.role, u.status, u.created_at, 
                            s.username AS sponsor_username, up.username AS upline_username
                     FROM users u
                     LEFT JOIN users s ON s.id = u.sponsor_id
                     LEFT JOIN users up ON up.id = u.upline_id
                     ORDER BY u.id ASC
                     LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($basicSql);
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Reset filters if they caused the error
        $search = $roleFilter = $statusFilter = '';
        $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $totalPages = max(1, ceil($totalUsers / $perPage));
        
        $_SESSION['flash'] = 'Filters reset due to database error. Showing all users.';
    } catch (PDOException $e2) {
        error_log("Fallback query also failed: " . $e2->getMessage());
        redirect('dashboard.php', 'Database error - please try again');
    }
}

// Get available roles and statuses for filters
try {
    $rolesStmt = $pdo->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL ORDER BY role");
    $availableRoles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $statusesStmt = $pdo->query("SELECT DISTINCT status FROM users WHERE status IS NOT NULL ORDER BY status");
    $availableStatuses = $statusesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Filter options fetch error: " . $e->getMessage());
    $availableRoles = ['user', 'admin']; // Fallback values
    $availableStatuses = ['active', 'inactive', 'suspended']; // Fallback values
}

// Helper function to generate sort URL
function getSortUrl($column, $currentSort, $currentOrder) {
    $newOrder = ($currentSort === $column && $currentOrder === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    $params['page'] = 'users'; // Ensure page parameter is maintained
    return 'dashboard.php?' . http_build_query($params);
}

// Helper function to generate pagination URL
function getPaginationUrl($page) {
    $params = $_GET;
    $params['p'] = $page;
    $params['page'] = 'users'; // Ensure page parameter is maintained
    return 'dashboard.php?' . http_build_query($params);
}

// Calculate pagination range
function getPaginationRange($currentPage, $totalPages, $maxLinks = 7) {
    if ($totalPages <= $maxLinks) {
        return range(1, $totalPages);
    }
    
    $half = floor($maxLinks / 2);
    $start = max(1, $currentPage - $half);
    $end = min($totalPages, $start + $maxLinks - 1);
    
    // Adjust start if we're near the end
    if ($end - $start + 1 < $maxLinks) {
        $start = max(1, $end - $maxLinks + 1);
    }
    
    return range($start, $end);
}
?>

<div class="bg-white shadow rounded-lg p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-xl font-bold">User Management</h2>
            <p class="text-gray-600">View and monitor all users in the system</p>
        </div>
        <div class="text-sm text-gray-500">
            Showing <?= number_format(count($users)) ?> of <?= number_format($totalUsers) ?> users
        </div>
    </div>

    <!-- Filters and Search -->
    <form method="get" action="dashboard.php" class="mb-6">
        <input type="hidden" name="page" value="users">
        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
            <!-- Search -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Username or ID" 
                       class="border rounded-lg p-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <!-- Role Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select name="role" class="border rounded-lg p-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Roles</option>
                    <?php foreach ($availableRoles as $r): ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($r)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Status Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="border rounded-lg p-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Statuses</option>
                    <?php foreach ($availableStatuses as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Per Page -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Per Page</label>
                <select name="per_page" class="border rounded-lg p-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <?php foreach ($perPageOptions as $option): ?>
                        <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                            <?= $option ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="flex gap-2">
            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition-colors">
                Apply Filters
            </button>
            <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
                <a href="dashboard.php?page=users" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition-colors">
                    Clear All
                </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Users Table -->
    <?php if (empty($users)): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-blue-800">
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                </svg>
                No users found matching your criteria.
            </div>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto bg-white border rounded-lg" id="users-table">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-3 text-left">
                            <a href="<?= getSortUrl('id', $sortBy, $sortOrder) ?>" class="text-gray-600 hover:text-gray-900 font-medium flex items-center">
                                ID
                                <?php if ($sortBy === 'id'): ?>
                                    <span class="ml-1"><?= $sortOrder === 'asc' ? '↑' : '↓' ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="p-3 text-left">
                            <a href="<?= getSortUrl('username', $sortBy, $sortOrder) ?>" class="text-gray-600 hover:text-gray-900 font-medium flex items-center">
                                Username
                                <?php if ($sortBy === 'username'): ?>
                                    <span class="ml-1"><?= $sortOrder === 'asc' ? '↑' : '↓' ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="p-3 text-left text-gray-600 font-medium">Sponsor</th>
                        <th class="p-3 text-left text-gray-600 font-medium">Upline</th>
                        <th class="p-3 text-left text-gray-600 font-medium">Position</th>
                        <th class="p-3 text-left">
                            <a href="<?= getSortUrl('status', $sortBy, $sortOrder) ?>" class="text-gray-600 hover:text-gray-900 font-medium flex items-center">
                                Status
                                <?php if ($sortBy === 'status'): ?>
                                    <span class="ml-1"><?= $sortOrder === 'asc' ? '↑' : '↓' ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="p-3 text-left">
                            <a href="<?= getSortUrl('role', $sortBy, $sortOrder) ?>" class="text-gray-600 hover:text-gray-900 font-medium flex items-center">
                                Role
                                <?php if ($sortBy === 'role'): ?>
                                    <span class="ml-1"><?= $sortOrder === 'asc' ? '↑' : '↓' ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="p-3 text-left">
                            <a href="<?= getSortUrl('created_at', $sortBy, $sortOrder) ?>" class="text-gray-600 hover:text-gray-900 font-medium flex items-center">
                                Created
                                <?php if ($sortBy === 'created_at'): ?>
                                    <span class="ml-1"><?= $sortOrder === 'asc' ? '↑' : '↓' ?></span>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="p-3 text-left text-gray-600 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="p-3 font-mono text-sm"><?= htmlspecialchars($u['id']) ?></td>
                            <td class="p-3 font-medium"><?= htmlspecialchars($u['username']) ?></td>
                            <td class="p-3">
                                <?php if ($u['sponsor_username']): ?>
                                    <span class="text-blue-600"><?= htmlspecialchars($u['sponsor_username']) ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3">
                                <?php if ($u['upline_username']): ?>
                                    <span class="text-green-600"><?= htmlspecialchars($u['upline_username']) ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                    <?= ($u['position'] ?? 'root') === 'left' ? 'bg-blue-100 text-blue-800' : 
                                        (($u['position'] ?? 'root') === 'right' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800') ?>">
                                    <?= htmlspecialchars(ucfirst($u['position'] ?? 'Root')) ?>
                                </span>
                            </td>
                            <td class="p-3">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                    <?= ($u['status'] ?? 'active') === 'active' ? 'bg-green-100 text-green-800' : 
                                        (($u['status'] ?? 'active') === 'inactive' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                    <?= htmlspecialchars(ucfirst($u['status'] ?? 'Active')) ?>
                                </span>
                            </td>
                            <td class="p-3">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                    <?= $u['role'] === 'admin' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800' ?>">
                                    <?= htmlspecialchars(ucfirst($u['role'])) ?>
                                </span>
                            </td>
                            <td class="p-3 text-sm text-gray-600">
                                <?= date('M j, Y', strtotime($u['created_at'])) ?>
                                <div class="text-xs text-gray-400">
                                    <?= date('g:i A', strtotime($u['created_at'])) ?>
                                </div>
                            </td>
                            <td class="p-3">
                                <div class="flex items-center space-x-2">
                                    <!-- God Mode Eye Icon -->
                                    <?php if ($u['role'] !== 'admin' && $u['id'] !== $uid): ?>
                                        <form method="post" action="god_mode.php" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="action" value="god_mode_access">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="preserve_params" value="<?= htmlspecialchars(http_build_query($_GET)) ?>">
                                            <button type="submit" 
                                                    title="View user dashboard (God Mode)"
                                                    class="text-blue-600 hover:text-blue-800 p-1 rounded-full hover:bg-blue-50 transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-gray-300 p-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"></path>
                                            </svg>
                                        </span>
                                    <?php endif; ?>

                                    <!-- Suspend/Unsuspend Toggle -->
                                    <?php if ($u['role'] !== 'admin' && $u['id'] !== $uid): ?>
                                        <form method="post" class="inline" onsubmit="return confirmAction(this, '<?= htmlspecialchars($u['username']) ?>', '<?= ($u['status'] ?? 'active') === 'suspended' ? 'reactivate' : 'suspend' ?>')">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="action" value="<?= ($u['status'] ?? 'active') === 'suspended' ? 'unsuspend' : 'suspend' ?>">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" 
                                                    title="<?= ($u['status'] ?? 'active') === 'suspended' ? 'Reactivate user' : 'Suspend user' ?>"
                                                    class="<?= ($u['status'] ?? 'active') === 'suspended' ? 'text-green-600 hover:text-green-800 hover:bg-green-50' : 'text-red-600 hover:text-red-800 hover:bg-red-50' ?> p-1 rounded-full transition-colors">
                                                <?php if (($u['status'] ?? 'active') === 'suspended'): ?>
                                                    <!-- Unlock Icon -->
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path>
                                                    </svg>
                                                <?php else: ?>
                                                    <!-- Lock Icon -->
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                                    </svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-gray-300 p-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728L18.364 5.636M5.636 18.364l12.728-12.728"></path>
                                            </svg>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Enhanced Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-6 flex flex-col sm:flex-row justify-between items-center gap-4">
                <!-- Results Info -->
                <div class="text-sm text-gray-600">
                    Showing <?= number_format($offset + 1) ?>-<?= number_format(min($offset + $perPage, $totalUsers)) ?> 
                    of <?= number_format($totalUsers) ?> users
                </div>

                <!-- Pagination Controls -->
                <nav class="flex items-center space-x-1">
                    <!-- First Page -->
                    <?php if ($currentPage > 2): ?>
                        <a href="<?= getPaginationUrl(1) ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                            First
                        </a>
                    <?php endif; ?>

                    <!-- Previous Page -->
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= getPaginationUrl($currentPage - 1) ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors flex items-center">
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                            Previous
                        </a>
                    <?php endif; ?>

                    <!-- Page Numbers -->
                    <?php 
                    $pageRange = getPaginationRange($currentPage, $totalPages);
                    foreach ($pageRange as $page): 
                    ?>
                        <?php if ($page === $currentPage): ?>
                            <span class="px-3 py-2 text-sm bg-blue-500 text-white rounded-lg font-medium">
                                <?= $page ?>
                            </span>
                        <?php else: ?>
                            <a href="<?= getPaginationUrl($page) ?>" 
                               class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                                <?= $page ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- Next Page -->
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?= getPaginationUrl($currentPage + 1) ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors flex items-center">
                            Next
                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </a>
                    <?php endif; ?>

                    <!-- Last Page -->
                    <?php if ($currentPage < $totalPages - 1): ?>
                        <a href="<?= getPaginationUrl($totalPages) ?>" 
                           class="px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                            Last
                        </a>
                    <?php endif; ?>
                </nav>

                <!-- Quick Page Jump -->
                <?php if ($totalPages > 10): ?>
                    <div class="flex items-center space-x-2">
                        <span class="text-sm text-gray-600">Go to:</span>
                        <form method="get" action="dashboard.php" class="flex items-center space-x-2">
                            <input type="hidden" name="page" value="users">
                            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                            <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                            <input type="hidden" name="per_page" value="<?= $perPage ?>">
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>">
                            <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder) ?>">
                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                            <input type="number" name="p" min="1" max="<?= $totalPages ?>" value="<?= $currentPage ?>" 
                                   class="w-16 px-2 py-1 text-sm border rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <button type="submit" class="px-2 py-1 text-sm bg-gray-200 rounded hover:bg-gray-300 transition-colors">
                                Go
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Export Options -->
        <div class="mt-4 pt-4 border-t flex justify-between items-center">
            <div class="text-sm text-gray-500">
                <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
                    Filtered results
                <?php else: ?>
                    All users
                <?php endif; ?>
            </div>
            
            <div class="flex space-x-2">
                <button onclick="printTable()" class="px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    Print Table
                </button>
                <a href="export_users.php?<?= http_build_query(array_filter(['q' => $search, 'role' => $roleFilter, 'status' => $statusFilter])) ?>" 
                   class="px-3 py-2 text-sm bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors">
                    Export CSV
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
@media print {
    /* Hide everything by default */
    body * { visibility: hidden; }
    
    /* Show only the print content */
    .print-content, .print-content * { visibility: visible; }
    .print-content { position: absolute; top: 0; left: 0; width: 100%; }
    
    /* Hide non-essential elements */
    .no-print, nav, .pagination, button, .export-options, .actions-column { display: none !important; }
    
    /* Table styling for print */
    table { font-size: 11px; width: 100%; border-collapse: collapse; }
    th, td { padding: 4px 6px; border: 1px solid #ddd; }
    th { background-color: #f5f5f5 !important; font-weight: bold; }
    
    /* Page settings */
    @page { margin: 0.5in; size: landscape; }
    
    /* Ensure table fits on page */
    .overflow-x-auto { overflow: visible !important; }
}
</style>

<script>
function confirmAction(form, username, action) {
    const actionText = action === 'suspend' ? 'suspend' : 'reactivate';
    const message = `Are you sure you want to ${actionText} user "${username}"?`;
    
    if (action === 'suspend') {
        return confirm(message + '\n\nThis will prevent them from logging in until reactivated.');
    } else {
        return confirm(message + '\n\nThis will allow them to log in again.');
    }
}

function printTable() {
    // Create a new window for printing
    const printWindow = window.open('', '_blank', 'width=1200,height=800');
    
    // Get current filters for the header
    const search = '<?= addslashes($search) ?>';
    const roleFilter = '<?= addslashes($roleFilter) ?>';
    const statusFilter = '<?= addslashes($statusFilter) ?>';
    
    // Build filter info
    let filterInfo = '';
    if (search || roleFilter || statusFilter) {
        filterInfo = '<p><strong>Filters Applied:</strong> ';
        const filters = [];
        if (search) filters.push(`Search: "${search}"`);
        if (roleFilter) filters.push(`Role: ${roleFilter}`);
        if (statusFilter) filters.push(`Status: ${statusFilter}`);
        filterInfo += filters.join(', ') + '</p>';
    }
    
    // Get the table HTML and remove Actions column
    const table = document.querySelector('#users-table table').cloneNode(true);
    // Remove Actions column header
    const actionHeader = table.querySelector('thead th:last-child');
    if (actionHeader) actionHeader.remove();
    // Remove Actions column cells
    const actionCells = table.querySelectorAll('tbody td:last-child');
    actionCells.forEach(cell => cell.remove());
    
    const tableHtml = table.outerHTML;
    
    // Create the print document
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Users Export - ${new Date().toLocaleDateString()}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #333; margin-bottom: 10px; }
                .header-info { margin-bottom: 20px; font-size: 12px; color: #666; }
                table { width: 100%; border-collapse: collapse; font-size: 11px; }
                th, td { padding: 6px 8px; border: 1px solid #ddd; text-align: left; }
                th { background-color: #f5f5f5; font-weight: bold; }
                tr:nth-child(even) { background-color: #f9f9f9; }
                .text-center { text-align: center; }
                .font-mono { font-family: monospace; }
                .inline-flex { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
                .bg-blue-100 { background-color: #dbeafe; color: #1e40af; }
                .bg-green-100 { background-color: #dcfce7; color: #166534; }
                .bg-red-100 { background-color: #fee2e2; color: #991b1b; }
                .bg-yellow-100 { background-color: #fef3c7; color: #92400e; }
                .bg-gray-100 { background-color: #f3f4f6; color: #374151; }
                @page { size: landscape; margin: 0.5in; }
                .summary { margin-top: 20px; font-size: 12px; }
                /* Remove sort arrows and links for print */
                a { text-decoration: none; color: inherit; }
                span.ml-1 { display: none; }
            </style>
        </head>
        <body>
            <h1>User Management Report</h1>
            <div class="header-info">
                <p><strong>Generated:</strong> ${new Date().toLocaleString()}</p>
                <p><strong>Total Users:</strong> <?= number_format($totalUsers) ?></p>
                <p><strong>Showing:</strong> <?= number_format(count($users)) ?> users</p>
                ${filterInfo}
            </div>
            
            <div class="table-container">
                ${tableHtml}
            </div>
            
            <div class="summary">
                <p><strong>Report Summary:</strong></p>
                <ul>
                    <li>Total users in system: <?= number_format($totalUsers) ?></li>
                    <li>Users shown in this report: <?= number_format(count($users)) ?></li>
                    <li>Export format: Print-friendly table</li>
                    <li>Generated by: Admin</li>
                </ul>
            </div>
        </body>
        </html>
    `;
    
    // Write content to print window
    printWindow.document.write(printContent);
    printWindow.document.close();
    
    // Wait for content to load, then print
    printWindow.onload = function() {
        printWindow.focus();
        printWindow.print();
        
        // Close the window after printing (optional)
        setTimeout(function() {
            printWindow.close();
        }, 100);
    };
}

// Auto-submit form when per_page changes
document.querySelector('select[name="per_page"]').addEventListener('change', function() {
    this.form.submit();
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Don't trigger if user is typing in an input
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
    
    // Previous page (Left arrow or P)
    if ((e.key === 'ArrowLeft' || e.key === 'p') && <?= $currentPage ?> > 1) {
        window.location.href = '<?= getPaginationUrl($currentPage - 1) ?>';
    }
    
    // Next page (Right arrow or N)
    if ((e.key === 'ArrowRight' || e.key === 'n') && <?= $currentPage ?> < <?= $totalPages ?>) {
        window.location.href = '<?= getPaginationUrl($currentPage + 1) ?>';
    }
    
    // Focus search (/)
    if (e.key === '/') {
        e.preventDefault();
        document.querySelector('input[name="q"]').focus();
    }
    
    // Print shortcut (Ctrl+P)
    if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        printTable();
    }
});

// Show keyboard shortcuts hint
document.addEventListener('DOMContentLoaded', function() {
    const hint = document.createElement('div');
    hint.className = 'fixed bottom-4 right-4 bg-gray-800 text-white text-xs px-3 py-2 rounded-lg opacity-0 transition-opacity duration-300 no-print';
    hint.innerHTML = 'Shortcuts: ← → or P/N for pages, / for search, Ctrl+P to print';
    document.body.appendChild(hint);
    
    // Show hint briefly on page load
    setTimeout(() => hint.classList.add('opacity-75'), 1000);
    setTimeout(() => hint.classList.remove('opacity-75'), 5000);
});

// Add loading indicator for god mode forms
document.addEventListener('DOMContentLoaded', function() {
    const godModeForms = document.querySelectorAll('form[action="god_mode.php"]');
    godModeForms.forEach(form => {
        form.addEventListener('submit', function() {
            const button = form.querySelector('button');
            const originalHtml = button.innerHTML;
            button.innerHTML = `
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            `;
            button.disabled = true;
            
            // Reset button after 3 seconds in case of error
            setTimeout(() => {
                button.innerHTML = originalHtml;
                button.disabled = false;
            }, 3000);
        });
    });
});

// Add tooltip functionality
document.addEventListener('DOMContentLoaded', function() {
    let currentTooltip = null;
    
    function showTooltip(element, text) {
        // Remove existing tooltip
        hideTooltip();
        
        const tooltip = document.createElement('div');
        tooltip.className = 'fixed bg-gray-900 text-white text-xs px-2 py-1 rounded z-50 pointer-events-none whitespace-nowrap';
        tooltip.textContent = text;
        tooltip.style.opacity = '0';
        tooltip.style.transition = 'opacity 0.2s';
        document.body.appendChild(tooltip);
        
        const rect = element.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        
        // Position tooltip above element, centered
        let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
        let top = rect.top - tooltipRect.height - 8;
        
        // Keep tooltip within viewport
        if (left < 5) left = 5;
        if (left + tooltipRect.width > window.innerWidth - 5) {
            left = window.innerWidth - tooltipRect.width - 5;
        }
        if (top < 5) {
            top = rect.bottom + 8; // Show below if no room above
        }
        
        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
        tooltip.style.opacity = '1';
        
        currentTooltip = tooltip;
    }
    
    function hideTooltip() {
        if (currentTooltip) {
            currentTooltip.remove();
            currentTooltip = null;
        }
    }
    
    // Add tooltip to elements with title attribute
    function addTooltipListeners() {
        const tooltipElements = document.querySelectorAll('[title]');
        tooltipElements.forEach(element => {
            const originalTitle = element.getAttribute('title');
            element.removeAttribute('title'); // Prevent default tooltip
            element.setAttribute('data-tooltip-text', originalTitle);
            
            element.addEventListener('mouseenter', function() {
                const text = this.getAttribute('data-tooltip-text');
                if (text) {
                    showTooltip(this, text);
                }
            });
            
            element.addEventListener('mouseleave', hideTooltip);
        });
    }
    
    // Initialize tooltips
    addTooltipListeners();
    
    // Hide tooltip when scrolling or clicking elsewhere
    window.addEventListener('scroll', hideTooltip);
    document.addEventListener('click', hideTooltip);
    
    // Re-add tooltips for dynamically added elements
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList') {
                addTooltipListeners();
            }
        });
    });
    
    observer.observe(document.body, { childList: true, subtree: true });
});
</script>