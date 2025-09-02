<?php
// pages/users.php - Admin-only user monitoring with enhanced pagination and search
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
$positionFilter = isset($_GET['position']) ? trim($_GET['position']) : '';
$sortBy = isset($_GET['sort']) && in_array($_GET['sort'], ['id', 'username', 'created_at', 'role']) ? $_GET['sort'] : 'id';
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

if ($positionFilter !== '') {
    $whereClauses[] = 'u.position = ?';
    $params[] = $positionFilter;
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
$validSortColumns = ['id', 'username', 'created_at', 'role', 'position'];
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
        $search = $roleFilter = $positionFilter = '';
        $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $totalPages = max(1, ceil($totalUsers / $perPage));
        
        $_SESSION['flash'] = 'Filters reset due to database error. Showing all users.';
    } catch (PDOException $e2) {
        error_log("Fallback query also failed: " . $e2->getMessage());
        redirect('dashboard.php', 'Database error - please try again');
    }
}

// Get available roles and positions for filters
try {
    $rolesStmt = $pdo->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL ORDER BY role");
    $availableRoles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $positionsStmt = $pdo->query("SELECT DISTINCT position FROM users WHERE position IS NOT NULL ORDER BY position");
    $availablePositions = $positionsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Filter options fetch error: " . $e->getMessage());
    $availableRoles = ['user', 'admin']; // Fallback values
    $availablePositions = ['left', 'right']; // Fallback values
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
            
            <!-- Position Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                <select name="position" class="border rounded-lg p-2 w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Positions</option>
                    <?php foreach ($availablePositions as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>" <?= $positionFilter === $p ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($p ?? 'Root')) ?>
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
            <?php if ($search !== '' || $roleFilter !== '' || $positionFilter !== ''): ?>
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
        <div class="overflow-x-auto bg-white border rounded-lg">
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
                        <th class="p-3 text-left text-gray-600 font-medium">Status</th>
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
                            <input type="hidden" name="position" value="<?= htmlspecialchars($positionFilter) ?>">
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
                <?php if ($search !== '' || $roleFilter !== '' || $positionFilter !== ''): ?>
                    Filtered results
                <?php else: ?>
                    All users
                <?php endif; ?>
            </div>
            
            <div class="flex space-x-2">
                <button onclick="window.print()" class="px-3 py-2 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                    Print
                </button>
                <a href="dashboard.php?page=export_users&<?= http_build_query(array_filter(['q' => $search, 'role' => $roleFilter, 'position' => $positionFilter])) ?>" 
                   class="px-3 py-2 text-sm bg-green-100 text-green-700 rounded-lg hover:bg-green-200 transition-colors">
                    Export CSV
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    table { font-size: 12px; }
    .bg-gray-50 { background-color: #f9f9f9 !important; }
}
</style>

<script>
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
});

// Show keyboard shortcuts hint
document.addEventListener('DOMContentLoaded', function() {
    const hint = document.createElement('div');
    hint.className = 'fixed bottom-4 right-4 bg-gray-800 text-white text-xs px-3 py-2 rounded-lg opacity-0 transition-opacity duration-300 no-print';
    hint.innerHTML = 'Shortcuts: ← → or P/N for pages, / for search';
    document.body.appendChild(hint);
    
    // Show hint briefly on page load
    setTimeout(() => hint.classList.add('opacity-75'), 1000);
    setTimeout(() => hint.classList.remove('opacity-75'), 4000);
});
</script>