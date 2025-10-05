<?php
require_once '../includes/email_notifications.php';

echo "<h1>Email Test</h1>";
echo "<p>Checking PHPMailer availability: " . (phpmailer_available() ? 'YES' : 'NO') . "</p>";

if (phpmailer_available()) {
    echo "<p>Attempting to send test email...</p>";
    $result = sendTestEmail('kapstonesystem310@gmail.com'); // Replace with your email
    echo "<p>Result: " . ($result ? 'SUCCESS' : 'FAILED') . "</p>";
    echo "<p>Check PHP error log for details</p>";
} else {
    echo "<p style='color:red'>PHPMailer files not found!</p>";
}
?>