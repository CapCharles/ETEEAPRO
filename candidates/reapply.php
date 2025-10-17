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

if (!$program_id) {
    header('Location: assessment.php');
    exit();
}

try {
    // Verify the previous application belongs to this user
    if ($previous_app_id) {
        $stmt = $pdo->prepare("SELECT id FROM applications WHERE id = ? AND user_id = ?");
        $stmt->execute([$previous_app_id, $user_id]);
        if (!$stmt->fetch()) {
            header('Location: assessment.php');
            exit();
        }
    }
    
    // Create new application
    $stmt = $pdo->prepare("
        INSERT INTO applications (user_id, program_id, application_status, created_at) 
        VALUES (?, ?, 'draft', NOW())
    ");
    $stmt->execute([$user_id, $program_id]);
    $new_app_id = $pdo->lastInsertId();
    
    // Redirect to upload page with new application
    $_SESSION['success_message'] = 'New application created successfully. You can now upload your documents.';
    header('Location: upload.php?id=' . $new_app_id);
    exit();
    
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Error creating new application. Please try again.';
    header('Location: assessment.php');
    exit();
}
?>