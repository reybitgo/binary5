<?php
// packages
$packages = $pdo->query("SELECT * FROM packages")->fetchAll();
?>

<!-- Package Store Section -->
<h2 class="text-2xl font-bold text-gray-800 mb-4">Package Store</h2>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($packages as $p): ?>
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-700"><?=htmlspecialchars($p['name'])?></h3>
        <p class="text-2xl text-blue-500">$<?=number_format($p['price'], 2)?></p>
        <p class="text-gray-600"><?=$p['pv']?> PV</p>
        <form method="post" action="dashboard.php" class="mt-4">
            <input type="hidden" name="package_id" value="<?=$p['id']?>">
            <button class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600" name="action" value="buy_package">Buy Now</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>