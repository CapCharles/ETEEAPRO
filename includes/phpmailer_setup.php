<?php
/**
 * PHPMailer Setup for ETEEAP System
 * Include this file once to load PHPMailer classes
 */

// Prevent multiple inclusions
if (!defined('PHPMAILER_LOADED')) {
    define('PHPMAILER_LOADED', true);
    
    // Check if PHPMailer classes are already loaded
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        // Your working PHPMailer paths
        require_once 'C:\xampp\PHPMailer\PHPMailer\src\Exception.php';
        require_once 'C:\xampp\PHPMailer\PHPMailer\src\PHPMailer.php';
        require_once 'C:\xampp\PHPMailer\PHPMailer\src\SMTP.php';
    }
    
    // Import classes into namespace
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;
}
?>