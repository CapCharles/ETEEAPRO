<?php
declare(strict_types=1);

// Debug mode (tanggalin kung production na)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * Subukan i-load ang PHPMailer.
 * Priority: Composer autoload → Manual PHPMailer folder.
 */
function _loadPHPMailer(): bool
{
    // 1) Composer autoload
    $vendor = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($vendor)) {
        require_once $vendor;
        return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
    }

    // 2) Manual paths
    $candidates = [
        __DIR__ . '/../PHPMailer/src',   // public_html/PHPMailer/src (kapatid ng includes)
        __DIR__ . '/PHPMailer/src',      // public_html/includes/PHPMailer/src
    ];

    foreach ($candidates as $src) {
        $exc = $src . '/Exception.php';
        $php = $src . '/PHPMailer.php';
        $smt = $src . '/SMTP.php';
        if (file_exists($exc) && file_exists($php) && file_exists($smt)) {
            require_once $exc;
            require_once $php;
            require_once $smt;
            return class_exists('\\PHPMailer\\PHPMailer\\PHPMailer');
        }
    }

    return false; // wala talagang PHPMailer
}

$HAS_PHPMAILER = _loadPHPMailer();

if ($HAS_PHPMAILER) {
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

    function makeMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
         $mail->Username = 'cspbank911@gmail.com';
    $mail->Password = 'uzhtbqmdqigquyqq';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

          $mail->setFrom('cspbank911@gmail.com', 'ETEEAP System');
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        return $mail;
    }

    function sendEmail(string $to, string $subject, string $html, ?string $plainText = null): bool
    {
        try {
            $mail = makeMailer();
            $mail->clearAllRecipients();
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $plainText ?: strip_tags($html);
            return $mail->send();
        } catch (Exception $e) {
            error_log('Email send failed: ' . $e->getMessage());
            return false;
        }
    }
} else {
    // fallback para hindi mag-crash si register.php
    if (!function_exists('sendEmail')) {
        function sendEmail(string $to, string $subject, string $html, ?string $plainText = null): bool
        {
            error_log('[email_notifications.php] PHPMailer not found. Skipping email send.');
            return false;
        }
    }
}
/**
 * Send registration confirmation to user
 */
function sendRegistrationNotification($user_email, $user_name) {
    try {
        $mail = makeMailer();
        
        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Registration - Under Review';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { background: #333; color: white; padding: 10px; text-align: center; }
                .status-badge { background: #ffc107; color: #000; padding: 5px 10px; border-radius: 15px; display: inline-block; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🎓 ETEEAP Registration</h2>
                </div>
                <div class='content'>
                    <h3>Dear {$user_name},</h3>
                    <p>Thank you for registering with the ETEEAP (Expanded Tertiary Education Equivalency and Accreditation Program)!</p>
                    
                    <p><strong>Registration Status:</strong> <span class='status-badge'>Under Review</span></p>
                    
                    <h4>What's Next?</h4>
                    <ul>
                        <li>✅ Your registration form has been received</li>
                        <li>✅ All required documents have been uploaded</li>
                        <li>⏳ Our admin team is reviewing your application</li>
                        <li>📧 You will receive an email notification once approved</li>
                    </ul>
                    
                    <p><strong>Important Notes:</strong></p>
                    <ul>
                        <li>Review process typically takes 2-3 business days</li>
                        <li>You cannot log in until your application is approved</li>
                        <li>If additional information is needed, we will contact you</li>
                    </ul>
                    
                    <p>If you have any questions, please contact our support team.</p>
                    
                    <p>Best regards,<br><strong>ETEEAP Admin Team</strong></p>
                </div>
                <div class='footer'>
                    <p>&copy; 2025 ETEEAP System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
        
        $mail->AltBody = "Dear {$user_name}, Your ETEEAP registration has been received and is currently under review. You will be notified once your application is approved.";
        
        $result = $mail->send();
        
        // Log email activity
        error_log("Registration email sent to: {$user_email} - Status: " . ($result ? 'Success' : 'Failed'));
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Registration email failed: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Send approval notification to user
 */
function sendApprovalNotification($user_email, $user_name) {
    try {
        $mail = makeMailer();
        
        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Registration - Approved! 🎉';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { background: #333; color: white; padding: 10px; text-align: center; }
                .success-badge { background: #28a745; color: white; padding: 5px 10px; border-radius: 15px; display: inline-block; }
                .login-btn { background: #667eea; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block; margin: 10px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🎉 Congratulations!</h2>
                    <h3>Your ETEEAP Application is Approved</h3>
                </div>
                <div class='content'>
                    <h3>Dear {$user_name},</h3>
                    <p>Great news! Your ETEEAP registration has been approved by our admin team.</p>
                    
                    <p><strong>Application Status:</strong> <span class='success-badge'>✅ Approved</span></p>
                    
                    <h4>You can now:</h4>
                    <ul>
                        <li>🔓 Log in to your ETEEAP account</li>
                        <li>📄 Create your formal application</li>
                        <li>📤 Upload additional supporting documents</li>
                        <li>📊 Track your assessment progress</li>
                    </ul>
                    
                    <div style='text-align: center; margin: 20px 0;'>
                        <a href='" . BASE_URL . "auth/login.php' class='login-btn'>Login to Your Account</a>
                    </div>
                    
                    <p><strong>Next Steps:</strong></p>
                    <ol>
                        <li>Log in using your registered email and password</li>
                        <li>Complete your candidate profile</li>
                        <li>Choose your preferred program</li>
                        <li>Upload supporting documents for evaluation</li>
                    </ol>
                    
                    <p>Welcome to the ETEEAP family! We're excited to help you achieve your educational goals.</p>
                    
                    <p>Best regards,<br><strong>ETEEAP Admin Team</strong></p>
                </div>
                <div class='footer'>
                    <p>&copy; 2025 ETEEAP System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
        
        $mail->AltBody = "Congratulations {$user_name}! Your ETEEAP registration has been approved. You can now log in at " . BASE_URL . "auth/login.php";
        
        $result = $mail->send();
        error_log("Approval email sent to: {$user_email} - Status: " . ($result ? 'Success' : 'Failed'));
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Approval email failed: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Send rejection notification to user
 */
function sendRejectionNotification($user_email, $user_name, $reason = '') {
    try {
        $mail = makeMailer();
        
        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Registration - Additional Information Required';
        
        $reason = !empty($reason) ? $reason : 'Additional review required';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { background: #333; color: white; padding: 10px; text-align: center; }
                .warning-badge { background: #dc3545; color: white; padding: 5px 10px; border-radius: 15px; display: inline-block; }
                .reason-box { background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 15px; margin: 15px 0; }
                .reapply-btn { background: #667eea; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block; margin: 10px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>📋 ETEEAP Registration Review</h2>
                </div>
                <div class='content'>
                    <h3>Dear {$user_name},</h3>
                    <p>Thank you for your interest in the ETEEAP program. After reviewing your application, we need additional information before we can proceed.</p>
                    
                    <p><strong>Application Status:</strong> <span class='warning-badge'>⚠️ Needs Attention</span></p>
                    
                    <div class='reason-box'>
                        <h4>📝 Review Comments:</h4>
                        <p><strong>{$reason}</strong></p>
                    </div>
                    
                    <h4>What you can do:</h4>
                    <ul>
                        <li>📧 Contact our support team for clarification</li>
                        <li>📄 Prepare the required additional information</li>
                        <li>🔄 Resubmit your application when ready</li>
                    </ul>
                    
                    <div style='text-align: center; margin: 20px 0;'>
                        <a href='" . BASE_URL . "auth/register.php' class='reapply-btn'>Reapply Now</a>
                    </div>
                    
                    <p><strong>Need Help?</strong></p>
                    <p>If you have questions about the requirements or need assistance, please don't hesitate to contact our support team.</p>
                    
                    <p>We appreciate your patience and look forward to helping you achieve your educational goals.</p>
                    
                    <p>Best regards,<br><strong>ETEEAP Admin Team</strong></p>
                </div>
                <div class='footer'>
                    <p>&copy; 2025 ETEEAP System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
        
        $mail->AltBody = "Dear {$user_name}, Your ETEEAP registration needs additional information. Reason: {$reason}. Please contact support or reapply at " . BASE_URL . "auth/register.php";
        
        $result = $mail->send();
        error_log("Rejection email sent to: {$user_email} - Status: " . ($result ? 'Success' : 'Failed'));
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Rejection email failed: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Notify admin of new registration
 */
function notifyAdminNewRegistration($user_name, $user_email, $admin_emails = ['admin@eteeap.com']) {
    try {
        $mail = makeMailer();
        
        // Add admin recipients
        foreach ($admin_emails as $admin_email) {
            $mail->addAddress($admin_email);
        }
        
        $mail->Subject = '🔔 New ETEEAP Registration - Pending Review';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .footer { background: #333; color: white; padding: 10px; text-align: center; }
                .info-box { background: #e3f2fd; border: 1px solid #2196f3; border-radius: 5px; padding: 15px; margin: 15px 0; }
                .review-btn { background: #667eea; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block; margin: 10px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>🔔 New Registration Alert</h2>
                </div>
                <div class='content'>
                    <h3>Dear Admin,</h3>
                    <p>A new ETEEAP registration has been submitted and requires your review.</p>
                    
                    <div class='info-box'>
                        <h4>📋 Registration Details:</h4>
                        <p><strong>Name:</strong> {$user_name}</p>
                        <p><strong>Email:</strong> {$user_email}</p>
                        <p><strong>Registration Date:</strong> " . date('F j, Y g:i A') . "</p>
                        <p><strong>Status:</strong> Pending Review</p>
                    </div>
                    
                    <h4>📄 Documents Submitted:</h4>
                    <ul>
                        <li>✅ ETEEAP Application Form</li>
                        <li>✅ Application Letter</li>
                        <li>✅ Curriculum Vitae (CV)</li>
                    </ul>
                    
                    <div style='text-align: center; margin: 20px 0;'>
                        <a href='" . BASE_URL . "admin/application-reviews.php' class='review-btn'>Review Application</a>
                    </div>
                    
                    <p><strong>Action Required:</strong></p>
                    <ol>
                        <li>Review uploaded documents</li>
                        <li>Verify completeness and accuracy</li>
                        <li>Approve or request additional information</li>
                        <li>User will be notified of your decision</li>
                    </ol>
                    
                    <p>Please review this application at your earliest convenience.</p>
                    
                    <p>Best regards,<br><strong>ETEEAP System</strong></p>
                </div>
                <div class='footer'>
                    <p>&copy; 2025 ETEEAP System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
        
        $mail->AltBody = "New ETEEAP registration from {$user_name} ({$user_email}) requires review. Please review at " . BASE_URL . "admin/application-reviews.php";
        
        $result = $mail->send();
        error_log("Admin notification sent for new registration: {$user_email} - Status: " . ($result ? 'Success' : 'Failed'));
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Admin notification failed: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Send test email to verify configuration
 */
function sendTestEmail($test_email = 'test@example.com') {
    try {
        $mail = makeMailer();
        $mail->addAddress($test_email);
        $mail->Subject = 'ETEEAP Email Test';
        $mail->Body = '<h1>Email Configuration Test</h1><p>If you receive this email, the ETEEAP email system is working correctly!</p>';
        $mail->AltBody = 'Email Configuration Test - ETEEAP email system is working correctly!';
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Test email failed: {$mail->ErrorInfo}");
        return false;
    }
}


/**
 * Send approval email that includes the assigned program + optional reroute reason
 */
function sendApprovalWithProgram($user_email, $user_name, $program_code, $program_name, $reroute_reason = '') {
    try {
        $mail = makeMailer();
        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Application Approved - Program Assignment';

        $htmlReason = '';
        if (!empty($reroute_reason)) {
            $htmlReason = "
            <div style='background:#fff3cd;padding:12px;border-radius:6px;border:1px solid #ffe8a1;margin:10px 0'>
                <strong>Reason for different program assignment:</strong><br>" . nl2br(htmlspecialchars($reroute_reason)) . "
            </div>";
        }

        $mail->Body = "
        <html><body style='font-family:Arial,sans-serif;color:#222'>
            <div style='max-width:640px;margin:auto'>
                <h2 style='background:#28a745;color:#fff;padding:16px;border-radius:8px'>Your Application is Approved ✅</h2>
                <p>Hi <strong>".htmlspecialchars($user_name)."</strong>,</p>
                <p>Your ETEEAP application has been approved.</p>
                <p><strong>Assigned Program:</strong><br>"
                . htmlspecialchars($program_code) . " - " . htmlspecialchars($program_name) .
                "</p>
                {$htmlReason}
                <p>You may now log in and proceed with the next steps.</p>
                <p><a href='".BASE_URL."auth/login.php' style='background:#667eea;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none'>Log in</a></p>
                <p>– ETEEAP Team</p>
            </div>
        </body></html>";

        $mail->AltBody =
            "Your application is approved.\n".
            "Assigned Program: {$program_code} - {$program_name}\n".
            (!empty($reroute_reason) ? "Reason: {$reroute_reason}\n" : "").
            "Login: ".BASE_URL."auth/login.php";

        return $mail->send();
    } catch (Exception $e) {
        error_log("sendApprovalWithProgram failed: {$e->getMessage()}");
        return false;
    }
}

?>