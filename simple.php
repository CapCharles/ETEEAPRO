<?php
/**
 * Simple ETEEAP Email Test
 * Basic test without class conflicts
 */

// Use your working PHPMailer code as base
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'C:\xampp\PHPMailer\PHPMailer\src\Exception.php';
require 'C:\xampp\PHPMailer\PHPMailer\src\PHPMailer.php';
require 'C:\xampp\PHPMailer\PHPMailer\src\SMTP.php';

// Test email sending function
function sendETEEAPEmail($toEmail, $subject, $htmlBody) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings (using your working configuration)
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'cspbank911@gmail.com';
        $mail->Password = 'uzhtbqmdqigquyqq';
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;

        // Recipients
        $mail->setFrom('cspbank911@gmail.com', 'ETEEAP System');
        $mail->addAddress($toEmail);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        echo "Mailer Error: {$mail->ErrorInfo}";
        return false;
    }
}

// Test different ETEEAP email templates
$testEmail = 'redpixelroze@gmail.com'; // Your test email

echo "<h2>🧪 ETEEAP Email Tests</h2>";

// Test 1: Welcome Email
echo "<h3>Test 1: Welcome Email</h3>";
$welcomeHTML = '
<div style="font-family: Arial, sans-serif; max-width: 600px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center;">
        <h1>🎓 Welcome to ETEEAP!</h1>
        <p>Your journey to academic recognition starts here</p>
    </div>
    <div style="padding: 20px;">
        <h2>Hello Test User!</h2>
        <p>Welcome to the ETEEAP platform! We\'re excited to help you transform your professional experience into academic credit.</p>
        <p><strong>Account Type:</strong> Candidate</p>
        <p><strong>Next Steps:</strong></p>
        <ul>
            <li>Complete your profile</li>
            <li>Choose your program</li>
            <li>Upload your documents</li>
            <li>Submit for evaluation</li>
        </ul>
        <p style="text-align: center;">
            <a href="http://localhost/eteeap/candidates/profile.php" style="background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Start Your Application</a>
        </p>
    </div>
</div>';

if (sendETEEAPEmail($testEmail, 'Welcome to ETEEAP - Your Account is Ready!', $welcomeHTML)) {
    echo "✅ Welcome email sent successfully!<br>";
} else {
    echo "❌ Welcome email failed!<br>";
}

// Test 2: Application Submitted
echo "<h3>Test 2: Application Submitted</h3>";
$submittedHTML = '
<div style="font-family: Arial, sans-serif; max-width: 600px;">
    <div style="background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 30px; text-align: center;">
        <h1>✅ Application Submitted!</h1>
        <p>Your ETEEAP application is now under review</p>
    </div>
    <div style="padding: 20px;">
        <h2>Great news, Test User!</h2>
        <p>Your ETEEAP application has been successfully submitted and is now in our evaluation queue.</p>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 20px 0;">
            <h3>📋 Application Summary</h3>
            <p><strong>Application ID:</strong> #000123</p>
            <p><strong>Program:</strong> BS Information Technology</p>
            <p><strong>Submitted:</strong> ' . date('F j, Y g:i A') . '</p>
        </div>
        <p><strong>What happens next?</strong></p>
        <ol>
            <li>Document verification (3-5 days)</li>
            <li>Expert assessment (10-14 days)</li>
            <li>Results notification</li>
        </ol>
        <p style="text-align: center;">
            <a href="http://localhost/eteeap/candidates/assessment.php" style="background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Track Application</a>
        </p>
    </div>
</div>';

if (sendETEEAPEmail($testEmail, 'Application Submitted Successfully - ETEEAP', $submittedHTML)) {
    echo "✅ Application submitted email sent successfully!<br>";
} else {
    echo "❌ Application submitted email failed!<br>";
}

// Test 3: Assessment Complete
echo "<h3>Test 3: Assessment Complete</h3>";
$assessmentHTML = '
<div style="font-family: Arial, sans-serif; max-width: 600px;">
    <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 30px; text-align: center;">
        <h1>🎯 Assessment Complete!</h1>
        <p>Your ETEEAP evaluation results are ready</p>
    </div>
    <div style="padding: 20px;">
        <h2>Dear Test User,</h2>
        <p>We\'re pleased to inform you that your ETEEAP assessment has been completed.</p>
        <div style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 25px; border-radius: 15px; text-align: center; margin: 20px 0;">
            <h3>📊 Your Results</h3>
            <div style="background: rgba(255,255,255,0.2); padding: 20px; border-radius: 10px;">
                <p><strong>Application ID:</strong> #000123</p>
                <p><strong>Program:</strong> BS Information Technology</p>
                <div style="font-size: 36px; font-weight: bold; margin: 10px 0;">85.5%</div>
                <div style="background: #28a745; padding: 8px 20px; border-radius: 20px; display: inline-block;">
                    QUALIFIED
                </div>
            </div>
        </div>
        <p><strong>Evaluator\'s Comments:</strong></p>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #667eea;">
            Excellent performance in all assessment criteria. Candidate demonstrates comprehensive understanding and practical application of IT concepts.
        </div>
        <p style="text-align: center; margin-top: 30px;">
            <a href="http://localhost/eteeap/candidates/assessment.php" style="background: #667eea; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px;">View Full Report</a>
        </p>
    </div>
</div>';

if (sendETEEAPEmail($testEmail, 'Assessment Complete - Your ETEEAP Results', $assessmentHTML)) {
    echo "✅ Assessment complete email sent successfully!<br>";
} else {
    echo "❌ Assessment complete email failed!<br>";
}

echo "<br><h3>🎉 Email Test Complete!</h3>";
echo "<p>Check your inbox at <strong>$testEmail</strong> for the test emails.</p>";
echo "<p><em>Note: Emails might go to spam folder initially.</em></p>";
?>