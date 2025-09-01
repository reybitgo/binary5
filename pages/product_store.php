<?php
// pages/product_store.php
$ref = (int)($_GET['ref'] ?? 0);   // affiliate user id
$product_id = (int)($_GET['id'] ?? 0); // specific product from affiliate link
$products = $pdo->query("SELECT * FROM products WHERE active = 1 ORDER BY id DESC")->fetchAll();

// Function to get username by ID
function getUsernameById($userId, $pdo) {
    if (!$userId) return '';
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result ? $result['username'] : 'Unknown User';
}

// Calculate final prices for all products
foreach ($products as &$p) {
    $p['final_price'] = $p['price'] * (1 - $p['discount']/100);
    $p['aff_link'] = "dashboard.php?page=product_store&ref=$uid&id=".$p['id'];
    $p['savings'] = $p['price'] - $p['final_price'];
}
unset($p); // Break the reference
?>
<div class="bg-white shadow rounded-lg p-6">
    <h2 class="text-2xl font-bold mb-4">Product Store</h2>

    <?php if ($ref && $ref !== $uid): ?>
        <?php 
        $referrer_name = getUsernameById($ref, $pdo);
        if ($product_id > 0): ?>
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
                <p class="text-sm text-green-800">
                    <strong>Affiliate Link Active!</strong> 
                    You're viewing products through <?= htmlspecialchars($referrer_name) ?>'s referral. 
                    They will earn commission on your purchases.
                </p>
            </div>
        <?php else: ?>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                <p class="text-sm text-blue-800">
                    <strong>Browsing via affiliate:</strong> <?= htmlspecialchars($referrer_name) ?>
                </p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($user['status'] === 'inactive'): ?>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
            <p class="text-sm text-yellow-800">
                <strong>Account Inactive:</strong> Your account needs activation to make purchases. 
                <a href="dashboard.php?page=store" class="underline">Purchase a package first</a>.
            </p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach ($products as $p): ?>
        <div class="border rounded-lg p-4 flex flex-col <?= ($product_id > 0 && $product_id == $p['id']) ? 'ring-2 ring-green-400 bg-green-50' : '' ?>">
            <?php if ($p['image_url']): ?>
                <img src="<?= htmlspecialchars($p['image_url']) ?>" class="w-full h-32 object-cover rounded mb-3" alt="<?= htmlspecialchars($p['name']) ?>">
            <?php else: ?>
                <div class="w-full h-32 bg-gray-200 rounded mb-3 flex items-center justify-center">
                    <span class="text-gray-500">No Image</span>
                </div>
            <?php endif; ?>
            
            <h3 class="text-lg font-semibold mb-1"><?= htmlspecialchars($p['name']) ?></h3>
            <p class="text-sm text-gray-600 mb-2"><?= htmlspecialchars($p['short_desc']) ?></p>
            
            <?php if ($p['long_desc']): ?>
                <details class="mb-2">
                    <summary class="text-xs text-blue-600 cursor-pointer">More details...</summary>
                    <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($p['long_desc']) ?></p>
                </details>
            <?php endif; ?>

            <div class="flex items-center gap-2 mb-3">
                <?php if ($p['discount'] > 0): ?>
                    <span class="line-through text-gray-400">$<?= number_format($p['price'], 2) ?></span>
                    <span class="text-xl font-bold text-green-600">$<?= number_format($p['final_price'], 2) ?></span>
                    <span class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-xs"><?= $p['discount'] ?>% off</span>
                <?php else: ?>
                    <span class="text-xl font-bold text-gray-800">$<?= number_format($p['final_price'], 2) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($p['affiliate_rate'] > 0): ?>
                <div class="text-xs text-purple-600 mb-2">
                    Affiliate commission: <?= $p['affiliate_rate'] ?>%
                </div>
            <?php endif; ?>

            <?php if ($user['status'] === 'active'): ?>
                <?php if ($user['balance'] >= $p['final_price']): ?>
                    <form method="post" action="dashboard.php" class="mt-auto">
                        <input type="hidden" name="action" value="buy_product">
                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="affiliate_id" value="<?= $ref ?>">
                        <button class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700 transition-colors">
                            Buy Now - $<?= number_format($p['final_price'], 2) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <button disabled class="w-full bg-gray-300 text-gray-500 py-2 rounded cursor-not-allowed">
                        Insufficient Balance
                    </button>
                    <p class="text-xs text-red-600 mt-1 text-center">
                        Need $<?= number_format($p['final_price'] - $user['balance'], 2) ?> more
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <button disabled class="w-full bg-yellow-400 text-yellow-800 py-2 rounded cursor-not-allowed">
                    Account Activation Required
                </button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if (empty($products)): ?>
        <div class="text-center py-8">
            <p class="text-gray-500">No products available at the moment.</p>
        </div>
    <?php endif; ?>
</div>