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
        
        // basic rates
        $stmt = $pdo->prepare("UPDATE packages SET
            daily_max = ?,
            pair_rate = ?,
            referral_rate = ?
        WHERE id = ?");
        $stmt->execute([
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

        @media (max-width: 640px) {
            .toast-container {
                top: 10px;
                right: 10px;
                left: 10px;
            }
            
            .toast {
                min-width: auto;
                max-width: none;
            }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Toast Container -->
    <div class="toast-container" id="toast-container"></div>
    
    <div class="container mx-auto px-4 py-8">
        <h2 class="text-3xl font-bold text-gray-800 inferit-text-color mb-6">Package Settings</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($packages as $pkg): ?>
                <div class="bg-white shadow-lg rounded-lg p-6">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">
                        <?= htmlspecialchars($pkg['name']) ?> ($<?= number_format($pkg['price'], 2) ?>)
                    </h3>

                    <form method="post" class="space-y-6 package-form" data-package-id="<?= $pkg['id'] ?>" onsubmit="return handleFormSubmit(event, this)">
                        <input type="hidden" name="package_id" value="<?= $pkg['id'] ?>">
                        <input type="hidden" name="save_package" value="1">
                        
                        <!-- Base Rates -->
                        <div class="space-y-4">
                            <h4 class="text-lg font-medium text-gray-700">Base Rates</h4>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-600">
                                    Max Pairs/Day
                                    <span class="ml-1 text-gray-400 cursor-help tooltip-container">
                                        ℹ️
                                        <span class="tooltip">Maximum number of pairs allowed per day</span>
                                    </span>
                                </label>
                                <input type="number" name="daily_max" min="0" required
                                       class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                       value="<?= htmlspecialchars($pkg['daily_max']) ?>">
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-gray-600">
                                    Pair Rate (0-1)
                                    <span class="ml-1 text-gray-400 cursor-help tooltip-container">
                                        ℹ️
                                        <span class="tooltip">Rate for successful pairs (0 to 1)</span>
                                    </span>
                                </label>
                                <input type="number" step="0.01" min="0" max="1" name="pair_rate" required
                                       class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
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
                                       class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                       value="<?= htmlspecialchars($pkg['referral_rate']) ?>">
                            </div>
                        </div>

                        <!-- Leadership Schedule -->
                        <div class="space-y-4">
                            <h4 class="text-lg font-medium text-gray-700">Matched Bonus Settings</h4>
                            <div class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <span class="w-8"></span>
                                    <span class="w-24 text-center font-medium text-gray-600">PVT</span>
                                    <span class="w-24 text-center font-medium text-gray-600">GVT</span>
                                    <span class="w-24 text-center font-medium text-gray-600">Rate</span>
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
                                    <div class="flex items-center gap-2">
                                        <span class="w-8 font-medium">L<?= $lvl ?></span>
                                        <input type="number" min="0" name="lead_pvt_<?= $lvl ?>" required
                                               class="w-24 px-2 py-1 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                               value="<?= htmlspecialchars($row['pvt_required']) ?>" placeholder="PVT">
                                        <input type="number" min="0" name="lead_gvt_<?= $lvl ?>" required
                                               class="w-24 px-2 py-1 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                               value="<?= htmlspecialchars($row['gvt_required']) ?>" placeholder="GVT">
                                        <input type="number" step="0.001" min="0" name="lead_rate_<?= $lvl ?>" required
                                               class="w-24 px-2 py-1 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                               value="<?= htmlspecialchars($row['rate']) ?>" placeholder="Rate">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Mentor Schedule -->
                        <div class="space-y-4">
                            <h4 class="text-lg font-medium text-gray-700">Mentor Bonus Settings</h4>
                            <div class="space-y-2">
                                <div class="flex items-center gap-2">
                                    <span class="w-8"></span>
                                    <span class="w-24 text-center font-medium text-gray-600">PVT</span>
                                    <span class="w-24 text-center font-medium text-gray-600">GVT</span>
                                    <span class="w-24 text-center font-medium text-gray-600">Rate</span>
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
                                    <div class="flex items-center gap-2">
                                        <span class="w-8 font-medium">L<?= $lvl ?></span>
                                        <input type="number" min="0" name="mentor_pvt_<?= $lvl ?>" required
                                               class="w-24 px-2 py-1 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                               value="<?= htmlspecialchars($row['pvt_required']) ?>" placeholder="PVT">
                                        <input type="number" min="0" name="mentor_gvt_<?= $lvl ?>" required
                                               class="w-24 px-2 py-1 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                               value="<?= htmlspecialchars($row['gvt_required']) ?>" placeholder="GVT">
                                        <input type="number" step="0.001" min="0" name="mentor_rate_<?= $lvl ?>" required
                                               class="w-24 px-2 py-1 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                               value="<?= htmlspecialchars($row['rate']) ?>" placeholder="Rate">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit"
                                class="w-full bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
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

        function validateForm(form) {
            const inputs = form.querySelectorAll('input[type="number"]');
            let valid = true;
            let errors = [];
            
            inputs.forEach(input => {
                if (input.hasAttribute('required') && !input.value) {
                    input.classList.add('border-red-500');
                    errors.push(`${input.name} is required`);
                    valid = false;
                } else {
                    input.classList.remove('border-red-500');
                }

                if (input.name.includes('rate') && input.value) {
                    const value = parseFloat(input.value);
                    if (input.name.includes('pair_rate') || input.name.includes('referral_rate')) {
                        if (value < 0 || value > 1) {
                            input.classList.add('border-red-500');
                            errors.push(`${input.name} must be between 0 and 1`);
                            valid = false;
                        }
                    } else if (value < 0) {
                        input.classList.add('border-red-500');
                        errors.push(`${input.name} cannot be negative`);
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
                    form.querySelectorAll('input[type="number"]').forEach(input => {
                        input.defaultValue = input.value;
                        input.classList.remove('border-red-500');
                    });
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