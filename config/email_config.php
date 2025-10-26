<?php
/**
 * Application Constants and Configuration
 * Configure your email settings and other constants here
 */

// Email Configuration (SMTP Settings)
// Update these with your actual SMTP server details

// For Gmail:
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your Gmail address
define('SMTP_PASSWORD', 'your-app-password'); // Gmail App Password (not your regular password)
define('SMTP_FROM_EMAIL', 'your-email@gmail.com');
define('SMTP_FROM_NAME', 'ETEEAP System');

// For other providers (e.g., Outlook, Yahoo, custom SMTP):
// define('SMTP_HOST', 'smtp.office365.com'); // For Outlook
// define('SMTP_HOST', 'smtp.mail.yahoo.com'); // For Yahoo
// define('SMTP_HOST', 'mail.yourdomain.com'); // For custom domain

// Admin Email (for notifications)
define('ADMIN_EMAIL', 'admin@eteeap.com'); // Change to your admin email

// Application Settings
define('APP_NAME', 'ETEEAP System');
define('APP_URL', 'http://localhost/eteeap'); // Change to your actual URL

// File Upload Settings
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB in bytes
define('UPLOAD_PATH', __DIR__ . '/../uploads/');

// Session Settings
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

// Pagination Settings
define('RECORDS_PER_PAGE', 10);

// Password Reset Token Expiry
define('PASSWORD_RESET_EXPIRY', 3600); // 1 hour in seconds

// Date/Time Format
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'F d, Y');
define('DISPLAY_DATETIME_FORMAT', 'F d, Y h:i A');

?>
