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
$program_id = isset($_GET['program_id']) ? $_GET['program_id'] : null;
$previous_app_id = isset($_GET['previous_app']) ? $_GET['previous_app'] : null;

if (!$program_id || !$previous_app_id) {
    $_SESSION['error'] = "Invalid reapplication request.";
    header('Location: assessment.php');
    exit();
}

// Verify that the previous application belongs to this user
try {
    $stmt = $pdo->prepare("
        SELECT id, program_id, application_status 
        FROM applications 
        WHERE id = ? AND user_id = ? AND program_id = ?
    ");
    $stmt->execute([$previous_app_id, $user_id, $program_id]);
    $previous_app = $stmt->fetch();
    
    if (!$previous_app) {
        $_SESSION['error'] = "Previous application not found.";
        header('Location: assessment.php');
        exit();
    }
    
    // Check if status allows reapplication
    if (!in_array($previous_app['application_status'], ['partially_qualified', 'not_qualified'])) {
        $_SESSION['error'] = "You cannot reapply for this application.";
        header('Location: assessment.php');
        exit();
    }
    
    // Check if user already has an active application for this program
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM applications 
        WHERE user_id = ? 
        AND program_id = ? 
        AND application_status IN ('draft', 'submitted', 'under_review')
    ");
    $stmt->execute([$user_id, $program_id]);
    $active_count = $stmt->fetch()['count'];
    
    if ($active_count > 0) {
        $_SESSION['error'] = "You already have an active application for this program. Please complete or submit it first.";
        header('Location: upload.php');
        exit();
    }
    
    // Check if already qualified
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM applications 
        WHERE user_id = ? 
        AND program_id = ? 
        AND application_status = 'qualified'
    ");
    $stmt->execute([$user_id, $program_id]);
    $qualified_count = $stmt->fetch()['count'];
    
    if ($qualified_count > 0) {
        $_SESSION['error'] = "You are already qualified in this program.";
        header('Location: assessment.php');
        exit();
    }
    
    // Create new application
    $stmt = $pdo->prepare("
        INSERT INTO applications (user_id, program_id, application_status) 
        VALUES (?, ?, 'draft')
    ");
    $stmt->execute([$user_id, $program_id]);
    
    $new_application_id = $pdo->lastInsertId();
    
    // Copy documents from previous application
    $stmt = $pdo->prepare("
        SELECT * FROM documents 
        WHERE application_id = ?
    ");
    $stmt->execute([$previous_app_id]);
    $previous_documents = $stmt->fetchAll();
    
    $copied_count = 0;
    foreach ($previous_documents as $doc) {
        // Insert copied document record (keeping the same file path)
        $stmt = $pdo->prepare("
            INSERT INTO documents (
                application_id, document_type, original_filename, stored_filename,
                file_path, file_size, mime_type, description, criteria_id, 
                hierarchical_data, is_copied_from_previous
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $new_application_id,
            $doc['document_type'],
            $doc['original_filename'],
            $doc['stored_filename'],
            $doc['file_path'],
            $doc['file_size'],
            $doc['mime_type'],
            $doc['description'],
            $doc['criteria_id'],
            $doc['hierarchical_data']
        ]);
        $copied_count++;
    }
    
    // Set success message
    $_SESSION['success'] = "Reapplication created successfully! {$copied_count} documents from your previous application have been automatically copied. You can add more documents or submit your application.";
    
    // Redirect to upload page
    header('Location: upload.php');
    exit();
    
} catch (PDOException $e) {
    error_log("Reapplication error: " . $e->getMessage());
    $_SESSION['error'] = "Failed to create reapplication. Please try again.";
    header('Location: assessment.php');
    exit();
}
?>