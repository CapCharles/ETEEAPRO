
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
    
    // ... rest of function stays the same

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
    $baseUrl   = _base_url_safe();
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
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta http-equiv="x-ua-compatible" content="ie=edge">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>ETEEAP Approval</title>
<style>
  html,body{margin:0!important;padding:0!important;height:100%!important;width:100%!important}
  *{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}
  a{text-decoration:none}
  img{border:0;line-height:100%;outline:none;text-decoration:none}
  table{border-collapse:collapse!important}
  @media screen and (max-width:600px){
    .container{width:100%!important}
    .px{padding-left:16px!important;padding-right:16px!important}
    .py{padding-top:16px!important;padding-bottom:16px!important}
    .h1{font-size:20px!important}
    .h2{font-size:14px!important}
  }
  :root{color-scheme:light dark;supported-color-schemes:light dark}
  @media (prefers-color-scheme:dark){
    .bg{background-color:#0f1115!important}
    .card{background-color:#161a22!important}
    .text{color:#e6e6e6!important}
    .muted{color:#b5b5b5!important}
    .footer{background-color:#0f1115!important;color:#b5b5b5!important}
    .btn{background-color:#6d9eff!important}
    .chip{background-color:#0f172a!important;color:#cbd5e1!important;border-color:#24304a!important}
  }
</style>
</head>
<body class="bg" style="background:#f3f5f9;margin:0;padding:0;">
  <!-- Preheader (hidden in most clients) -->
  <div style="display:none;overflow:hidden;line-height:1px;opacity:0;max-height:0;max-width:0;">
    ' . htmlspecialchars($preheader) . '
  </div>

  <table role="presentation" width="100%" bgcolor="#f3f5f9" style="background:#f3f5f9;">
    <tr>
      <td align="center" style="padding:24px;">
        <table role="presentation" width="600" class="container" style="width:600px;max-width:600px;">
          <!-- Brand row -->
          <tr>
            <td class="px" style="padding:0 24px 16px 24px;">
              <table role="presentation" width="100%">
                <tr>
                  <td style="font:600 16px Arial,Helvetica,sans-serif;color:#344054;">ETEEAP System</td>
                  <td align="right" style="font:12px Arial,Helvetica,sans-serif;color:#98A2B3;">' . date('F j, Y') . '</td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Card -->
          <tr>
            <td class="px" style="padding:0 24px 24px 24px;">
              <table role="presentation" width="100%" class="card" style="background:#ffffff;border-radius:12px;overflow:hidden;">
                <!-- Hero -->
                <tr>
                  <td align="center" style="background:linear-gradient(135deg,#22c55e 0%,#16a34a 100%);padding:24px;">
                    <div class="h1" style="font:700 22px Arial,Helvetica,sans-serif;color:#ffffff;">Your Application is Approved ✅</div>
                    <div class="h2" style="font:14px Arial,Helvetica,sans-serif;color:#eafff2;margin-top:4px;">You can now continue to the next steps.</div>
                  </td>
                </tr>

                <!-- Body -->
                <tr>
                  <td class="py px text" style="padding:24px;font:14px Arial,Helvetica,sans-serif;color:#344054;line-height:1.7;">
                    <p style="margin:0 0 12px 0;">Hi <strong>' . htmlspecialchars($fullName) . '</strong>,</p>
                    <p style="margin:0 0 12px 0;">Your ETEEAP application has been <strong>approved</strong>.</p>

                    <!-- Assigned Program -->
                    <div class="chip" style="margin:12px 0 14px 0;padding:14px;border:1px solid #e6e8ee;border-radius:10px;background:#fbfcff;">
                      <div style="font-weight:600;margin-bottom:6px;">Assigned Program</div>
                      <div style="font:14px Arial,Helvetica,sans-serif;">
                        ' . htmlspecialchars($program_code) . ' — ' . htmlspecialchars($program_name) . '
                      </div>
                      ' . $reasonHtml . '
                    </div>

                    <!-- Next steps -->
                    <div style="margin:14px 0 16px 0;padding:12px 14px;border:1px solid #e6e8ee;border-radius:10px;background:#ffffff;">
                      <div style="font-weight:600;margin-bottom:6px;">Next Steps</div>
                      <ul style="margin:0 0 0 18px;padding:0;">
                        <li>Log in to your ETEEAP account</li>
                        <li>Complete your candidate profile</li>
                        <li>Upload supporting documents for evaluation</li>
                        <li>Track your assessment progress</li>
                      </ul>
                    </div>

                    <!-- Button -->
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:12px 0 4px 0;">
                      <tr>
                        <td align="center" bgcolor="#3b82f6" style="border-radius:8px;">
                          <a href="' . $baseUrl . 'auth/login.php" class="btn"
                             style="display:inline-block;padding:12px 18px;font:600 14px Arial,Helvetica,sans-serif;color:#ffffff;background:#3b82f6;border-radius:8px;">
                            Log in to your account
                          </a>
                        </td>
                      </tr>
                    </table>

                    <p class="muted" style="margin:16px 0 0 0;color:#667085;font:12px Arial,Helvetica,sans-serif;">
                      If the button doesn’t work, copy and paste this URL into your browser:<br>
                      <span style="word-break:break-all;color:#475467;">' . $baseUrl . 'auth/login.php</span>
                    </p>
                  </td>
                </tr>

                <!-- Divider -->
                <tr><td style="padding:0 24px;"><hr style="border:none;border-top:1px solid #e6e8ee;margin:0;"></td></tr>

                <!-- Help -->
                <tr>
                  <td class="px" style="padding:16px 24px 24px 24px;font:12px Arial,Helvetica,sans-serif;color:#667085;">
                    Need help? Reply to this email or contact support.
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td class="px" style="padding:0 24px 32px 24px;">
              <table role="presentation" width="100%" class="footer" style="background:#0f172a;border-radius:12px;">
                <tr>
                  <td align="center" style="padding:18px 16px;font:12px Arial,Helvetica,sans-serif;color:#cbd5e1;">
                    &copy; ' . date('Y') . ' ETEEAP System. All rights reserved.
                    &nbsp;&middot;&nbsp; <a href="' . $baseUrl . '" style="color:#93c5fd;">Visit website</a>
                    &nbsp;&middot;&nbsp; <a href="#" style="color:#93c5fd;">Privacy Policy</a>
                    &nbsp;&middot;&nbsp; <a href="#" style="color:#93c5fd;">Contact Support</a>
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

?>
