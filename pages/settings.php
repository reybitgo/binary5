<!-- Settings Section -->
<h2 class="text-2xl font-bold text-gray-800 mb-4">Settings</h2>

<div class="bg-white shadow rounded-lg p-6">
    <p class="text-gray-600">Settings functionality is not implemented yet.</p>
    
    <div class="mt-6">
        <h3 class="text-lg font-semibold text-gray-700 mb-4">Account Information</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Username</label>
                <p class="mt-1 text-sm text-gray-900"><?=htmlspecialchars($user['username'])?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Email</label>
                <p class="mt-1 text-sm text-gray-900"><?=htmlspecialchars($user['email'] ?? 'Not set')?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Member Since</label>
                <p class="mt-1 text-sm text-gray-900"><?=htmlspecialchars($user['created_at'] ?? 'N/A')?></p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Sponsor</label>
                <p class="mt-1 text-sm text-gray-900"><?=htmlspecialchars($user['sponsor_name'] ?? 'None')?></p>
            </div>
        </div>
    </div>
    
    <div class="mt-6">
        <h3 class="text-lg font-semibold text-gray-700 mb-4">Quick Stats</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="border rounded-lg p-4">
                <p class="text-sm text-gray-600">Current Balance</p>
                <p class="text-lg font-semibold text-green-600">$<?=number_format($user['balance'], 2)?></p>
            </div>
            <div class="border rounded-lg p-4">
                <p class="text-sm text-gray-600">Left Count</p>
                <p class="text-lg font-semibold text-blue-600"><?=$user['left_count'] ?? 0?></p>
            </div>
            <div class="border rounded-lg p-4">
                <p class="text-sm text-gray-600">Right Count</p>
                <p class="text-lg font-semibold text-blue-600"><?=$user['right_count'] ?? 0?></p>
            </div>
            <div class="border rounded-lg p-4">
                <p class="text-sm text-gray-600">Pairs Today</p>
                <p class="text-lg font-semibold text-purple-600"><?=$user['pairs_today'] ?? 0?>/10</p>
            </div>
        </div>
    </div>
</div>