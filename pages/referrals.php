<?php
// pages/referrals.php - Simple working version
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    redirect('login.php');
}

$uid = $_SESSION['user_id'];

// Initialize variables with defaults
$totalReferralBonus = 0;
$referrals = [];
$error = null;

try {
    // Step 1: Get total referral bonus (simple query)
    $totalBonusStmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM wallet_tx 
        WHERE user_id = ? AND type = 'referral_bonus'
    ");
    $totalBonusStmt->execute([$uid]);
    $totalReferralBonus = (float)$totalBonusStmt->fetchColumn();
    
    echo "<!-- Debug: Total bonus query successful: $totalReferralBonus -->\n";

} catch (PDOException $e) {
    error_log("Error getting total referral bonus: " . $e->getMessage());
    $error = "Error loading bonus data";
}

try {
    // Step 2: Get direct referrals (simple query first)
    $referralsStmt = $pdo->prepare("
        SELECT 
            id,
            username,
            created_at,
            status
        FROM users 
        WHERE sponsor_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $referralsStmt->execute([$uid]);
    $referrals = $referralsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<!-- Debug: Found " . count($referrals) . " referrals -->\n";

} catch (PDOException $e) {
    error_log("Error getting referrals: " . $e->getMessage());
    $error = "Error loading referral list";
}

// Try to get earnings per referral (separate query to avoid complexity)
$referralEarnings = [];
if (!empty($referrals) && !$error) {
    try {
        foreach ($referrals as $ref) {
            $earningsStmt = $pdo->prepare("
                SELECT COALESCE(SUM(amount), 0)
                FROM wallet_tx 
                WHERE user_id = ? 
                AND type = 'referral_bonus'
                AND created_at >= (
                    SELECT created_at 
                    FROM users 
                    WHERE id = ? 
                    LIMIT 1
                )
            ");
            $earningsStmt->execute([$uid, $ref['id']]);
            $referralEarnings[$ref['id']] = (float)$earningsStmt->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log("Error calculating individual earnings: " . $e->getMessage());
        // Continue without individual earnings
    }
}

// Basic stats
$totalReferrals = count($referrals);
$activeReferrals = 0;
foreach ($referrals as $ref) {
    if (($ref['status'] ?? 'active') === 'active') {
        $activeReferrals++;
    }
}
?>

<!-- Referrals Section -->
<div class="space-y-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-800 mb-2">Referral Network</h2>
        <p class="text-gray-600">Manage your direct referrals and track commissions</p>
    </div>

    <!-- Error Display -->
    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-800">
            <div class="flex items-center">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
                <?= htmlspecialchars($error) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Simple Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Total Earnings -->
        <div class="bg-white shadow rounded-lg p-6 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Total Referral Earnings</p>
                    <p class="text-2xl font-bold text-gray-900">$<?= number_format($totalReferralBonus, 2) ?></p>
                </div>
                <div class="bg-blue-100 rounded-full p-3">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Total Referrals -->
        <div class="bg-white shadow rounded-lg p-6 border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Total Referrals</p>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($totalReferrals) ?></p>
                </div>
                <div class="bg-green-100 rounded-full p-3">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- Active Referrals -->
        <div class="bg-white shadow rounded-lg p-6 border-l-4 border-purple-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-gray-500 text-sm font-medium">Active Referrals</p>
                    <p class="text-2xl font-bold text-gray-900"><?= number_format($activeReferrals) ?></p>
                </div>
                <div class="bg-purple-100 rounded-full p-3">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Referral Link Card -->
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-700 mb-4">Your Referral Link</h3>
        <div class="bg-gray-50 border rounded-lg p-4">
            <div class="flex items-center space-x-3">
                <input type="text" id="referralLink" readonly 
                       value="<?= (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] ?>/register.php?ref=<?= $uid ?>" 
                       class="flex-1 border rounded p-3 bg-white text-sm font-mono">
                <button onclick="copyReferralLink()" 
                        class="bg-blue-500 text-white px-4 py-3 rounded hover:bg-blue-600 transition-colors">
                    Copy Link
                </button>
            </div>
            <p class="text-sm text-gray-600 mt-3">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Share this link to earn commission on referral purchases
            </p>
        </div>
    </div>

    <!-- Referrals List -->
    <div class="bg-white shadow rounded-lg p-6">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-lg font-semibold text-gray-700">Direct Referrals</h3>
            <div class="text-sm text-gray-500">
                <?= number_format($totalReferrals) ?> total
            </div>
        </div>

        <?php if (empty($referrals)): ?>
            <div class="text-center py-12">
                <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No referrals yet</h3>
                <p class="text-gray-600 mb-4">Start sharing your referral link to build your network!</p>
                <button onclick="copyReferralLink()" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition-colors">
                    Copy Referral Link
                </button>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="p-3 text-left text-gray-600 font-medium">Referral</th>
                            <th class="p-3 text-left text-gray-600 font-medium">Joined</th>
                            <th class="p-3 text-left text-gray-600 font-medium">Status</th>
                            <th class="p-3 text-left text-gray-600 font-medium">Your Earnings</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($referrals as $ref): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="p-3">
                                    <div class="flex items-center space-x-3">
                                        <div class="bg-blue-100 rounded-full w-10 h-10 flex items-center justify-center">
                                            <span class="text-blue-600 font-semibold">
                                                <?= strtoupper(substr($ref['username'], 0, 2)) ?>
                                            </span>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900"><?= htmlspecialchars($ref['username']) ?></p>
                                            <p class="text-sm text-gray-500">ID: <?= $ref['id'] ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <div class="text-sm">
                                        <div class="text-gray-900"><?= date('M j, Y', strtotime($ref['created_at'])) ?></div>
                                        <div class="text-gray-500"><?= date('g:i A', strtotime($ref['created_at'])) ?></div>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full 
                                        <?php
                                        $status = $ref['status'] ?? 'active';
                                        if ($status === 'active') {
                                            echo 'bg-green-100 text-green-800';
                                        } elseif ($status === 'inactive') {
                                            echo 'bg-yellow-100 text-yellow-800';
                                        } else {
                                            echo 'bg-red-100 text-red-800';
                                        }
                                        ?>">
                                        <?= htmlspecialchars(ucfirst($status)) ?>
                                    </span>
                                </td>
                                <td class="p-3">
                                    <div class="text-sm">
                                        <div class="font-medium text-green-600">
                                            $<?= number_format($referralEarnings[$ref['id']] ?? 0, 2) ?>
                                        </div>
                                        <?php if (($referralEarnings[$ref['id']] ?? 0) > 0): ?>
                                            <div class="text-gray-500">earned</div>
                                        <?php else: ?>
                                            <div class="text-gray-400">no purchases yet</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Simple Summary -->
    <div class="bg-white shadow rounded-lg p-6">
        <h3 class="text-lg font-semibold text-gray-700 mb-4">Summary</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="text-2xl font-bold text-blue-600"><?= $totalReferrals ?></div>
                <div class="text-sm text-gray-600">Total Referrals</div>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="text-2xl font-bold text-green-600"><?= $activeReferrals ?></div>
                <div class="text-sm text-gray-600">Active Members</div>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <div class="text-2xl font-bold text-purple-600">
                    <?= $totalReferrals > 0 ? number_format(($activeReferrals / $totalReferrals) * 100, 1) : 0 ?>%
                </div>
                <div class="text-sm text-gray-600">Conversion Rate</div>
            </div>
        </div>
    </div>
</div>

<script>
function copyReferralLink() {
    const linkInput = document.getElementById('referralLink');
    
    // Try modern clipboard API first
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(linkInput.value).then(() => {
            showCopySuccess();
        }).catch(() => {
            fallbackCopy();
        });
    } else {
        fallbackCopy();
    }
    
    function fallbackCopy() {
        linkInput.select();
        linkInput.setSelectionRange(0, 99999);
        try {
            document.execCommand('copy');
            showCopySuccess();
        } catch (err) {
            alert('Please manually copy the link:\n' + linkInput.value);
        }
    }
    
    function showCopySuccess() {
        const button = event.target;
        const originalText = button.textContent;
        const originalClass = button.className;
        
        button.textContent = 'Copied!';
        button.className = 'bg-green-500 text-white px-4 py-3 rounded transition-colors';
        
        setTimeout(() => {
            button.textContent = originalText;
            button.className = originalClass;
        }, 2000);
    }
}

// Debug info toggle
console.log('Referrals page loaded');
console.log('Total referrals:', <?= $totalReferrals ?>);
console.log('Total bonus:', <?= $totalReferralBonus ?>);
</script>