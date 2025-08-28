<?php
// pages/users.php - Admin-only user monitoring with pagination and search
require_once 'config.php';
require_once 'functions.php';

if (!isset($uid)) redirect('login.php');

// Admin check
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$uid]);
$role = $stmt->fetchColumn();
if ($role !== 'admin') {
    http_response_code(403);
    redirect('dashboard.php', 'Admin access only');
}

// Pagination
$perPage = 10;
$currentPage = max(1, filter_var($_GET['p'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
$offset = ($currentPage - 1) * $perPage;

// Search
$search = trim($_GET['q'] ?? '');
$whereClause = '';
$params = [];
if ($search !== '') {
    $whereClause = 'WHERE u.username LIKE ? OR u.id = ?';
    $params = ["%$search%", filter_var($search, FILTER_VALIDATE_INT) ?: 0];
}

// Count total users for pagination
$countSql = "SELECT COUNT(*) FROM users u $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalUsers / $perPage));

// Fetch users
$sql = "
    SELECT 
        u.id,
        u.username,
        u.position,
        u.role,
        u.created_at,
        s.username AS sponsor_username,
        up.username AS upline_username
    FROM users u
    LEFT JOIN users s ON s.id = u.sponsor_id
    LEFT JOIN users up ON up.id = u.upline_id
    $whereClause
    ORDER BY u.id ASC
    LIMIT ? OFFSET ?
";
try {
    $stmt = $pdo->prepare($sql);
    // Bind parameters
    $paramIndex = 1;
    if ($search !== '') {
        $stmt->bindValue($paramIndex++, "%$search%", PDO::PARAM_STR);
        $stmt->bindValue($paramIndex++, filter_var($search, FILTER_VALIDATE_INT) ?: 0, PDO::PARAM_INT);
    }
    $stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT); // Explicitly bind as integer
    $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);  // Explicitly bind as integer
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("User fetch error: " . $e->getMessage());
    redirect('dashboard.php', 'Error fetching users');
}
?>

<div class="bg-white shadow rounded-lg p-6">
    <h2 class="text-xl font-bold mb-4">User Management</h2>
    <p class="text-gray-600 mb-4">View and monitor all users in the system</p>

    <!-- Search Form -->
    <form method="get" action="dashboard.php" class="mb-6">
        <input type="hidden" name="page" value="users">
        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
        <div class="flex gap-4">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by username or ID" class="border rounded-lg p-2 w-full max-w-md">
            <button class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600">Search</button>
            <?php if ($search !== ''): ?>
                <a href="dashboard.php?page=users" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Users Table -->
    <?php if (empty($users)): ?>
        <div class="alert alert-info">No users found.</div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left table-bordered">
                <thead>
                    <tr class="text-gray-600">
                        <th class="p-2">ID</th>
                        <th class="p-2">Username</th>
                        <th class="p-2">Sponsor</th>
                        <th class="p-2">Upline</th>
                        <th class="p-2">Position</th>
                        <th class="p-2">Role</th>
                        <th class="p-2">Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr class="border-t">
                            <td class="p-2"><?= htmlspecialchars($u['id']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['username']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['sponsor_username'] ?? 'None') ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['upline_username'] ?? 'None') ?></td>
                            <td class="p-2"><?= htmlspecialchars(ucfirst($u['position'] ?? 'Root')) ?></td>
                            <td class="p-2"><?= htmlspecialchars(ucfirst($u['role'])) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4 flex justify-center">
                <ul class="pagination">
                    <?php if ($currentPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="dashboard.php?page=users&p=<?= $currentPage - 1 ?>&q=<?= urlencode($search) ?>">Previous</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="dashboard.php?page=users&p=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($currentPage < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="dashboard.php?page=users&p=<?= $currentPage + 1 ?>&q=<?= urlencode($search) ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>