<?php
// pages/store.php
// -------------------- PACKAGE STORE -------------------
$packages = $pdo->query("SELECT * FROM packages")->fetchAll();

// Get user's wallet balance
$walletStmt = $pdo->prepare("SELECT balance FROM wallets WHERE user_id = ?");
$walletStmt->execute([$uid]);
$walletBalance = $walletStmt->fetchColumn() ?: 0.00;

// Check if user is inactive
$isInactive = ($user['status'] === 'inactive');
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
.activation-notice {
    background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    margin-bottom: 1.5rem;
    text-align: center;
}
.activation-notice h3 {
    margin: 0 0 0.5rem 0;
    font-size: 1.25rem;
    font-weight: bold;
}
.activation-notice p {
    margin: 0;
    font-size: 0.95rem;
    opacity: 0.95;
}
.package-card {
    transition: all 0.3s ease;
    position: relative;
}
.package-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}
.inactive-highlight {
    border: 2px solid #ff6b6b;
    background: linear-gradient(135deg, #fff 0%, #ffe6e6 100%);
}
.activation-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #ff6b6b;
    color: white;
    font-size: 0.75rem;
    font-weight: bold;
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
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
            <?php if ($isInactive): ?>
                <div style="font-size: 0.875rem; margin-top: 0.5rem; opacity: 0.9;">
                    Account Status: <span style="color: #ffcccc; font-weight: bold;">Inactive</span>
                </div>
            <?php endif; ?>
        </div>
    </a>
</div>

<?php if ($isInactive): ?>
<div class="activation-notice">
    <h3>🚀 Activate Your Account</h3>
    <p>Purchase any package below to activate your account and unlock all MLM features including bonuses, binary tree, and referral rewards!</p>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($packages as $p): ?>
    <div class="bg-white shadow rounded-lg p-6 package-card <?= $isInactive ? 'inactive-highlight' : '' ?>">
        <?php if ($isInactive): ?>
            <div class="activation-badge">ACTIVATES</div>
        <?php endif; ?>
        
        <h3 class="text-lg font-semibold text-gray-700"><?=htmlspecialchars($p['name'])?></h3>
        <p class="text-2xl text-blue-500 font-bold">$<?=number_format($p['price'], 2)?></p>
        <p class="text-gray-600 font-medium"><?=$p['pv']?> PV</p>
        
        <?php if ($isInactive): ?>
            <div class="bg-green-50 border border-green-200 rounded-lg p-3 mt-3 mb-3">
                <p class="text-sm text-green-800 font-medium mb-1">✅ Account Activation Included</p>
                <p class="text-xs text-green-700">This package will immediately activate your account</p>
            </div>
        <?php endif; ?>
        
        <div class="text-sm text-gray-600 mt-3 space-y-1">
            <p><strong>Daily Limit:</strong> <?=$p['daily_max']?> pairs</p>
            <p><strong>Pair Rate:</strong> <?=($p['pair_rate']*100)?>%</p>
            <p><strong>Referral:</strong> <?=($p['referral_rate']*100)?>%</p>
            <p><strong>Matched Bonus:</strong> 5 Levels deep</p>
            <p><strong>Mentor Bonus:</strong> 5 Levels reverse</p>
        </div>
        
        <form method="post" action="dashboard.php" class="mt-4">
            <input type="hidden" name="package_id" value="<?=$p['id']?>">
            <?php if ($walletBalance < $p['price']): ?>
                <div class="text-center mb-3">
                    <p class="text-sm text-red-600">Insufficient balance</p>
                    <a href="dashboard.php?page=wallet" class="text-blue-500 text-sm underline">Add funds to wallet</a>
                </div>
            <?php endif; ?>
            <button class="w-full <?= $walletBalance >= $p['price'] ? 'bg-blue-500 hover:bg-blue-600' : 'bg-gray-400 cursor-not-allowed' ?> text-white py-2 rounded-lg transition-colors font-medium" 
                    name="action" value="buy_package" 
                    <?= $walletBalance < $p['price'] ? 'disabled' : '' ?>>
                <?php if ($isInactive): ?>
                    Activate & Buy Now
                <?php else: ?>
                    Buy Now
                <?php endif; ?>
            </button>
        </form>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($isInactive): ?>
<div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-4">
    <h4 class="font-semibold text-blue-800 mb-2">Why Activate Your Account?</h4>
    <ul class="text-sm text-blue-700 space-y-1">
        <li>• <strong>Binary Tree Access:</strong> View and manage your downline structure</li>
        <li>• <strong>Earn Commissions:</strong> Get pair bonuses, referral rewards, and leadership bonuses</li>
        <li>• <strong>Full Dashboard Access:</strong> Access all features including referrals, leadership, and mentor sections</li>
        <li>• <strong>Networking Opportunities:</strong> Start building your network and earning potential</li>
    </ul>
</div>
<?php endif; ?>