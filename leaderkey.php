<?php
// leaderkey.php - Leader key generator script
require 'config.php';

// Security check - require authentication token or admin login
$isAuthorized = false;

// Check if user is logged in as admin
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if ($user && $user['role'] === 'admin') {
        $isAuthorized = true;
    }
}

// Alternative: Check for auth token (set this in your config or environment)
$authToken = 'your_secret_auth_token_here'; // Change this to a secure token
if (isset($_GET['auth']) && $_GET['auth'] === $authToken) {
    $isAuthorized = true;
}

if (!$isAuthorized) {
    http_response_code(403);
    die('<!DOCTYPE html>
    <html>
    <head><title>Access Denied</title></head>
    <body style="font-family: Arial, sans-serif; text-align: center; padding: 50px;">
        <h1>Access Denied</h1>
        <p>You must be logged in as an admin to access this page.</p>
        <a href="dashboard.php">Go to Dashboard</a>
    </body>
    </html>');
}

// Handle form submission
$generatedKeys = [];
$message = '';
$count = 1;

if ($_POST && isset($_POST['generate'])) {
    $count = max(1, min(100, (int)($_POST['count'] ?? 1)));
    
    // Create logs directory if it doesn't exist
    if (!is_dir('logs')) {
        mkdir('logs', 0755, true);
    }

    $keyFile = 'logs/leader_keys.log';
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    $baseUrl .= rtrim(dirname($_SERVER['REQUEST_URI']), '/');

    // Generate leader keys
    $keyData = [];

    for ($i = 0; $i < $count; $i++) {
        // Generate a secure random key similar to password hash
        $randomBytes = random_bytes(32);
        $leaderKey = 'lk_' . bin2hex($randomBytes);
        
        // Store key data for logging
        $timestamp = date('Y-m-d H:i:s');
        $keyData[] = $timestamp . '|' . $leaderKey . '|unused|||';
        
        // Generate URL
        $registrationUrl = $baseUrl . '/register.php?leaderkey=' . $leaderKey;
        $generatedKeys[] = [
            'key' => $leaderKey,
            'url' => $registrationUrl
        ];
    }

    // Append to log file
    if (file_put_contents($keyFile, implode("\n", $keyData) . "\n", FILE_APPEND | LOCK_EX)) {
        $message = "Successfully generated {$count} leader key" . ($count > 1 ? 's' : '') . "!";
    } else {
        $message = "Error: Could not save keys to log file.";
    }
}

// Get existing count from URL if present
if (!$_POST && isset($_GET['count'])) {
    $count = max(1, min(100, (int)$_GET['count']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leader Key Generator</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .form-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input {
            width: 100px;
            padding: 8px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
        }
        .generate-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            margin-left: 10px;
        }
        .generate-btn:hover {
            background: #218838;
        }
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .message.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .message.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .key-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .key-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #007bff;
            font-size: 14px;
            word-break: break-all;
        }
        .key-url {
            font-family: 'Courier New', monospace;
            color: #28a745;
            font-size: 13px;
            word-break: break-all;
            margin-top: 8px;
        }
        .copy-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
        }
        .copy-btn:hover {
            background: #0056b3;
        }
        .copy-btn.success {
            background: #28a745;
        }
        .copy-all-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin: 20px 0;
        }
        .copy-all-btn:hover {
            background: #218838;
        }
        .instructions {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            margin-top: 30px;
        }
        .instructions h3 {
            color: #856404;
            margin-top: 0;
        }
        .instructions ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .instructions li {
            margin-bottom: 8px;
            color: #856404;
        }
        .log-info {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .log-info strong {
            color: #155724;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #007bff;
            text-decoration: none;
            font-weight: bold;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        
        <h1>🔑 Leader Key Generator</h1>
        
        <!-- Key Generation Form -->
        <div class="form-section">
            <h3 style="margin-top: 0;">Generate New Leader Keys</h3>
            <form method="post">
                <div class="form-group" style="display: inline-block;">
                    <label for="count">Number of Keys (1-100):</label>
                    <input type="number" id="count" name="count" value="<?= htmlspecialchars($count) ?>" min="1" max="100" required>
                    <button type="submit" name="generate" class="generate-btn">Generate Keys</button>
                </div>
            </form>
        </div>

        <?php if ($message): ?>
            <div class="message <?= strpos($message, 'Error') === 0 ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($generatedKeys)): ?>
            <div class="log-info">
                <strong>📝 Log File:</strong> Keys have been saved to <code>logs/leader_keys.log</code>
            </div>

            <button class="copy-all-btn" onclick="copyAllUrls()">📋 Copy All URLs</button>

            <div id="keysList">
                <?php foreach ($generatedKeys as $index => $keyInfo): ?>
                <div class="key-item">
                    <div>
                        <strong>Key <?= $index + 1 ?>:</strong>
                        <span class="key-code"><?= htmlspecialchars($keyInfo['key']) ?></span>
                        <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($keyInfo['key'], ENT_QUOTES) ?>', this)">Copy Key</button>
                    </div>
                    <div>
                        <strong>Registration URL:</strong>
                        <div class="key-url"><?= htmlspecialchars($keyInfo['url']) ?></div>
                        <button class="copy-btn" onclick="copyText('<?= htmlspecialchars($keyInfo['url'], ENT_QUOTES) ?>', this)">Copy URL</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="instructions">
            <h3>📋 Instructions</h3>
            <ul>
                <li><strong>Share URLs:</strong> Send the registration URLs to leaders who need immediate activation</li>
                <li><strong>One-time Use:</strong> Each key can only be used once for registration</li>
                <li><strong>Tracking:</strong> All key usage is logged in the system</li>
                <li><strong>Security:</strong> Keys are cryptographically secure and cannot be guessed</li>
                <li><strong>Access Control:</strong> This generator requires admin login or auth token</li>
                <li><strong>Generate More:</strong> Use the form above to generate additional keys as needed</li>
            </ul>
        </div>
    </div>

    <script>
        function copyText(text, button) {
            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    showCopySuccess(button);
                }).catch(function(err) {
                    console.error('Clipboard API failed:', err);
                    fallbackCopyText(text, button);
                });
            } else {
                // Fallback for older browsers or non-HTTPS
                fallbackCopyText(text, button);
            }
        }

        function fallbackCopyText(text, button) {
            // Create temporary textarea
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showCopySuccess(button);
                } else {
                    showCopyError(button);
                }
            } catch (err) {
                console.error('Fallback copy failed:', err);
                showCopyError(button);
            }
            
            document.body.removeChild(textArea);
        }

        function showCopySuccess(button) {
            const originalText = button.textContent;
            const originalClass = button.className;
            button.textContent = 'Copied!';
            button.className = originalClass + ' success';
            setTimeout(() => {
                button.textContent = originalText;
                button.className = originalClass;
            }, 2000);
        }

        function showCopyError(button) {
            const originalText = button.textContent;
            button.textContent = 'Failed';
            setTimeout(() => {
                button.textContent = originalText;
            }, 2000);
            
            // Show fallback message
            prompt('Copy this text manually:', button.getAttribute('data-text') || 'Copy failed');
        }

        function copyAllUrls() {
            const urls = [];
            <?php if (!empty($generatedKeys)): ?>
                <?php foreach ($generatedKeys as $keyInfo): ?>
                urls.push('<?= addslashes($keyInfo['url']) ?>');
                <?php endforeach; ?>
            <?php endif; ?>
            
            if (urls.length === 0) {
                alert('No URLs to copy. Generate some keys first.');
                return;
            }
            
            const allUrls = urls.join('\n');
            
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(allUrls).then(function() {
                    showCopyAllSuccess();
                }).catch(function(err) {
                    console.error('Clipboard API failed:', err);
                    fallbackCopyAllUrls(allUrls);
                });
            } else {
                fallbackCopyAllUrls(allUrls);
            }
        }

        function fallbackCopyAllUrls(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showCopyAllSuccess();
                } else {
                    prompt('Copy all URLs manually:', text);
                }
            } catch (err) {
                console.error('Fallback copy failed:', err);
                prompt('Copy all URLs manually:', text);
            }
            
            document.body.removeChild(textArea);
        }

        function showCopyAllSuccess() {
            const btn = document.querySelector('.copy-all-btn');
            const originalText = btn.textContent;
            btn.textContent = '✅ All URLs Copied!';
            btn.style.background = '#28a745';
            setTimeout(() => {
                btn.textContent = originalText;
                btn.style.background = '#28a745';
            }, 3000);
        }
    </script>
</body>
</html>