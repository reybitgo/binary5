<?php
/*  pages/admin_ewallet_fragment.php
 *  Admin-only e-wallet request table & actions
 *  (everything between <main> tags, no <html> wrapper)
 */

// Ensure we are inside dashboard.php
if (!isset($uid)) return;

$q = $pdo->prepare(
  "SELECT r.*, u.username 
   FROM ewallet_requests r
   JOIN users u ON u.id = r.user_id
   WHERE r.status='pending'
   ORDER BY r.id DESC"
);
$q->execute();
?>

<div class="bg-white shadow rounded-lg p-6">
  <h2 class="text-xl font-bold mb-4">Pending E-Wallet Requests (Admin)</h2>

  <?php if (!$q->rowCount()): ?>
    <div class="alert alert-info">No pending requests.</div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead>
          <tr class="text-gray-600">
            <th class="p-2">#</th>
            <th class="p-2">User</th>
            <th class="p-2">Type</th>
            <th class="p-2">Amount (USDT)</th>
            <th class="p-2">Address / Tx Hash</th>
            <th class="p-2">Requested</th>
            <th class="p-2">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($q as $r): ?>
            <tr class="border-t">
              <td class="p-2"><?=$r['id']?></td>
              <td class="p-2"><?=htmlspecialchars($r['username'])?></td>
              <td class="p-2"><?=ucfirst($r['type'])?></td>
              <td class="p-2"><?=$r['usdt_amount']?></td>
              <td class="p-2"><?=htmlspecialchars($r['wallet_address'] ?? $r['tx_hash'] ?? '')?></td>
              <td class="p-2"><?=$r['created_at']?></td>
              <td class="p-2">
                <form method="post" action="dashboard.php?page=wallet" class="inline">
                  <input type="hidden" name="req_id" value="<?=$r['id']?>">
                  <input type="hidden" name="action" value="approve">
                  <button class="bg-green-500 text-white px-2 py-1 rounded text-xs">Approve</button>
                </form>
                <form method="post" action="dashboard.php?page=wallet" class="inline ml-1">
                  <input type="hidden" name="req_id" value="<?=$r['id']?>">
                  <input type="hidden" name="action" value="reject">
                  <button class="bg-red-500 text-white px-2 py-1 rounded text-xs">Reject</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>