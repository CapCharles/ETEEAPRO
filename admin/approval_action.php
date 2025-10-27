<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in and has approval rights
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['director_eteeap', 'ced', 'vpaa'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$application_id = isset($_POST['application_id']) ? intval($_POST['application_id']) : 0;
$action = isset($_POST['action']) ? $_POST['action'] : ''; // 'approve' or 'reject'
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

// Validate inputs
if (!$application_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Get application details
    $stmt = $pdo->prepare("
        SELECT a.*, 
               CONCAT(u.first_name, ' ', u.last_name) as candidate_name,
               u.email as candidate_email,
               p.program_name, p.program_code
        FROM applications a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN programs p ON a.program_id = p.id
        WHERE a.id = ?
    ");
    $stmt->execute([$application_id]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$application) {
        throw new Exception('Application not found');
    }
    
    // CRITICAL: Only allow approval of QUALIFIED applications
    // Partially qualified and Not qualified should NOT go through approval process
    if (!in_array($application['application_status'], ['qualified', 'under_review'])) {
        throw new Exception('Only QUALIFIED applications can be approved. This application is marked as: ' . $application['application_status']);
    }
    
    // Determine which approval level based on user type
    $approval_level = '';
    $status_column = '';
    $approver_column = '';
    $timestamp_column = '';
    $remarks_column = '';
    $next_status = '';
    $previous_status = '';
    
    switch ($user_type) {
        case 'director_eteeap':
            // Check if this is the first approval
            if ($application['director_eteeap_status'] !== 'pending') {
                throw new Exception('This application has already been processed by Director ETEEAP');
            }
            
            $approval_level = 'director_eteeap';
            $status_column = 'director_eteeap_status';
            $approver_column = 'director_eteeap_approved_by';
            $timestamp_column = 'director_eteeap_approved_at';
            $remarks_column = 'director_eteeap_remarks';
            $previous_status = 'pending';
            $next_status = $action === 'approve' ? 'approved' : 'rejected';
            break;
            
        case 'ced':
            // Check if Director ETEEAP has approved first
            if ($application['director_eteeap_status'] !== 'approved') {
                throw new Exception('Director ETEEAP must approve first before CED can review');
            }
            if ($application['ced_status'] !== 'pending') {
                throw new Exception('This application has already been processed by CED');
            }
            
            $approval_level = 'ced';
            $status_column = 'ced_status';
            $approver_column = 'ced_approved_by';
            $timestamp_column = 'ced_approved_at';
            $remarks_column = 'ced_remarks';
            $previous_status = 'pending';
            $next_status = $action === 'approve' ? 'approved' : 'rejected';
            break;
            
        case 'vpaa':
            // Check if both Director ETEEAP and CED have approved
            if ($application['director_eteeap_status'] !== 'approved') {
                throw new Exception('Director ETEEAP must approve first');
            }
            if ($application['ced_status'] !== 'approved') {
                throw new Exception('CED must approve first before VPAA can review');
            }
            if ($application['vpaa_status'] !== 'pending') {
                throw new Exception('This application has already been processed by VPAA');
            }
            
            $approval_level = 'vpaa';
            $status_column = 'vpaa_status';
            $approver_column = 'vpaa_approved_by';
            $timestamp_column = 'vpaa_approved_at';
            $remarks_column = 'vpaa_remarks';
            $previous_status = 'pending';
            $next_status = $action === 'approve' ? 'approved' : 'rejected';
            break;
            
        default:
            throw new Exception('Invalid user type for approval');
    }
    
    // Update the application with approval/rejection
    $sql = "UPDATE applications SET 
            $status_column = ?,
            $approver_column = ?,
            $timestamp_column = NOW(),
            $remarks_column = ?";
    
    // If VPAA approves, update final approval status
    if ($user_type === 'vpaa' && $action === 'approve') {
        $sql .= ", final_approval_status = 'fully_approved'";
    }
    
    // If anyone rejects, update final approval status
    if ($action === 'reject') {
        $sql .= ", final_approval_status = 'rejected'";
    }
    
    $sql .= " WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$next_status, $user_id, $remarks, $application_id]);
    
    // Log the approval action
    $stmt = $pdo->prepare("
        INSERT INTO approval_logs 
        (application_id, approver_role, approver_id, action, remarks, previous_status, new_status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $application_id,
        $approval_level,
        $user_id,
        $action === 'approve' ? 'approved' : 'rejected',
        $remarks,
        $previous_status,
        $next_status
    ]);
    
    // Send email notifications (commented out - implement based on your email setup)
    // require_once '../includes/email_notifications.php';
    
    //  EMAIL NOTIFICATIONS - UNCOMMENT WHEN EMAIL SYSTEM IS READY
    if ($action === 'approve') {
        // Determine who to notify next
        if ($user_type === 'director_eteeap') {
            // Notify CED
            $stmt = $pdo->query("SELECT email FROM users WHERE user_type = 'ced' AND status = 'active' LIMIT 1");
            $ced = $stmt->fetch();
            if ($ced) {
                sendApprovalNotification(
                    $ced['email'],
                    'CED',
                    $application['candidate_name'],
                    $application['program_name'],
                    $application_id,
                    'Director ETEEAP has approved this application. It now requires your review.'
                );
            }
        } elseif ($user_type === 'ced') {
            // Notify VPAA
            $stmt = $pdo->query("SELECT email FROM users WHERE user_type = 'vpaa' AND status = 'active' LIMIT 1");
            $vpaa = $stmt->fetch();
            if ($vpaa) {
                sendApprovalNotification(
                    $vpaa['email'],
                    'VPAA',
                    $application['candidate_name'],
                    $application['program_name'],
                    $application_id,
                    'CED has approved this application. It now requires your final review.'
                );
            }
        } elseif ($user_type === 'vpaa') {
            // Notify candidate - FINAL APPROVAL
            sendFinalApprovalNotification(
                $application['candidate_email'],
                $application['candidate_name'],
                $application['program_name'],
                $application['application_status'],
                $application['total_score']
            );
        }
    } else {
        // Rejection - notify evaluator and candidate
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$application['evaluator_id']]);
        $evaluator = $stmt->fetch();
        
        if ($evaluator) {
            sendRejectionNotification(
                $evaluator['email'],
                'Evaluator',
                $application['candidate_name'],
                $application['program_name'],
                $application_id,
                $user_type,
                $remarks
            );
        }
        
        // Also notify candidate about rejection
        sendCandidateRejectionNotification(
            $application['candidate_email'],
            $application['candidate_name'],
            $application['program_name'],
            $user_type
        );
    }
    // END OF EMAIL NOTIFICATIONS */
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    $response = [
        'success' => true,
        'message' => $action === 'approve' 
            ? 'Application approved successfully!' 
            : 'Application rejected successfully!',
        'action' => $action,
        'approver' => strtoupper(str_replace('_', ' ', $user_type)),
        'application_id' => $application_id
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ========================================
// Email Notification Functions
// ========================================

function sendApprovalNotification($to_email, $recipient_role, $candidate_name, $program_name, $app_id, $message) {
    $subject = "ETEEAP Application Awaiting Your Review";
    
    $email_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; }
            .content { background: #f8f9fa; padding: 20px; }
            .button { background: #667eea; color: white; padding: 10px 20px; text-decoration: none; display: inline-block; margin: 10px 0; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>ETEEAP Application Review Required</h2>
            </div>
            <div class='content'>
                <p>Dear $recipient_role,</p>
                <p>$message</p>
                <p><strong>Application Details:</strong></p>
                <ul>
                    <li><strong>Candidate:</strong> $candidate_name</li>
                    <li><strong>Program:</strong> $program_name</li>
                    <li><strong>Application ID:</strong> #$app_id</li>
                </ul>
                <p>Please log in to the system to review and approve this application.</p>
                <a href='" . SITE_URL . "/admin/evaluate.php?id=$app_id' class='button'>Review Application</a>
            </div>
            <div class='footer'>
                <p>This is an automated notification from the ETEEAP System</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($to_email, $subject, $email_body);
}

function sendFinalApprovalNotification($to_email, $candidate_name, $program_name, $status, $score) {
    $subject = "ETEEAP Application - Final Decision";
    
    $status_text = '';
    if ($status === 'qualified') {
        $status_text = '<span style="color: #198754; font-weight: bold;">QUALIFIED</span>';
    } elseif ($status === 'partially_qualified') {
        $status_text = '<span style="color: #ffc107; font-weight: bold;">PARTIALLY QUALIFIED</span>';
    } else {
        $status_text = '<span style="color: #dc3545; font-weight: bold;">NOT QUALIFIED</span>';
    }
    
    $email_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #134e5e 0%, #71b280 100%); color: white; padding: 20px; text-align: center; }
            .content { background: #f8f9fa; padding: 20px; }
            .result-box { background: white; border: 2px solid #198754; padding: 20px; margin: 20px 0; text-align: center; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>ETEEAP Application - Final Decision</h2>
            </div>
            <div class='content'>
                <p>Dear $candidate_name,</p>
                <p>We are pleased to inform you that your ETEEAP application has been fully reviewed and approved by all required authorities.</p>
                <div class='result-box'>
                    <h3>Final Assessment Result</h3>
                    <p><strong>Program:</strong> $program_name</p>
                    <p><strong>Status:</strong> $status_text</p>
                    <p><strong>Score:</strong> $score%</p>
                </div>
                <p>Please log in to your account to view the complete evaluation details and next steps.</p>
                <p>If you have any questions, please contact the ETEEAP office.</p>
            </div>
            <div class='footer'>
                <p>This is an automated notification from the ETEEAP System</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($to_email, $subject, $email_body);
}

function sendRejectionNotification($to_email, $recipient_role, $candidate_name, $program_name, $app_id, $rejector_role, $remarks) {
    $subject = "ETEEAP Application Returned for Review";
    
    $rejector_name = strtoupper(str_replace('_', ' ', $rejector_role));
    
    $email_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
            .content { background: #f8f9fa; padding: 20px; }
            .remarks { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 15px 0; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Application Returned for Review</h2>
            </div>
            <div class='content'>
                <p>Dear $recipient_role,</p>
                <p>The following application has been returned for review by $rejector_name.</p>
                <p><strong>Application Details:</strong></p>
                <ul>
                    <li><strong>Candidate:</strong> $candidate_name</li>
                    <li><strong>Program:</strong> $program_name</li>
                    <li><strong>Application ID:</strong> #$app_id</li>
                </ul>
                " . ($remarks ? "<div class='remarks'><strong>Remarks:</strong><br>$remarks</div>" : "") . "
                <p>Please review and make necessary revisions.</p>
            </div>
            <div class='footer'>
                <p>This is an automated notification from the ETEEAP System</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($to_email, $subject, $email_body);
}

function sendCandidateRejectionNotification($to_email, $candidate_name, $program_name, $rejector_role) {
    $subject = "ETEEAP Application - Update on Your Application";
    
    $email_body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #6c757d; color: white; padding: 20px; text-align: center; }
            .content { background: #f8f9fa; padding: 20px; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Application Update</h2>
            </div>
            <div class='content'>
                <p>Dear $candidate_name,</p>
                <p>We would like to inform you that your ETEEAP application for <strong>$program_name</strong> is currently under additional review.</p>
                <p>The evaluation team is working to ensure a thorough and accurate assessment of your application.</p>
                <p>We will notify you once the review process is complete.</p>
                <p>Thank you for your patience and understanding.</p>
            </div>
            <div class='footer'>
                <p>This is an automated notification from the ETEEAP System</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return sendEmail($to_email, $subject, $email_body);
}
?>