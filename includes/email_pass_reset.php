<?php
/**
 * Password Reset Email Notifications Helper
 * 
 * This file contains email functions specifically for the password reset feature.
 * This is separate from your existing email_notifications.php file.
 * 
 * Only contains:
 * - sendPasswordResetEmail() - Sends password reset link to user
 * 
 * Your existing email_notifications.php should contain:
 * - sendRegistrationNotification()
 * - notifyAdminNewRegistration()
 * - Other email functions for your system
 * 
 * Make sure to configure your email settings in config/email_config.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Send password reset email to user
 */
function sendPasswordResetEmail($email, $name, $reset_link, $reset_token) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - ETEEAP System';
        
        // Email body
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    margin: 0;
                    padding: 0;
                    background-color: #f4f4f4;
                }
                .container {
                    max-width: 600px;
                    margin: 20px auto;
                    background: #ffffff;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                }
                .header {
                    background: linear-gradient(135deg, #0066cc, #0052a3);
                    color: white;
                    padding: 30px 20px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                    font-weight: 600;
                }
                .header .icon {
                    font-size: 48px;
                    margin-bottom: 10px;
                }
                .content {
                    padding: 40px 30px;
                }
                .content h2 {
                    color: #0066cc;
                    margin-top: 0;
                    font-size: 20px;
                }
                .content p {
                    margin: 15px 0;
                    color: #555;
                }
                .button-container {
                    text-align: center;
                    margin: 30px 0;
                }
                .button {
                    display: inline-block;
                    padding: 14px 35px;
                    background: linear-gradient(135deg, #0066cc, #0052a3);
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 600;
                    font-size: 16px;
                    box-shadow: 0 4px 15px rgba(0, 102, 204, 0.3);
                }
                .button:hover {
                    background: linear-gradient(135deg, #0052a3, #003d7a);
                }
                .info-box {
                    background: #f0f9ff;
                    border-left: 4px solid #0066cc;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 5px;
                }
                .info-box p {
                    margin: 5px 0;
                    font-size: 14px;
                }
                .warning-box {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 5px;
                }
                .warning-box p {
                    margin: 5px 0;
                    color: #856404;
                    font-size: 14px;
                }
                .footer {
                    background: #f8f9fa;
                    padding: 25px 30px;
                    text-align: center;
                    color: #666;
                    font-size: 13px;
                    border-top: 1px solid #e3e3e3;
                }
                .footer p {
                    margin: 8px 0;
                }
                .token-box {
                    background: #f8f9fa;
                    border: 2px dashed #dee2e6;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 5px;
                    text-align: center;
                    word-break: break-all;
                }
                .token-box code {
                    font-size: 12px;
                    color: #e83e8c;
                    font-family: "Courier New", monospace;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="icon">🔐</div>
                    <h1>Password Reset Request</h1>
                </div>
                
                <div class="content">
                    <h2>Hello ' . htmlspecialchars($name) . ',</h2>
                    
                    <p>We received a request to reset your password for your ETEEAP account. If you made this request, click the button below to reset your password:</p>
                    
                    <div class="button-container">
                        <a href="' . htmlspecialchars($reset_link) . '" class="button">Reset My Password</a>
                    </div>
                    
                    <div class="info-box">
                        <p><strong>⏱️ Important:</strong> This link will expire in 1 hour for security reasons.</p>
                        <p><strong>📧 Email:</strong> ' . htmlspecialchars($email) . '</p>
                    </div>
                    
                    <p>If the button above doesn\'t work, copy and paste this link into your browser:</p>
                    <div class="token-box">
                        <code>' . htmlspecialchars($reset_link) . '</code>
                    </div>
                    
                    <div class="warning-box">
                        <p><strong>⚠️ Didn\'t request this?</strong></p>
                        <p>If you didn\'t request a password reset, please ignore this email. Your password will remain unchanged, and your account is secure.</p>
                    </div>
                    
                    <p>For security purposes, we\'ve included your reset token below (you won\'t need this if you use the link):</p>
                    <div class="token-box">
                        <code>' . htmlspecialchars($reset_token) . '</code>
                    </div>
                </div>
                
                <div class="footer">
                    <p><strong>ETEEAP System</strong></p>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>If you need assistance, please contact our support team.</p>
                    <p style="margin-top: 15px; color: #999; font-size: 11px;">
                        &copy; ' . date('Y') . ' ETEEAP. All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        // Plain text version
        $mail->AltBody = "Hello $name,\n\n"
            . "We received a request to reset your password for your ETEEAP account.\n\n"
            . "To reset your password, please visit this link:\n"
            . "$reset_link\n\n"
            . "This link will expire in 1 hour.\n\n"
            . "If you didn't request this password reset, please ignore this email.\n\n"
            . "Reset Token: $reset_token\n\n"
            . "Best regards,\n"
            . "ETEEAP System";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Password reset email error: " . $mail->ErrorInfo);
        return false;
    }
}
?>
