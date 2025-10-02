<?php
// includes/email_notifications.php

function phpmailer_available(): bool {
    $base = __DIR__ . '/PHPMailer/src/';
    return file_exists($base . 'PHPMailer.php')
        && file_exists($base . 'SMTP.php')
        && file_exists($base . 'Exception.php');
}

function makeMailer() {
    if (!phpmailer_available()) {
        error_log('[MAIL] PHPMailer files not found in ' . __DIR__ . '/PHPMailer/src/');
        return null; // para hindi mag-fatal, babalik na lang tayo ng null
    }

    // Lazy require (dito lang i-load kapag talagang gagamitin)
    $base = __DIR__ . '/PHPMailer/src/';
    require_once $base . 'Exception.php';
    require_once $base . 'PHPMailer.php';
    require_once $base . 'SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'cspbank911@gmail.com';
    $mail->Password   = 'uzhtbqmdqigquyqq';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; // Changed
    $mail->Port       = 587; // Changed
    $mail->setFrom('cspbank911@gmail.com', 'ETEEAP System');
    $mail->isHTML(true);
    return $mail;
}

/** Example function */
function sendRegistrationEmail(string $toEmail, string $toName): bool {
    $mail = makeMailer();
    if ($mail === null) {
        error_log('[MAIL] Skipping sendRegistrationEmail: PHPMailer not available');
        return false;
    }
    try {
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = 'Welcome to ETEEAPRO';
        $mail->Body    = '<p>Hi ' . htmlspecialchars($toName) . ', welcome!</p>';
        $mail->AltBody = 'Hi ' . $toName . ', welcome!';
        return $mail->send();
    } catch (Throwable $e) {
        error_log('[MAIL] sendRegistrationEmail error: ' . $e->getMessage());
        return false;
    }
}

function sendRegistrationNotification($user_email, $user_name) {
    try {
        $mail = makeMailer();
        if ($mail === null) {
            error_log('[MAIL] Skipping sendRegistrationNotification: PHPMailer not available');
            return false;
        }

        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Registration - Under Review';

        $mail->Body = "
        <html>
        <body>
            <h2>🎓 ETEEAP Registration</h2>
            <p>Dear {$user_name},</p>
            <p>Thank you for registering! Your application is <b>Under Review</b>.</p>
        </body>
        </html>";
        $mail->AltBody = "Dear {$user_name}, Your registration is under review.";

        return $mail->send();
    } catch (Throwable $e) {
        error_log("Registration email failed: " . $e->getMessage());
        return false;
    }
}

function sendApprovalNotification($user_email, $user_name) {
    try {
        $mail = makeMailer();
        if ($mail === null) {
            error_log('[MAIL] Skipping sendApprovalNotification: PHPMailer not available');
            return false;
        }

        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Registration - Approved 🎉';
        $mail->Body = "<p>Congratulations {$user_name}, your application is approved!</p>";
        $mail->AltBody = "Congratulations {$user_name}, your application is approved!";

        return $mail->send();
    } catch (Throwable $e) {
        error_log("Approval email failed: " . $e->getMessage());
        return false;
    }
}

function sendRejectionNotification($user_email, $user_name, $reason = '') {
    try {
        $mail = makeMailer();
        if ($mail === null) {
            error_log('[MAIL] Skipping sendRejectionNotification: PHPMailer not available');
            return false;
        }

        $reason = !empty($reason) ? $reason : 'Additional information required';

        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Registration - Needs Attention';
        $mail->Body = "<p>Dear {$user_name},</p><p>Your registration needs attention. Reason: {$reason}</p>";
        $mail->AltBody = "Dear {$user_name}, your registration needs attention. Reason: {$reason}";

        return $mail->send();
    } catch (Throwable $e) {
        error_log("Rejection email failed: " . $e->getMessage());
        return false;
    }
}

function notifyAdminNewRegistration($user_name, $user_email, $admin_emails = ['admin@eteeap.com']) {
    try {
        $mail = makeMailer();
        if ($mail === null) {
            error_log('[MAIL] Skipping notifyAdminNewRegistration: PHPMailer not available');
            return false;
        }

        foreach ($admin_emails as $admin_email) {
            $mail->addAddress($admin_email);
        }
        $mail->Subject = '🔔 New Registration Submitted';
        $mail->Body = "<p>New registration from {$user_name} ({$user_email})</p>";
        $mail->AltBody = "New registration from {$user_name} ({$user_email})";

        return $mail->send();
    } catch (Throwable $e) {
        error_log("Admin notification failed: " . $e->getMessage());
        return false;
    }
}

function sendTestEmail($test_email = 'test@example.com') {
    try {
        $mail = makeMailer();
        if ($mail === null) {
            error_log('[MAIL] Skipping sendTestEmail: PHPMailer not available');
            return false;
        }

        $mail->addAddress($test_email);
        $mail->Subject = 'ETEEAP Email Test';
        $mail->Body = '<p>If you received this, the email system works.</p>';
        $mail->AltBody = 'ETEEAP Email Test';
        return $mail->send();
    } catch (Throwable $e) {
        error_log("Test email failed: " . $e->getMessage());
        return false;
    }
}

function sendApprovalWithProgram($user_email, $user_name, $program_code, $program_name, $reroute_reason = '') {
    try {
        $mail = makeMailer();
        if ($mail === null) {
            error_log('[MAIL] Skipping sendApprovalWithProgram: PHPMailer not available');
            return false;
        }

        $mail->addAddress($user_email, $user_name);
        $mail->Subject = 'ETEEAP Application Approved';

        $reasonText = !empty($reroute_reason) ? "Reason: {$reroute_reason}" : "";

        $mail->Body = "<p>Your application is approved.<br>Program: {$program_code} - {$program_name}<br>{$reasonText}</p>";
        $mail->AltBody = "Application approved. Program: {$program_code} - {$program_name}. {$reasonText}";

        return $mail->send();
    } catch (Throwable $e) {
        error_log("sendApprovalWithProgram failed: " . $e->getMessage());
        return false;
    }
}
?>
