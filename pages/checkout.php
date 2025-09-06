<?php
// pages/checkout.php - Member checkout for logged-in users
$uid = $_SESSION['user_id'];

// Check if there are items in cart
if (empty($_SESSION['cart']) || empty($_SESSION['cart_products'])) {
    redirect('dashboard.php?page=product_store', 'Your cart is empty. Please add some products first.');
}

// Get affiliate information if available
$affiliate_id = isset($_SESSION['aff']) ? (int)$_SESSION['aff'] : null;
$affiliate_user = null;

if ($affiliate_id && $affiliate_id != $uid) {
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ? AND status = 'active'");
    $stmt->execute([$affiliate_id]);
    $affiliate_user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Calculate cart totals
$cart_items = [];
$cart_total = 0;
$total_items = 0;

foreach ($_SESSION['cart'] as $product_id => $quantity) {
    if (isset($_SESSION['cart_products'][$product_id])) {
        $product = $_SESSION['cart_products'][$product_id];
        $final_price = $product['price'] * (1 - $product['discount'] / 100);
        $item_total = $final_price * $quantity;
        
        $cart_items[] = [
            'product' => $product,
            'quantity' => $quantity,
            'final_price' => $final_price,
            'item_total' => $item_total
        ];
        
        $cart_total += $item_total;
        $total_items += $quantity;
    }
}

// Get user's current balance
$balance_stmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ?");
$balance_stmt->execute([$uid]);
$current_balance = $balance_stmt->fetchColumn() ?? 0;

$balance_needed = max(0, $cart_total - $current_balance);

// Handle checkout options
if ($_POST['action'] ?? '' === 'checkout_now') {
    // Immediate checkout - pay now
    if ($current_balance >= $cart_total) {
        try {
            $pdo->beginTransaction();
            
            foreach ($cart_items as $item) {
                // Deduct from wallet
                $pdo->prepare("UPDATE wallets SET balance = balance - ? WHERE user_id = ?")
                    ->execute([$item['item_total'], $uid]);
                
                // Record purchase transaction
                $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, 'product_purchase', ?)")
                    ->execute([$uid, -$item['item_total']]);

                // Process affiliate commission if applicable
                if ($affiliate_id && $affiliate_id != $uid && $item['product']['affiliate_rate'] > 0) {
                    $commission = $item['item_total'] * ($item['product']['affiliate_rate'] / 100);
                    
                    // Ensure affiliate has a wallet
                    $pdo->prepare("INSERT IGNORE INTO wallets (user_id, balance) VALUES (?, 0.00)")
                        ->execute([$affiliate_id]);
                    
                    // Credit affiliate commission
                    $pdo->prepare("UPDATE wallets SET balance = balance + ? WHERE user_id = ?")
                        ->execute([$commission, $affiliate_id]);
                    
                    // Record affiliate commission transaction
                    $pdo->prepare("INSERT INTO wallet_tx (user_id, package_id, type, amount) VALUES (?, NULL, 'affiliate_bonus', ?)")
                        ->execute([$affiliate_id, $commission]);
                }
            }
            
            $pdo->commit();
            
            // Clear cart
            unset($_SESSION['cart']);
            unset($_SESSION['cart_products']);
            unset($_SESSION['aff']);
            
            redirect('dashboard.php?page=overview', 'Purchase completed successfully! Thank you for your order.');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            redirect('dashboard.php?page=checkout', 'Purchase failed: ' . $e->getMessage());
        }
    } else {
        redirect('dashboard.php?page=checkout', 'Insufficient balance. Please choose "Order for Later" option.');
    }
}

if ($_POST['action'] ?? '' === 'order_for_later') {
    // Order for later - create pending orders
    try {
        $pdo->beginTransaction();
        
        // Create pending orders table if it doesn't exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pending_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(12,2) NOT NULL,
                total_amount DECIMAL(12,2) NOT NULL,
                affiliate_id INT NULL,
                status ENUM('pending_payment', 'paid', 'cancelled') DEFAULT 'pending_payment',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                FOREIGN KEY (affiliate_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        // Create pending orders for cart items
        foreach ($cart_items as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO pending_orders (user_id, product_id, quantity, unit_price, total_amount, affiliate_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $uid,
                $item['product']['id'],
                $item['quantity'],
                $item['final_price'],
                $item['item_total'],
                $affiliate_id
            ]);
        }

        $pdo->commit();

        // Clear cart
        unset($_SESSION['cart']);
        unset($_SESSION['cart_products']);

        redirect('dashboard.php?page=pending_orders', 'Orders saved for later! Top up your wallet when ready to complete the purchase.');

    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Order for later error: " . $e->getMessage());
        redirect('dashboard.php?page=checkout', 'Failed to save orders. Please try again.');
    }
}
?>

<div class="bg-white shadow rounded-lg">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-gray-200">
        <div class="flex justify-between items-center">
            <h2 class="text-xl font-semibold text-gray-800">Checkout</h2>
            <div class="text-right">
                <div class="text-sm text-gray-500">Your Balance</div>
                <div class="text-lg font-bold text-blue-600">$<?= number_format($current_balance, 2) ?></div>
            </div>
        </div>
        
        <?php if ($affiliate_user): ?>
            <div class="mt-3 p-3 bg-purple-50 border border-purple-200 rounded-lg">
                <div class="flex items-center">
                    <div class="text-purple-600 mr-2">🎯</div>
                    <div>
                        <div class="text-sm font-medium text-purple-800">
                            Affiliate Purchase - Referred by <?= htmlspecialchars($affiliate_user['username']) ?>
                        </div>
                        <div class="text-xs text-purple-600">
                            Commission will be credited to your referrer upon purchase completion
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Order Summary -->
    <div class="p-6">
        <h3 class="text-lg font-semibold mb-4">Order Summary</h3>
        
        <div class="space-y-4 mb-6">
            <?php foreach ($cart_items as $item): ?>
                <div class="flex items-center justify-between p-4 border rounded-lg">
                    <div class="flex items-center space-x-4">
                        <img src="<?= htmlspecialchars($item['product']['image_url'] ?: '/images/placeholder.jpg') ?>" 
                             alt="<?= htmlspecialchars($item['product']['name']) ?>"
                             class="w-16 h-16 object-cover rounded-lg">
                        <div>
                            <h4 class="font-medium text-gray-800"><?= htmlspecialchars($item['product']['name']) ?></h4>
                            <p class="text-sm text-gray-500"><?= htmlspecialchars($item['product']['short_desc']) ?></p>
                            <div class="flex items-center space-x-2 mt-1">
                                <span class="text-sm text-gray-600">Qty: <?= $item['quantity'] ?></span>
                                <span class="text-sm text-gray-600">×</span>
                                <span class="text-sm font-medium">$<?= number_format($item['final_price'], 2) ?></span>
                                <?php if ($item['product']['discount'] > 0): ?>
                                    <span class="text-xs bg-red-100 text-red-800 px-2 py-1 rounded-full">
                                        <?= $item['product']['discount'] ?>% OFF
                                    </span>
                                <?php endif; ?>
                                <?php if ($item['product']['affiliate_rate'] > 0): ?>
                                    <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded-full">
                                        <?= $item['product']['affiliate_rate'] ?>% Commission
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-semibold text-gray-800">$<?= number_format($item['item_total'], 2) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Total and Balance Info -->
        <div class="border-t pt-4 mb-6">
            <div class="flex justify-between items-center mb-2">
                <span class="text-gray-600">Items (<?= $total_items ?>):</span>
                <span class="font-medium">$<?= number_format($cart_total, 2) ?></span>
            </div>
            <div class="flex justify-between items-center mb-4">
                <span class="text-lg font-semibold">Total:</span>
                <span class="text-xl font-bold text-green-600">$<?= number_format($cart_total, 2) ?></span>
            </div>
            
            <!-- Balance Status -->
            <div class="p-4 rounded-lg <?= $current_balance >= $cart_total ? 'bg-green-50 border border-green-200' : 'bg-orange-50 border border-orange-200' ?>">
                <div class="flex justify-between items-center">
                    <div>
                        <div class="font-medium <?= $current_balance >= $cart_total ? 'text-green-800' : 'text-orange-800' ?>">
                            <?= $current_balance >= $cart_total ? 'Sufficient Balance' : 'Insufficient Balance' ?>
                        </div>
                        <div class="text-sm <?= $current_balance >= $cart_total ? 'text-green-600' : 'text-orange-600' ?>">
                            <?php if ($current_balance >= $cart_total): ?>
                                You can complete this purchase now
                            <?php else: ?>
                                You need $<?= number_format($balance_needed, 2) ?> more
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-500">Your Balance</div>
                        <div class="text-lg font-bold <?= $current_balance >= $cart_total ? 'text-green-600' : 'text-orange-600' ?>">
                            $<?= number_format($current_balance, 2) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checkout Options -->
        <div class="space-y-3">
            <?php if ($current_balance >= $cart_total): ?>
                <!-- Can checkout now -->
                <form method="post" onsubmit="return confirm('Complete purchase for $<?= number_format($cart_total, 2) ?>?')">
                    <input type="hidden" name="action" value="checkout_now">
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors">
                        Complete Purchase Now - $<?= number_format($cart_total, 2) ?>
                    </button>
                </form>
                
                <form method="post">
                    <input type="hidden" name="action" value="order_for_later">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors">
                        Save for Later (Pay When Ready)
                    </button>
                </form>
            <?php else: ?>
                <!-- Need to top up first -->
                <a href="dashboard.php?page=wallet" class="block w-full bg-orange-600 hover:bg-orange-700 text-white font-semibold py-3 px-6 rounded-lg text-center transition-colors">
                    Top Up Wallet (+$<?= number_format($balance_needed, 2) ?>)
                </a>
                
                <form method="post">
                    <input type="hidden" name="action" value="order_for_later">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors">
                        Save Orders for Later
                    </button>
                </form>
            <?php endif; ?>
            
            <a href="dashboard.php?page=product_store" class="block w-full bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-3 px-6 rounded-lg text-center transition-colors">
                Continue Shopping
            </a>
        </div>

        <!-- Payment Methods Info -->
        <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
            <h4 class="font-medium text-blue-800 mb-2">How to Top Up Your Wallet</h4>
            <ul class="text-sm text-blue-700 space-y-1">
                <li>• Go to Wallet page and request a top-up</li>
                <li>• Send cryptocurrency to the provided address</li>
                <li>• Your balance will be updated after admin approval</li>
                <li>• Complete your pending orders anytime</li>
            </ul>
        </div>
    </div>
</div>

<script>
// Add loading states to form submissions
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function() {
        const button = this.querySelector('button[type="submit"]');
        if (button) {
            const originalText = button.innerHTML;
            button.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Processing...';
            button.disabled = true;
            
            // Re-enable after timeout in case of error
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 5000);
        }
    });
});
</script>