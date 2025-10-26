<?php
// includes/email_pass_reset.php
// Password Reset Email - Uses EXACT same structure as email_notifications.php

// ====== SMTP CREDENTIALS (same as email_notifications.php) ======
if (!defined('MAIL_FROM_EMAIL')) { define('MAIL_FROM_EMAIL', 'cspbank911@gmail.com'); }
if (!defined('MAIL_FROM_NAME'))  { define('MAIL_FROM_NAME',  'ETEEAP System'); }
if (!defined('MAIL_USERNAME'))   { define('MAIL_USERNAME',   'cspbank911@gmail.com'); }
if (!defined('MAIL_PASSWORD'))   { define('MAIL_PASSWORD',   'uzhtbqmdqigquyqq'); }
if (!defined('MAIL_DEBUG'))      { define('MAIL_DEBUG', false); }

// Check if PHPMailer is available (EXACT same as email_notifications.php)
function phpmailer_available_reset(): bool {
    // XAMPP local path - PHPMailer is in project root
    $base = __DIR__ . '/../PHPMailer/PHPMailer/src/';
    
    $exists = file_exists($base . 'PHPMailer.php')
        && file_exists($base . 'SMTP.php')
        && file_exists($base . 'Exception.php');
    
    if (!$exists) {
        error_log('[MAIL] PHPMailer not found. Checked: ' . realpath(__DIR__ . '/..') . '/PHPMailer/PHPMailer/src/');
    }
    
    return $exists;
}

// Build PHPMailer instance (EXACT same as email_notifications.php)
function buildMailerReset(string $mode = 'smtps') {
    if (!phpmailer_available_reset()) {
        error_log('[MAIL] PHPMailer files not found');
        return null;
    }
    
    // Use same base path
    $base = __DIR__ . '/../PHPMailer/PHPMailer/src/';
    require_once $base . 'Exception.php';
    require_once $base . 'PHPMailer.php';
    require_once $base . 'SMTP.php';
    
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    // Debug to error_log
    $mail->SMTPDebug  = MAIL_DEBUG ? PHPMailer\PHPMailer\SMTP::DEBUG_SERVER : PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
    if (MAIL_DEBUG) {
        $mail->Debugoutput = function($str, $level) { error_log('[SMTP] ' . trim($str)); };
    }

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;

    // SSL/TLS relax (EXACT same as email_notifications.php)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ];

    if ($mode === 'starttls') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
    } else {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
    }

    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';

    return $mail;
}

// Try send with fallback (EXACT same as email_notifications.php)
function send_with_fallback_reset(callable $prepare): bool {
    try {
        // 1) Try SMTPS:465
        $m1 = buildMailerReset('smtps');
        if ($m1) {
            $prepare($m1);
            $ok1 = $m1->send();
            if ($ok1) return true;
            error_log('[MAIL] SMTPS send failed: ' . $m1->ErrorInfo);
        } else {
            error_log('[MAIL] buildMailerReset(smtps) returned null.');
        }

        // 2) Fallback STARTTLS:587
        $m2 = buildMailerReset('starttls');
        if ($m2) {
            $prepare($m2);
            $ok2 = $m2->send();
            if ($ok2) return true;
            error_log('[MAIL] STARTTLS send failed: ' . $m2->ErrorInfo);
        } else {
            error_log('[MAIL] buildMailerReset(starttls) returned null.');
        }

        return false;
    } catch (Throwable $e) {
        error_log('[MAIL] send_with_fallback_reset exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send password reset email to user
 * Uses the same pattern as sendRegistrationNotification
 */
function sendPasswordResetEmail($email, $name, $reset_link, $reset_token) {
    $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 40px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td valign="middle" align="middle">
                                        <h1 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 600;"> 🔐 Password Reset Request</h1>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px 0; color: #1a1a1a; font-size: 20px; font-weight: 600;">
                                Hello ' . htmlspecialchars($name) . ',
                            </h2>
                            
                            <p style="margin: 0 0 20px 0; color: #444444; font-size: 15px; line-height: 1.6;">
                                We received a request to reset your password for your ETEEAP account. If you made this request, click the button below to reset your password:
                            </p>
                            
                            <!-- CTA Button -->
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="' . htmlspecialchars($reset_link) . '" style="display: inline-block; padding: 14px 35px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff !important; text-decoration: none; border-radius: 25px; font-weight: 600; font-size: 16px;">
                                    Reset My Password
                                </a>
                            </div>
                            
                            <!-- Info Box -->
                            <div style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border-left: 4px solid #2196f3; border-radius: 8px; padding: 20px; margin: 25px 0;">
                                <p style="margin: 0 0 10px 0; color: #1565c0; font-weight: 600; font-size: 15px;">
                                    ⏱️ Important Information
                                </p>
                                <p style="margin: 0; color: #1976d2; font-size: 14px; line-height: 1.6;">
                                    This password reset link will expire in <strong>1 hour</strong> for security reasons.<br>
                                    Account: <strong>' . htmlspecialchars($email) . '</strong>
                                </p>
                            </div>
                            
                            <p style="margin: 20px 0; color: #444444; font-size: 14px; line-height: 1.6;">
                                If the button above doesn\'t work, copy and paste this link into your browser:
                            </p>
                            
                            <div style="background: #f8f9fa; border: 2px dashed #dee2e6; border-radius: 8px; padding: 15px; margin: 20px 0; word-break: break-all;">
                                <p style="margin: 0; color: #6c757d; font-size: 12px; font-family: monospace;">
                                    ' . htmlspecialchars($reset_link) . '
                                </p>
                            </div>
                            
                            <!-- Warning Box -->
                            <div style="background: linear-gradient(135deg, #fff3cd 0%, #fff8e1 100%); border-left: 4px solid #ffc107; border-radius: 8px; padding: 20px; margin: 25px 0;">
                                <p style="margin: 0 0 10px 0; color: #856404; font-weight: 600; font-size: 15px;">
                                    ⚠️ Didn\'t Request This?
                                </p>
                                <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.6;">
                                    If you didn\'t request a password reset, please ignore this email. Your password will remain unchanged and your account is secure.
                                </p>
                            </div>
                            
                            <p style="margin: 30px 0 0 0; color: #444444; font-size: 15px; line-height: 1.6;">
                                Best regards,<br>
                                <strong style="color: #1a1a1a;">ETEEAP System Team</strong>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background: #0f172a; color: #ffffff; padding: 30px; text-align: center;">
                            <p style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #ffffff;">
                                ETEEAP System
                            </p>
                            <p style="margin: 0 0 15px 0; font-size: 12px; color: rgba(255,255,255,0.8);">
                                This is an automated notification from the ETEEAP System.<br>
                                Please do not reply directly to this email.
                            </p>
                            <p style="margin: 15px 0 0 0; font-size: 11px; color: rgba(255,255,255,0.6);">
                                © ' . date('Y') . ' ETEEAP System. All rights reserved.
                            </p>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

    // Plain text alternative
    $alt = "Password Reset Request\n\n"
         . "Hello " . $name . ",\n\n"
         . "We received a request to reset your password for your ETEEAP account.\n\n"
         . "To reset your password, please visit this link:\n"
         . $reset_link . "\n\n"
         . "This link will expire in 1 hour.\n\n"
         . "If you didn't request this password reset, please ignore this email.\n\n"
         . "Best regards,\nETEEAP System Team";

    $ok = send_with_fallback_reset(function($mail) use ($email, $name, $html, $alt) {
        $mail->addAddress($email, $name);
        $mail->Subject = 'Password Reset Request - ETEEAP System';
        $mail->Body    = $html;
        $mail->AltBody = $alt;
    });
    
    error_log('[MAIL] sendPasswordResetEmail to ' . $email . ': ' . ($ok ? 'Success' : 'Failed'));
    return $ok;
}

?>
