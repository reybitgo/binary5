<?php
// pages/export_users.php - CSV export functionality for users
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

// Get the same filters as the users page
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$roleFilter = isset($_GET['role']) ? trim($_GET['role']) : '';
$positionFilter = isset($_GET['position']) ? trim($_GET['position']) : '';

// Build WHERE clause (same logic as users.php)
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

// Enhanced query with more details for export
$sql = "
    SELECT 
        u.id,
        u.username,
        u.email,
        u.role,
        u.status,
        u.position,
        u.left_count,
        u.right_count,
        u.pairs_today,
        u.created_at,
        u.last_login,
        s.username AS sponsor_username,
        s.id AS sponsor_id,
        up.username AS upline_username,
        up.id AS upline_id,
        w.balance,
        (SELECT COUNT(*) FROM users WHERE sponsor_id = u.id) as direct_referrals,
        (SELECT COUNT(*) FROM users WHERE upline_id = u.id) as downlines,
        (SELECT p.name FROM packages p 
         JOIN wallet_tx wt ON wt.package_id = p.id 
         WHERE wt.user_id = u.id AND wt.type='package' 
         ORDER BY wt.id DESC LIMIT 1) as current_package,
        (SELECT SUM(wt.amount) FROM wallet_tx wt 
         WHERE wt.user_id = u.id AND wt.type = 'pair_bonus' 
         AND DATE(wt.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as binary_earnings_30d,
        (SELECT SUM(wt.amount) FROM wallet_tx wt 
         WHERE wt.user_id = u.id AND wt.type = 'referral_bonus' 
         AND DATE(wt.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as referral_earnings_30d,
        (SELECT SUM(wt.amount) FROM wallet_tx wt 
         WHERE wt.user_id = u.id AND wt.type = 'leadership_bonus' 
         AND DATE(wt.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as leadership_earnings_30d,
        (SELECT SUM(wt.amount) FROM wallet_tx wt 
         WHERE wt.user_id = u.id AND wt.type = 'leadership_reverse_bonus' 
         AND DATE(wt.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as mentor_earnings_30d
    FROM users u
    LEFT JOIN users s ON s.id = u.sponsor_id
    LEFT JOIN users up ON up.id = u.upline_id
    LEFT JOIN wallets w ON w.user_id = u.id
    $whereClause
    ORDER BY u.id ASC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Export query error: " . $e->getMessage());
    redirect('dashboard.php?page=users', 'Export failed - database error');
}

// Generate filename with timestamp and filters
$filename_parts = ['users_export'];
if ($search) $filename_parts[] = 'search_' . preg_replace('/[^a-zA-Z0-9]/', '', $search);
if ($roleFilter) $filename_parts[] = 'role_' . $roleFilter;
if ($positionFilter) $filename_parts[] = 'pos_' . $positionFilter;
$filename_parts[] = date('Y-m-d_H-i-s');
$filename = implode('_', $filename_parts) . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8 (helps with Excel compatibility)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers
$headers = [
    'ID',
    'Username', 
    'Email',
    'Role',
    'Status',
    'Position',
    'Sponsor ID',
    'Sponsor Username',
    'Upline ID', 
    'Upline Username',
    'Current Package',
    'Wallet Balance',
    'Left Count',
    'Right Count',
    'Pairs Today',
    'Direct Referrals',
    'Total Downlines',
    'Binary Earnings (30d)',
    'Referral Earnings (30d)', 
    'Leadership Earnings (30d)',
    'Mentor Earnings (30d)',
    'Registration Date',
    'Last Login'
];

fputcsv($output, $headers);

// Output data rows
foreach ($users as $user) {
    $row = [
        $user['id'],
        $user['username'],
        $user['email'] ?: 'N/A',
        ucfirst($user['role']),
        ucfirst($user['status']),
        $user['position'] ? ucfirst($user['position']) : 'Root',
        $user['sponsor_id'] ?: 'N/A',
        $user['sponsor_username'] ?: 'N/A',
        $user['upline_id'] ?: 'N/A', 
        $user['upline_username'] ?: 'N/A',
        $user['current_package'] ?: 'None',
        number_format($user['balance'] ?: 0, 2),
        $user['left_count'] ?: 0,
        $user['right_count'] ?: 0,
        $user['pairs_today'] ?: 0,
        $user['direct_referrals'] ?: 0,
        $user['downlines'] ?: 0,
        number_format($user['binary_earnings_30d'] ?: 0, 2),
        number_format($user['referral_earnings_30d'] ?: 0, 2),
        number_format($user['leadership_earnings_30d'] ?: 0, 2),
        number_format($user['mentor_earnings_30d'] ?: 0, 2),
        date('Y-m-d H:i:s', strtotime($user['created_at'])),
        $user['last_login'] ? date('Y-m-d H:i:s', strtotime($user['last_login'])) : 'Never'
    ];
    
    fputcsv($output, $row);
}

// Add summary row
fputcsv($output, []);
fputcsv($output, ['EXPORT SUMMARY']);
fputcsv($output, ['Total Users Exported', count($users)]);
fputcsv($output, ['Export Date', date('Y-m-d H:i:s')]);
fputcsv($output, ['Exported By', $_SESSION['username'] ?? 'Admin']);

if ($search || $roleFilter || $positionFilter) {
    fputcsv($output, ['Filters Applied', 'Yes']);
    if ($search) fputcsv($output, ['Search Term', $search]);
    if ($roleFilter) fputcsv($output, ['Role Filter', $roleFilter]);
    if ($positionFilter) fputcsv($output, ['Position Filter', $positionFilter]);
} else {
    fputcsv($output, ['Filters Applied', 'None - All Users']);
}

fclose($output);

// Log the export action
try {
    $pdo->prepare("
        INSERT INTO admin_logs (admin_id, action, details, ip_address) 
        VALUES (?, 'user_export', ?, ?)
    ")->execute([
        $uid, 
        json_encode([
            'total_users' => count($users),
            'filename' => $filename,
            'filters' => array_filter(['search' => $search, 'role' => $roleFilter, 'position' => $positionFilter])
        ]),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);
} catch (PDOException $e) {
    // Log error but don't stop export
    error_log("Failed to log export action: " . $e->getMessage());
}

exit;