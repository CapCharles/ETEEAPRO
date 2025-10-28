<?php
// includes/email_notifications.php
// Safe + verbose PHPMailer wrapper: logs, SSL relax, 465→587 fallback

// ====== TEMP DEBUG (set to false pag ok na) ======
if (!defined('MAIL_DEBUG')) { define('MAIL_DEBUG', true); } // true para makita logs sa error_log

// ====== SMTP CREDENTIALS ======
if (!defined('MAIL_FROM_EMAIL')) { define('MAIL_FROM_EMAIL', 'cspbank911@gmail.com'); }
if (!defined('MAIL_FROM_NAME'))  { define('MAIL_FROM_NAME',  'ETEEAP System'); }
if (!defined('MAIL_USERNAME'))   { define('MAIL_USERNAME',   'cspbank911@gmail.com'); }
if (!defined('MAIL_PASSWORD'))   { define('MAIL_PASSWORD',   'uzhtbqmdqigquyqq'); } // Gmail App Password

function phpmailer_available(): bool {
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

function _base_url_safe(): string {
    return defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' : '/';
}

// Build PHPMailer instance.
// $mode = 'smtps' (465) or 'starttls' (587)
function buildMailer(string $mode = 'smtps') {
    if (!phpmailer_available()) {
        error_log('[MAIL] PHPMailer files not found');
        return null;
    }
    
    // Use same base path
    $base = __DIR__ . '/../PHPMailer/PHPMailer/src/';
    require_once $base . 'Exception.php';
    require_once $base . 'PHPMailer.php';
    require_once $base . 'SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    // Debug to error_log (DO NOT enable in production)
    $mail->SMTPDebug  = MAIL_DEBUG ? PHPMailer\PHPMailer\SMTP::DEBUG_SERVER : PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
    if (MAIL_DEBUG) {
        $mail->Debugoutput = function($str, $level) { error_log('[SMTP] ' . trim($str)); };
    }

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;

    // SSL/TLS relax (para hindi ma-block agad sa shared host na walang CA)
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

// Try send with fallback (465 → 587). Always logs ErrorInfo on failure.
function send_with_fallback(callable $prepare): bool {
    try {
        // 1) Try SMTPS:465
        $m1 = buildMailer('smtps');
        if ($m1) {
            $prepare($m1);
            $ok1 = $m1->send();
            if ($ok1) return true;
            error_log('[MAIL] SMTPS send failed: ' . $m1->ErrorInfo);
        } else {
            error_log('[MAIL] buildMailer(smtps) returned null.');
        }

        // 2) Fallback STARTTLS:587
        $m2 = buildMailer('starttls');
        if ($m2) {
            $prepare($m2);
            $ok2 = $m2->send();
            if ($ok2) return true;
            error_log('[MAIL] STARTTLS send failed: ' . $m2->ErrorInfo);
        } else {
            error_log('[MAIL] buildMailer(starttls) returned null.');
        }

        return false;
    } catch (Throwable $e) {
        error_log('[MAIL] send_with_fallback exception: ' . $e->getMessage());
        return false;
    }
}


/** Optional example; safe kahit walang PHPMailer */
function sendRegistrationEmail(string $toEmail, string $toName): bool {
    return send_with_fallback(function($mail) use ($toEmail, $toName) {
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = 'Welcome to ETEEAPRO';
        $mail->Body    = '<p>Hi ' . htmlspecialchars($toName) . ', welcome!</p>';
        $mail->AltBody = 'Hi ' . $toName . ', welcome!';
    });
}


function sendRegistrationNotification($user_email, $user_name) {
    $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; background-color: #f4f4f4; -webkit-font-smoothing: antialiased;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 40px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                               
                                    <td valign="middle" align="middle">
                                        <h1 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 600; letter-spacing: -0.5px;"> 🎓 ETEEAP Registration</h1>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 40px 40px 20px 40px;">
                            <h2 style="margin: 0 0 20px 0; color: #1a1a1a; font-size: 20px; font-weight: 600; line-height: 1.4;">Dear '.htmlspecialchars($user_name).',</h2>
                            
                            <p style="margin: 0 0 20px 0; color: #444444; font-size: 15px; line-height: 1.6;">Thank you for registering with the ETEEAP (Expanded Tertiary Education Equivalency and Accreditation Program)!</p>
                            
                            <!-- Status Badge -->
                            <div style="margin: 0 0 30px 0;">
                                <span style="display: inline-block; padding: 8px 16px; background-color: #fff3cd; color: #856404; font-size: 14px; font-weight: 600; border-radius: 6px; border: 1px solid #ffeaa7;">
                                    <span style="display: inline-block; width: 8px; height: 8px; background-color: #ffc107; border-radius: 50%; margin-right: 8px; vertical-align: middle;"></span>
                                    Under Review
                                </span>
                            </div>
                            
                            <!-- What\'s Next Section -->
                            <h3 style="margin: 30px 0 16px 0; color: #1a1a1a; font-size: 16px; font-weight: 600;">What\'s Next?</h3>
                            
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-bottom: 30px;">
                                <tr>
                                    <td style="padding: 0 0 12px 0;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td width="24" valign="top" style="padding-top: 2px;">
                                                    <span style="color: #28a745; font-size: 16px;">✓</span>
                                                </td>
                                                <td style="color: #444444; font-size: 15px; line-height: 1.6;">Your registration form has been received</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 0 0 12px 0;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td width="24" valign="top" style="padding-top: 2px;">
                                                    <span style="color: #28a745; font-size: 16px;">✓</span>
                                                </td>
                                                <td style="color: #444444; font-size: 15px; line-height: 1.6;">All required documents have been uploaded</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 0 0 12px 0;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td width="24" valign="top" style="padding-top: 2px;">
                                                    <span style="color: #ffc107; font-size: 16px;">⏳</span>
                                                </td>
                                                <td style="color: #444444; font-size: 15px; line-height: 1.6;">Our admin team is reviewing your application</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 0 0 12px 0;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td width="24" valign="top" style="padding-top: 2px;">
                                                    <span style="color: #17a2b8; font-size: 16px;">✉</span>
                                                </td>
                                                <td style="color: #444444; font-size: 15px; line-height: 1.6;">You will receive an email notification once approved</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Important Notes Section -->
                            <div style="background-color: #f8f9fa; border-left: 4px solid #667eea; border-radius: 4px; padding: 20px; margin-bottom: 30px;">
                                <h3 style="margin: 0 0 12px 0; color: #1a1a1a; font-size: 16px; font-weight: 600;">Important Notes</h3>
                                
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                    <tr>
                                        <td style="padding: 0 0 8px 0;">
                                            <span style="color: #666666; font-size: 14px; line-height: 1.6;">• Review process typically takes 2-3 business days</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 0 0 8px 0;">
                                            <span style="color: #666666; font-size: 14px; line-height: 1.6;">• You cannot log in until your application is approved</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 0 0 0 0;">
                                            <span style="color: #666666; font-size: 14px; line-height: 1.6;">• If additional information is needed, we will contact you</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <p style="margin: 0 0 10px 0; color: #444444; font-size: 15px; line-height: 1.6;">If you have any questions, please contact our support team.</p>
                            
                            <p style="margin: 0; color: #444444; font-size: 15px; line-height: 1.6;">Best regards,<br><strong style="color: #1a1a1a;">ETEEAP Admin Team</strong></p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #303030ff; padding: 30px 40px; border-top: 1px solid #e0e0e0;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td align="center" style="padding-bottom: 12px;">
                                        <a href="#" style="color: #667eea; text-decoration: none; font-size: 13px; margin: 0 12px;">Privacy Policy</a>
                                        <span style="color: #cccccc;">|</span>
                                        <a href="#" style="color: #667eea; text-decoration: none; font-size: 13px; margin: 0 12px;">Contact Support</a>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="color: #ffffffff; font-size: 12px; line-height: 1.5;">
                                        &copy; '.date('Y').' ETEEAP System. All rights reserved.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

    $alt = "Dear {$user_name}, Your ETEEAP registration has been received and is currently under review. You will be notified once your application is approved.";

    $ok = send_with_fallback(function($mail) use ($user_email, $user_name, $html, $alt) {
        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Registration - Under Review';
        $mail->Body    = $html;
        $mail->AltBody = $alt;
    });
    error_log('Registration email to ' . $user_email . ' => ' . ($ok ? 'Success' : 'Failed'));
    return $ok;
}


/** Rejection / Needs info (FULL HTML) */
function sendRejectionNotification($user_email, $user_name, $reason = '') {
    $baseUrl = _base_url_safe();
    $reason = !empty($reason) ? $reason : 'Additional review required';
    $html = "
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
            ul { padding-left: 18px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>📋 ETEEAP Registration Review</h2>
            </div>
            <div class='content'>
                <h3>Dear ".htmlspecialchars($user_name).",</h3>
                <p>Thank you for your interest in the ETEEAP program. After reviewing your application, we need additional information before we can proceed.</p>
                <p><strong>Application Status:</strong> <span class='warning-badge'>⚠️ Needs Attention</span></p>
                <div class='reason-box'>
                    <h4>📝 Review Comments:</h4>
                    <p><strong>".nl2br(htmlspecialchars($reason))."</strong></p>
                </div>
                <h4>What you can do:</h4>
                <ul>
                    <li>📧 Contact our support team for clarification</li>
                    <li>📄 Prepare the required additional information</li>
                    <li>🔄 Resubmit your application when ready</li>
                </ul>
                <div style='text-align: center; margin: 20px 0;'>
                    <a href='".$baseUrl."auth/register.php' class='reapply-btn'>Reapply Now</a>
                </div>
                <p><strong>Need Help?</strong></p>
                <p>If you have questions about the requirements or need assistance, please don't hesitate to contact our support team.</p>
                <p>We appreciate your patience and look forward to helping you achieve your educational goals.</p>
                <p>Best regards,<br><strong>ETEEAP Admin Team</strong></p>
            </div>
            <div class='footer'>
                <p>&copy; ".date('Y')." ETEEAP System. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";
    $alt = "Dear {$user_name}, Your ETEEAP registration needs additional information. Reason: {$reason}. Please reapply at ".$baseUrl."auth/register.php";

    $ok = send_with_fallback(function($mail) use ($user_email, $user_name, $html, $alt) {
        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Registration - Additional Information Required';
        $mail->Body    = $html;
        $mail->AltBody = $alt;
    });
    error_log('Rejection email to ' . $user_email . ' => ' . ($ok ? 'Success' : 'Failed'));
    return $ok;
}

/** Admin notify (FULL HTML) */
function notifyAdminNewRegistration($user_name, $user_email, $admin_emails = ['cspbank911@gmail.com']) {
    $baseUrl = _base_url_safe();
    $html = "
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
                    <p><strong>Name:</strong> ".htmlspecialchars($user_name)."</p>
                    <p><strong>Email:</strong> ".htmlspecialchars($user_email)."</p>
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
                    <a href='".$baseUrl."admin/application-reviews.php' class='review-btn'>Review Application</a>
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
                <p>&copy; ".date('Y')." ETEEAP System. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";
    $alt = "New ETEEAP registration from {$user_name} ({$user_email}) requires review. Review at ".$baseUrl."admin/application-reviews.php";

    $ok = send_with_fallback(function($mail) use ($admin_emails, $html, $alt) {
        foreach ($admin_emails as $admin_email) {
            $mail->addAddress($admin_email);
        }
        $mail->Subject = '🔔 New ETEEAP Registration - Pending Review';
        $mail->Body    = $html;
        $mail->AltBody = $alt;
    });
    error_log('Admin notification for ' . $user_email . ' => ' . ($ok ? 'Success' : 'Failed'));
    return $ok;
}



/** Approval + Program (PRO Email Template) */
function sendApprovalWithProgram($user_email, $user_name, $program_code, $program_name, $reroute_reason = '') {
    $baseUrl   = 'https://eteeapro.site/';
    $fullName  = trim($user_name);
    $preheader = "Your ETEEAP application is approved. Program: {$program_code} – {$program_name}. Continue inside.";

    $reasonHtml = '';
    if (!empty($reroute_reason)) {
        $reasonHtml = '
        <div style="margin-top:10px;padding:12px 14px;border:1px solid #ffe8a1;border-radius:8px;background:#fff8e1;color:#7a5d00;">
            <strong>Note on program assignment:</strong><br>' . nl2br(htmlspecialchars($reroute_reason)) . '
        </div>';
    }

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>ETEEAP Application Approved</title>
<style>
  html,body{margin:0!important;padding:0!important;width:100%!important;height:100%!important}
  *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}
  table{border-collapse:collapse!important}
  a{text-decoration:none}
  @media screen and (max-width:600px){
    .container{width:100%!important}
  }
  :root{color-scheme:light dark;supported-color-schemes:light dark}
  @media (prefers-color-scheme:dark){
    body{background-color:#0f1115!important}
    .card{background-color:#161a22!important;color:#e6e6e6!important}
    .footer{background-color:#0f1115!important;color:#b5b5b5!important}
    a.btn{background-color:#6d9eff!important}
  }
</style>
</head>
<body style="background:#f3f5f9;margin:0;padding:0;">
  <!-- Preheader -->
  <div style="display:none;overflow:hidden;line-height:1px;opacity:0;max-height:0;max-width:0;">' . htmlspecialchars($preheader) . '</div>

  <table role="presentation" width="100%" bgcolor="#f3f5f9">
    <tr>
      <td align="center" style="padding:24px;">
        <!-- Compact centered card -->
        <table role="presentation" width="100%" class="container" style="max-width:480px;width:100%;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
          <!-- Header -->
          <tr>
            <td align="center" style="background:linear-gradient(135deg,#22c55e 0%,#16a34a 100%);padding:20px 16px;">
              <div style="font:700 20px Arial,Helvetica,sans-serif;color:#ffffff;">Your Application is Approved ✅</div>
              <div style="font:14px Arial,Helvetica,sans-serif;color:#eafff2;">You can now continue to the next steps.</div>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:24px;font:14px Arial,Helvetica,sans-serif;color:#344054;line-height:1.7;">
              <p>Hi <strong>' . htmlspecialchars($fullName) . '</strong>,</p>
              <p>Your ETEEAP application has been <strong>approved</strong>.</p>

              <div style="margin:16px 0;padding:12px 14px;border:1px solid #e6e8ee;border-radius:8px;background:#fbfcff;">
                <div style="font-weight:600;margin-bottom:4px;">Assigned Program</div>
                <div>' . htmlspecialchars($program_code) . ' — ' . htmlspecialchars($program_name) . '</div>
                ' . $reasonHtml . '
              </div>

              <div style="margin:16px 0;padding:12px 14px;border:1px solid #e6e8ee;border-radius:8px;background:#fbfcff;">
                <div style="font-weight:600;margin-bottom:4px;">Next Steps</div>
                <ul style="margin:0 0 0 18px;padding:0;">
                  <li>Log in to your ETEEAP account</li>
                  <li>Complete your candidate profile</li>
                  <li>Upload supporting documents for evaluation</li>
                  <li>Track your assessment progress</li>
                </ul>
              </div>

              <!-- Centered login button -->
              <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:20px auto;">
                <tr>
                  <td align="center">
                    <a href="' . $baseUrl . 'auth/login.php"
                       class="btn"
                       style="display:inline-block;
                              padding:10px 22px;
                              font:600 14px Arial,Helvetica,sans-serif;
                              color:#ffffff;
                              background-color:#3b82f6;
                              border-radius:6px;
                              text-decoration:none;">
                      Log in to your account
                    </a>
                  </td>
                </tr>
              </table>

              <p style="font-size:12px;color:#667085;margin-top:20px;text-align:center;">
                If the button doesn't work, copy and paste this URL into your browser:<br>
                <a href="' . $baseUrl . 'auth/login.php" style="color:#3b82f6;">' . $baseUrl . 'auth/login.php</a>
              </p>

              <hr style="border:none;border-top:1px solid #e6e8ee;margin:24px 0;">
              <p style="font-size:12px;color:#888;text-align:center;">Need help? Reply to this email or contact support.</p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td align="center" class="footer" style="background:#0f172a;padding:16px;border-radius:0 0 12px 12px;">
              <p style="font:12px Arial,Helvetica,sans-serif;color:#ffffffff;margin:0;">
                &copy; ' . date('Y') . ' ETEEAP System. All rights reserved.<br>
                <a href="' . $baseUrl . '" style="color:#93c5fd;">Visit Website</a> ·
                <a href="' . $baseUrl . 'privacy" style="color:#93c5fd;">Privacy Policy</a> ·
                <a href="' . $baseUrl . 'contact" style="color:#93c5fd;">Contact Support</a>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

    $alt = "Your ETEEAP application has been approved. Program: {$program_code} - {$program_name}. Log in at {$baseUrl}auth/login.php";

    $ok = send_with_fallback(function($mail) use ($user_email, $fullName, $html, $alt) {
        $mail->addAddress($user_email, $fullName);
        $mail->Subject = 'ETEEAP Application Approved — Program Assigned';
        $mail->Body    = $html;
        $mail->AltBody = $alt;
        $mail->isHTML(true);
    });

    error_log("sendApprovalWithProgram to {$user_email}: " . ($ok ? 'Success' : 'Failed'));
    return $ok;
}


function sendEvaluationResultEmail($application, $final_score, $final_status, $recommendation, $bridgingUnits) {
    global $pdo;
    
    try {
        error_log("=== EMAIL FUNCTION START ===");
        error_log("Recipient: " . $application['candidate_email']);
        error_log("Score: " . $final_score);
        error_log("Status: " . $final_status);
        
        // Status badge color
        $statusColor = $final_status === 'qualified' ? '#28a745' : 
                      ($final_status === 'partially_qualified' ? '#ffc107' : '#dc3545');
        
        // Parse recommendation to extract subjects
        $recommendationLines = explode("\n", $recommendation);
        $creditedSubjects = [];
        $bridgingSubjects = [];
        $inCreditedSection = false;
        $inBridgingSection = false;
        
        foreach ($recommendationLines as $line) {
            $line = trim($line);
            
            if (strpos($line, 'CREDITED SUBJECTS') !== false) {
                $inCreditedSection = true;
                $inBridgingSection = false;
                continue;
            }
            if (strpos($line, 'REQUIRED BRIDGING COURSES') !== false) {
                $inCreditedSection = false;
                $inBridgingSection = true;
                continue;
            }
            if (strpos($line, 'PROGRAM COMPLETION TIMELINE') !== false || 
                strpos($line, 'NEXT STEPS') !== false ||
                strpos($line, 'OUTSTANDING ACHIEVEMENT') !== false) {
                $inCreditedSection = false;
                $inBridgingSection = false;
                continue;
            }
            
            if ($inCreditedSection && preg_match('/^✓\s+(.+)$/', $line, $matches)) {
                $creditedSubjects[] = [
                    'name' => trim($matches[1]),
                    'evidence' => ''
                ];
            } elseif ($inCreditedSection && preg_match('/Evidence:\s*(.+)$/i', $line, $matches)) {
                if (!empty($creditedSubjects)) {
                    $creditedSubjects[count($creditedSubjects) - 1]['evidence'] = trim($matches[1]);
                }
            }
            
            if ($inBridgingSection && preg_match('/^\d+\.\s+(.+?)\s*\(([^)]+)\)/', $line, $matches)) {
                $bridgingSubjects[] = [
                    'name' => trim($matches[1]),
                    'code' => trim($matches[2]),
                    'units' => 0,
                    'priority' => ''
                ];
            } elseif ($inBridgingSection && preg_match('/Units:\s*(\d+)\s*\|\s*Priority:\s*\[([^\]]+)\]/i', $line, $matches)) {
                if (!empty($bridgingSubjects)) {
                    $bridgingSubjects[count($bridgingSubjects) - 1]['units'] = (int)$matches[1];
                    $bridgingSubjects[count($bridgingSubjects) - 1]['priority'] = trim($matches[2]);
                }
            }
        }
        
        // Build credited subjects table
        $creditedTableRows = '';
        if (!empty($creditedSubjects)) {
            foreach ($creditedSubjects as $index => $subject) {
                $creditedTableRows .= '
                    <tr style="' . ($index % 2 === 0 ? 'background-color: #f8f9fa;' : '') . '">
                        <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">' . htmlspecialchars($subject['name']) . '</td>
                        <td style="padding: 12px; border-bottom: 1px solid #dee2e6; color: #6c757d; font-size: 14px;">' . htmlspecialchars($subject['evidence']) . '</td>
                    </tr>';
            }
        }
        
        // Build bridging subjects table
        $bridgingTableRows = '';
        if (!empty($bridgingSubjects)) {
            foreach ($bridgingSubjects as $index => $subject) {
                $priorityBadge = strpos(strtoupper($subject['priority']), 'HIGH') !== false ? 
                    '<span style="background-color: #dc3545; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">HIGH PRIORITY</span>' :
                    '<span style="background-color: #6c757d; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">STANDARD</span>';
                
                $bridgingTableRows .= '
                    <tr style="' . ($index % 2 === 0 ? 'background-color: #f8f9fa;' : '') . '">
                        <td style="padding: 12px; border-bottom: 1px solid #dee2e6; font-weight: 600;">' . htmlspecialchars($subject['name']) . '</td>
                        <td style="padding: 12px; border-bottom: 1px solid #dee2e6; text-align: center; color: #6c757d;">' . htmlspecialchars($subject['code']) . '</td>
                        <td style="padding: 12px; border-bottom: 1px solid #dee2e6; text-align: center; font-weight: 600;">' . $subject['units'] . '</td>
                        <td style="padding: 12px; border-bottom: 1px solid #dee2e6; text-align: center;">' . $priorityBadge . '</td>
                    </tr>';
            }
        }
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background-color: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                            
                            <!-- Header -->
                            <tr>
                                <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center;">
                                    <h1 style="color: white; margin: 0; font-size: 28px;">🎓 ETEEAP Evaluation Results</h1>
                                    <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0 0; font-size: 14px;">Expanded Tertiary Education Equivalency and Accreditation Program</p>
                                </td>
                            </tr>
                            
                            <!-- Greeting -->
                            <tr>
                                <td style="padding: 30px;">
                                    <p style="font-size: 16px; color: #333; margin: 0 0 20px 0;">Dear <strong>' . htmlspecialchars($application['candidate_name']) . '</strong>,</p>
                                    <p style="font-size: 14px; color: #666; line-height: 1.6; margin: 0 0 20px 0;">Your ETEEAP application for <strong>' . htmlspecialchars($application['program_name']) . '</strong> has been evaluated. Below are your assessment results:</p>
                                </td>
                            </tr>
                            
                            <!-- Results Card -->
                            <tr>
                                <td style="padding: 0 30px 30px 30px;">
                                    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f8f9fa; border-radius: 8px; border: 2px solid ' . $statusColor . ';">
                                        <tr>
                                            <td style="padding: 20px;">
                                                <table width="100%">
                                                    <tr>
                                                        <td width="50%" style="padding: 10px;">
                                                            <div style="font-size: 13px; color: #6c757d; margin-bottom: 5px;">Final Score</div>
                                                            <div style="font-size: 32px; font-weight: bold; color: ' . $statusColor . ';">' . $final_score . '%</div>
                                                        </td>
                                                        <td width="50%" style="padding: 10px; text-align: right;">
                                                            <div style="font-size: 13px; color: #6c757d; margin-bottom: 5px;">Status</div>
                                                            <div style="display: inline-block; background-color: ' . $statusColor . '; color: white; padding: 8px 16px; border-radius: 20px; font-weight: 600; font-size: 14px;">
                                                                ' . strtoupper(str_replace('_', ' ', $final_status)) . '
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            
                            ' . ($final_status === 'qualified' && $bridgingUnits > 0 ? '
                            <!-- Bridging Units Badge -->
                            <tr>
                                <td style="padding: 0 30px 20px 30px; text-align: center;">
                                    <div style="display: inline-block; background-color: #17a2b8; color: white; padding: 10px 20px; border-radius: 25px; font-weight: 600;">
                                        🎓 Bridging Requirements: ' . $bridgingUnits . ' units
                                    </div>
                                </td>
                            </tr>
                            ' : '') . '
                            
                            <!-- Detailed Evaluation & Recommendations -->
                            <tr>
                                <td style="padding: 0 30px 30px 30px;">
                                    <div style="background-color: #e7f3ff; border-left: 4px solid #007bff; padding: 20px; border-radius: 5px;">
                                        <h2 style="color: #007bff; margin: 0 0 15px 0; font-size: 18px;">📋 Detailed Evaluation & Recommendations</h2>
                                        
                                        ' . (!empty($creditedSubjects) ? '
                                        <div style="margin-bottom: 25px;">
                                            <h3 style="color: #28a745; font-size: 16px; margin: 0 0 10px 0;">✅ Credited Subjects - Prior Learning Recognition</h3>
                                            <p style="font-size: 13px; color: #666; margin: 0 0 10px 0;">The following subjects have been credited based on your demonstrated competencies:</p>
                                            <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #dee2e6; border-radius: 5px; overflow: hidden;">
                                                <thead>
                                                    <tr style="background-color: #28a745; color: white;">
                                                        <th style="padding: 12px; text-align: left; font-weight: 600; font-size: 14px;">Subject Name</th>
                                                        <th style="padding: 12px; text-align: left; font-weight: 600; font-size: 14px;">Evidence</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ' . $creditedTableRows . '
                                                </tbody>
                                            </table>
                                            <p style="font-size: 12px; color: #666; margin: 10px 0 0 0; font-style: italic;">
                                                Summary: ' . count($creditedSubjects) . ' subjects credited through prior learning assessment
                                            </p>
                                        </div>
                                        ' : '') . '
                                        
                                        ' . (!empty($bridgingSubjects) ? '
                                        <div style="margin-bottom: 20px;">
                                            <h3 style="color: #dc3545; font-size: 16px; margin: 0 0 10px 0;">📚 Required Bridging Courses</h3>
                                            <p style="font-size: 13px; color: #666; margin: 0 0 10px 0;">To complete your degree, you must fulfill ' . $bridgingUnits . ' units of bridging courses:</p>
                                            <table width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #dee2e6; border-radius: 5px; overflow: hidden;">
                                                <thead>
                                                    <tr style="background-color: #dc3545; color: white;">
                                                        <th style="padding: 12px; text-align: left; font-weight: 600; font-size: 14px;">Subject Name</th>
                                                        <th style="padding: 12px; text-align: center; font-weight: 600; font-size: 14px;">Code</th>
                                                        <th style="padding: 12px; text-align: center; font-weight: 600; font-size: 14px;">Units</th>
                                                        <th style="padding: 12px; text-align: center; font-weight: 600; font-size: 14px;">Priority</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ' . $bridgingTableRows . '
                                                </tbody>
                                                <tfoot>
                                                    <tr style="background-color: #f8f9fa; font-weight: bold;">
                                                        <td colspan="2" style="padding: 12px; text-align: right; border-top: 2px solid #dee2e6;">Total Bridging Units Required:</td>
                                                        <td style="padding: 12px; text-align: center; border-top: 2px solid #dee2e6; color: #dc3545; font-size: 16px;">' . $bridgingUnits . '</td>
                                                        <td style="padding: 12px; border-top: 2px solid #dee2e6;"></td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                        ' : '') . '
                                        
                                        <div style="background-color: white; padding: 15px; border-radius: 5px; margin-top: 15px;">
                                            <pre style="font-family: Arial, sans-serif; font-size: 13px; color: #333; line-height: 1.6; white-space: pre-wrap; margin: 0;">' . htmlspecialchars($recommendation) . '</pre>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Next Steps -->
                            <tr>
                                <td style="padding: 0 30px 30px 30px;">
                                    <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; border-radius: 5px;">
                                        <h3 style="color: #856404; margin: 0 0 10px 0; font-size: 16px;">📍 Next Steps</h3>
                                        <ul style="margin: 0; padding-left: 20px; color: #856404;">
                                            <li style="margin-bottom: 8px;">Our Admissions Office will contact you within 3-5 business days</li>
                                            <li style="margin-bottom: 8px;">Check your email regularly for further instructions</li>
                                            <li style="margin-bottom: 8px;">Prepare required enrollment documents</li>
                                            <li>For urgent inquiries, contact us at <a href="mailto:pobletecharles11@gmail.com" style="color: #856404; font-weight: 600;">pobletecharles11@gmail.com</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #dee2e6;">
                                    <p style="margin: 0; font-size: 12px; color: #6c757d;">
                                        This is an automated message from the ETEEAP System.<br>
                                        For questions, please contact <a href="mailto:pobletecharles11@gmail.com" style="color: #667eea;">pobletecharles11@gmail.com</a>
                                    </p>
                                </td>
                            </tr>
                            
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';
        
        $alt = strip_tags($recommendation);
        
        $ok = send_with_fallback(function($mail) use ($application, $html, $alt) {
            $mail->addAddress($application['candidate_email'], $application['candidate_name']);
            $mail->Subject = 'ETEEAP Evaluation Results - ' . ucfirst(str_replace('_', ' ', $application['application_status']));
            $mail->Body    = $html;
            $mail->AltBody = $alt;
        });
        
        error_log("✅ Email sent successfully!");
        return $ok;
        
    } catch (Exception $e) {
        error_log("❌ Email Error: " . $e->getMessage());
        return false;
    }


?>