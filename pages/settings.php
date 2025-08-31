<?php
// pages/settings.php (admin only)
require_once 'config.php';
require_once 'functions.php';

if (!isset($uid)) redirect('login.php');

$role = $pdo->query("SELECT role FROM users WHERE id = $uid")->fetchColumn();
if ($role !== 'admin') redirect('dashboard.php', 'Admin access only');

// ---- handle AJAX save ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_package'])) {
    // Clean any output buffers and prevent any HTML output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        $pid = (int)$_POST['package_id'];
        
        // Validate package exists
        $packageExists = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE id = ?");
        $packageExists->execute([$pid]);
        if (!$packageExists->fetchColumn()) {
            throw new Exception('Package not found');
        }
        
        // Update package basic info and rates
        $stmt = $pdo->prepare("UPDATE packages SET
            name = ?,
            price = ?,
            pv = ?,
            daily_max = ?,
            pair_rate = ?,
            referral_rate = ?
        WHERE id = ?");
        $stmt->execute([
            trim($_POST['name']),
            max(0, (float)$_POST['price']),
            max(0, (float)$_POST['pv']),
            max(0, (int)$_POST['daily_max']),
            max(0, min(1, (float)$_POST['pair_rate'])),
            max(0, min(1, (float)$_POST['referral_rate'])),
            $pid
        ]);

        // leadership schedule
        foreach (range(1,5) as $lvl) {
            $stmt = $pdo->prepare("REPLACE INTO package_leadership_schedule
              (package_id, level, pvt_required, gvt_required, rate)
              VALUES (?,?,?,?,?)");
            $stmt->execute([
                $pid, $lvl,
                max(0, (int)$_POST["lead_pvt_$lvl"]),
                max(0, (int)$_POST["lead_gvt_$lvl"]),
                max(0, (float)$_POST["lead_rate_$lvl"])
            ]);
        }
        
        // mentor schedule
        foreach (range(1,5) as $lvl) {
            $stmt = $pdo->prepare("REPLACE INTO package_mentor_schedule
              (package_id, level, pvt_required, gvt_required, rate)
              VALUES (?,?,?,?,?)");
            $stmt->execute([
                $pid, $lvl,
                max(0, (int)$_POST["mentor_pvt_$lvl"]),
                max(0, (int)$_POST["mentor_gvt_$lvl"]),
                max(0, (float)$_POST["mentor_rate_$lvl"])
            ]);
        }
        
        $response = ['status' => 'success', 'message' => 'Package settings saved successfully'];
        echo json_encode($response);
        
    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => 'Failed to save settings: ' . $e->getMessage()];
        echo json_encode($response);
    }
    
    exit();
}

// ---- handle add new package ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_package'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        // Validate input
        $name = trim($_POST['new_name']);
        $price = max(0, (float)$_POST['new_price']);
        $pv = max(0, (float)$_POST['new_pv']);
        
        if (empty($name)) {
            throw new Exception('Package name is required');
        }
        
        // Check if package name already exists
        $nameCheck = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE name = ?");
        $nameCheck->execute([$name]);
        if ($nameCheck->fetchColumn() > 0) {
            throw new Exception('Package name already exists');
        }
        
        // Insert new package with default values
        $stmt = $pdo->prepare("INSERT INTO packages (name, price, pv, daily_max, pair_rate, referral_rate) VALUES (?, ?, ?, 10, 0.05, 0.10)");
        $stmt->execute([$name, $price, $pv]);
        
        $newPackageId = $pdo->lastInsertId();
        
        // Initialize default leadership schedule
        foreach (range(1, 5) as $lvl) {
            $stmt = $pdo->prepare("INSERT INTO package_leadership_schedule (package_id, level, pvt_required, gvt_required, rate) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$newPackageId, $lvl, $lvl * 100, $lvl * 50, $lvl * 0.01]);
        }
        
        // Initialize default mentor schedule
        foreach (range(1, 5) as $lvl) {
            $stmt = $pdo->prepare("INSERT INTO package_mentor_schedule (package_id, level, pvt_required, gvt_required, rate) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$newPackageId, $lvl, $lvl * 50, $lvl * 25, $lvl * 0.005]);
        }
        
        $response = ['status' => 'success', 'message' => 'New package added successfully', 'reload' => true];
        echo json_encode($response);
        
    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => 'Failed to add package: ' . $e->getMessage()];
        echo json_encode($response);
    }
    
    exit();
}

// ---- handle delete package ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_package'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        $pid = (int)$_POST['package_id'];
        
        // Check if package exists
        $packageExists = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE id = ?");
        $packageExists->execute([$pid]);
        if (!$packageExists->fetchColumn()) {
            throw new Exception('Package not found');
        }
        
        // Check if package has been purchased (prevent deletion if in use)
        $userCheck = $pdo->prepare("SELECT COUNT(*) FROM wallet_tx WHERE package_id = ? AND type = 'package'");
        $userCheck->execute([$pid]);
        if ($userCheck->fetchColumn() > 0) {
            throw new Exception('Cannot delete package: It has transaction history');
        }
        
        // Delete related records first
        $pdo->prepare("DELETE FROM package_leadership_schedule WHERE package_id = ?")->execute([$pid]);
        $pdo->prepare("DELETE FROM package_mentor_schedule WHERE package_id = ?")->execute([$pid]);
        
        // Delete the package
        $stmt = $pdo->prepare("DELETE FROM packages WHERE id = ?");
        $stmt->execute([$pid]);
        
        $response = ['status' => 'success', 'message' => 'Package deleted successfully', 'reload' => true];
        echo json_encode($response);
        
    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => 'Failed to delete package: ' . $e->getMessage()];
        echo json_encode($response);
    }
    
    exit();
}

// ---- fetch data ----
$packages = $pdo->query("SELECT * FROM packages ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Package Settings</title>
    <style>
        .tooltip {
            position: absolute;
            background: #1f2937;
            color: white;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 20;
            transform: translateY(-100%) translateX(-50%);
            top: -0.5rem;
            pointer-events: none;
            display: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .tooltip-container {
            position: relative;
            display: inline-block;
        }
        .tooltip::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: #1f2937 transparent transparent transparent;
        }
        
        /* Toast Notification Styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            pointer-events: none;
        }
        
        .toast {
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            margin-bottom: 10px;
            padding: 16px;
            min-width: 300px;
            max-width: 400px;
            pointer-events: auto;
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            border-left: 4px solid;
        }
        
        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }
        
        .toast.success {
            border-left-color: #10b981;
        }
        
        .toast.error {
            border-left-color: #ef4444;
        }
        
        .toast.warning {
            border-left-color: #f59e0b;
        }
        
        .toast.info {
            border-left-color: #3b82f6;
        }
        
        .toast-header {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .toast-icon {
            width: 20px;
            height: 20px;
            margin-right: 8px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }
        
        .toast.success .toast-icon {
            background-color: #10b981;
        }
        
        .toast.error .toast-icon {
            background-color: #ef4444;
        }
        
        .toast.warning .toast-icon {
            background-color: #f59e0b;
        }
        
        .toast.info .toast-icon {
            background-color: #3b82f6;
        }
        
        .toast-title {
            font-weight: 600;
            color: #1f2937;
            flex: 1;
        }
        
        .toast-close {
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            font-size: 18px;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .toast-close:hover {
            color: #374151;
        }
        
        .toast-message {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            border-radius: 0 0 8px 8px;
            transition: width linear;
        }
        
        .toast.success .toast-progress {
            background-color: #10b981;
        }
        
        .toast.error .toast-progress {
            background-color: #ef4444;
        }
        
        .toast.warning .toast-progress {
            background-color: #f59e0b;
        }
        
        .toast.info .toast-progress {
            background-color: #3b82f6;
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 1rem;
        }
        
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 24px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.9) translateY(-20px);
            transition: transform 0.3s ease;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }
        
        .modal.show .modal-content {
            transform: scale(1) translateY(0);
        }

        /* Mobile-specific improvements */
        .bonus-table-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .bonus-input {
            min-width: 0;
            flex: 1;
        }
        
        .level-label {
            min-width: 2rem;
            text-align: center;
            font-weight: 500;
        }

        /* Enhanced mobile responsive breakpoints */
        @media (max-width: 768px) {
            .toast-container {
                top: 10px;
                right: 10px;
                left: 10px;
            }
            
            .toast {
                min-width: auto;
                max-width: none;
            }
            
            .modal-content {
                padding: 1rem;
                margin: 1rem;
                max-height: calc(100vh - 2rem);
            }
            
            /* Reduce grid gap on mobile */
            .packages-grid {
                gap: 1rem;
            }
            
            /* Stack bonus inputs vertically on very small screens */
            .bonus-table-row {
                flex-wrap: wrap;
                gap: 0.25rem;
            }
            
            .bonus-input {
                min-width: calc(33.333% - 0.25rem);
            }
        }

        @media (max-width: 480px) {
            .bonus-table-row {
                display: grid;
                grid-template-columns: auto 1fr 1fr 1fr;
                gap: 0.5rem;
                align-items: center;
            }
            
            .bonus-input {
                min-width: 0;
            }
            
            /* Make package cards full width on very small screens */
            .packages-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Prevent horizontal scroll */
        body {
            overflow-x: hidden;
        }
        
        * {
            box-sizing: border-box;
        }
        
        /* Ensure inputs don't overflow */
        input[type="text"],
        input[type="number"] {
            min-width: 0;
            width: 100%;
        }
        
        /* Collapsible sections for mobile */
        .collapsible-section {
            transition: max-height 0.3s ease-out;
            overflow: hidden;
        }
        
        .collapsible-header {
            cursor: pointer;
            user-select: none;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .collapsible-toggle {
            transition: transform 0.3s ease;
        }
        
        .collapsible-section.collapsed {
            max-height: 0;
        }
        
        .collapsible-header[aria-expanded="false"] .collapsible-toggle {
            transform: rotate(-90deg);
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Toast Container -->
    <div class="toast-container" id="toast-container"></div>
    
    <!-- Add Package Modal -->
    <div id="addPackageModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-semibold text-gray-800">Add New Package</h3>
                <button onclick="closeAddPackageModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>
            
            <form id="addPackageForm" onsubmit="return handleAddPackage(event)">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Package Name
                            <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="new_name" required
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                               placeholder="Enter package name">
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Price ($)
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="number" step="0.01" min="0" name="new_price" required
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                   placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                PV
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="number" step="0.01" min="0" name="new_pv" required
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                   placeholder="0.00">
                        </div>
                    </div>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-3 mt-6">
                    <button type="button" onclick="closeAddPackageModal()"
                            class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-400 transition-colors order-2 sm:order-1">
                        Cancel
                    </button>
                    <button type="submit"
                            class="flex-1 bg-green-600 text-white py-2 rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed order-1 sm:order-2">
                        <span class="button-text">Add Package</span>
                        <span class="button-loading hidden">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Adding...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="modal">
        <div class="modal-content max-w-md">
            <div class="flex items-center justify-center w-12 h-12 mx-auto mb-4 bg-red-100 rounded-full">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.464 0L4.35 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
            </div>
            
            <div class="text-center mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Delete Package</h3>
                <p class="text-gray-600 text-sm sm:text-base">
                    Are you sure you want to delete "<span id="deletePackageName" class="font-medium text-gray-900"></span>"? 
                    This action cannot be undone.
                </p>
            </div>
            
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="button" onclick="closeDeleteConfirmModal()"
                        class="flex-1 bg-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-400 transition-colors order-2 sm:order-1">
                    Cancel
                </button>
                <button type="button" onclick="confirmDeletePackage()"
                        class="flex-1 bg-red-600 text-white py-2 rounded-lg hover:bg-red-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed order-1 sm:order-2">
                    <span class="button-text">Delete Package</span>
                    <span class="button-loading hidden">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Deleting...
                    </span>
                </button>
            </div>
        </div>
    </div>
    
    <div class="container mx-auto px-4 py-4 sm:py-8">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-800">Package Settings</h2>
            <button onclick="openAddPackageModal()" 
                    class="w-full sm:w-auto bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-700 transition-colors flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Add Package
            </button>
        </div>

        <div class="packages-grid grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6">
            <?php foreach ($packages as $pkg): ?>
                <div class="bg-white shadow-lg rounded-lg p-4 sm:p-6 relative">
                    <!-- Delete Button -->
                    <button onclick="showDeleteConfirm(<?= $pkg['id'] ?>, '<?= htmlspecialchars($pkg['name'], ENT_QUOTES) ?>')"
                            class="absolute top-3 sm:top-4 right-3 sm:right-4 text-red-500 hover:text-red-700 transition-colors"
                            title="Delete Package">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>

                    <form method="post" class="space-y-4 sm:space-y-6 package-form" data-package-id="<?= $pkg['id'] ?>" onsubmit="return handleFormSubmit(event, this)">
                        <input type="hidden" name="package_id" value="<?= $pkg['id'] ?>">
                        <input type="hidden" name="save_package" value="1">
                        
                        <!-- Package Basic Info -->
                        <div class="space-y-3 sm:space-y-4">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 pr-8">Package Details</h3>
                            
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-600">
                                    Package Name
                                    <span class="ml-1 text-gray-400 cursor-help tooltip-container">
                                        ℹ️
                                        <span class="tooltip">Name of the package</span>
                                    </span>
                                </label>
                                <input type="text" name="name" required
                                       class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                       value="<?= htmlspecialchars($pkg['name']) ?>">
                            </div>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-gray-600">
                                        Price ($)
                                        <span class="ml-1 text-gray-400 cursor-help tooltip-container">
                                            ℹ️
                                            <span class="tooltip">Package price in dollars</span>
                                        </span>
                                    </label>
                                    <input type="number" step="0.01" min="0" name="price" required
                                           class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                           value="<?= htmlspecialchars($pkg['price']) ?>">
                                </div>
                                
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-gray-600">
                                        PV
                                        <span class="ml-1 text-gray-400 cursor-help tooltip-container">
                                            ℹ️
                                            <span class="tooltip">Package volume points</span>
                                        </span>
                                    </label>
                                    <input type="number" step="0.01" min="0" name="pv" required
                                           class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                           value="<?= htmlspecialchars($pkg['pv']) ?>">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Base Rates -->
                        <div class="space-y-3 sm:space-y-4">
                            <div class="collapsible-header" onclick="toggleSection(this, 'base-rates-<?= $pkg['id'] ?>')" aria-expanded="true">
                                <h4 class="text-base sm:text-lg font-medium text-gray-700">Base Rates</h4>
                                <svg class="collapsible-toggle w-5 h-5 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                            <div id="base-rates-<?= $pkg['id'] ?>" class="collapsible-section space-y-3">
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-gray-600">
                                        Max Pairs/Day
                                        <span class="ml-1 text-gray-400 cursor-help tooltip-container">
                                            ℹ️
                                            <span class="tooltip">Maximum number of pairs allowed per day</span>
                                        </span>
                                    </label>
                                    <input type="number" name="daily_max" min="0" required
                                           class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                           value="<?= htmlspecialchars($pkg['daily_max']) ?>">
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-600">
                                            Pair Rate (0-1)
                                            <span class="ml-1 text-gray-400 cursor-help tooltip-container">
                                                ℹ️
                                                <span class="tooltip">Rate for successful pairs (0 to 1)</span>
                                            </span>
                                        </label>
                                        <input type="number" step="0.01" min="0" max="1" name="pair_rate" required
                                               class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                               value="<?= htmlspecialchars($pkg['pair_rate']) ?>">
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-sm font-medium text-gray-600">
                                            Referral Rate (0-1)
                                            <span class="ml-1 text-gray-400 cursor-help tooltip-container">
                                                ℹ️
                                                <span class="tooltip">Rate for referrals (0 to 1)</span>
                                            </span>
                                        </label>
                                        <input type="number" step="0.01" min="0" max="1" name="referral_rate" required
                                               class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                               value="<?= htmlspecialchars($pkg['referral_rate']) ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Leadership Schedule -->
                        <div class="space-y-3 sm:space-y-4">
                            <div class="collapsible-header" onclick="toggleSection(this, 'leadership-<?= $pkg['id'] ?>')" aria-expanded="false">
                                <h4 class="text-base sm:text-lg font-medium text-gray-700">Matched Bonus Settings</h4>
                                <svg class="collapsible-toggle w-5 h-5 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                            <div id="leadership-<?= $pkg['id'] ?>" class="collapsible-section collapsed">
                                <div class="space-y-2">
                                    <div class="hidden sm:flex items-center gap-2 text-xs font-medium text-gray-500 uppercase tracking-wide mb-3">
                                        <span class="level-label">Lvl</span>
                                        <span class="bonus-input text-center">PVT</span>
                                        <span class="bonus-input text-center">GVT</span>
                                        <span class="bonus-input text-center">Rate</span>
                                    </div>
                                    <?php
                                    $lead = $pdo->prepare("SELECT * FROM package_leadership_schedule WHERE package_id = ? ORDER BY level");
                                    $lead->execute([$pkg['id']]);
                                    $leadRows = $lead->fetchAll(PDO::FETCH_ASSOC);
                                    $leadData = array_fill(1, 5, ['pvt_required' => 0, 'gvt_required' => 0, 'rate' => 0]);
                                    foreach ($leadRows as $row) {
                                        $leadData[$row['level']] = [
                                            'pvt_required' => $row['pvt_required'],
                                            'gvt_required' => $row['gvt_required'],
                                            'rate' => $row['rate']
                                        ];
                                    }
                                    foreach (range(1, 5) as $lvl):
                                        $row = $leadData[$lvl];
                                    ?>
                                        <div class="bonus-table-row">
                                            <span class="level-label text-sm font-medium text-gray-700">L<?= $lvl ?></span>
                                            <input type="number" min="0" name="lead_pvt_<?= $lvl ?>" required
                                                   class="bonus-input px-2 py-1 text-sm border rounded focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                                   value="<?= htmlspecialchars($row['pvt_required']) ?>" placeholder="PVT">
                                            <input type="number" min="0" name="lead_gvt_<?= $lvl ?>" required
                                                   class="bonus-input px-2 py-1 text-sm border rounded focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                                   value="<?= htmlspecialchars($row['gvt_required']) ?>" placeholder="GVT">
                                            <input type="number" step="0.001" min="0" name="lead_rate_<?= $lvl ?>" required
                                                   class="bonus-input px-2 py-1 text-sm border rounded focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                                   value="<?= htmlspecialchars($row['rate']) ?>" placeholder="Rate">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Mentor Schedule -->
                        <div class="space-y-3 sm:space-y-4">
                            <div class="collapsible-header" onclick="toggleSection(this, 'mentor-<?= $pkg['id'] ?>')" aria-expanded="false">
                                <h4 class="text-base sm:text-lg font-medium text-gray-700">Mentor Bonus Settings</h4>
                                <svg class="collapsible-toggle w-5 h-5 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                            <div id="mentor-<?= $pkg['id'] ?>" class="collapsible-section collapsed">
                                <div class="space-y-2">
                                    <div class="hidden sm:flex items-center gap-2 text-xs font-medium text-gray-500 uppercase tracking-wide mb-3">
                                        <span class="level-label">Lvl</span>
                                        <span class="bonus-input text-center">PVT</span>
                                        <span class="bonus-input text-center">GVT</span>
                                        <span class="bonus-input text-center">Rate</span>
                                    </div>
                                    <?php
                                    $mentor = $pdo->prepare("SELECT * FROM package_mentor_schedule WHERE package_id = ? ORDER BY level");
                                    $mentor->execute([$pkg['id']]);
                                    $mentorRows = $mentor->fetchAll(PDO::FETCH_ASSOC);
                                    $mentorData = array_fill(1, 5, ['pvt_required' => 0, 'gvt_required' => 0, 'rate' => 0]);
                                    foreach ($mentorRows as $row) {
                                        $mentorData[$row['level']] = [
                                            'pvt_required' => $row['pvt_required'],
                                            'gvt_required' => $row['gvt_required'],
                                            'rate' => $row['rate']
                                        ];
                                    }
                                    foreach (range(1, 5) as $lvl):
                                        $row = $mentorData[$lvl];
                                    ?>
                                        <div class="bonus-table-row">
                                            <span class="level-label text-sm font-medium text-gray-700">L<?= $lvl ?></span>
                                            <input type="number" min="0" name="mentor_pvt_<?= $lvl ?>" required
                                                   class="bonus-input px-2 py-1 text-sm border rounded focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                                   value="<?= htmlspecialchars($row['pvt_required']) ?>" placeholder="PVT">
                                            <input type="number" min="0" name="mentor_gvt_<?= $lvl ?>" required
                                                   class="bonus-input px-2 py-1 text-sm border rounded focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                                   value="<?= htmlspecialchars($row['gvt_required']) ?>" placeholder="GVT">
                                            <input type="number" step="0.001" min="0" name="mentor_rate_<?= $lvl ?>" required
                                                   class="bonus-input px-2 py-1 text-sm border rounded focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                                   value="<?= htmlspecialchars($row['rate']) ?>" placeholder="Rate">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <button type="submit"
                                class="w-full bg-blue-600 text-white py-2 px-4 text-sm sm:text-base rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                            <span class="button-text">Save Settings</span>
                            <span class="button-loading hidden">
                                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Saving...
                            </span>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // Toast Notification System
        class ToastNotification {
            constructor() {
                this.container = document.getElementById('toast-container');
                this.toasts = [];
            }
            
            show(message, type = 'info', duration = 5000) {
                const toast = this.createToast(message, type, duration);
                this.container.appendChild(toast);
                this.toasts.push(toast);
                
                // Trigger animation
                requestAnimationFrame(() => {
                    toast.classList.add('show');
                });
                
                // Auto remove
                if (duration > 0) {
                    const progressBar = toast.querySelector('.toast-progress');
                    if (progressBar) {
                        progressBar.style.width = '100%';
                        progressBar.style.transitionDuration = duration + 'ms';
                        requestAnimationFrame(() => {
                            progressBar.style.width = '0%';
                        });
                    }
                    
                    setTimeout(() => {
                        this.remove(toast);
                    }, duration);
                }
            }
            
            createToast(message, type, duration) {
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                
                const icons = {
                    success: '✓',
                    error: '✕',
                    warning: '!',
                    info: 'i'
                };
                
                const titles = {
                    success: 'Success',
                    error: 'Error',
                    warning: 'Warning',
                    info: 'Info'
                };
                
                toast.innerHTML = `
                    <div class="toast-header">
                        <div class="toast-icon">${icons[type] || icons.info}</div>
                        <div class="toast-title">${titles[type] || titles.info}</div>
                        <button class="toast-close" onclick="toastSystem.remove(this.closest('.toast'))">&times;</button>
                    </div>
                    <div class="toast-message">${message}</div>
                    ${duration > 0 ? '<div class="toast-progress"></div>' : ''}
                `;
                
                return toast;
            }
            
            remove(toast) {
                if (!toast || !toast.parentNode) return;
                
                toast.classList.remove('show');
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                    this.toasts = this.toasts.filter(t => t !== toast);
                }, 300);
            }
            
            success(message, duration = 5000) {
                this.show(message, 'success', duration);
            }
            
            error(message, duration = 7000) {
                this.show(message, 'error', duration);
            }
            
            warning(message, duration = 6000) {
                this.show(message, 'warning', duration);
            }
            
            info(message, duration = 5000) {
                this.show(message, 'info', duration);
            }
        }
        
        // Initialize toast system
        const toastSystem = new ToastNotification();

        // Collapsible sections functionality
        function toggleSection(header, sectionId) {
            const section = document.getElementById(sectionId);
            const isExpanded = header.getAttribute('aria-expanded') === 'true';
            
            header.setAttribute('aria-expanded', !isExpanded);
            
            if (isExpanded) {
                section.classList.add('collapsed');
            } else {
                section.classList.remove('collapsed');
            }
        }

        // Modal Functions
        function openAddPackageModal() {
            const modal = document.getElementById('addPackageModal');
            document.getElementById('addPackageForm').reset();
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeAddPackageModal() {
            const modal = document.getElementById('addPackageModal');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        // Delete confirmation modal functions
        let packageToDelete = null;
        
        function showDeleteConfirm(packageId, packageName) {
            packageToDelete = packageId;
            document.getElementById('deletePackageName').textContent = packageName;
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeDeleteConfirmModal() {
            const modal = document.getElementById('deleteConfirmModal');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
            packageToDelete = null;
        }
        
        function confirmDeletePackage() {
            if (!packageToDelete) return;
            
            const submitButton = document.querySelector('#deleteConfirmModal button[onclick="confirmDeletePackage()"]');
            setButtonState(submitButton, true);

            const formData = new FormData();
            formData.append('delete_package', '1');
            formData.append('package_id', packageToDelete);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server returned non-JSON response');
                }
                
                return response.json();
            })
            .then(data => {
                if (data.status === 'success') {
                    toastSystem.success(data.message);
                    closeDeleteConfirmModal();
                    
                    // Reload page if requested
                    if (data.reload) {
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    }
                } else {
                    toastSystem.error(data.message);
                }
            })
            .catch(error => {
                console.error('Error details:', error);
                toastSystem.error(`Failed to delete package: ${error.message}`);
            })
            .finally(() => {
                setButtonState(submitButton, false);
            });
        }
        
        // Close modals when clicking outside
        document.getElementById('addPackageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddPackageModal();
            }
        });
        
        document.getElementById('deleteConfirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteConfirmModal();
            }
        });
        
        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddPackageModal();
                closeDeleteConfirmModal();
            }
        });

        function validateForm(form) {
            const inputs = form.querySelectorAll('input[type="number"], input[type="text"]');
            let valid = true;
            let errors = [];
            
            inputs.forEach(input => {
                if (input.hasAttribute('required') && !input.value.trim()) {
                    input.classList.add('border-red-500');
                    errors.push(`${input.name.replace('_', ' ')} is required`);
                    valid = false;
                } else {
                    input.classList.remove('border-red-500');
                }

                if (input.type === 'number' && input.value) {
                    const value = parseFloat(input.value);
                    if (input.name.includes('rate') && (input.name.includes('pair_rate') || input.name.includes('referral_rate'))) {
                        if (value < 0 || value > 1) {
                            input.classList.add('border-red-500');
                            errors.push(`${input.name.replace('_', ' ')} must be between 0 and 1`);
                            valid = false;
                        }
                    } else if (value < 0) {
                        input.classList.add('border-red-500');
                        errors.push(`${input.name.replace('_', ' ')} cannot be negative`);
                        valid = false;
                    }
                }
            });

            if (!valid) {
                toastSystem.error('Please correct the following errors:<br>• ' + errors.slice(0, 3).join('<br>• ') + (errors.length > 3 ? '<br>• and ' + (errors.length - 3) + ' more...' : ''));
            }
            return valid;
        }

        function setButtonState(button, loading) {
            const textElement = button.querySelector('.button-text');
            const loadingElement = button.querySelector('.button-loading');
            
            if (loading) {
                textElement.classList.add('hidden');
                loadingElement.classList.remove('hidden');
                button.disabled = true;
            } else {
                textElement.classList.remove('hidden');
                loadingElement.classList.add('hidden');
                button.disabled = false;
            }
        }

        function handleFormSubmit(event, form) {
            event.preventDefault();
            if (!validateForm(form)) return false;

            const submitButton = form.querySelector('button[type="submit"]');
            setButtonState(submitButton, true);

            const formData = new FormData(form);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                // Check if response is actually JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server returned non-JSON response');
                }
                
                return response.json();
            })
            .then(data => {
                if (data.status === 'success') {
                    toastSystem.success(data.message);
                    // Update form default values
                    form.querySelectorAll('input[type="number"], input[type="text"]').forEach(input => {
                        input.defaultValue = input.value;
                        input.classList.remove('border-red-500');
                    });
                    
                    // Reload page if requested
                    if (data.reload) {
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    }
                } else {
                    toastSystem.error(data.message);
                }
            })
            .catch(error => {
                console.error('Error details:', error);
                
                // Check if it's a JSON parsing error
                if (error.message.includes('Unexpected token')) {
                    toastSystem.error('Server response error. Please check browser console and try again.');
                } else {
                    toastSystem.error(`Failed to save settings: ${error.message}`);
                }
            })
            .finally(() => {
                setButtonState(submitButton, false);
            });

            return false;
        }

        function handleAddPackage(event) {
            event.preventDefault();
            const form = event.target;
            
            if (!validateForm(form)) return false;

            const submitButton = form.querySelector('button[type="submit"]');
            setButtonState(submitButton, true);

            const formData = new FormData(form);
            formData.append('add_package', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server returned non-JSON response');
                }
                
                return response.json();
            })
            .then(data => {
                if (data.status === 'success') {
                    toastSystem.success(data.message);
                    closeAddPackageModal();
                    
                    // Reload page if requested
                    if (data.reload) {
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    }
                } else {
                    toastSystem.error(data.message);
                }
            })
            .catch(error => {
                console.error('Error details:', error);
                toastSystem.error(`Failed to add package: ${error.message}`);
            })
            .finally(() => {
                setButtonState(submitButton, false);
            });

            return false;
        }

        // Tooltip handling
        document.addEventListener('DOMContentLoaded', () => {
            const containers = document.querySelectorAll('.tooltip-container');
            containers.forEach(container => {
                const tooltip = container.querySelector('.tooltip');
                container.addEventListener('mouseenter', () => {
                    tooltip.style.display = 'block';
                    const rect = tooltip.getBoundingClientRect();
                    if (rect.right > window.innerWidth) {
                        tooltip.style.left = 'auto';
                        tooltip.style.right = '0';
                        tooltip.style.transform = 'translateY(-100%) translateX(0)';
                    }
                    if (rect.left < 0) {
                        tooltip.style.left = '0';
                        tooltip.style.right = 'auto';
                        tooltip.style.transform = 'translateY(-100%) translateX(0)';
                    }
                });
                container.addEventListener('mouseleave', () => {
                    tooltip.style.display = 'none';
                });
                container.addEventListener('touchstart', (e) => {
                    e.preventDefault();
                    tooltip.style.display = tooltip.style.display === 'block' ? 'none' : 'block';
                });
            });
            
            // Show welcome message
            toastSystem.info('Package settings loaded. Make changes and click Save Settings to update.');
        });
    </script>
</body>
</html>