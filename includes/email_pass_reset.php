<?php
/**
 * Password Reset Email Notifications Helper
 * For ONLINE server (eteeapro.site)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader (for online server)
require __DIR__ . '/../vendor/autoload.php';

/**
 * Send password reset email to user
 */
function sendPasswordResetEmail($email, $name, $reset_link, $reset_token) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->SMTPDebug = 0; // 0 = no debug, 2 = debug output
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'cspbank911@gmail.com';           // Your working Gmail
        $mail->Password = 'uzhtbqmdqigquyqq';               // Your working App Password
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;
        
        // Recipients
        $mail->setFrom('cspbank911@gmail.com', 'ETEEAP System');
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
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); }
                .header { background: linear-gradient(135deg, #0066cc, #0052a3); color: white; padding: 30px 20px; text-align: center; }
                .header h1 { margin: 0; font-size: 24px; }
                .content { padding: 40px 30px; }
                .content h2 { color: #0066cc; margin-top: 0; }
                .button { display: inline-block; padding: 14px 35px; background: #0066cc; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; }
                .info-box { background: #f0f9ff; border-left: 4px solid #0066cc; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .footer { background: #f8f9fa; padding: 25px; text-align: center; color: #666; font-size: 13px; }
                .link-box { background: #f8f9fa; border: 2px dashed #ccc; padding: 15px; margin: 20px 0; word-break: break-all; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div style="font-size: 48px; margin-bottom: 10px;">🔐</div>
                    <h1>Password Reset Request</h1>
                </div>
                
                <div class="content">
                    <h2>Hello ' . htmlspecialchars($name) . ',</h2>
                    
                    <p>We received a request to reset your password for your ETEEAP account.</p>
                    
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="' . htmlspecialchars($reset_link) . '" class="button">Reset My Password</a>
                    </div>
                    
                    <div class="info-box">
                        <p><strong>⏱️ Important:</strong> This link expires in 1 hour.</p>
                        <p><strong>📧 Email:</strong> ' . htmlspecialchars($email) . '</p>
                    </div>
                    
                    <p>If the button doesn\'t work, copy this link:</p>
                    <div class="link-box">
                        ' . htmlspecialchars($reset_link) . '
                    </div>
                    
                    <div class="warning-box">
                        <p><strong>⚠️ Didn\'t request this?</strong></p>
                        <p>Ignore this email. Your password remains unchanged.</p>
                    </div>
                </div>
                
                <div class="footer">
                    <p><strong>ETEEAP System</strong></p>
                    <p>Do not reply to this automated email.</p>
                    <p>&copy; ' . date('Y') . ' ETEEAP. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        // Plain text version
        $mail->AltBody = "Hello $name,\n\nReset your password: $reset_link\n\nLink expires in 1 hour.\n\nETEEAP System";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Password reset email error: " . $mail->ErrorInfo);
        return false;
    }
}
?>
