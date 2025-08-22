<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) redirect('login.php');

// Simple role check (extend as needed)
$role = $pdo->query("SELECT role FROM users WHERE id = ".$_SESSION['user_id'])->fetchColumn();
if ($role !== 'admin') {
    http_response_code(403);
    exit('Admin only');
}

if (($_POST['action'] ?? '') && ($id = (int)($_POST['req_id'] ?? 0))) {
    $stmt = $pdo->prepare('SELECT * FROM ewallet_requests WHERE id = ? AND status = "pending"');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        redirect('admin_ewallet.php', 'No pending request');
    }

    $pdo->beginTransaction();

    /* ensure wallet row exists */
    $pdo->prepare('INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)')
        ->execute([$row['user_id']]);

    if ($_POST['action'] === 'approve') {
        /* DEDUCT on approval */
        $pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE user_id = ?')
            ->execute([$row['usdt_amount'], $row['user_id']]);
        $pdo->prepare('INSERT INTO wallet_tx (user_id, type, amount) VALUES (?,"withdraw",?)')
            ->execute([$row['user_id'], -$row['usdt_amount']]);

        $pdo->prepare('UPDATE ewallet_requests SET status="approved", updated_at=NOW() WHERE id=?')
            ->execute([$id]);
        $pdo->commit();
        redirect('admin_ewallet.php', 'Approved');

    } elseif ($_POST['action'] === 'reject') {
        /* no deduction ever happened on request, so simply mark rejected */
        $pdo->prepare('UPDATE ewallet_requests SET status="rejected", updated_at=NOW() WHERE id=?')
            ->execute([$id]);
        $pdo->commit();
        redirect('admin_ewallet.php', 'Rejected');
    }
}

$q = $pdo->query(
  "SELECT r.*, u.username 
   FROM ewallet_requests r
   JOIN users u ON u.id = r.user_id
   WHERE r.status='pending'
   ORDER BY r.id DESC"
);
?>
<!doctype html>
<html>
<head>
  <title>E-Wallet Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <h2>E-Wallet Requests (Pending)</h2>
  <?php if (!$q->rowCount()): ?>
    <div class="alert alert-info">No pending requests.</div>
  <?php else: ?>
    <table class="table table-bordered">
      <thead>
        <tr>
          <th>#</th><th>User</th><th>Type</th><th>Amount (USDT)</th>
          <th>Wallet / Tx Hash</th><th>Requested</th><th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($q as $r): ?>
          <tr>
            <td><?=$r['id']?></td>
            <td><?=htmlspecialchars($r['username'])?></td>
            <td><?=ucfirst($r['type'])?></td>
            <td><?=$r['usdt_amount']?></td>
            <td><?=htmlspecialchars($r['wallet_address'] ?? $r['tx_hash'] ?? '')?></td>
            <td><?=$r['created_at']?></td>
            <td>
              <form method="post" class="d-inline">
                <input type="hidden" name="req_id" value="<?=$r['id']?>">
                <button class="btn btn-sm btn-success" name="action" value="approve">Approve</button>
                <button class="btn btn-sm btn-danger" name="action" value="reject">Reject</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
</div>
</body>
</html>