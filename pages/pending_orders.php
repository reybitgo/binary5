<?php
// pages/pending_orders.php – member pending-orders page
$uid = $_SESSION['user_id'];

/* ----------------------------------------------------------
   1.  Pending orders
---------------------------------------------------------- */
$stmt = $pdo->prepare("
    SELECT po.*, p.name as product_name, p.image_url, p.short_desc,
           aff.username as affiliate_username
    FROM pending_orders po
    JOIN products p ON po.product_id = p.id
    LEFT JOIN users aff ON po.affiliate_id = aff.id
    WHERE po.user_id = ? AND po.status = 'pending_payment'
    ORDER BY po.created_at DESC
");
$stmt->execute([$uid]);
$pending_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_amount = 0;
foreach ($pending_orders as $order) {
    $total_amount += $order['total_amount'];
}

/* ----------------------------------------------------------
   2.  User balance
---------------------------------------------------------- */
$balance_stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ?");
$balance_stmt->execute([$uid]);
$current_balance = $balance_stmt->fetchColumn() ?? 0;
$balance_needed  = max(0, $total_amount - $current_balance);

/* ----------------------------------------------------------
   3.  Actions
---------------------------------------------------------- */
if (($_POST['action'] ?? '') === 'complete_orders') {
    if ($current_balance >= $total_amount) {
        try {
            $pdo->beginTransaction();

            foreach ($pending_orders as $order) {
                // debit buyer
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")
                    ->execute([$order['total_amount'], $uid]);
                // ledger
                $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, 'product_purchase', ?)")
                    ->execute([$uid, -$order['total_amount']]);

                // affiliate commission
                if ($order['affiliate_id'] && $order['affiliate_id'] != $uid) {
                    $product_stmt = $pdo->prepare("SELECT affiliate_rate FROM products WHERE id = ?");
                    $product_stmt->execute([$order['product_id']]);
                    $rate = (float)$product_stmt->fetchColumn();
                    if ($rate > 0) {
                        $commission = $order['total_amount'] * ($rate / 100);
                        $pdo->prepare("INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)")
                            ->execute([$order['affiliate_id']]);
                        $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")
                            ->execute([$commission, $order['affiliate_id']]);
                        $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, 'affiliate_bonus', ?)")
                            ->execute([$order['affiliate_id'], $commission]);
                    }
                }

                // mark paid
                $pdo->prepare("UPDATE pending_orders SET status = 'paid', updated_at = NOW() WHERE id = ?")
                    ->execute([$order['id']]);
            }

            $pdo->commit();
            redirect('dashboard.php?page=pending_orders', 'All orders completed successfully! Thank you for your purchase.');

        } catch (Exception $e) {
            $pdo->rollBack();
            redirect('dashboard.php?page=pending_orders', 'Payment processing failed: ' . $e->getMessage());
        }
    } else {
        redirect('dashboard.php?page=pending_orders', 'Insufficient balance. Please top up your wallet first.');
    }
}

if (($_POST['action'] ?? '') === 'cancel_order') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE pending_orders SET status = 'cancelled', updated_at = NOW()
                           WHERE id = ? AND user_id = ? AND status = 'pending_payment'");
    $stmt->execute([$order_id, $uid]);
    if ($stmt->rowCount()) {
        redirect('dashboard.php?page=pending_orders', 'Order cancelled successfully.');
    } else {
        redirect('dashboard.php?page=pending_orders', 'Order not found or cannot be cancelled.');
    }
}

/* ----------------------------------------------------------
   4.  History (last 20 completed/cancelled)
---------------------------------------------------------- */
$hist = $pdo->prepare("
    SELECT po.*, p.name as product_name, p.image_url,
           aff.username as affiliate_username
    FROM pending_orders po
    JOIN products p ON po.product_id = p.id
    LEFT JOIN users aff ON po.affiliate_id = aff.id
    WHERE po.user_id = ? AND po.status IN ('paid','cancelled')
    ORDER BY po.updated_at DESC
    LIMIT 20
");
$hist->execute([$uid]);
$completed_orders = $hist->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="space-y-6">

<!-- ==========================================================
     SUCCESS BANNER (shown once after auto-login)
========================================================== -->
<?php if (isset($_SESSION['flash']) && ($_SESSION['flash_type'] ?? '') === 'success'): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded" role="alert">
        <div class="flex">
            <div class="py-1">
                <svg class="w-6 h-6 mr-2" fill="currentColor" viewBox="0 0 20 20"><path d="M10 12a2 2 0 100-4 2 2 0 000 4zM7 10V8a1 1 0 011-1h4a1 1 0 011 1v2a1 1 0 11-2 0v-.5a.5.5 0 00-.5-.5H9a.5.5 0 00-.5.5V10a1 1 0 11-2 0z"/><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            </div>
            <div>
                <p class="font-bold">Welcome aboard!</p>
                <p class="text-sm"><?= htmlspecialchars($_SESSION['flash']) ?></p>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
<?php endif; ?>

<!-- ==========================================================
     WELCOME BLOCK (for brand-new inactive users)
========================================================== -->
<?php if (!empty($pending_orders) && $user['status'] === 'inactive'): ?>
    <div class="bg-gradient-to-r from-blue-50 to-green-50 border border-blue-200 rounded-lg p-6">
        <div class="flex items-start">
            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="ml-4">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Welcome to Shoppe Club!</h3>
                <p class="text-gray-600 mb-3">Your account has been created successfully. To complete your order and activate your account, please:</p>
                <ol class="list-decimal list-inside text-sm text-gray-600 space-y-1">
                    <li>Top up your wallet with the required amount</li>
                    <li>Complete your pending orders</li>
                    <li>Start earning commissions immediately!</li>
                </ol>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ==========================================================
     PENDING ORDERS
========================================================== -->
<?php if (!empty($pending_orders)): ?>
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800">Pending Orders</h2>
                <div class="text-right">
                    <div class="text-sm text-gray-500">Current Balance</div>
                    <div class="text-lg font-bold text-blue-600">$<?= number_format($current_balance, 2) ?></div>
                </div>
            </div>
        </div>

        <div class="p-6">
            <!-- balance bar -->
            <div class="mb-6 p-4 rounded-lg <?= $current_balance >= $total_amount ? 'bg-green-50 border border-green-200' : 'bg-orange-50 border border-orange-200' ?>">
                <div class="flex justify-between items-center">
                    <div>
                        <div class="font-medium <?= $current_balance >= $total_amount ? 'text-green-800' : 'text-orange-800' ?>">
                            <?= $current_balance >= $total_amount ? 'Ready to Complete Orders!' : 'Wallet Top-up Required' ?>
                        </div>
                        <div class="text-sm <?= $current_balance >= $total_amount ? 'text-green-600' : 'text-orange-600' ?>">
                            <?php if ($current_balance >= $total_amount): ?>
                                You have sufficient balance to complete all pending orders.
                            <?php else: ?>
                                You need $<?= number_format($balance_needed, 2) ?> more to complete your orders.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold <?= $current_balance >= $total_amount ? 'text-green-600' : 'text-orange-600' ?>">
                            $<?= number_format($total_amount, 2) ?>
                        </div>
                        <div class="text-sm text-gray-500">Total Required</div>
                    </div>
                </div>
            </div>

            <!-- orders list -->
            <div class="space-y-4 mb-6">
                <?php foreach ($pending_orders as $order): ?>
                    <div class="border rounded-lg p-4 hover:bg-gray-50 transition-colors">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-4">
                                <img src="<?= htmlspecialchars($order['image_url'] ?: '/images/placeholder.jpg') ?>"
                                     alt="<?= htmlspecialchars($order['product_name']) ?>"
                                     class="w-16 h-16 object-cover rounded-lg flex-shrink-0">
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-800"><?= htmlspecialchars($order['product_name']) ?></h4>
                                    <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($order['short_desc']) ?></p>
                                    <div class="flex items-center space-x-4 mt-2 text-sm text-gray-600">
                                        <span>Qty: <?= $order['quantity'] ?></span>
                                        <span>Unit Price: $<?= number_format($order['unit_price'], 2) ?></span>
                                        <?php if ($order['affiliate_username']): ?>
                                            <span class="text-purple-600">Referred by: <?= htmlspecialchars($order['affiliate_username']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-semibold text-gray-800">$<?= number_format($order['total_amount'], 2) ?></div>
                                <div class="text-sm text-gray-500"><?= date('M j, Y g:i A', strtotime($order['created_at'])) ?></div>
                                <form method="post" class="mt-2" onsubmit="return confirm('Are you sure you want to cancel this order?')">
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800 text-sm">Cancel</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- action buttons -->
            <div class="flex flex-col sm:flex-row gap-3">
                <?php if ($current_balance >= $total_amount): ?>
                    <form method="post" class="flex-1" onsubmit="return confirm('Complete all pending orders for $<?= number_format($total_amount, 2) ?>?')">
                        <input type="hidden" name="action" value="complete_orders">
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors">
                            Complete All Orders - $<?= number_format($total_amount, 2) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <a href="dashboard.php?page=wallet&amount=<?= number_format($balance_needed, 2, '.', '') ?>" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg text-center transition-colors">
                        Top Up Wallet (+$<?= number_format($balance_needed, 2) ?>)
                    </a>
                <?php endif; ?>
                <a href="dashboard.php?page=product_store" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-3 px-6 rounded-lg text-center transition-colors">
                    Continue Shopping
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- No pending orders -->
    <div class="bg-white shadow rounded-lg p-8 text-center">
        <div class="text-gray-400 text-6xl mb-4">📦</div>
        <h3 class="text-lg font-medium text-gray-800 mb-2">No Pending Orders</h3>
        <p class="text-gray-500 mb-4">You don't have any orders waiting for payment.</p>
        <a href="dashboard.php?page=product_store" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition-colors">
            Browse Products
        </a>
    </div>
<?php endif; ?>

<!-- ==========================================================
     ORDER HISTORY
========================================================== -->
<?php if (!empty($completed_orders)): ?>
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Order History</h2>
        </div>
        <div class="p-6">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="text-gray-600 border-b">
                            <th class="pb-3 font-medium">Product</th>
                            <th class="pb-3 font-medium">Quantity</th>
                            <th class="pb-3 font-medium">Amount</th>
                            <th class="pb-3 font-medium">Status</th>
                            <th class="pb-3 font-medium">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($completed_orders as $order): ?>
                            <tr>
                                <td class="py-3">
                                    <div class="flex items-center space-x-3">
                                        <img src="<?= htmlspecialchars($order['image_url'] ?: '/images/placeholder.jpg') ?>"
                                             alt="<?= htmlspecialchars($order['product_name']) ?>"
                                             class="w-10 h-10 object-cover rounded">
                                        <div>
                                            <div class="font-medium"><?= htmlspecialchars($order['product_name']) ?></div>
                                            <?php if ($order['affiliate_username']): ?>
                                                <div class="text-xs text-purple-600">via <?= htmlspecialchars($order['affiliate_username']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3"><?= $order['quantity'] ?></td>
                                <td class="py-3 font-semibold">$<?= number_format($order['total_amount'], 2) ?></td>
                                <td class="py-3">
                                    <span class="px-2 py-1 text-xs rounded-full <?= $order['status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= ucfirst($order['status']) ?>
                                    </span>
                                </td>
                                <td class="py-3 text-sm text-gray-500">
                                    <?= date('M j, Y', strtotime($order['updated_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ==========================================================
     HELP / INFO
========================================================== -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
    <h3 class="text-lg font-semibold text-blue-800 mb-3">Need Help?</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
        <div>
            <h4 class="font-medium text-blue-700 mb-2">Payment Process</h4>
            <ul class="text-blue-600 space-y-1">
                <li>• Top up your wallet via cryptocurrency</li>
                <li>• Orders are processed automatically</li>
                <li>• Affiliate commissions are paid instantly</li>
            </ul>
        </div>
        <div>
            <h4 class="font-medium text-blue-700 mb-2">Account Benefits</h4>
            <ul class="text-blue-600 space-y-1">
                <li>• Earn commissions on referral sales</li>
                <li>• Access to binary and leadership bonuses</li>
                <li>• Build your own affiliate network</li>
            </ul>
        </div>
    </div>
</div>

</div><!-- /space-y-6 -->

<script>
/* ----  auto-refresh every 30 s when pending orders exist  ---- */
<?php if (!empty($pending_orders)): ?>
setTimeout(function () { window.location.reload(); }, 30000);
<?php endif; ?>

/* ----  loading state on buttons  ---- */
document.querySelectorAll('form button[type="submit"]').forEach(button => {
    button.closest('form').addEventListener('submit', function () {
        const orig = button.innerHTML;
        button.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
        button.disabled = true;
        setTimeout(() => { button.innerHTML = orig; button.disabled = false; }, 5000);
    });
});
</script>