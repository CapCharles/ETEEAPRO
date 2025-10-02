<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'evaluator'])) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Handle individual document actions
if ($_POST && isset($_POST['document_action'])) {
    $action = $_POST['document_action'];
    $document_id = (int)$_POST['document_id'];
    $comment = trim($_POST['document_comment'] ?? '');
    $applicant_user_id = (int)$_POST['applicant_user_id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get document info first
        $stmt = $pdo->prepare("SELECT * FROM application_forms WHERE id = ?");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch();
        
        if (!$document) {
            throw new Exception('Document not found');
        }
        
        if ($action === 'approve_document') {
            $stmt = $pdo->prepare("
                UPDATE application_forms 
                SET status = 'approved', review_comments = ?, reviewed_by = ?, review_date = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$comment, $user_id, $document_id]);
            
            $success_message = "Document approved successfully!";
            $notification_type = 'success';
            $notification_title = 'Document Approved';
            $notification_message = "Your document '{$document['original_filename']}' has been approved.";
            
        } elseif ($action === 'reject_document') {
            if (empty($comment)) {
                $errors[] = "Comment is required when rejecting a document";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE application_forms 
                    SET status = 'rejected', review_comments = ?, reviewed_by = ?, review_date = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$comment, $user_id, $document_id]);
                
                $success_message = "Document rejected with feedback!";
                $notification_type = 'error';
                $notification_title = 'Document Rejected';
                $notification_message = "Your document '{$document['original_filename']}' has been rejected.";
            }
            
        
     } elseif ($action === 'request_revision') {
            if (empty($comment)) {
                $errors[] = "Comment is required when requesting revision";
            } else {
                $stmt = $pdo->prepare("
                    UPDATE application_forms 
                    SET status = 'needs_revision', review_comments = ?, reviewed_by = ?, review_date = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$comment, $user_id, $document_id]);
                
                $success_message = "Revision requested with feedback!";
                $notification_type = 'warning';
                $notification_title = 'Document Needs Revision';
                $notification_message = "Your document '{$document['original_filename']}' needs revision.";
            }
        }
        
        if (empty($errors)) {
            // Create notification for the candidate
            if ($comment) {
                $notification_message .= "\n\nReviewer Comments: " . $comment;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$applicant_user_id, $notification_title, $notification_message, $notification_type]);
            
            // Log activity
            logActivity($pdo, "document_reviewed", $user_id, "application_forms", $document_id, null, [
                'action' => $action,
                'comment' => $comment,
                'document_name' => $document['original_filename']
            ]);
            
            $pdo->commit();
            recomputeApplicationRowStatus($pdo, $applicant_user_id);
            // Redirect with success message to refresh the page
            redirectWithMessage('application-reviews.php', $success_message, 'success');
        } else {
            $pdo->rollBack();
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Failed to process document review: " . $e->getMessage();
    }
}

// Debug: Check actual status values in database
try {
    $debug_stmt = $pdo->query("
        SELECT DISTINCT u.application_form_status, COUNT(*) as count
        FROM users u
        INNER JOIN application_forms af ON u.id = af.user_id
        GROUP BY u.application_form_status
    ");
    error_log("=== Application Status Distribution ===");
    while ($row = $debug_stmt->fetch()) {
        error_log("Status: " . ($row['application_form_status'] ?? 'NULL') . " = " . $row['count']);
    }
} catch (PDOException $e) {
    error_log("Debug query failed: " . $e->getMessage());
}

// Handle bulk actions
if ($_POST && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selected_users = $_POST['selected_users'] ?? [];
    
    if (empty($selected_users)) {
        $errors[] = "Please select at least one applicant to process.";
    } else {
        try {
            $pdo->beginTransaction();
            
            $processed_count = 0;
            
            foreach ($selected_users as $applicant_user_id) {
                if ($action === 'bulk_approve') {
                    // Update all pending forms for this user
                    $stmt = $pdo->prepare("
                        UPDATE application_forms 
                        SET status = 'approved', reviewed_by = ?, review_date = NOW() 
                        WHERE user_id = ? AND status = 'pending_review'
                    ");
                    $stmt->execute([$user_id, $applicant_user_id]);
                    
                    // Update user account status
                    $stmt = $pdo->prepare("
                        UPDATE users 
                        SET application_form_status = 'approved', status = 'active',
                            approved_by = ?, approval_date = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$user_id, $applicant_user_id]);
                    
                    // Create notification
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications (user_id, title, message, type)
                        VALUES (?, ?, ?, 'success')
                    ");
                    $stmt->execute([
                        $applicant_user_id,
                        'Application Approved!',
                        'Your ETEEAP application forms have been approved. You can now log in and start your assessment journey.'
                    ]);
                    
                    $processed_count++;
                }
            }
            
            $pdo->commit();
            
            if ($processed_count > 0) {
                $success_message = "Successfully processed {$processed_count} applicants.";
                
                // Log bulk activity
                logActivity($pdo, "bulk_applications_approved", $user_id, "application_forms", null, null, [
                    'count' => $processed_count,
                    'user_ids' => $selected_users
                ]);
            }
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Failed to process bulk action. Please try again.";
        }
    }
}

// Handle individual actions
if ($_POST) {
    if (isset($_POST['approve_applicant'])) {
        $applicant_user_id = $_POST['user_id'];
        $assigned_program_id = (int)($_POST['assigned_program_id'] ?? 0);
        $reroute_reason = trim($_POST['reroute_reason'] ?? '');
        
        try {
            $pdo->beginTransaction();

            $applicant_user_id = (int)$_POST['user_id'];
            $assigned_program_id = isset($_POST['assigned_program_id']) ? (int)$_POST['assigned_program_id'] : 0;
            $reroute_reason = trim($_POST['reroute_reason'] ?? '');

            // Get preferred (for comparison)
            $prefId = null;
            $pref = $pdo->prepare("SELECT preferred_program_id FROM user_program_preferences WHERE user_id=?");
            $pref->execute([$applicant_user_id]);
            if ($row = $pref->fetch(PDO::FETCH_ASSOC)) {
                $prefId = (int)$row['preferred_program_id'];
            }
            // Default to preferred if nothing posted
            if (!$assigned_program_id && $prefId) {
                $assigned_program_id = $prefId;
            }

            // Validate chosen program
            $pg = $pdo->prepare("SELECT id, program_code, program_name FROM programs WHERE id=? AND status='active'");
            $pg->execute([$assigned_program_id]);
            $assignedProgram = $pg->fetch(PDO::FETCH_ASSOC);
            if (!$assignedProgram) {
                throw new Exception('Invalid program assignment.');
            }

            // (1) approve forms
            $stmt = $pdo->prepare("
                UPDATE application_forms 
                SET status='approved', reviewed_by=?, review_date=NOW()
                WHERE user_id=? AND status='pending_review'
            ");
            $stmt->execute([$user_id, $applicant_user_id]);

            // (2) activate user
            $stmt = $pdo->prepare("
                UPDATE users 
                SET application_form_status='approved', status='active',
                    approved_by=?, approval_date=NOW()
                WHERE id=?
            ");
            $stmt->execute([$user_id, $applicant_user_id]);

            // (3) ensure an application record exists (draft)
            $stmt = $pdo->prepare("
              SELECT id, program_id, application_status 
              FROM applications WHERE user_id=? ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$applicant_user_id]);
            $app = $stmt->fetch();

            if ($app) {
                // update program kung iba
                if ($assigned_program_id && (int)$app['program_id'] !== $assigned_program_id) {
                    $upd = $pdo->prepare("UPDATE applications SET program_id=? WHERE id=?");
                    $upd->execute([$assigned_program_id, $app['id']]);
                }
                $application_id = (int)$app['id'];
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO applications (user_id, program_id, application_status, created_at)
                    VALUES (?, ?, 'draft', NOW())
                ");
                $ins->execute([$applicant_user_id, $assigned_program_id ?: null]);
                $application_id = (int)$pdo->lastInsertId();
            }

            // (4) seed application_requirements once (if empty)
            $check = $pdo->prepare("SELECT COUNT(*) c FROM application_requirements ar 
                                    JOIN applications a ON a.id=ar.application_id
                                    WHERE ar.application_id=?");
            $check->execute([$application_id]);
            if ((int)$check->fetch()['c'] === 0 && $assigned_program_id) {
                $reqs = $pdo->prepare("
                    SELECT id, code, name, is_required, max_files, allowed_types
                    FROM program_requirements WHERE program_id=? ORDER BY id
                ");
                $reqs->execute([$assigned_program_id]);
                $rows = $reqs->fetchAll();
                if ($rows) {
                    $insReq = $pdo->prepare("
                        INSERT INTO application_requirements
                        (application_id, requirement_id, code, name, is_required, max_files, allowed_types)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($rows as $r) {
                        $insReq->execute([
                            $application_id,
                            $r['id'], $r['code'], $r['name'],
                            (int)$r['is_required'], (int)$r['max_files'], $r['allowed_types']
                        ]);
                    }
                }
            }
            // 5) Internal notification
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (?, 'Application Approved', CONCAT('Assigned Program: ', ?, ' - ', ?), 'success')
            ");
            $stmt->execute([
                $applicant_user_id,
                $assignedProgram['program_code'],
                $assignedProgram['program_name']
            ]);

            $pdo->commit();
            redirectWithMessage('application-reviews.php','Applicant approved. User is active and can start uploading.','success');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Failed to approve applicant: " . $e->getMessage();
        }
    }
    
elseif (isset($_POST['reject_applicant'])) {
    // Force output to see if this code runs
    error_log("========== REJECT APPLICANT TRIGGERED ==========");
    error_log("POST data: " . print_r($_POST, true));
    
    $applicant_user_id = (int)$_POST['user_id'];
    $rejection_reason = trim($_POST['rejection_reason']);
    
    error_log("User ID: " . $applicant_user_id);
    error_log("Rejection Reason: " . $rejection_reason);
    error_log("Admin User ID: " . $user_id);
    
    if (empty($rejection_reason)) {
        error_log("ERROR: Rejection reason is empty!");
        $errors[] = "Rejection reason is required";
    } else {
        error_log("Starting database transaction...");
        
        try {
            $pdo->beginTransaction();
            error_log("Transaction started");
            
            // Update ALL forms for this user to rejected
            $stmt = $pdo->prepare("
                UPDATE application_forms 
                SET status = 'rejected', 
                    review_comments = ?, 
                    reviewed_by = ?, 
                    review_date = NOW() 
                WHERE user_id = ?
            ");
            $stmt->execute([$rejection_reason, $user_id, $applicant_user_id]);
            $forms_affected = $stmt->rowCount();
            error_log("Application forms updated: " . $forms_affected . " rows");
            
            // Update user status to rejected
            $stmt = $pdo->prepare("
                UPDATE users 
                SET application_form_status = 'rejected', 
                    rejection_reason = ?,
                    status = 'inactive'
                WHERE id = ?
            ");
            $stmt->execute([$rejection_reason, $applicant_user_id]);
            $users_affected = $stmt->rowCount();
            error_log("Users table updated: " . $users_affected . " rows");
            
            // Create notification
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                VALUES (?, ?, ?, 'error', NOW())
            ");
            $stmt->execute([
                $applicant_user_id,
                'Application Forms Rejected',
                'Your ETEEAP application has been rejected. Reason: ' . $rejection_reason
            ]);
            error_log("Notification created");
            
            // Log activity
            try {
                logActivity($pdo, "application_forms_rejected", $user_id, "users", $applicant_user_id, null, [
                    'rejection_reason' => $rejection_reason
                ]);
                error_log("Activity logged");
            } catch (Exception $e) {
                error_log("Activity log failed: " . $e->getMessage());
            }
            
            $pdo->commit();
            error_log("Transaction committed successfully!");
            error_log("========== REJECTION COMPLETE ==========");
            
            // Redirect to the rejected tab
            $_SESSION['flash_message'] = 'Applicant rejected successfully.';
            $_SESSION['flash_type'] = 'success';
            
            header('Location: application-reviews.php?filter_status=rejected');
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("========== REJECTION FAILED ==========");
            error_log("ERROR: " . $e->getMessage());
            error_log("Stack: " . $e->getTraceAsString());
            $errors[] = "Failed to reject applicant: " . $e->getMessage();
        }
    }
}
}

// Load all active programs for dropdowns
$all_programs = [];
try {
    $stmt = $pdo->query("SELECT id, program_code, program_name FROM programs WHERE status='active' ORDER BY program_name");
    $all_programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_programs = [];
}

// UPDATED: Get grouped application forms by user with review information - Show all documents
$status_filter = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'pending';

$where_conditions = ["1=1"];
if ($status_filter === 'pending') {
    // Show both NULL/pending AND users who haven't been fully approved/rejected yet
    $where_conditions[] = "(u.application_form_status IS NULL OR u.application_form_status = 'pending' OR u.application_form_status NOT IN ('approved', 'rejected'))";
} elseif ($status_filter === 'approved') {
    $where_conditions[] = "u.application_form_status = 'approved'";
} elseif ($status_filter === 'rejected') {
    $where_conditions[] = "u.application_form_status = 'rejected'";
}
// 'all' shows everything - no additional condition

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

$grouped_applicants = [];
try {
    $stmt = $pdo->query("
        SELECT 
            u.id as user_id,
            u.first_name, 
            u.last_name, 
            u.email, 
            u.phone, 
            u.created_at as registration_date,
            u.application_form_status,
            upp.preferred_program_id,
            pp.program_code AS preferred_code,
            pp.program_name AS preferred_name,
            COUNT(af.id) as total_forms,
            GROUP_CONCAT(
                CONCAT(af.id, '|', af.original_filename, '|', af.file_size, '|', af.file_path, '|', 
                       COALESCE(af.file_description, ''), '|', af.status, '|', 
                       COALESCE(af.review_comments, ''), '|', COALESCE(u2.first_name, ''), ' ', COALESCE(u2.last_name, ''), '|',
                       COALESCE(af.review_date, ''))
                SEPARATOR ';;'
            ) as forms_data,
            MIN(af.upload_date) as first_upload,
            MAX(af.upload_date) as last_upload
        FROM users u
        INNER JOIN application_forms af ON u.id = af.user_id
        LEFT JOIN user_program_preferences upp ON upp.user_id = u.id
        LEFT JOIN programs pp ON pp.id = upp.preferred_program_id
        LEFT JOIN users u2 ON af.reviewed_by = u2.id
        $where_clause
        GROUP BY u.id, u.first_name, u.last_name, u.email, u.phone, u.created_at, u.application_form_status,
                 upp.preferred_program_id, pp.program_code, pp.program_name
        ORDER BY 
            CASE 
                WHEN u.application_form_status = 'pending' OR u.application_form_status IS NULL THEN 1
                WHEN u.application_form_status = 'approved' THEN 2
                WHEN u.application_form_status = 'rejected' THEN 3
                ELSE 4
            END,
            u.created_at DESC
    ");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Process forms data...
        $forms = [];
        if (!empty($row['forms_data'])) {
            foreach (explode(';;', $row['forms_data']) as $form_string) {
                $form_parts = explode('|', $form_string);
                if (count($form_parts) >= 4) {
                    $forms[] = [
                        'id' => $form_parts[0],
                        'filename' => $form_parts[1],
                        'size' => $form_parts[2],
                        'path' => $form_parts[3],
                        'description' => $form_parts[4] ?? '',
                        'status' => $form_parts[5] ?? 'pending_review',
                        'review_comments' => $form_parts[6] ?? '',
                        'reviewer_name' => trim($form_parts[7] ?? ''),
                        'review_date' => $form_parts[8] ?? ''
                    ];
                }
            }
        }
        $row['forms'] = $forms;
        $grouped_applicants[] = $row;
    }
} catch (PDOException $e) {
    $grouped_applicants = [];
    $errors[] = "Failed to load applications: " . $e->getMessage();
}

// UPDATED: Get statistics to include needs_revision
$stats = [];
try {
    // Pending = users without approved/rejected application_form_status
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT u.id) as total 
        FROM users u
        INNER JOIN application_forms af ON u.id = af.user_id
        WHERE (u.application_form_status IS NULL OR u.application_form_status = 'pending' OR u.application_form_status NOT IN ('approved', 'rejected'))
    ");
    $stats['pending'] = $stmt->fetch()['total'];
    
    // Approved users
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT u.id) as total 
        FROM users u
        WHERE u.application_form_status = 'approved'
    ");
    $stats['approved'] = $stmt->fetch()['total'];
    
    // Rejected users
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT u.id) as total 
        FROM users u
        WHERE u.application_form_status = 'rejected'
    ");
    $stats['rejected'] = $stmt->fetch()['total'];
    
    // Users with documents needing revision (but not yet approved/rejected overall)
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT u.id) as total 
        FROM users u
        INNER JOIN application_forms af ON u.id = af.user_id
        WHERE af.status = 'needs_revision'
        AND (u.application_form_status IS NULL OR u.application_form_status NOT IN ('approved', 'rejected'))
    ");
    $stats['revision'] = $stmt->fetch()['total'];
    
    // Total users with any documents
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as total FROM application_forms");
    $stats['total'] = $stmt->fetch()['total'];
} catch (PDOException $e) {
    $stats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'revision' => 0, 'total' => 0];
}

// Check for flash messages
$flash = getFlashMessage();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success_message = $flash['message'];
    } else {
        $errors[] = $flash['message'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Reviews - ETEEAP Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            margin: 0; 
            padding-top: 0 !important;
        }
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 0;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            padding: 1rem 1.5rem;
            border-radius: 0;
            transition: all 0.3s ease;
        }
        .sidebar .nav-link:hover {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.2);
        }
        .table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border: none;
            text-align: center;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .applicant-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        .urgency-high { color: #dc3545; }
        .urgency-medium { color: #ffc107; }
        .urgency-low { color: #6c757d; }
        
        /* Document Status Badges */
        .forms-badge {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 12px;
            margin: 2px;
            display: inline-block;
            position: relative;
        }
        .forms-badge.status-approved { 
            border-color: #28a745; 
            background: #28a745; 
            color: white;
            font-weight: 600;
        }
        .forms-badge.status-rejected { 
            border-color: #dc3545; 
            background: #dc3545; 
            color: white;
            font-weight: 600;
        }
        .forms-badge.status-needs_revision { 
            border-color: #ffc107; 
            background: #ffc107; 
            color: #000;
            font-weight: 600;
        }
        .forms-badge.status-pending_review { 
            border-color: #6c757d; 
            background: rgba(108, 117, 125, 0.1); 
            color: #6c757d;
        }
        
        .bulk-actions-bar {
            background: #e9ecef;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: none;
        }
        .bulk-actions-bar.show {
            display: block;
        }
        
        /* Document Review Modal Styles */
        .document-review-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid #dee2e6;
            transition: all 0.3s ease;
        }
        .document-review-card.status-approved {
            border-left-color: #28a745;
            background: rgba(40, 167, 69, 0.05);
        }
        .document-review-card.status-rejected {
            border-left-color: #dc3545;
            background: rgba(220, 53, 69, 0.05);
        }
        .document-review-card.status-needs_revision {
            border-left-color: #ffc107;
            background: rgba(255, 193, 7, 0.05);
        }
        .document-review-card.status-pending_review {
            border-left-color: #6c757d;
            background: rgba(108, 117, 125, 0.05);
        }
        
        .status-indicator {
            position: absolute;
            top: -2px;
            right: -2px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .status-indicator.approved { background: #28a745; }
        .status-indicator.rejected { background: #dc3545; }
        .status-indicator.needs_revision { background: #ffc107; }
        .status-indicator.pending_review { background: #6c757d; }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar">
                <div class="p-3">
                    <h4 class="text-white mb-4">
                        <i class="fas fa-graduation-cap me-2"></i>
                        ETEEAP Admin
                    </h4>
                    
                    <nav class="nav flex-column">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-2"></i>
                            Dashboard
                        </a>
                        <a class="nav-link active" href="application-reviews.php">
                            <i class="fas fa-file-signature me-2"></i>
                            Application Reviews
                            <?php if ($stats['pending'] > 0): ?>
                            <span class="badge bg-warning ms-2"><?php echo $stats['pending']; ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="nav-link" href="evaluate.php">
                            <i class="fas fa-clipboard-check me-2"></i>
                            Evaluate Applications
                        </a>
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar me-2"></i>
                            Reports
                        </a>
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users me-2"></i>
                            Manage Users
                        </a>
                        <a class="nav-link" href="programs.php">
                            <i class="fas fa-graduation-cap me-2"></i>
                            Manage Programs
                        </a>
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog me-2"></i>
                            Settings
                        </a>
                    </nav>
                </div>
                
                <div class="mt-auto p-3">
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" 
                           id="dropdownUser" data-bs-toggle="dropdown">
                            <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" 
                                 style="width: 32px; height: 32px;">
                                <i class="fas fa-user text-dark"></i>
                            </div>
                            <span class="small"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark text-small shadow">
                            <li><a class="dropdown-item" href="../candidates/profile.php">Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../auth/logout.php">Sign out</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2 class="mb-1">Application Form Reviews</h2>
                            <p class="text-muted mb-0">Review and approve candidate application forms with document-level controls</p>
                        </div>
                        <div class="d-flex gap-2">
                            <span class="badge bg-warning fs-6">
                                <i class="fas fa-clock me-1"></i>
                                <?php echo $stats['pending']; ?> Pending
                            </span>
                            <span class="badge bg-info fs-6">
                                <i class="fas fa-edit me-1"></i>
                                <?php echo $stats['revision']; ?> Need Revision
                            </span>
                            <button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
                                <i class="fas fa-sync me-1"></i>Refresh
                            </button>
                        </div>
                    </div>

                    <ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $status_filter === 'pending' ? 'active' : ''; ?>" 
           href="?filter_status=pending">
            <i class="fas fa-clock me-1"></i>Pending 
            <span class="badge bg-warning"><?php echo $stats['pending']; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $status_filter === 'approved' ? 'active' : ''; ?>" 
           href="?filter_status=approved">
            <i class="fas fa-check-circle me-1"></i>Approved 
            <span class="badge bg-success"><?php echo $stats['approved']; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $status_filter === 'rejected' ? 'active' : ''; ?>" 
           href="?filter_status=rejected">
            <i class="fas fa-times-circle me-1"></i>Rejected 
            <span class="badge bg-danger"><?php echo $stats['rejected']; ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $status_filter === 'all' ? 'active' : ''; ?>" 
           href="?filter_status=all">
            <i class="fas fa-list me-1"></i>All 
            <span class="badge bg-secondary"><?php echo $stats['total']; ?></span>
        </a>
    </li>
</ul>

                    <!-- Messages -->
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($success_message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-file-alt fa-2x text-warning mb-2"></i>
                                <div class="stat-number text-warning"><?php echo $stats['pending']; ?></div>
                                <div class="text-muted">Pending Review</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-edit fa-2x text-info mb-2"></i>
                                <div class="stat-number text-info"><?php echo $stats['revision']; ?></div>
                                <div class="text-muted">Need Revision</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                <div class="stat-number text-success"><?php echo $stats['approved']; ?></div>
                                <div class="text-muted">Approved</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <i class="fas fa-users fa-2x text-primary mb-2"></i>
                                <div class="stat-number text-primary"><?php echo $stats['total']; ?></div>
                                <div class="text-muted">Total Users</div>
                            </div>
                        </div>
                    </div>

                    <!-- Bulk Actions Bar -->
                    <?php if (!empty($grouped_applicants)): ?>
                    <div class="bulk-actions-bar" id="bulkActionsBar">
                        <form method="POST" action="" id="bulkForm">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <label class="form-check-label fw-bold">
                                        <input type="checkbox" class="form-check-input me-2" id="selectAll">
                                        <span id="selectedCount">0</span> applicants selected
                                    </label>
                                </div>
                                <div class="d-flex gap-2">
                                    <input type="hidden" name="selected_users" id="bulkSelectedIds" value="">
                                    <button type="submit" name="bulk_action" value="bulk_approve" class="btn btn-success btn-sm" 
                                            onclick="return confirm('Are you sure you want to approve all selected applicants?')">
                                        <i class="fas fa-check me-1"></i>Approve Selected
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">
                                        Clear Selection
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Applicants Table -->
                    <?php if (empty($grouped_applicants)): ?>
                    <div class="table-container p-5 text-center">
                        <i class="fas fa-inbox fa-4x text-muted mb-4"></i>
                        <h4>No Pending Applications</h4>
                        <p class="text-muted">All application forms have been reviewed.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" class="form-check-input" id="selectAllTable" onchange="toggleSelectAll(this)">
                                        </th>
                                        <th>Applicant</th>
                                        <th>Contact</th>
                                        <th>Program Assignment</th>
                                        <th>Application Forms</th>
                                        <th>Registered</th>
                                        <th>Waiting</th>
                                        <th width="200">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grouped_applicants as $applicant): ?>
                                    <?php 
                                    $days_waiting = ceil((time() - strtotime($applicant['first_upload'])) / (60 * 60 * 24));
                                    $urgency_class = $days_waiting > 7 ? 'urgency-high' : ($days_waiting > 3 ? 'urgency-medium' : 'urgency-low');
                                    
                                    // Count documents needing revision
                                    $revision_count = 0;
                                    $approved_count = 0;
                                    $total_count = count($applicant['forms']);
                                    
                                    foreach ($applicant['forms'] as $form) {
                                        if ($form['status'] === 'needs_revision') {
                                            $revision_count++;
                                        }
                                        if ($form['status'] === 'approved') {
                                            $approved_count++;
                                        }
                                    }
                                    
                                    // Check if eligible for overall approval (at least 3 approved documents)
                                    $can_approve_all = ($approved_count >= 3);
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="form-check-input applicant-checkbox" 
                                                   value="<?php echo $applicant['user_id']; ?>" 
                                                   onchange="updateSelection()">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="applicant-photo me-3">
                                                    <?php echo strtoupper(substr($applicant['first_name'], 0, 1) . substr($applicant['last_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold">
                                                        <?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?>
                                                    </div>
                                                    <small class="text-muted">ID: <?php echo $applicant['user_id']; ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <small class="d-block">
                                                    <i class="fas fa-envelope me-1"></i>
                                                    <?php echo htmlspecialchars($applicant['email']); ?>
                                                </small>
                                                <?php if ($applicant['phone']): ?>
                                                <small class="text-muted d-block">
                                                    <i class="fas fa-phone me-1"></i>
                                                    <?php echo htmlspecialchars($applicant['phone']); ?>
                                                </small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small text-muted mb-1">
                                                <?php if ($applicant['preferred_code']): ?>
                                                <div class="mb-1">
                                                    <strong>Preferred:</strong> 
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($applicant['preferred_code']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <label class="form-label small mb-1">Assign Program</label>
                                                <select class="form-select form-select-sm"
                                                        id="assigned_program_<?php echo $applicant['user_id']; ?>"
                                                        data-pref-id="<?php echo (int)($applicant['preferred_program_id'] ?? 0); ?>">
                                                    <?php foreach ($all_programs as $pg): ?>
                                                    <option value="<?php echo $pg['id']; ?>"
                                                      <?php 
                                                        $defaultId = (int)($applicant['preferred_program_id'] ?? 0);
                                                        echo ($pg['id'] == $defaultId ? 'selected' : '');
                                                      ?>>
                                                      <?php echo htmlspecialchars($pg['program_code'].' - '.$pg['program_name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <textarea class="form-control form-control-sm mt-2 d-none"
                                                          id="reroute_reason_<?php echo $applicant['user_id']; ?>"
                                                          placeholder="Reason (required if different from preferred)"></textarea>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="position-relative">
                                                <?php if ($revision_count > 0): ?>
                                                <span class="notification-badge"><?php echo $revision_count; ?></span>
                                                <?php endif; ?>
                                                
                                                <div class="d-flex flex-wrap">
                                                    <?php foreach ($applicant['forms'] as $form): ?>
                                                    <?php
                                                    $filename = $form['filename'];
                                                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                                                    $iconMap = [
                                                        'pdf'  => 'fa-file-pdf text-danger',
                                                        'doc'  => 'fa-file-word text-primary',
                                                        'docx' => 'fa-file-word text-primary',
                                                        'xls'  => 'fa-file-excel text-success',
                                                        'xlsx' => 'fa-file-excel text-success',
                                                        'png'  => 'fa-file-image text-info',
                                                        'jpg'  => 'fa-file-image text-info',
                                                        'jpeg' => 'fa-file-image text-info',
                                                    ];
                                                    $iconClass = $iconMap[$ext] ?? 'fa-file text-secondary';
                                                    $short = strlen($filename) > 20 ? substr($filename, 0, 20) . '...' : $filename;
                                                    
                                                    // Special handling for rejected applicants - all forms show as rejected
                                                    // $displayStatus = $form['status'];
                                                    // if ($applicant['application_form_status'] === 'rejected') {
                                                    //     $displayStatus = 'rejected';
                                                    // }

                                                    $displayStatus = $form['status']; // doc’s own status lang ang basehan

                                                    ?>
                                                    <span class="forms-badge status-<?php echo $displayStatus; ?>" 
                                                          title="<?php echo ucfirst(str_replace('_', ' ', $displayStatus)); ?> - <?php echo htmlspecialchars($filename); ?>">
                                                        <i class="fas <?php echo $iconClass; ?> me-1"></i>
                                                        <?php echo htmlspecialchars($short); ?>
                                                        <?php if ($form['status'] === 'approved'): ?>
                                                        <i class="fas fa-check-circle ms-1" style="font-size: 10px;"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                    <?php endforeach; ?>
                                                </div>
                                                
                                                <!-- Application Progress Indicator -->
                                                <div class="mt-2">
                                                    <?php if ($applicant['application_form_status'] === 'approved'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check me-1"></i>Application Approved
                                                    </span>
                                                    <?php elseif ($applicant['application_form_status'] === 'rejected'): ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-times me-1"></i>Application Rejected
                                                    </span>
                                                    <?php elseif ($revision_count > 0): ?>
                                                    <span class="badge bg-warning">
                                                        <i class="fas fa-edit me-1"></i>Needs Revision
                                                    </span>
                                                    <?php else: ?>
                                                    <!-- Show approval progress but no status change -->
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-info small">
                                                            <i class="fas fa-check me-1"></i><?php echo $approved_count; ?>/3 Approved
                                                        </span>
                                                        <?php if (!$can_approve_all): ?>
                                                        <small class="text-muted">(Need <?php echo 3 - $approved_count; ?> more)</small>
                                                        <?php else: ?>
                                                        <small class="text-success">Ready for approval!</small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <small><?php echo date('M j, Y', strtotime($applicant['registration_date'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="<?php echo $urgency_class; ?>">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo $days_waiting; ?> day<?php echo $days_waiting !== 1 ? 's' : ''; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($applicant['application_form_status'] === 'approved'): ?>
                                            <!-- Already approved applicant -->
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button class="btn btn-sm btn-outline-info" 
                                                        onclick="viewDocumentReview(<?php echo htmlspecialchars(json_encode($applicant['forms'])); ?>, 
                                                                                   '<?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?>', 
                                                                                   <?php echo $applicant['user_id']; ?>)"
                                                        data-bs-toggle="tooltip" title="View Approved Documents">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <span class="btn btn-sm btn-success disabled">
                                                    <i class="fas fa-check me-1"></i>Approved
                                                </span>
                                            </div>
                                            
                                            <?php elseif ($applicant['application_form_status'] === 'rejected'): ?>
                                            <!-- Already rejected applicant -->
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button class="btn btn-sm btn-outline-info" 
                                                        onclick="viewDocumentReview(<?php echo htmlspecialchars(json_encode($applicant['forms'])); ?>, 
                                                                                   '<?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?>', 
                                                                                   <?php echo $applicant['user_id']; ?>)"
                                                        data-bs-toggle="tooltip" title="View Rejected Documents">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <span class="btn btn-sm btn-danger disabled">
                                                    <i class="fas fa-times me-1"></i>Rejected
                                                </span>
                                            </div>
                                            
                                            <?php else: ?>
                                            <!-- Pending applicant - conditional actions based on approval count -->
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button class="btn btn-sm btn-outline-info" 
                                                        onclick="viewDocumentReview(<?php echo htmlspecialchars(json_encode($applicant['forms'])); ?>, 
                                                                                   '<?php echo htmlspecialchars($applicant['first_name'] . ' ' . $applicant['last_name']); ?>', 
                                                                                   <?php echo $applicant['user_id']; ?>)"
                                                        data-bs-toggle="tooltip" title="Review Documents">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
       <button type="button" class="btn btn-danger" 
        onclick="showRejectModal(<?php echo $applicant['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($applicant['first_name'] . ' ' . $applicant['last_name'])); ?>')"
        data-bs-toggle="tooltip" title="Reject Application">
    <i class="fas fa-times"></i>
</button>

                                                <?php if ($can_approve_all): ?>
                                                <button type="button" class="btn btn-success" 
                                                        onclick="approveApplicant(<?php echo $applicant['user_id']; ?>)"
                                                        data-bs-toggle="tooltip" title="Approve All Forms (<?php echo $approved_count; ?>/3 minimum met)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <?php else: ?>
                                                <button type="button" class="btn btn-secondary disabled" 
                                                        data-bs-toggle="tooltip" title="Need at least 3 approved documents (Currently: <?php echo $approved_count; ?>/3)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Document Review Modal -->
    <div class="modal fade" id="documentReviewModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-signature me-2"></i>
                        Document Review - <span id="reviewApplicantName"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="documentsReviewContainer">
                        <!-- Documents will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        Changes will be saved automatically and notifications sent to the candidate.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Document Action Modal -->
    <div class="modal fade" id="documentActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentActionTitle">Document Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="document_action" id="documentActionType">
                    <input type="hidden" name="document_id" id="documentId">
                    <input type="hidden" name="applicant_user_id" id="documentApplicantId">
                    
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>Document:</strong> <span id="documentName"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label for="document_comment" class="form-label">Comments <span id="commentRequired" class="text-danger">*</span></label>
                            <textarea class="form-control" id="document_comment" name="document_comment" rows="4" 
                                      placeholder="Provide feedback or comments for this document..."></textarea>
                            <div class="form-text">
                                <span id="commentHelp">Comments will be sent to the applicant via notification.</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn" id="documentActionSubmit">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

 <!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle me-2"></i>
                    Reject Application Forms
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="rejectApplicationForm">
                <input type="hidden" id="reject_user_id" name="user_id">
                <input type="hidden" name="reject_applicant" value="1">
                
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> You are about to reject all application forms for:
                        <div class="mt-2">
                            <strong class="text-danger" id="reject_applicant_name"></strong>
                        </div>
                    </div>
                    
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-2"></i>
                        This action will:
                        <ul class="mb-0 mt-2">
                            <li>Mark all submitted documents as rejected</li>
                            <li>Set user status to inactive</li>
                            <li>Send notification to the applicant</li>
                            <li>Prevent further application submissions</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label fw-bold">
                            Rejection Reason <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" 
                                  id="rejection_reason" 
                                  name="rejection_reason" 
                                  rows="5" 
                                  required 
                                  placeholder="Please provide a detailed and constructive reason for rejection. This will be shared with the applicant."
                                  style="resize: vertical;"></textarea>
                        <div class="form-text">
                            <i class="fas fa-lightbulb me-1"></i>
                            Be specific and professional. Include what was missing or insufficient.
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirm_rejection" required>
                        <label class="form-check-label" for="confirm_rejection">
                            I confirm that I have reviewed all documents and this rejection is final
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" id="rejectSubmitBtn">
                        <i class="fas fa-times-circle me-1"></i>Reject Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Selection management
        let selectedApplicants = new Set();

        function toggleSelectAll(checkbox) {
            const applicantCheckboxes = document.querySelectorAll('.applicant-checkbox');
            applicantCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    selectedApplicants.add(cb.value);
                } else {
                    selectedApplicants.delete(cb.value);
                }
            });
            updateSelectionUI();
        }

        function updateSelection() {
            selectedApplicants.clear();
            document.querySelectorAll('.applicant-checkbox:checked').forEach(cb => {
                selectedApplicants.add(cb.value);
            });
            
            // Update select all checkbox
            const selectAllCheckbox = document.getElementById('selectAllTable');
            const totalCheckboxes = document.querySelectorAll('.applicant-checkbox').length;
            const checkedCheckboxes = document.querySelectorAll('.applicant-checkbox:checked').length;
            
            selectAllCheckbox.checked = checkedCheckboxes === totalCheckboxes && totalCheckboxes > 0;
            selectAllCheckbox.indeterminate = checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes;
            
            updateSelectionUI();
        }

        function updateSelectionUI() {
            const count = selectedApplicants.size;
            const bulkBar = document.getElementById('bulkActionsBar');
            const selectedCountSpan = document.getElementById('selectedCount');
            const bulkSelectedIds = document.getElementById('bulkSelectedIds');
            
            if (count > 0) {
                bulkBar.classList.add('show');
                selectedCountSpan.textContent = count;
                bulkSelectedIds.value = Array.from(selectedApplicants).join(',');
            } else {
                bulkBar.classList.remove('show');
            }
        }

        function clearSelection() {
            selectedApplicants.clear();
            document.querySelectorAll('.applicant-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllTable').checked = false;
            updateSelectionUI();
        }

        // Enhanced document review function
        function viewDocumentReview(documents, applicantName, applicantId) {
            document.getElementById('reviewApplicantName').textContent = applicantName;
            const container = document.getElementById('documentsReviewContainer');
            
            if (!documents || documents.length === 0) {
                container.innerHTML = '<div class="text-center py-4"><i class="fas fa-folder-open fa-2x text-muted mb-2"></i><p class="text-muted">No documents found</p></div>';
                new bootstrap.Modal(document.getElementById('documentReviewModal')).show();
                return;
            }
            
            // Check if this is a completed application (all approved or all rejected)
            const allProcessed = documents.every(doc => doc.status === 'approved' || doc.status === 'rejected');
            const isRejectedApplicant = documents.some(doc => doc.status === 'rejected');
            
            let html = '';
            documents.forEach((doc, index) => {
                const fileSize = formatFileSize(parseInt(doc.size));
                const fileName = doc.filename;
                const description = doc.description || 'No description';
                const ext = (fileName.split('.').pop() || '').toLowerCase();
                const iconInfo = iconForExt(ext);
                const status = doc.status || 'pending_review';
                const reviewComments = doc.review_comments || '';
                const reviewerName = doc.reviewer_name || '';
                const reviewDate = doc.review_date || '';

                html += `
                    <div class="document-review-card status-${status}">
                        <div class="row align-items-center">
                            <div class="col-md-1">
                                <i class="fas ${iconInfo.icon} fa-2x ${iconInfo.color}"></i>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-1">${escapeHtml(fileName)}</h6>
                                <small class="text-muted d-block">Size: ${fileSize}</small>
                                ${description !== 'No description' ? `<small class="text-muted d-block">Description: ${escapeHtml(description)}</small>` : ''}
                                
                                <div class="mt-2">
                                    <span class="badge bg-${getStatusBadgeColor(status)}">${getStatusDisplayName(status)}</span>
                                    ${status !== 'pending_review' && reviewDate ? `<small class="text-muted ms-2">Reviewed: ${formatDate(reviewDate)}</small>` : ''}
                                </div>
                                
                                ${reviewComments ? `
                                <div class="mt-2 p-2 bg-light rounded">
                                    <strong>Review Comments:</strong><br>
                                    <small>${escapeHtml(reviewComments)}</small>
                                    ${reviewerName ? `<br><small class="text-muted">- ${escapeHtml(reviewerName)}</small>` : ''}
                                </div>` : ''}
                            </div>
                            <div class="col-md-3 text-end">
                                <button class="btn btn-sm btn-outline-primary mb-1" onclick="viewDocument('${doc.path}', '${escapeHtml(fileName)}', '${doc.id}')">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>
                            </div>
                            <div class="col-md-2 text-end">
                                ${!allProcessed && (status === 'pending_review' || status === 'needs_revision') ? `
                                <div class="btn-group-vertical btn-group-sm w-100">
                                    <button type="button" class="btn btn-success btn-sm" 
                                            onclick="setDocumentAction('approve_document', '${doc.id}', '${escapeHtml(fileName)}', '${applicantId}')">
                                        <i class="fas fa-check me-1"></i>Approve
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm" 
                                            onclick="setDocumentAction('request_revision', '${doc.id}', '${escapeHtml(fileName)}', '${applicantId}')">
                                        <i class="fas fa-edit me-1"></i>Revise
                                    </button>
                              
                                </div>
                                ` : `
                                <div class="text-center">
                                    ${status === 'approved' ? 
                                        '<i class="fas fa-check-circle text-success fa-2x mb-1"></i>' : 
                                        status === 'rejected' ? 
                                        '<i class="fas fa-times-circle text-danger fa-2x mb-1"></i>' :
                                        '<i class="fas fa-exclamation-triangle text-warning fa-2x mb-1"></i>'
                                    }
                                    <div class="small text-muted">${getStatusDisplayName(status)}</div>
                                </div>
                                `}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            // Add application status summary at the top if processed
            if (allProcessed) {
                const statusSummary = isRejectedApplicant ? 
                    '<div class="alert alert-danger mb-3"><i class="fas fa-times-circle me-2"></i><strong>Application Rejected</strong> - All documents have been reviewed and rejected.</div>' :
                    '<div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i><strong>Application Approved</strong> - All documents have been reviewed and approved.</div>';
                html = statusSummary + html;
            }
            
            container.innerHTML = html;
            new bootstrap.Modal(document.getElementById('documentReviewModal')).show();
        }



        // Document Action Modal Functions
        function setDocumentAction(action, documentId, documentName, applicantUserId) {
            document.getElementById('documentActionType').value = action;
            document.getElementById('documentId').value = documentId;
            document.getElementById('documentName').textContent = documentName;
            document.getElementById('documentApplicantId').value = applicantUserId;
            
            const modal = new bootstrap.Modal(document.getElementById('documentActionModal'));
            const title = document.getElementById('documentActionTitle');
            const submitBtn = document.getElementById('documentActionSubmit');
            const commentRequired = document.getElementById('commentRequired');
            const commentHelp = document.getElementById('commentHelp');
            const commentField = document.getElementById('document_comment');
            
            // Reset form
            commentField.value = '';
            commentField.required = false;
            
            if (action === 'approve_document') {
                title.textContent = 'Approve Document';
                submitBtn.className = 'btn btn-success';
                submitBtn.innerHTML = '<i class="fas fa-check me-1"></i>Approve';
                commentRequired.style.display = 'none';
                commentHelp.textContent = 'Optional approval comments.';
            }
            //  else if (action === 'reject_document') {
            //     title.textContent = 'Reject Document';
            //     submitBtn.className = 'btn btn-danger';
            //     submitBtn.innerHTML = '<i class="fas fa-times me-1"></i>Reject';
            //     commentRequired.style.display = 'inline';
            //     commentHelp.textContent = 'Please explain why this document is being rejected.';
            //     commentField.required = true;
            // }
             else if (action === 'request_revision') {
                title.textContent = 'Request Document Revision';
                submitBtn.className = 'btn btn-warning';
                submitBtn.innerHTML = '<i class="fas fa-edit me-1"></i>Request Revision';
                commentRequired.style.display = 'inline';
                commentHelp.textContent = 'Please specify what needs to be revised.';
                commentField.required = true;
            }
            
            modal.show();
        }

        // Individual action functions
        function approveApplicant(userId) {
            const sel = document.getElementById('assigned_program_' + userId);
            const assignedProgramId = sel ? sel.value : '';
            const prefId = sel ? sel.getAttribute('data-pref-id') : '';
            const reason = document.getElementById('reroute_reason_' + userId)?.value || '';

            if (!assignedProgramId) {
                alert('Please choose a program to assign.');
                return;
            }
            if (prefId && assignedProgramId !== prefId && reason.trim() === '') {
                alert('Please provide a reason for assigning a different program.');
                return;
            }

            if (confirm('Approve this applicant and assign the selected program?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                  <input type="hidden" name="approve_applicant" value="1">
                  <input type="hidden" name="user_id" value="${userId}">
                  <input type="hidden" name="assigned_program_id" value="${assignedProgramId}">
                  <input type="hidden" name="reroute_reason" value="${reason.replace(/"/g,'&quot;')}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

    // Show reject modal with proper initialization
function showRejectModal(userId, applicantName) {
    // Set form values
    document.getElementById('reject_user_id').value = userId;
    document.getElementById('reject_applicant_name').textContent = applicantName;
    document.getElementById('rejection_reason').value = '';
    document.getElementById('confirm_rejection').checked = false;
    
    // Enable submit button (in case it was disabled before)
    const submitBtn = document.getElementById('rejectSubmitBtn');
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fas fa-times-circle me-1"></i>Reject Application';
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
}

// Handle reject form submission
document.addEventListener('DOMContentLoaded', function() {
    const rejectForm = document.getElementById('rejectApplicationForm');
    
    if (rejectForm) {
        rejectForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent default to add validation
            
            const userId = document.getElementById('reject_user_id').value;
            const reason = document.getElementById('rejection_reason').value.trim();
            const confirmed = document.getElementById('confirm_rejection').checked;
            const applicantName = document.getElementById('reject_applicant_name').textContent;
            
            // Validation
            if (!userId) {
                alert('Error: User ID not found. Please try again.');
                return false;
            }
            
            if (!reason) {
                alert('Please provide a rejection reason.');
                document.getElementById('rejection_reason').focus();
                return false;
            }
            
            if (reason.length < 20) {
                alert('Please provide a more detailed rejection reason (at least 20 characters).');
                document.getElementById('rejection_reason').focus();
                return false;
            }
            
            if (!confirmed) {
                alert('Please confirm that you have reviewed all documents.');
                return false;
            }
            
            // Final confirmation
            if (!confirm(`Final Confirmation:\n\nReject ${applicantName}?\n\nThis action cannot be undone.`)) {
                return false;
            }
            
            // Disable submit button and show loading
            const submitBtn = document.getElementById('rejectSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
            
            // Submit the form
            this.submit();
            
            return true;
        });
    }
});

// Keep your working direct function as backup
function rejectApplicantDirect(userId, applicantName) {
    const reason = prompt(`Reject ${applicantName}?\n\nPlease provide a detailed rejection reason:`);
    
    if (reason === null) return; // User cancelled
    
    if (reason.trim() === '') {
        alert('Rejection reason is required');
        return;
    }
    
    if (reason.trim().length < 20) {
        alert('Please provide a more detailed reason (at least 20 characters)');
        return;
    }
    
    if (!confirm(`Confirm rejection of ${applicantName}?\n\nThis action cannot be undone.`)) {
        return;
    }
    
    // Create and submit form
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    form.innerHTML = `
        <input type="hidden" name="reject_applicant" value="1">
        <input type="hidden" name="user_id" value="${userId}">
        <input type="hidden" name="rejection_reason" value="${escapeHtml(reason)}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Helper function for escaping HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
        function viewDocument(filePath, fileName, documentId = null) {
            // Use the dedicated document viewer
            let viewerUrl = 'view-document.php?path=' + encodeURIComponent(filePath);
            
            // If document ID is available, use it for better tracking
            if (documentId) {
                viewerUrl = 'view-document.php?id=' + documentId;
            }
            
            // Open in new window
            const viewerWindow = window.open(
                viewerUrl, 
                '_blank', 
                'width=1000,height=750,scrollbars=yes,resizable=yes,menubar=no,toolbar=no,status=no'
            );
            
            if (!viewerWindow) {
                alert('Please allow pop-ups to view documents');
                return;
            }
            
            // Focus the new window
            viewerWindow.focus();
        }

        // Utility functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function iconForExt(ext) {
            ext = (ext || '').toLowerCase();
            const map = {
                pdf:  { icon: 'fa-file-pdf',        color: 'text-danger' },
                doc:  { icon: 'fa-file-word',       color: 'text-primary' },
                docx: { icon: 'fa-file-word',       color: 'text-primary' },
                xls:  { icon: 'fa-file-excel',      color: 'text-success' },
                xlsx: { icon: 'fa-file-excel',      color: 'text-success' },
                ppt:  { icon: 'fa-file-powerpoint', color: 'text-warning' },
                pptx: { icon: 'fa-file-powerpoint', color: 'text-warning' },
                png:  { icon: 'fa-file-image',      color: 'text-info' },
                jpg:  { icon: 'fa-file-image',      color: 'text-info' },
                jpeg: { icon: 'fa-file-image',      color: 'text-info' },
                gif:  { icon: 'fa-file-image',      color: 'text-info' },
            };
            return map[ext] || { icon: 'fa-file', color: 'text-secondary' };
        }

        function getStatusBadgeColor(status) {
            const colors = {
                'pending_review': 'secondary',
                'approved': 'success',
                'rejected': 'danger',
                'needs_revision': 'warning'
            };
            return colors[status] || 'secondary';
        }

        function getStatusDisplayName(status) {
            const names = {
                'pending_review': 'Pending Review',
                'approved': 'Approved',
                'rejected': 'Rejected',
                'needs_revision': 'Needs Revision'
            };
            return names[status] || status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
        }

        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Handle program assignment changes
        document.addEventListener('change', function(e){
            if (e.target && e.target.matches('select[id^="assigned_program_"]')) {
                const sel = e.target;
                const uid = sel.id.replace('assigned_program_', '');
                const prefId = sel.getAttribute('data-pref-id');
                const reasonBox = document.getElementById('reroute_reason_' + uid);
                if (prefId && sel.value !== prefId) {
                    reasonBox.classList.remove('d-none');
                } else {
                    reasonBox.classList.add('d-none');
                    reasonBox.value = '';
                }
            }
        });

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Auto refresh notification check every 30 seconds
            setInterval(function() {
                // Check for new pending applications that need attention
                const pendingCount = <?php echo $stats['pending']; ?>;
                if (pendingCount > 0) {
                    console.log('Checking for new applications...');
                    // You could add a subtle notification here
                }
            }, 30000);
        });

        // Form submission handling
        document.addEventListener('submit', function(e) {
            if (e.target.matches('form')) {
                const submitBtn = e.target.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
                    
                    // Re-enable after 10 seconds as fallback
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }, 10000);
                }
            }
        });

        // Show success notification when documents are updated
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Add candidate update notification listener
        document.addEventListener('DOMContentLoaded', function() {
            // Check if there's a success message about document updates
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                showNotification('Document review completed successfully! Candidate has been notified.', 'success');
            }
        });

        console.log('ETEEAP Application Reviews System with Document-Level Controls initialized successfully');
    </script>
</body>
</html>