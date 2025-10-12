<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';

// Check if user is logged in and is a candidate
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'candidate') {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$errors = [];

// Get user information with application form status
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: ../auth/logout.php');
        exit();
    }
} catch (PDOException $e) {
    die("Database error occurred");
}

// Get user's progress statistics for approved users
$progress_stats = [];
if ($user['application_form_status'] === 'approved') {
    try {
        // Get user's active applications with detailed progress
        $stmt = $pdo->prepare("
            SELECT a.*, p.program_name, p.program_code,
                   COUNT(DISTINCT d.id) as uploaded_documents,
                   COUNT(DISTINCT ac.id) as total_criteria,
                   COUNT(DISTINCT e.id) as evaluated_criteria,
                   ROUND(AVG(CASE WHEN e.score IS NOT NULL THEN (e.score / e.max_score) * 100 END), 1) as avg_score_percentage
            FROM applications a 
            LEFT JOIN programs p ON a.program_id = p.id 
            LEFT JOIN documents d ON a.id = d.application_id
            LEFT JOIN assessment_criteria ac ON p.id = ac.program_id AND ac.status = 'active'
            LEFT JOIN evaluations e ON a.id = e.application_id AND ac.id = e.criteria_id
            WHERE a.user_id = ?
            GROUP BY a.id
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $applications_with_progress = $stmt->fetchAll();
        
        // Get overall progress summary
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT a.id) as total_applications,
                COUNT(DISTINCT CASE WHEN a.application_status = 'draft' THEN a.id END) as draft_applications,
                COUNT(DISTINCT CASE WHEN a.application_status = 'submitted' THEN a.id END) as submitted_applications,
                COUNT(DISTINCT CASE WHEN a.application_status IN ('under_review', 'qualified', 'partially_qualified', 'not_qualified') THEN a.id END) as evaluated_applications,
                COUNT(DISTINCT d.id) as total_documents,
                COUNT(DISTINCT CASE WHEN a.application_status IN ('qualified', 'partially_qualified') THEN a.id END) as successful_applications
            FROM applications a
            LEFT JOIN documents d ON a.id = d.application_id
            WHERE a.user_id = ?
        ");
        $stmt->execute([$user_id]);
        $progress_stats = $stmt->fetch();
        
        // Get next steps recommendations
        $next_steps = [];
        foreach ($applications_with_progress as $app) {
            $steps = [];
            if ($app['application_status'] === 'draft') {
                if ($app['uploaded_documents'] < 3) {
                    $steps[] = ['action' => 'Upload more documents', 'priority' => 'high', 'description' => 'Upload at least 3 supporting documents'];
                }
                $steps[] = ['action' => 'Submit application', 'priority' => 'medium', 'description' => 'Submit for evaluation once documents are complete'];
            } elseif ($app['application_status'] === 'submitted') {
                $steps[] = ['action' => 'Wait for evaluation', 'priority' => 'low', 'description' => 'Your application is under review'];
            } elseif ($app['application_status'] === 'under_review') {
                $steps[] = ['action' => 'Evaluation in progress', 'priority' => 'low', 'description' => 'Evaluators are reviewing your documents'];
            }
            $next_steps[$app['id']] = $steps;
        }
        
    } catch (PDOException $e) {
        $applications_with_progress = [];
        $progress_stats = [
            'total_applications' => 0, 'draft_applications' => 0, 'submitted_applications' => 0,
            'evaluated_applications' => 0, 'total_documents' => 0, 'successful_applications' => 0
        ];
        $next_steps = [];
    }
}

// Handle initial document upload
if ($_POST && isset($_POST['upload_document'])) {
    $file_description = trim($_POST['file_description']);
    
    // Validation
    if (empty($file_description)) {
        $errors[] = "Document description is required";
    }
    
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        if ($_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = "Please select a file to upload";
        } else {
            $errors[] = "File upload error: " . $_FILES['document']['error'];
        }
    } else {
        $file = $_FILES['document'];
        $file_size = $file['size'];
        $file_type = $file['type'];
        $original_name = $file['name'];
        
        // Validate file size (10MB max)
        if ($file_size > 10 * 1024 * 1024) {
            $errors[] = "File size must be less than 10MB";
        }
        
        // Validate file type
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Only PDF, JPG, PNG, DOC, and DOCX files are allowed";
        }
        
        if ($file_size === 0) {
            $errors[] = "The selected file is empty";
        }
    }
    
    if (empty($errors)) {
        try {
            // Create upload directory if it doesn't exist
            $upload_dir = '../uploads/application_forms/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
            $stored_filename = $user_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $stored_filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                // Insert form record - matching your exact table structure
                $stmt = $pdo->prepare("
                    INSERT INTO application_forms (
                        user_id, file_type, file_description, original_filename, 
                        stored_filename, file_path, file_size, mime_type, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_review')
                ");
                
                // Determine file type category
                $file_type_category = 'document'; // default
                if (in_array($file_type, ['application/pdf'])) {
                    $file_type_category = 'pdf';
                } elseif (in_array($file_type, ['image/jpeg', 'image/jpg', 'image/png'])) {
                    $file_type_category = 'image';
                } elseif (in_array($file_type, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])) {
                    $file_type_category = 'document';
                }
                
                $result = $stmt->execute([
                    $user_id,
                    $file_type_category,
                    $file_description,
                    $original_name,
                    $stored_filename,
                    $file_path,
                    $file_size,
                    $file_type
                ]);
                
                if ($result) {
                    $form_id = $pdo->lastInsertId();
                    $success_message = "Application form uploaded successfully! It will be reviewed by the administrators.";
                    
                    // Create admin notification
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, title, message, type) 
                            VALUES (
                                (SELECT id FROM users WHERE user_type = 'admin' LIMIT 1),
                                'New Application Form Uploaded',
                                ?, 
                                'info'
                            )
                        ");
                        $notification_message = "Candidate " . $user['first_name'] . " " . $user['last_name'] . " has uploaded: " . $file_description;
                        $stmt->execute([$notification_message]);
                    } catch (PDOException $e) {
                        // Ignore notification errors
                    }
                } else {
                    $errors[] = "Failed to save document record to database.";
                }
            } else {
                $errors[] = "Failed to upload file. Please try again.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error occurred while saving document: " . $e->getMessage();
            error_log("Profile.php upload error: " . $e->getMessage());
        }
    }
}

// Handle document resubmission
if ($_POST && isset($_POST['resubmit_document'])) {
    $original_form_id = $_POST['original_form_id'];
    $file_description = trim($_POST['file_description']);
    
    // Validation
    if (empty($file_description)) {
        $errors[] = "Document description is required";
    }
    
    if (empty($original_form_id) || !is_numeric($original_form_id)) {
        $errors[] = "Invalid original form reference";
    }
    
    // Verify original form belongs to user
    try {
        $stmt = $pdo->prepare("SELECT id, status FROM application_forms WHERE id = ? AND user_id = ?");
        $stmt->execute([$original_form_id, $user_id]);
        $original_form = $stmt->fetch();
        if (!$original_form) {
            $errors[] = "Original form not found or access denied";
        }
    } catch (PDOException $e) {
        $errors[] = "Database error verifying original form";
    }
    
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please select a file to upload";
    } else {
        $file = $_FILES['document'];
        $file_size = $file['size'];
        $file_type = $file['type'];
        $original_name = $file['name'];
        
        // Same validation as upload
        if ($file_size > 10 * 1024 * 1024) {
            $errors[] = "File size must be less than 10MB";
        }
        
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Only PDF, JPG, PNG, DOC, and DOCX files are allowed";
        }
        
        if ($file_size === 0) {
            $errors[] = "The selected file is empty";
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Create upload directory if it doesn't exist
            $upload_dir = '../uploads/application_forms/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate unique filename
            $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
            $stored_filename = 'resubmit_' . $user_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $upload_dir . $stored_filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                // Determine file type category
                $file_type_category = 'document'; // default
                if (in_array($file_type, ['application/pdf'])) {
                    $file_type_category = 'pdf';
                } elseif (in_array($file_type, ['image/jpeg', 'image/jpg', 'image/png'])) {
                    $file_type_category = 'image';
                } elseif (in_array($file_type, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])) {
                    $file_type_category = 'document';
                }
                
                // Insert new form record with reference to original
                $stmt = $pdo->prepare("
                    INSERT INTO application_forms (
                        user_id, file_type, file_description, original_filename, 
                        stored_filename, file_path, file_size, mime_type, 
                        status, resubmission_of
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_review', ?)
                ");
                
                $result = $stmt->execute([
                    $user_id,
                    $file_type_category,
                    $file_description,
                    $original_name,
                    $stored_filename,
                    $file_path,
                    $file_size,
                    $file_type,
                    $original_form_id
                ]);
                
                if ($result) {
                    $new_form_id = $pdo->lastInsertId();
                    
                    // Mark original form as resubmitted
                    $stmt = $pdo->prepare("UPDATE application_forms SET status = 'resubmitted' WHERE id = ?");
                    $stmt->execute([$original_form_id]);
                    
                    $pdo->commit();
                    $success_message = "Document resubmitted successfully! It will be reviewed by the administrators.";
                } else {
                    $pdo->rollBack();
                    $errors[] = "Failed to save resubmission record.";
                }
            } else {
                $pdo->rollBack();
                $errors[] = "Failed to upload file. Please try again.";
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database error occurred while saving resubmission: " . $e->getMessage();
            error_log("Profile.php resubmit error: " . $e->getMessage());
        }
    }
}

// Get user's program preferences
$program_preferences = null;
try {
    $stmt = $pdo->prepare("
        SELECT pp.*, 
            p1.program_name as preferred_program_name, p1.program_code as preferred_program_code,
            p2.program_name as secondary_program_name, p2.program_code as secondary_program_code
        FROM user_program_preferences pp
        LEFT JOIN programs p1 ON pp.preferred_program_id = p1.id
        LEFT JOIN programs p2 ON pp.secondary_program_id = p2.id
        WHERE pp.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $program_preferences = $stmt->fetch();
} catch (PDOException $e) {
    $program_preferences = null;
}

// Get user's application forms and their review status
try {
    $stmt = $pdo->prepare("
        SELECT af.*, u.first_name as reviewer_first_name, u.last_name as reviewer_last_name
        FROM application_forms af
        LEFT JOIN users u ON af.reviewed_by = u.id
        WHERE af.user_id = ?
        ORDER BY af.upload_date DESC
    ");
    $stmt->execute([$user_id]);
    $application_forms = $stmt->fetchAll();
} catch (PDOException $e) {
    $application_forms = [];
}

// Get user's applications (only accessible if approved)
$applications = [];
if ($user['application_form_status'] === 'approved') {
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, p.program_name, p.program_code 
            FROM applications a 
            LEFT JOIN programs p ON a.program_id = p.id 
            WHERE a.user_id = ? 
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $applications = $stmt->fetchAll();
    } catch (PDOException $e) {
        $applications = [];
    }
}

// Get notifications for the user
try {
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    $notifications = [];
}

// Handle profile update
if ($_POST && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $middle_name = trim($_POST['middle_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    
    // Validation
    if (empty($first_name)) {
        $errors[] = "First name is required";
    }
    
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    }
    
    // Update profile if no errors
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?, middle_name = ?, phone = ?, address = ? 
                WHERE id = ?
            ");
            $stmt->execute([$first_name, $last_name, $middle_name, $phone, $address, $user_id]);
            
            $success_message = "Profile updated successfully!";
            
            // Update session name
            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
            
            // Refresh user data
            $user['first_name'] = $first_name;
            $user['last_name'] = $last_name;
            $user['middle_name'] = $middle_name;
            $user['phone'] = $phone;
            $user['address'] = $address;
            
        } catch (PDOException $e) {
            $errors[] = "Profile update failed. Please try again.";
        }
    }
}

// Handle AJAX notification mark as read
if (isset($_GET['mark_read']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET read_status = 1 WHERE user_id = ? AND id = ?");
        $stmt->execute([$user_id, $_GET['mark_read']]);
        
        // Return JSON response for AJAX
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit();
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Handle regular notification mark as read
if (isset($_GET['mark_read'])) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET read_status = 1 WHERE user_id = ? AND id = ?");
        $stmt->execute([$user_id, $_GET['mark_read']]);
        header('Location: profile.php');
        exit();
    } catch (PDOException $e) {
        // Ignore error
    }
}

// Get status badge class for application form status
function getFormStatusBadge($status) {
    switch ($status) {
        case 'approved': return 'bg-success';
        case 'rejected': return 'bg-danger';
        case 'needs_revision': return 'bg-warning';
        case 'pending_review': return 'bg-secondary';
        case 'resubmitted': return 'bg-info';
        default: return 'bg-secondary';
    }
}

// Check if user has documents that need revision
$has_revision_needed = false;
$revision_forms = [];
foreach ($application_forms as $form) {
    if ($form['status'] === 'needs_revision' && !$form['resubmission_of']) {
        $has_revision_needed = true;
        $revision_forms[] = $form;
    }
}

// Calculate overall progress percentage
$overall_progress = 0;
if ($user['application_form_status'] === 'approved') {
    $progress_factors = [
        'account_approved' => 25, // Base 25% for being approved
        'has_application' => isset($applications_with_progress) && count($applications_with_progress) > 0 ? 25 : 0,
        'documents_uploaded' => min(25, ($progress_stats['total_documents'] ?? 0) * 5), // 5% per document, max 25%
        'evaluation_progress' => ($progress_stats['evaluated_applications'] ?? 0) > 0 ? 25 : 0
    ];
    $overall_progress = array_sum($progress_factors);
} else {
    $overall_progress = $user['application_form_status'] === 'pending' ? 10 : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - ETEEAP</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            margin: 0; 
            padding-top: 0 !important;
        }
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
        }
        .progress-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
        }
        .progress-step {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid rgba(255,255,255,0.3);
        }
        .progress-step.completed {
            border-left-color: #28a745;
            background: rgba(40, 167, 69, 0.2);
        }
        .progress-step.current {
            border-left-color: #ffc107;
            background: rgba(255, 193, 7, 0.2);
        }
        .progress-step.pending {
            border-left-color: #6c757d;
            background: rgba(108, 117, 125, 0.1);
        }
        .status-alert {
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .approval-pending {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        .approval-rejected {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .approval-approved {
            background: linear-gradient(135deg, #d1e7dd 0%, #badbcc 100%);
            border: 1px solid #badbcc;
            color: #0f5132;
        }
        .revision-needed {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        .next-steps-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            border: none;
            border-left: 4px solid #28a745;
        }
        .step-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid #dee2e6;
        }
        .step-high { border-left-color: #dc3545; }
        .step-medium { border-left-color: #ffc107; }
        .step-low { border-left-color: #28a745; }
        .nav-pills .nav-link {
            border-radius: 10px;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .document-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid #dee2e6;
        }
        .document-approved { border-left-color: #28a745; }
        .document-rejected { border-left-color: #dc3545; }
        .document-needs-revision { border-left-color: #ffc107; }
        .document-pending-review { border-left-color: #6c757d; }
        .document-resubmission { border-left-color: #17a2b8; background: #e7f7ff; }
        .document-resubmitted { border-left-color: #6c757d; background: #f8f9fa; opacity: 0.7; }
        .notification-item {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-left: 4px solid #007bff;
            transition: all 0.3s ease;
        }
        .notification-item.unread {
            background: #f8f9ff;
            border-left-color: #007bff;
        }
        .notification-item.read {
            background: #f8f9fa;
            border-left-color: #dee2e6;
        }
        .access-restricted {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            color: #856404;
        }
        .resubmit-form {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1rem;
        }
        .file-drop-zone {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .file-drop-zone:hover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }
        .file-drop-zone.drag-over {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.1);
        }
        .file-drop-zone.file-selected {
            border-color: #28a745;
            background: rgba(40, 167, 69, 0.05);
        }
        .progress-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            margin: 0 auto 1rem;
            position: relative;
            background: conic-gradient(#28a745 0deg, #28a745 calc(var(--progress) * 3.6deg), #e9ecef calc(var(--progress) * 3.6deg), #e9ecef 360deg);
        }
        .progress-circle::before {
            content: '';
            position: absolute;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: white;
        }
        .progress-circle span {
            position: relative;
            z-index: 1;
        }

.btn-group .btn {
  min-width: 160px; /* adjust as needed */
}

    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../index.php">
                <i class="fas fa-graduation-cap me-2"></i>ETEEAPRO
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="profile.php">
                            <i class="fas fa-user me-1"></i>Profile
                        </a>
                    </li>
                    <?php if ($user['application_form_status'] === 'approved'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="upload.php">
                            <i class="fas fa-upload me-1"></i>Upload Documents
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="assessment.php">
                            <i class="fas fa-clipboard-check me-1"></i>Assessment
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($user['first_name']); ?>
                            <?php 
                            $unread_count = count(array_filter($notifications, function($n) { return !$n['read_status']; }));
                            if ($unread_count > 0): 
                            ?>
                            <span class="badge bg-danger ms-1" id="notification-badge"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="display-5 fw-bold mb-2">Welcome, <?php echo htmlspecialchars($user['first_name']); ?>!</h1>
                    <p class="lead mb-0">Manage your ETEEAP application and track your progress</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="text-white-50">
                        <small>Member since <?php echo date('F Y', strtotime($user['created_at'])); ?></small><br>
                        <span class="badge <?php echo getFormStatusBadge($user['application_form_status']); ?> fs-6">
                            <?php echo ucfirst(str_replace('_', ' ', $user['application_form_status'])); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Progress Tracking Section (for approved users) -->
        <?php if ($user['application_form_status'] === 'approved'): ?>
                <!-- Application Form Status Alert -->
        <?php if ($user['application_form_status'] === 'pending'): ?>
        <div class="status-alert approval-pending">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5><i class="fas fa-clock me-2"></i>Application Under Review</h5>
                    <p class="mb-0">Your ETEEAP application forms are currently being reviewed by our administrators. You will be notified once your application is approved and you can access the full system.</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <i class="fas fa-hourglass-half fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
        <?php elseif ($user['application_form_status'] === 'rejected'): ?>
        <div class="status-alert approval-rejected">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5><i class="fas fa-times-circle me-2"></i>Application Rejected</h5>
                    <p class="mb-1">Unfortunately, your application has been rejected.</p>
                    <?php if ($user['rejection_reason']): ?>
                    <p class="mb-0"><strong>Reason:</strong> <?php echo htmlspecialchars($user['rejection_reason']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-md-end">
                    <i class="fas fa-times-circle fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
        <?php elseif ($user['application_form_status'] === 'approved'): ?>
        <div class="status-alert approval-approved">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5><i class="fas fa-check-circle me-2"></i>Application Approved!</h5>
                    <p class="mb-0">Congratulations! Your application has been approved. You now have full access to the ETEEAP system.</p>
                    <?php if ($user['approval_date']): ?>
                    <small>Approved on <?php echo date('F j, Y', strtotime($user['approval_date'])); ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-md-end">
                    <i class="fas fa-check-circle fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="progress-section">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h3><i class="fas fa-chart-line me-2"></i>Your ETEEAP Journey Progress</h3>
                    <p class="mb-4">Track your progress through the ETEEAP assessment process</p>
                    
                    <!-- Progress Steps -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="progress-step completed">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-check-circle fa-2x me-3"></i>
                                    <div>
                                        <h6 class="mb-1">Application Forms Approved</h6>
                                        <small>Your initial application has been approved</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="progress-step <?php echo count($applications) > 0 ? 'completed' : 'current'; ?>">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-<?php echo count($applications) > 0 ? 'check-circle' : 'clock'; ?> fa-2x me-3"></i>
                                    <div>
                                        <h6 class="mb-1">Create ETEEAP Application</h6>
                                        <small><?php echo count($applications) > 0 ? 'Application created!' : 'Create your first ETEEAP application'; ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="progress-step <?php echo ($progress_stats['total_documents'] ?? 0) > 0 ? 'completed' : (count($applications) > 0 ? 'current' : 'pending'); ?>">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-<?php echo ($progress_stats['total_documents'] ?? 0) > 0 ? 'check-circle' : (count($applications) > 0 ? 'clock' : 'hourglass-start'); ?> fa-2x me-3"></i>
                                    <div>
                                        <h6 class="mb-1">Upload Supporting Documents</h6>
                                        <small><?php echo ($progress_stats['total_documents'] ?? 0); ?> documents uploaded</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="progress-step <?php echo ($progress_stats['evaluated_applications'] ?? 0) > 0 ? 'completed' : 'pending'; ?>">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-<?php echo ($progress_stats['evaluated_applications'] ?? 0) > 0 ? 'check-circle' : 'hourglass-start'; ?> fa-2x me-3"></i>
                                    <div>
                                        <h6 class="mb-1">Assessment & Evaluation</h6>
                                        <small><?php echo ($progress_stats['evaluated_applications'] ?? 0) > 0 ? 'Assessment completed!' : 'Waiting for evaluation'; ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 text-center">
                    <div class="progress-circle" style="--progress: <?php echo $overall_progress; ?>">
                        <span><?php echo $overall_progress; ?>%</span>
                    </div>
                    <h5 class="mb-2">Overall Progress</h5>
                    <p class="mb-0">Keep going! You're making great progress.</p>
                </div>
            </div>
        </div>

        <!-- Next Steps Recommendations -->
        <?php if (!empty($applications_with_progress) && !empty($next_steps)): ?>
        <div class="next-steps-card p-4 mb-4">
            <h5><i class="fas fa-lightbulb me-2"></i>Recommended Next Steps</h5>
            <div class="row g-3">
                <?php foreach ($applications_with_progress as $app): ?>
                    <?php if (!empty($next_steps[$app['id']])): ?>
                    <div class="col-md-6">
                        <h6 class="text-primary"><?php echo htmlspecialchars($app['program_code']); ?> Application</h6>
                        <?php foreach ($next_steps[$app['id']] as $step): ?>
                        <div class="step-item step-<?php echo $step['priority']; ?>">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-<?php echo $step['priority'] === 'high' ? 'exclamation-circle' : ($step['priority'] === 'medium' ? 'clock' : 'info-circle'); ?> me-2"></i>
                                <div>
                                    <strong><?php echo htmlspecialchars($step['action']); ?></strong>
                                    <br><small><?php echo htmlspecialchars($step['description']); ?></small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Stats for Approved Users -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="profile-card p-3 text-center">
                    <i class="fas fa-graduation-cap fa-2x text-primary mb-2"></i>
                    <div class="h4 text-primary"><?php echo $progress_stats['total_applications'] ?? 0; ?></div>
                    <div class="text-muted">Applications</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="profile-card p-3 text-center">
                    <i class="fas fa-file-upload fa-2x text-info mb-2"></i>
                    <div class="h4 text-info"><?php echo $progress_stats['total_documents'] ?? 0; ?></div>
                    <div class="text-muted">Documents</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="profile-card p-3 text-center">
                    <i class="fas fa-clipboard-check fa-2x text-warning mb-2"></i>
                    <div class="h4 text-warning"><?php echo $progress_stats['evaluated_applications'] ?? 0; ?></div>
                    <div class="text-muted">Evaluated</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="profile-card p-3 text-center">
                    <i class="fas fa-trophy fa-2x text-success mb-2"></i>
                    <div class="h4 text-success"><?php echo $progress_stats['successful_applications'] ?? 0; ?></div>
                    <div class="text-muted">Qualified</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document Revision Alert -->
        <?php if ($has_revision_needed): ?>
        <div class="status-alert revision-needed">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5><i class="fas fa-edit me-2"></i>Documents Need Revision</h5>
                    <p class="mb-0">Some of your documents require revision. Please review the comments and resubmit the corrected documents.</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <button class="btn btn-warning" onclick="document.getElementById('forms-tab').click()">
                        <i class="fas fa-edit me-2"></i>Review & Resubmit
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

    
        <!-- Messages -->
        <?php if ($success_message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php elseif (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Profile Card -->
                <div class="card profile-card mb-4">
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="bg-primary rounded-circle d-inline-flex align-items-center justify-content-center" 
                                style="width: 80px; height: 80px;">
                                <i class="fas fa-user fa-2x text-white"></i>
                            </div>
                            <h5 class="mt-3 mb-1"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                            <p class="text-muted mb-2"><?php echo htmlspecialchars($user['email']); ?></p>
                            <span class="badge <?php echo getFormStatusBadge($user['application_form_status']); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $user['application_form_status'])); ?>
                            </span>
                            <?php if ($has_revision_needed): ?>
                            <br><span class="badge bg-warning mt-1">
                                <i class="fas fa-edit me-1"></i>Revision Needed
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($program_preferences): ?>
                        <div class="border-top pt-3">
                            <h6 class="mb-2">Program Preferences</h6>
                            <div class="mb-2">
                                <small class="text-muted">Preferred:</small><br>
                                <strong><?php echo htmlspecialchars($program_preferences['preferred_program_code']); ?></strong>
                                <small class="text-muted"> - <?php echo htmlspecialchars($program_preferences['preferred_program_name']); ?></small>
                            </div>
                            <?php if ($program_preferences['secondary_program_id']): ?>
                            <div>
                                <small class="text-muted">Alternative:</small><br>
                                <strong><?php echo htmlspecialchars($program_preferences['secondary_program_code']); ?></strong>
                                <small class="text-muted"> - <?php echo htmlspecialchars($program_preferences['secondary_program_name']); ?></small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Notifications -->
                <?php if (!empty($notifications)): ?>
                <div class="card profile-card">
                    <div class="card-body">
                        <h6 class="mb-3">Recent Notifications</h6>
                        <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item <?php echo $notification['read_status'] ? 'read' : 'unread'; ?>" data-notification-id="<?php echo $notification['id']; ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 small"><?php echo htmlspecialchars($notification['title']); ?></h6>
                                    <p class="mb-1 small"><?php echo htmlspecialchars($notification['message']); ?></p>
                                    <small class="text-muted"><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></small>
                                </div>
                                <?php if (!$notification['read_status']): ?>
                                <button class="btn btn-sm btn-outline-primary mark-read-btn" 
                                        onclick="markNotificationRead(<?php echo $notification['id']; ?>)">
                                    <i class="fas fa-check"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Main Content -->
            <div class="col-lg-8">
                <div class="card profile-card">
                    <div class="card-body">
                        <!-- Tab Navigation -->
                        <ul class="nav nav-pills mb-4" id="profileTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="overview-tab" data-bs-toggle="pill" data-bs-target="#overview" type="button">
                                    <i class="fas fa-chart-line me-1"></i>Overview
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $has_revision_needed ? 'text-warning' : ''; ?>" id="forms-tab" data-bs-toggle="pill" data-bs-target="#forms" type="button">
                                    <i class="fas fa-file-alt me-1"></i>Application Forms
                                    <?php if ($has_revision_needed): ?>
                                        <span class="badge bg-warning ms-1"><?php echo count($revision_forms); ?></span>
                                    <?php endif; ?>
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="profile-tab" data-bs-toggle="pill" data-bs-target="#profile" type="button">
                                    <i class="fas fa-user me-1"></i>Edit Profile
                                </button>
                            </li>

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="applications-tab" data-bs-toggle="pill" data-bs-target="#applications" type="button">
                                    <i class="fas fa-graduation-cap me-1"></i>My Applications
                                </button>
                            </li>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content" id="profileTabsContent">
                            <!-- Overview Tab -->
                            <div class="tab-pane fade show active" id="overview" role="tabpanel">
                                <h5 class="mb-3">Account Overview</h5>
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <div class="text-center p-3 bg-light rounded">
                                            <i class="fas fa-file-alt fa-2x text-primary mb-2"></i>
                                            <div class="h5 mb-1"><?php echo count($application_forms); ?></div>
                                            <small class="text-muted">Forms Submitted</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 bg-light rounded">
                                            <i class="fas fa-bell fa-2x text-warning mb-2"></i>
                                            <div class="h5 mb-1" id="unread-count"><?php echo count(array_filter($notifications, function($n) { return !$n['read_status']; })); ?></div>
                                            <small class="text-muted">Unread Notifications</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="text-center p-3 bg-light rounded">
                                            <i class="fas fa-graduation-cap fa-2x text-success mb-2"></i>
                                            <div class="h5 mb-1"><?php echo count($applications); ?></div>
                                            <small class="text-muted">ETEEAP Applications</small>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($user['application_form_status'] === 'approved'): ?>
                                <div class="mt-4">
                                    <h6>Quick Actions</h6>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <a href="upload.php" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-upload me-1"></i>Upload Documents
                                        </a>
                                        <a href="assessment.php" class="btn btn-outline-success btn-sm">
                                            <i class="fas fa-clipboard-check me-1"></i>View Assessment
                                        </a>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="access-restricted">
                                    <i class="fas fa-lock fa-3x mb-3"></i>
                                    <h5>Full Access Pending</h5>
                                    <p class="mb-0">Complete system access will be available once your application forms are approved.</p>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Application Forms Tab -->
                            <?php $can_submit_forms = ($user['application_form_status'] !== 'approved'); ?>

                            <div class="tab-pane fade" id="forms" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0">Application Forms</h5>
                                    <?php if ($can_submit_forms && (empty($application_forms) || $user['application_form_status'] === 'rejected')): ?>
                                        <button class="btn btn-primary btn-sm" onclick="showUploadForm()">
                                            <i class="fas fa-upload me-1"></i>Upload Document
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if (empty($application_forms)): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                                        <h5>No Application Forms Yet</h5>
                                        <p class="text-muted mb-4">Upload your application forms to get started with the ETEEAP process.</p>
                                        <?php if ($can_submit_forms): ?>
                                        <button class="btn btn-primary" onclick="showUploadForm()">
                                            <i class="fas fa-upload me-2"></i>Upload First Document
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($application_forms as $form): ?>
                                        <div class="document-item document-<?php echo str_replace('_', '-', $form['status']); ?> <?php echo $form['resubmission_of'] ? 'document-resubmission' : ''; ?>">
                                            <div class="row align-items-start">
                                                <div class="col-md-8">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <h6 class="mb-0 me-2"><?php echo htmlspecialchars($form['file_description']); ?></h6>
                                                        <?php if ($form['resubmission_of']): ?>
                                                            <span class="badge bg-info small">Resubmission</span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <p class="mb-1 small text-muted">
                                                        <i class="fas fa-file-pdf me-1"></i>
                                                        <?php echo htmlspecialchars($form['original_filename']); ?>
                                                    </p>

                                                    <div class="mb-2">
                                                        <span class="badge <?php echo getFormStatusBadge($form['status']); ?> small">
                                                            <?php echo ucfirst(str_replace('_', ' ', $form['status'])); ?>
                                                        </span>
                                                        <span class="badge bg-secondary small ms-1">
                                                            <?php echo number_format($form['file_size'] / 1024, 1); ?> KB
                                                        </span>
                                                    </div>

                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-calendar me-1"></i>
                                                        Uploaded: <?php echo date('M j, Y g:i A', strtotime($form['upload_date'])); ?>
                                                    </small>

                                                    <?php if ($form['review_date']): ?>
                                                    <small class="text-muted d-block">
                                                        <i class="fas fa-user-check me-1"></i>
                                                        Reviewed: <?php echo date('M j, Y g:i A', strtotime($form['review_date'])); ?>
                                                        <?php if ($form['reviewer_first_name']): ?>
                                                            by <?php echo htmlspecialchars($form['reviewer_first_name'].' '.$form['reviewer_last_name']); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="col-md-4 text-md-end">
                                                    <button class="btn btn-outline-primary btn-sm mb-2"
                                                            onclick="viewDocument('<?php echo htmlspecialchars($form['stored_filename']); ?>',
                                                                                  '<?php echo htmlspecialchars($form['file_description']); ?>')">
                                                        <i class="fas fa-eye me-1"></i>View Document
                                                    </button>
                                                    <br>

                                                    <?php if ($can_submit_forms && $form['status'] === 'needs_revision'): ?>
                                                        <?php
                                                        $this_form_resubmitted = false;
                                                        foreach ($application_forms as $check_form) {
                                                            if ($check_form['resubmission_of'] == $form['id']) { $this_form_resubmitted = true; break; }
                                                        }
                                                        ?>
                                                        <?php if (!$this_form_resubmitted): ?>
                                                            <button class="btn btn-warning btn-sm"
                                                                    onclick="showResubmitForm(<?php echo (int)$form['id']; ?>,
                                                                                              '<?php echo htmlspecialchars($form['file_description'], ENT_QUOTES); ?>')">
                                                                <i class="fas fa-edit me-1"></i>Resubmit Document
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="badge bg-info">Resubmitted</span>
                                                        <?php endif; ?>
                                                        
                                                    <?php elseif ($form['status'] === 'approved'): ?>
                                                        <span class="badge bg-success">
                                                            <i class="fas fa-check me-1"></i>Approved
                                                        </span>
                                                        
                                                    <?php elseif ($form['status'] === 'rejected'): ?>
                                                        <span class="badge bg-danger">
                                                            <i class="fas fa-times me-1"></i>Rejected
                                                        </span>
                                                        
                                                    <?php elseif ($form['status'] === 'pending_review'): ?>
                                                        <span class="badge bg-secondary">
                                                            <i class="fas fa-clock me-1"></i>Under Review
                                                        </span>
                                                        
                                                    <?php elseif ($form['status'] === 'resubmitted'): ?>
                                                        <!-- Show which newer document replaced this one -->
                                                        <?php
                                                        $replacement_form = null;
                                                        foreach ($application_forms as $check_form) {
                                                            if ($check_form['resubmission_of'] == $form['id']) {
                                                                $replacement_form = $check_form;
                                                                break;
                                                            }
                                                        }
                                                        ?>
                                                        <span class="badge bg-secondary">
                                                            Superseded
                                                            <?php if ($replacement_form): ?>
                                                            <br><small>by <?php echo htmlspecialchars($replacement_form['original_filename']); ?></small>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Review Comments -->
                                            <?php if ($form['review_comments']): ?>
                                            <div class="mt-3 p-2 bg-white rounded border">
                                                <small class="text-muted d-block"><strong><i class="fas fa-comment me-1"></i>Reviewer Comments:</strong></small>
                                                <small><?php echo nl2br(htmlspecialchars($form['review_comments'])); ?></small>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <!-- Resubmission Chain Info -->
                                            <?php if ($form['resubmission_of']): ?>
                                            <div class="mt-2 p-2 bg-info bg-opacity-10 rounded border border-info">
                                                <small class="text-info d-block">
                                                    <strong><i class="fas fa-info-circle me-1"></i>Note:</strong> 
                                                    This is a resubmission of Document #<?php echo $form['resubmission_of']; ?>
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <!-- Initial Upload Form -->
                                <div id="uploadForm" class="resubmit-form" style="display: none;">
                                    <h6 class="mb-3"><i class="fas fa-upload me-2"></i>Upload Application Form</h6>
                                    <form method="POST" action="" enctype="multipart/form-data" id="documentUploadForm">
                                        <div class="mb-3">
                                            <label for="upload_description" class="form-label">Document Description *</label>
                                            <input type="text" class="form-control" id="upload_description" name="file_description" placeholder="e.g., ETEEAP Application Form, Resume, Transcript" required>
                                            <div class="form-text">Briefly describe what this document contains</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="upload_document" class="form-label">Choose File *</label>
                                            <input type="file" class="form-control" id="upload_document" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                                            
                                            <div class="file-drop-zone mt-2" onclick="triggerFileInput('upload_document')">
                                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                                <h6>Drop files here or click to browse</h6>
                                                <p class="text-muted mb-0 small">
                                                    Supported formats: PDF, JPG, PNG, DOC, DOCX<br>
                                                    Maximum file size: 10MB
                                                </p>
                                            </div>
                                            <div id="upload_file_name" class="mt-2 text-success" style="display: none;">
                                                <i class="fas fa-file me-1"></i>
                                                <span></span>
                                            </div>
                                        </div>

                                        <div class="d-flex gap-2">
                                            <button type="submit" name="upload_document" class="btn btn-primary">
                                                <i class="fas fa-upload me-2"></i>Upload Document
                                            </button>
                                            <button type="button" class="btn btn-secondary" onclick="hideUploadForm()">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Resubmission Form -->
                                <div id="resubmitForm" class="resubmit-form" style="display: none;">
                                    <h6 class="mb-3"><i class="fas fa-edit me-2"></i>Resubmit Document</h6>
                                    <form method="POST" action="" enctype="multipart/form-data" id="documentResubmitForm">
                                        <input type="hidden" id="original_form_id" name="original_form_id">
                                        
                                        <div class="mb-3">
                                            <label for="resubmit_description" class="form-label">Document Description *</label>
                                            <input type="text" class="form-control" id="resubmit_description" name="file_description" required>
                                            <div class="form-text">You can modify the description if needed</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="resubmit_document" class="form-label">Choose New File *</label>
                                            <input type="file" class="form-control" id="resubmit_document" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                                            
                                            <div class="file-drop-zone mt-2" onclick="triggerFileInput('resubmit_document')">
                                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                                <h6>Drop files here or click to browse</h6>
                                                <p class="text-muted mb-0 small">
                                                    Supported formats: PDF, JPG, PNG, DOC, DOCX<br>
                                                    Maximum file size: 10MB
                                                </p>
                                            </div>
                                            <div id="resubmit_file_name" class="mt-2 text-success" style="display: none;">
                                                <i class="fas fa-file me-1"></i>
                                                <span></span>
                                            </div>
                                        </div>

                                        <div class="d-flex gap-2">
                                            <button type="submit" name="resubmit_document" class="btn btn-warning">
                                                <i class="fas fa-upload me-2"></i>Resubmit Document
                                            </button>
                                            <button type="button" class="btn btn-secondary" onclick="hideResubmitForm()">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Profile Tab -->
                            <div class="tab-pane fade" id="profile" role="tabpanel">
                                <h5 class="mb-3">Edit Profile Information</h5>
                                
                                <form method="POST" action="">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="first_name" class="form-label">First Name *</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                                value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="last_name" class="form-label">Last Name *</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                                value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                                        </div>
                                        
                                        <div class="col-12">
                                            <label for="middle_name" class="form-label">Middle Name</label>
                                            <input type="text" class="form-control" id="middle_name" name="middle_name" 
                                                value="<?php echo htmlspecialchars($user['middle_name']); ?>">
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="email" class="form-label">Email Address</label>
                                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                                            <div class="form-text">Email cannot be changed</div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <label for="phone" class="form-label">Phone Number</label>
                                            <input type="tel" class="form-control" id="phone" name="phone" 
                                                value="<?php echo htmlspecialchars($user['phone']); ?>">
                                        </div>
                                        
                                        <div class="col-12">
                                            <label for="address" class="form-label">Address</label>
                                            <textarea class="form-control" id="address" name="address" rows="3"><?php echo htmlspecialchars($user['address']); ?></textarea>
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <button type="submit" name="update_profile" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i>Update Profile
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Applications Tab -->
                            <div class="tab-pane fade" id="applications" role="tabpanel">
                                <?php if ($user['application_form_status'] !== 'approved'): ?>
                                    <div class="access-restricted">
                                        <i class="fas fa-lock fa-3x mb-3"></i>
                                        <h5>Applications are available after approval</h5>
                                        <p class="mb-0">Once your application forms are approved, you'll manage your ETEEAP applications here.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h5 class="mb-0">My ETEEAP Applications</h5>
                                        <a href="upload.php" class="btn btn-primary btn-sm">
                                            <i class="fas fa-plus me-1"></i>New Application
                                        </a>
                                    </div>

                                    <?php if (empty($applications)): ?>
                                        <div class="text-center py-4">
                                            <i class="fas fa-graduation-cap fa-3x text-muted mb-3"></i>
                                            <h5>Ready to Start Your ETEEAP Journey?</h5>
                                            <p class="text-muted mb-4">Your application forms have been approved. You can now create your first ETEEAP application and upload supporting documents.</p>
                                            <a href="upload.php" class="btn btn-primary">
                                                <i class="fas fa-plus me-2"></i>Create First Application
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <!-- Applications Progress Table -->
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th>Program</th>
                                                        <th>Documents</th>
                                                        <th>Status</th>
                                                        <th>Progress</th>
                                                        <th>Score</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($applications_with_progress as $app): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo htmlspecialchars($app['program_name']); ?></div>
                                                            <small class="text-muted"><?php echo htmlspecialchars($app['program_code']); ?></small>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo $app['uploaded_documents']; ?> docs</span>
                                                            <?php if ($app['uploaded_documents'] < 3): ?>
                                                            <br><small class="text-warning">Need more docs</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $status_classes = [
                                                                'draft' => 'bg-secondary',
                                                                'submitted' => 'bg-info',
                                                                'under_review' => 'bg-warning',
                                                                'qualified' => 'bg-success',
                                                                'partially_qualified' => 'bg-warning',
                                                                'not_qualified' => 'bg-danger'
                                                            ];
                                                            ?>
                                                            <span class="badge <?php echo $status_classes[$app['application_status']] ?? 'bg-secondary'; ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $app['application_status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $completion = $app['total_criteria'] > 0 ? round(($app['evaluated_criteria'] / $app['total_criteria']) * 100) : 0;
                                                            ?>
                                                            <div class="progress" style="height: 8px;">
                                                                <div class="progress-bar" style="width: <?php echo $completion; ?>%"></div>
                                                            </div>
                                                            <small class="text-muted"><?php echo $app['evaluated_criteria']; ?>/<?php echo $app['total_criteria']; ?> criteria</small>
                                                        </td>
                                                        <td>
                                                            <?php if ($app['total_score'] > 0): ?>
                                                                <span class="fw-bold text-primary"><?php echo $app['total_score']; ?>%</span>
                                                                <?php if ($app['avg_score_percentage']): ?>
                                                                <br><small class="text-muted">Avg: <?php echo $app['avg_score_percentage']; ?>%</small>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">Pending</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                          <div class="btn-group" role="group">
    <a href="assessment.php?id=<?php echo $app['id']; ?>" 
       class="btn btn-sm btn-outline-primary">
        <i class="fas fa-eye me-1"></i>Details
    </a>
    <?php if ($app['application_status'] === 'draft'): ?>
        <a href="upload.php?id=<?php echo $app['id']; ?>" 
           class="btn btn-sm btn-outline-success">
            <i class="fas fa-upload me-1"></i>Documents
        </a>
    <?php endif; ?>
</div>

                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Function to mark notification as read and update badge
        function markNotificationRead(notificationId) {
            fetch(`?mark_read=${notificationId}`, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(response => {
                if (response.ok) {
                    // Remove the notification from unread list
                    const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
                    if (notificationElement) {
                        notificationElement.classList.remove('unread');
                        notificationElement.classList.add('read');
                        // Remove the mark read button
                        const markReadBtn = notificationElement.querySelector('.mark-read-btn');
                        if (markReadBtn) markReadBtn.remove();
                    }
                    
                    // Update badge in navbar and overview
                    updateNotificationBadge();
                }
            }).catch(error => {
                console.error('Error marking notification as read:', error);
            });
        }

        // Function to update notification badge count
        function updateNotificationBadge() {
            const unreadCount = document.querySelectorAll('.notification-item.unread').length;
            const badge = document.querySelector('#notification-badge');
            const overviewCount = document.querySelector('#unread-count');
            
            if (unreadCount === 0) {
                if (badge) badge.remove();
            } else {
                if (badge) {
                    badge.textContent = unreadCount;
                }
            }
            
            if (overviewCount) {
                overviewCount.textContent = unreadCount;
            }
        }

        // === File UI helpers ===
        function triggerFileInput(inputId) {
            const fileInput = document.getElementById(inputId);
            if (fileInput) fileInput.click();
        }

        function showUploadForm() {
            document.getElementById('uploadForm').style.display = 'block';
            document.getElementById('uploadForm').scrollIntoView({ behavior: 'smooth' });
        }

        function hideUploadForm() {
            document.getElementById('uploadForm').style.display = 'none';
            document.getElementById('documentUploadForm').reset();
            resetFileDropZone('upload_document', 'upload_file_name');
        }

        function showResubmitForm(formId, description) {
            const cleanDescription = description.replace(/'/g, "\\'").replace(/"/g, '\\"');
            document.getElementById('original_form_id').value = formId;
            document.getElementById('resubmit_description').value = cleanDescription;
            document.getElementById('resubmitForm').style.display = 'block';
            document.getElementById('resubmitForm').scrollIntoView({ behavior: 'smooth' });
        }

        function hideResubmitForm() {
            document.getElementById('resubmitForm').style.display = 'none';
            document.getElementById('documentResubmitForm').reset();
            resetFileDropZone('resubmit_document', 'resubmit_file_name');
        }

        function resetFileDropZone(inputId, fileNameId) {
            const fileInput = document.getElementById(inputId);
            const fileName = document.getElementById(fileNameId);
            const dropZone = fileInput?.parentElement?.querySelector('.file-drop-zone');
            if (fileName) fileName.style.display = 'none';
            if (dropZone) dropZone.classList.remove('file-selected');
            if (dropZone) {
                dropZone.innerHTML = `
                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                    <h6>Drop files here or click to browse</h6>
                    <p class="text-muted mb-0 small">Supported formats: PDF, JPG, PNG, DOC, DOCX<br>Maximum file size: 10MB</p>
                `;
            }
        }

        function handleFileSelection(input, fileNameId) {
            const fileName = document.getElementById(fileNameId);
            const dropZone = input.parentElement.querySelector('.file-drop-zone');
            if (input.files.length > 0) {
                const file = input.files[0];
                if (fileName) {
                    fileName.querySelector('span').textContent = file.name;
                    fileName.style.display = 'block';
                }
                if (dropZone) {
                    dropZone.classList.add('file-selected');
                    dropZone.innerHTML = `
                        <i class="fas fa-file fa-2x mb-2"></i>
                        <h6>File Selected: ${file.name}</h6>
                        <p class="text-muted mb-0 small">Click to choose a different file</p>
                    `;
                }
                validateFile(file, input);
            }
        }

        function validateFile(file, input) {
            const maxSize = 10 * 1024 * 1024; // 10MB
            const allowedTypes = [
                'application/pdf',
                'image/jpeg',
                'image/jpg',
                'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            let isValid = true;
            let msg = '';

            if (file.size > maxSize) {
                isValid = false; msg = 'File size must be less than 10MB';
            } else if (!allowedTypes.includes(file.type)) {
                isValid = false; msg = 'Only PDF, JPG, PNG, DOC, and DOCX files are allowed';
            }
            if (!isValid) {
                alert(msg);
                input.value = '';
                const fileNameId = input.id === 'upload_document' ? 'upload_file_name' : 'resubmit_file_name';
                resetFileDropZone(input.id, fileNameId);
            }
        }

        function setupDragAndDrop(inputId) {
            const fileInput = document.getElementById(inputId);
            const dropZone = fileInput?.parentElement?.querySelector('.file-drop-zone');
            if (!fileInput || !dropZone) return;

            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
                dropZone.addEventListener(evt, preventDefaults, false);
                document.body.addEventListener(evt, preventDefaults, false);
            });
            ['dragenter', 'dragover'].forEach(evt => {
                dropZone.addEventListener(evt, () => dropZone.classList.add('drag-over'), false);
            });
            ['dragleave', 'drop'].forEach(evt => {
                dropZone.addEventListener(evt, () => dropZone.classList.remove('drag-over'), false);
            });
            dropZone.addEventListener('drop', function(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            }, false);

            function preventDefaults(e) { e.preventDefault(); e.stopPropagation(); }
        }

        // View document
        function viewDocument(filename, description) {
            const documentUrl = '../uploads/application_forms/' + encodeURIComponent(filename);
            window.open(documentUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
        }

        // DOM ready setup
        document.addEventListener('DOMContentLoaded', function() {
            // Wire file inputs
            const uploadInput = document.getElementById('upload_document');
            if (uploadInput) uploadInput.addEventListener('change', function(){ handleFileSelection(this, 'upload_file_name'); });

            const resubmitInput = document.getElementById('resubmit_document');
            if (resubmitInput) resubmitInput.addEventListener('change', function(){ handleFileSelection(this, 'resubmit_file_name'); });

            // Drag & drop
            setupDragAndDrop('upload_document');
            setupDragAndDrop('resubmit_document');

            // Tab persistence
            const activeTab = localStorage.getItem('activeProfileTab');
            if (activeTab) {
                const tabElement = document.querySelector(`#${activeTab}-tab`);
                if (tabElement) new bootstrap.Tab(tabElement).show();
            }
            document.querySelectorAll('[data-bs-toggle="pill"]').forEach(tab => {
                tab.addEventListener('shown.bs.tab', e => {
                    const tabId = e.target.id.replace('-tab', '');
                    localStorage.setItem('activeProfileTab', tabId);
                });
            });

            // Auto-switch to forms tab if there are revision needed documents
            <?php if ($has_revision_needed && !isset($_POST)): ?>
            const formsTab = document.querySelector('#forms-tab');
            if (formsTab) {
                new bootstrap.Tab(formsTab).show();
                setTimeout(() => {
                    const revisionDocs = document.querySelectorAll('.document-needs-revision');
                    revisionDocs.forEach(doc => { doc.style.animation = 'pulse 2s ease-in-out 3'; });
                }, 500);
            }
            <?php endif; ?>

            // Initialize notification badge update
            updateNotificationBadge();

            // Notification hover effect
            document.querySelectorAll('.notification-item').forEach(notification => {
                notification.addEventListener('mouseenter', function(){ this.style.transform = 'translateX(5px)'; });
                notification.addEventListener('mouseleave', function(){ this.style.transform = 'translateX(0)'; });
            });
        });

        // Form validation before submit
        document.getElementById('documentUploadForm')?.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('upload_document');
            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault(); alert('Please select a file to upload'); return false;
            }
        });

        document.getElementById('documentResubmitForm')?.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('resubmit_document');
            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault(); alert('Please select a file to upload'); return false;
            }
            if (!confirm('Are you sure you want to resubmit this document? The new file will replace your previous submission.')) {
                e.preventDefault(); return false;
            }
        });

        // Auto-hide success messages after 5s
        setTimeout(function() {
            const successAlerts = document.querySelectorAll('.alert-success');
            successAlerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Pulse animation CSS
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
                70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
                100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>