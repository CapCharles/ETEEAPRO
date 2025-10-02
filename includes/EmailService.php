<?php
/**
 * Email Service for ETEEAP System
 * Handles all email sending functionality
 */

// Import PHPMailer classes - Updated with your working paths
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Your working PHPMailer paths
require 'C:\xampp\PHPMailer\PHPMailer\src\Exception.php';
require 'C:\xampp\PHPMailer\PHPMailer\src\PHPMailer.php';
require 'C:\xampp\PHPMailer\PHPMailer\src\SMTP.php';

require_once '../config/email.php';

class EmailService {
    
    private $mailer;
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->setupMailer();
    }
    
    /**
     * Setup PHPMailer configuration
     */
    private function setupMailer() {
        $this->mailer = new PHPMailer(true);
        
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = SMTP_HOST;
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = SMTP_USERNAME;
            $this->mailer->Password = SMTP_PASSWORD;
            $this->mailer->SMTPSecure = SMTP_SECURE;
            $this->mailer->Port = SMTP_PORT;
            
            // Default sender
            $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            
            // Debug settings
            if (EMAIL_DEBUG) {
                $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
            }
            
        } catch (Exception $e) {
            error_log("Email configuration error: " . $e->getMessage());
        }
    }
    
    /**
     * Send email with template
     */
    public function sendEmail($to, $templateType, $data = []) {
        if (!EMAIL_ENABLED) {
            return true; // Skip email sending if disabled
        }
        
        try {
            $template = EmailConfig::getTemplate($templateType);
            if (!$template) {
                throw new Exception("Email template not found: $templateType");
            }
            
            // Reset recipients
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            
            // Set recipient
            if (is_array($to)) {
                foreach ($to as $email) {
                    $this->mailer->addAddress($email);
                }
            } else {
                $this->mailer->addAddress($to);
            }
            
            // Set subject
            $this->mailer->Subject = $template['subject'];
            
            // Generate email content
            $emailContent = $this->generateEmailContent($template['template'], $data);
            
            // Set email body
            $this->mailer->isHTML(true);
            $this->mailer->Body = $emailContent;
            
            // Send email
            $result = $this->mailer->send();
            
            // Log email activity
            $this->logEmailActivity($to, $templateType, $result);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            $this->logEmailActivity($to, $templateType, false, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate email content from template
     */
    private function generateEmailContent($templateFile, $data) {
        $templatePath = "../includes/email_templates/$templateFile";
        
        if (!file_exists($templatePath)) {
            throw new Exception("Email template file not found: $templatePath");
        }
        
        // Extract data variables for template
        extract($data);
        
        // Start output buffering
        ob_start();
        include $templatePath;
        $content = ob_get_clean();
        
        return $content;
    }
    
    /**
     * Send welcome email to new users
     */
    public function sendWelcomeEmail($userEmail, $userName, $userType = 'candidate') {
        $data = [
            'user_name' => $userName,
            'user_email' => $userEmail,
            'user_type' => $userType,
            'login_url' => BASE_URL . 'auth/login.php',
            'dashboard_url' => $userType === 'candidate' ? BASE_URL . 'candidates/profile.php' : BASE_URL . 'admin/dashboard.php'
        ];
        
        return $this->sendEmail($userEmail, 'welcome', $data);
    }
    
    /**
     * Send application submitted notification
     */
    public function sendApplicationSubmitted($userEmail, $userName, $programName, $applicationId) {
        $data = [
            'user_name' => $userName,
            'program_name' => $programName,
            'application_id' => $applicationId,
            'assessment_url' => BASE_URL . 'candidates/assessment.php?id=' . $applicationId,
            'submission_date' => date('F j, Y g:i A')
        ];
        
        // Send to candidate
        $candidateResult = $this->sendEmail($userEmail, 'application_submitted', $data);
        
        // Send to admins
        $adminData = array_merge($data, [
            'candidate_email' => $userEmail,
            'admin_url' => BASE_URL . 'admin/evaluate.php?id=' . $applicationId
        ]);
        
        $adminResult = $this->sendEmail(ADMIN_EMAIL_RECIPIENTS, 'admin_new_application', $adminData);
        
        return $candidateResult && $adminResult;
    }
    
    /**
     * Send application under review notification
     */
    public function sendApplicationUnderReview($userEmail, $userName, $programName, $applicationId) {
        $data = [
            'user_name' => $userName,
            'program_name' => $programName,
            'application_id' => $applicationId,
            'assessment_url' => BASE_URL . 'candidates/assessment.php?id=' . $applicationId,
            'review_date' => date('F j, Y')
        ];
        
        return $this->sendEmail($userEmail, 'application_under_review', $data);
    }
    
    /**
     * Send assessment complete notification
     */
    public function sendAssessmentComplete($userEmail, $userName, $programName, $applicationId, $status, $score, $recommendation) {
        $data = [
            'user_name' => $userName,
            'program_name' => $programName,
            'application_id' => $applicationId,
            'status' => $status,
            'score' => $score,
            'recommendation' => $recommendation,
            'assessment_url' => BASE_URL . 'candidates/assessment.php?id=' . $applicationId,
            'completion_date' => date('F j, Y'),
            'status_class' => 'status-' . $status
        ];
        
        return $this->sendEmail($userEmail, 'assessment_complete', $data);
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordReset($userEmail, $userName, $resetToken) {
        $data = [
            'user_name' => $userName,
            'reset_url' => BASE_URL . 'auth/reset_password.php?token=' . $resetToken,
            'reset_token' => $resetToken,
            'expiry_time' => '24 hours'
        ];
        
        return $this->sendEmail($userEmail, 'password_reset', $data);
    }
    
    /**
     * Test email configuration
     */
    public function testEmailConfiguration($testEmail) {
        $data = [
            'user_name' => 'Test User',
            'test_time' => date('F j, Y g:i A')
        ];
        
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($testEmail);
            $this->mailer->Subject = 'ETEEAP Email Configuration Test';
            $this->mailer->isHTML(true);
            $this->mailer->Body = "
                <h2>Email Test Successful!</h2>
                <p>Your ETEEAP email configuration is working correctly.</p>
                <p>Test performed at: " . $data['test_time'] . "</p>
                <p>If you received this email, your SMTP settings are properly configured.</p>
            ";
            
            return $this->mailer->send();
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Log email activity to database
     */
    private function logEmailActivity($recipient, $templateType, $success, $errorMessage = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO email_logs (recipient, template_type, status, error_message, sent_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $status = $success ? 'sent' : 'failed';
            $recipientStr = is_array($recipient) ? implode(', ', $recipient) : $recipient;
            
            $stmt->execute([$recipientStr, $templateType, $status, $errorMessage]);
            
        } catch (PDOException $e) {
            error_log("Failed to log email activity: " . $e->getMessage());
        }
    }
    
    /**
     * Get email statistics
     */
    public function getEmailStats($startDate = null, $endDate = null) {
        try {
            $whereClause = "";
            $params = [];
            
            if ($startDate && $endDate) {
                $whereClause = "WHERE DATE(sent_at) BETWEEN ? AND ?";
                $params = [$startDate, $endDate];
            }
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    template_type,
                    status,
                    COUNT(*) as count,
                    DATE(sent_at) as date
                FROM email_logs 
                $whereClause
                GROUP BY template_type, status, DATE(sent_at)
                ORDER BY sent_at DESC
            ");
            
            $stmt->execute($params);
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>