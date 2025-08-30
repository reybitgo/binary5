<?php
// pages/profile.php
// User profile page with edit functionality
require_once 'config.php';
require_once 'functions.php';

if (!isset($uid)) redirect('login.php');

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_email'])) {
        $new_email = trim($_POST['email']);
        
        if (empty($new_email)) {
            $message = 'Email cannot be empty.';
            $message_type = 'error';
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $message_type = 'error';
        } else {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$new_email, $uid]);
            
            if ($stmt->fetch()) {
                $message = 'This email address is already in use.';
                $message_type = 'error';
            } else {
                // Update email
                $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                if ($stmt->execute([$new_email, $uid])) {
                    $message = 'Email updated successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Failed to update email. Please try again.';
                    $message_type = 'error';
                }
            }
        }
    }
    
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Fetch current password hash
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $message = 'All password fields are required.';
            $message_type = 'error';
        } elseif (!password_verify($current_password, $user_data['password'])) {
            $message = 'Current password is incorrect.';
            $message_type = 'error';
        } elseif (strlen($new_password) < 6) {
            $message = 'New password must be at least 6 characters long.';
            $message_type = 'error';
        } elseif ($new_password !== $confirm_password) {
            $message = 'New passwords do not match.';
            $message_type = 'error';
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            
            if ($stmt->execute([$hashed_password, $uid])) {
                $message = 'Password updated successfully!';
                $message_type = 'success';
            } else {
                $message = 'Failed to update password. Please try again.';
                $message_type = 'error';
            }
        }
    }
}

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

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Success/Error Messages -->
    <?php if ($message): ?>
        <div class="p-4 rounded-lg <?= $message_type === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800' ?>">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <?php if ($message_type === 'success'): ?>
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    <?php else: ?>
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                        </svg>
                    <?php endif; ?>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium"><?= htmlspecialchars($message) ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Profile Information -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
            <h2 class="text-2xl font-bold text-white flex items-center">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                My Profile
            </h2>
        </div>

        <div class="p-6">
            <!-- Basic Info (Read-only) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <div class="bg-gray-50 p-4 rounded-lg">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Username</label>
                    <p class="text-lg font-semibold text-gray-900"><?= htmlspecialchars($user['username']) ?></p>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Wallet Balance</label>
                    <p class="text-lg font-semibold text-green-600">
                        $<?= number_format($user['balance'], 2) ?>
                    </p>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Member Since</label>
                    <p class="text-lg text-gray-900">
                        <?= date('M j, Y', strtotime($user['created_at'])) ?>
                    </p>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Sponsor</label>
                    <p class="text-lg text-gray-900">
                        <?= htmlspecialchars(getUsernameById($user['sponsor_id'], $pdo) ?? 'None') ?>
                    </p>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Upline</label>
                    <p class="text-lg text-gray-900">
                        <?= htmlspecialchars(getUsernameById($user['upline_id'], $pdo) ?? 'None') ?>
                    </p>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Binary Position</label>
                    <p class="text-lg text-gray-900">
                        <?= htmlspecialchars(ucfirst($user['position'] ?? 'Root')) ?>
                    </p>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg">
                    <label class="block text-sm font-medium text-gray-600 mb-1">Account Status</label>
                    <span class="inline-flex px-3 py-1 rounded-full text-sm font-semibold
                        <?= $user['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                        <?= htmlspecialchars(ucfirst($user['status'])) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Email Update Section -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
            <h3 class="text-xl font-bold text-white flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                Update Email Address
            </h3>
        </div>

        <div class="p-6">
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Current Email</label>
                    <p class="text-lg text-gray-600 mb-4">
                        <?= htmlspecialchars($user['email'] ?? 'Not provided') ?>
                    </p>
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">New Email Address</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        value="<?= htmlspecialchars($user['email'] ?? '') ?>"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                        placeholder="Enter new email address"
                        required
                    >
                </div>

                <div class="flex justify-end">
                    <button 
                        type="submit" 
                        name="update_email"
                        class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition-colors font-medium flex items-center"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Update Email
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Update Section -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden">
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
            <h3 class="text-xl font-bold text-white flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                Change Password
            </h3>
        </div>

        <div class="p-6">
            <form method="POST" class="space-y-4">
                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                    <input 
                        type="password" 
                        id="current_password" 
                        name="current_password"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                        placeholder="Enter current password"
                        required
                    >
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                    <input 
                        type="password" 
                        id="new_password" 
                        name="new_password"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                        placeholder="Enter new password (min. 6 characters)"
                        minlength="6"
                        required
                    >
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                        placeholder="Confirm new password"
                        required
                    >
                </div>

                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-yellow-600 mt-0.5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        <div>
                            <p class="text-sm text-yellow-800">
                                <strong>Security Note:</strong> Choose a strong password with at least 6 characters. Consider using a mix of letters, numbers, and symbols.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button 
                        type="submit" 
                        name="update_password"
                        class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition-colors font-medium flex items-center"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden">
        <div class="bg-gradient-to-r from-gray-700 to-gray-800 px-6 py-4">
            <h3 class="text-xl font-bold text-white">Quick Actions</h3>
        </div>
        
        <div class="p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <a href="dashboard.php?page=wallet" class="bg-green-50 hover:bg-green-100 border border-green-200 rounded-lg p-4 transition-colors group">
                    <div class="flex items-center">
                        <div class="bg-green-500 p-2 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-green-800">View Wallet</p>
                            <p class="text-xs text-green-600">Manage your funds</p>
                        </div>
                    </div>
                </a>

                <a href="dashboard.php?page=network" class="bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg p-4 transition-colors group">
                    <div class="flex items-center">
                        <div class="bg-blue-500 p-2 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-blue-800">My Network</p>
                            <p class="text-xs text-blue-600">View your team</p>
                        </div>
                    </div>
                </a>

                <a href="dashboard.php?page=earnings" class="bg-purple-50 hover:bg-purple-100 border border-purple-200 rounded-lg p-4 transition-colors group">
                    <div class="flex items-center">
                        <div class="bg-purple-500 p-2 rounded-lg">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-purple-800">Earnings</p>
                            <p class="text-xs text-purple-600">Track your income</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Password confirmation validation
document.addEventListener('DOMContentLoaded', function() {
    const newPassword = document.getElementById('new_password');    
    const confirmPassword = document.getElementById('confirm_password');
    
    function validatePassword() {
        if (confirmPassword.value && newPassword.value !== confirmPassword.value) {
            confirmPassword.setCustomValidity('Passwords do not match');
        } else {
            confirmPassword.setCustomValidity('');
        }
    }
    
    newPassword.addEventListener('input', validatePassword);
    confirmPassword.addEventListener('input', validatePassword);
});
</script>