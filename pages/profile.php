<?php
// pages/profile.php
// User profile page
require_once 'config.php';
require_once 'functions.php';

if (!isset($uid)) redirect('login.php');

// Fetch current user details
$stmt = $pdo->prepare("
    SELECT u.*, w.balance
    FROM users u
    JOIN wallets w ON w.user_id = u.id
    WHERE u.id = ?
");
$stmt->execute([$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) redirect('dashboard.php', 'User not found.');
?>

<div class="bg-white shadow rounded-lg p-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-4">My Profile</h2>

    <!-- Basic Info -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div>
            <label class="block text-sm font-medium text-gray-700">Username</label>
            <p class="mt-1 text-lg text-gray-900"><?= htmlspecialchars($user['username']) ?></p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Email</label>
            <p class="mt-1 text-lg text-gray-900">
                <?= htmlspecialchars($user['email'] ?? 'Not provided') ?>
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Wallet Balance</label>
            <p class="mt-1 text-lg font-semibold text-green-600">
                $<?= number_format($user['balance'], 2) ?>
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Member Since</label>
            <p class="mt-1 text-lg text-gray-900">
                <?= htmlspecialchars($user['created_at']) ?>
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Sponsor</label>
            <p class="mt-1 text-lg text-gray-900">
                <?= htmlspecialchars(getUsernameById($user['sponsor_id'], $pdo) ?? 'None') ?>
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Upline</label>
            <p class="mt-1 text-lg text-gray-900">
                <?= htmlspecialchars(getUsernameById($user['upline_id'], $pdo) ?? 'None') ?>
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Binary Position</label>
            <p class="mt-1 text-lg text-gray-900">
                <?= htmlspecialchars(ucfirst($user['position'] ?? 'Root')) ?>
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700">Account Status</label>
            <p class="mt-1 text-lg text-gray-900">
                <span class="px-2 py-1 rounded-full text-xs font-semibold
                    <?= $user['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                    <?= htmlspecialchars(ucfirst($user['status'])) ?>
                </span>
            </p>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="mt-8 flex flex-col sm:flex-row gap-4">
        <a href="dashboard.php?page=wallet" class="w-full sm:w-auto bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 text-center">
            View Wallet
        </a>
        <a href="dashboard.php?page=settings" class="w-full sm:w-auto bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 text-center">
            Account Settings
        </a>
    </div>
</div>