<?php
// pages/affiliate.php
$products = $pdo->query(
    "SELECT id, name, price, image_url, discount, affiliate_rate, short_desc 
     FROM products 
     WHERE active = 1 AND affiliate_rate > 0 
     ORDER BY affiliate_rate DESC, id DESC"
)->fetchAll();

// total affiliate earnings
$total_earned = $pdo->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM wallet_tx WHERE user_id=? AND type='affiliate_bonus'"
);
$total_earned->execute([$uid]);
$total_earned = $total_earned->fetchColumn() ?: 0;

// recent sales (CORRECTED)
$recent_sales = $pdo->prepare("
    SELECT wt.amount, wt.created_at, buyer.username AS buyer
    FROM wallet_tx wt
    JOIN wallet_tx purchase_tx ON purchase_tx.type='product_purchase' 
        AND purchase_tx.amount < 0
        AND purchase_tx.created_at BETWEEN wt.created_at - INTERVAL 1 DAY AND wt.created_at + INTERVAL 1 DAY
    JOIN users buyer ON buyer.id = purchase_tx.user_id
    WHERE wt.user_id = ? 
      AND wt.type='affiliate_bonus'
    ORDER BY wt.created_at DESC
    LIMIT 10
");
$recent_sales->execute([$uid]);
$recent_sales = $recent_sales->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="bg-white shadow rounded-lg p-6">
    <div class="mb-6">
        <h2 class="text-2xl font-bold mb-2">Affiliate Dashboard</h2>
        <p class="text-gray-600 mb-4">Share product links and earn commission on every sale.</p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-green-50 p-4 rounded-lg">
                <h3 class="font-semibold text-green-800">Total Earned</h3>
                <p class="text-2xl font-bold text-green-600">$<?= number_format($total_earned,2) ?></p>
            </div>
            <div class="bg-blue-50 p-4 rounded-lg">
                <h3 class="font-semibold text-blue-800">Available Products</h3>
                <p class="text-2xl font-bold text-blue-600"><?= count($products) ?></p>
            </div>
        </div>
    </div>

    <?php if (!empty($products)): ?>
        <h3 class="text-xl font-semibold mb-4">Available Products</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <?php foreach ($products as $p): ?>
            <div class="border rounded-lg p-4 flex gap-4">
                <?php if ($p['image_url']): ?>
                    <img src="<?= htmlspecialchars($p['image_url']) ?>" class="w-24 h-24 object-cover rounded" alt="<?= htmlspecialchars($p['name']) ?>">
                <?php else: ?>
                    <div class="w-24 h-24 bg-gray-200 rounded flex items-center justify-center"><span class="text-gray-500 text-xs">No Image</span></div>
                <?php endif; ?>
                <div class="flex-1">
                    <h4 class="font-semibold mb-1"><?= htmlspecialchars($p['name']) ?></h4>
                    <p class="text-sm text-gray-600 mb-2"><?= htmlspecialchars($p['short_desc']) ?></p>
                    <div class="text-sm mb-2">
                        <span class="text-gray-600">Price: $<?= number_format($p['price'],2) ?></span>
                        <?php if ($p['discount']>0): ?><span class="text-red-600 ml-2">(<?= $p['discount'] ?>% off)</span><?php endif; ?>
                    </div>
                    <div class="text-sm text-purple-600 mb-3">
                        <strong>Commission: <?= $p['affiliate_rate'] ?>%</strong>
                        (Earn $<?= number_format(($p['price']*(1-$p['discount']/100))*($p['affiliate_rate']/100),2) ?> per sale)
                    </div>
                    <button onclick="openLinkModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')" class="bg-purple-600 text-white px-3 py-1 rounded text-sm hover:bg-purple-700 transition-colors">Get Affiliate Link</button>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-8"><p class="text-gray-500">No affiliate products available at the moment.</p></div>
    <?php endif; ?>

    <?php if (!empty($recent_sales)): ?>
        <h3 class="text-xl font-semibold mb-4">Recent Affiliate Sales</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead><tr class="text-gray-600 border-b"><th class="p-2">Date</th><th class="p-2">Buyer</th><th class="p-2">Commission</th></tr></thead>
                <tbody>
                <?php foreach ($recent_sales as $sale): ?>
                    <tr class="border-b">
                        <td class="p-2"><?= date('M j, Y g:i A', strtotime($sale['created_at'])) ?></td>
                        <td class="p-2"><?= htmlspecialchars($sale['buyer']) ?></td>
                        <td class="p-2 text-green-600 font-semibold">+$<?= number_format($sale['amount'],2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div id="linkModal" class="modal">
    <div class="modal-content">
        <h3 class="text-lg mb-3">Affiliate Link for <span id="prodName" class="font-semibold text-purple-600"></span></h3>
        <div class="mb-3">
            <label class="block text-sm font-medium mb-1">Direct Product Link:</label>
            <input readonly id="affLink" class="w-full border rounded p-2 mb-2 text-sm">
            <button onclick="copyLink()" class="bg-purple-600 text-white px-3 py-1 rounded text-sm hover:bg-purple-700">Copy Link</button>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium mb-1">General Store Link:</label>
            <input readonly id="generalLink" class="w-full border rounded p-2 mb-2 text-sm">
            <button onclick="copyGeneralLink()" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">Copy Link</button>
        </div>
        <div class="flex gap-2"><button onclick="closeAffModal()" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Close</button></div>
    </div>
</div>

<style>
.modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:all .3s;z-index:1000}
.modal.show{opacity:1;visibility:visible}
.modal-content{background:#fff;padding:1.5rem;border-radius:12px;width:90%;max-width:600px;max-height:90vh;overflow-y:auto}
</style>

<script>
/* Robust clipboard + toast */
function copyToClipboard(text, msg = 'Link copied!') {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => showToast(msg));
        return;
    }
    const input = document.createElement('input');
    input.value = text;
    document.body.appendChild(input);
    input.select();
    input.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        showToast(msg);
    } catch (err) {
        prompt('Copy the link manually:', text);
    }
    document.body.removeChild(input);
}

function showToast(msg) {
    const toast = document.createElement('div');
    toast.textContent = msg;
    toast.className = 'fixed top-4 right-4 bg-green-600 text-white px-4 py-2 rounded shadow z-50';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function openLinkModal(id, name) {
    const base = `${window.location.protocol}//${window.location.host}`;
    document.getElementById('prodName').textContent = name;
    document.getElementById('affLink').value = `${base}/dashboard.php?page=product_store&aff=<?= $uid ?>&id=${id}`;
    document.getElementById('generalLink').value = `${base}/dashboard.php?page=product_store&aff=<?= $uid ?>`;
    document.getElementById('linkModal').classList.add('show');
}
function closeAffModal() { document.getElementById('linkModal').classList.remove('show'); }
function copyLink() { copyToClipboard(document.getElementById('affLink').value, 'Affiliate link copied!'); }
function copyGeneralLink() { copyToClipboard(document.getElementById('generalLink').value, 'General affiliate link copied!'); }
</script>