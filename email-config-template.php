<?php
// email-config-template.php - Email configuration template
// Copy this to email-config.php and update with your settings

return [
    // SMTP Settings
    'smtp' => [
        'enabled' => true, // Set to false to use PHP mail() function
        'host' => 'smtp.hostinger.com', // Your SMTP server
        'port' => 587, // Port (587 for TLS, 465 for SSL)
        'encryption' => 'tls', // 'tls' or 'ssl'
        'username' => 'support@rixile.org', // Your email
        'password' => '-----', // Your app password (not regular password)
        'from_email' => 'noreply@rixile.org',   
        'from_name' => 'Rixile Support',
        'reply_to' => 'support@rixile.org'
    ],

    // Common SMTP providers configurations:
    
    /*
    // Gmail Configuration:
    'smtp' => [
        'enabled' => true,
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'your-email@gmail.com',
        'password' => 'your-16-digit-app-password', // Generate from Google Account settings
        'from_email' => 'your-email@gmail.com',
        'from_name' => 'Your Site Name',
        'reply_to' => 'your-email@gmail.com'
    ],

    // Outlook/Hotmail Configuration:
    'smtp' => [
        'enabled' => true,
        'host' => 'smtp.live.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'your-email@outlook.com',
        'password' => 'your-password',
        'from_email' => 'your-email@outlook.com',
        'from_name' => 'Your Site Name',
        'reply_to' => 'your-email@outlook.com'
    ],

    // cPanel/Shared Hosting Configuration:
    'smtp' => [
        'enabled' => true,
        'host' => 'mail.yourdomain.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'noreply@yourdomain.com',
        'password' => 'your-email-password',
        'from_email' => 'noreply@yourdomain.com',
        'from_name' => 'Your Site Name',
        'reply_to' => 'support@yourdomain.com'
    ],
    */

    // Email templates
    'templates' => [
        'subject' => 'Password Reset Request - Your Site',
        'company_name' => 'Your Company Name',
        'support_email' => 'support@yoursite.com'
    ],

    // Security settings
    'security' => [
        'rate_limit_per_hour' => 3, // Max reset requests per hour per email
        'token_expiry_hours' => 1, // How long reset tokens are valid
        'localhost_debug' => true // Show debug info on localhost
    ]
];