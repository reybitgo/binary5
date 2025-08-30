<?php
// pages/store.php
// -------------------- PACKAGE STORE -------------------
$packages = $pdo->query("SELECT * FROM packages")->fetchAll();

// Get user's wallet balance
$walletStmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ?");
$walletStmt->execute([$uid]);
$walletBalance = $walletStmt->fetchColumn() ?: 0.00;
?>
<style>
.store-header-container {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}
.wallet-summary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    min-width: 280px;
}
.wallet-balance {
    font-size: 1.5rem;
    font-weight: bold;
    margin-bottom: 0.5rem;
}
.package-details {
    font-size: 0.875rem;
    opacity: 0.9;
    line-height: 1.4;
}
.package-details strong {
    color: #fff;
}
@media (max-width: 768px) {
    .store-header-container {
        flex-direction: column;
    }
    .wallet-summary {
        width: 100%;
        max-width: none;
    }
}
</style>

<!-- Package Store Section -->
<div class="store-header-container">
    <div>
        <h2 class="text-2xl font-bold text-gray-800 mb-4">Package Store</h2>
    </div>
    
    <a href="dashboard.php?page=wallet">
        <div class="wallet-summary">
            <div class="wallet-balance">💰 Wallet: $<?= number_format($walletBalance, 2) ?></div>
        </div>
    </a>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($packages as $p): ?>
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-700"><?=htmlspecialchars($p['name'])?></h3>
        <p class="text-2xl text-blue-500">$<?=number_format($p['price'], 2)?></p>
        <p class="text-gray-600"><?=$p['pv']?> PV</p>
        <p class="text-sm text-gray-500 mt-2">
            Daily: <?=$p['daily_max']?> pairs<br>
            Pair Rate: <?=($p['pair_rate']*100)?>%<br>
            Referral: <?=($p['referral_rate']*100)?>%
            Matched Bonus: 5 Levels deep
            Mentor Bonus: 5 Levels reverse
        </p>
        <form method="post" action="dashboard.php" class="mt-4">
            <input type="hidden" name="package_id" value="<?=$p['id']?>">
            <button class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600" 
                    name="action" value="buy_package">
                Buy Now
            </button>
        </form>
    </div>
    <?php endforeach; ?>
</div>