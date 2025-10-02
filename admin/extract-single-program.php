<?php
// admin/extract-single-program.php

session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';
require_once '../includes/simple_document_processor.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

try {
    // Get all application forms for this user
    $stmt = $pdo->prepare("
        SELECT id FROM application_forms 
        WHERE user_id = ? AND status = 'pending_review'
        ORDER BY upload_date DESC
    ");
    $stmt->execute([$user_id]);
    $forms = $stmt->fetchAll();
    
    if (empty($forms)) {
        echo json_encode(['success' => false, 'message' => 'No application forms found for this user']);
        exit;
    }
    
    $processed = 0;
    $successful = 0;
    
    // Process each form
    foreach ($forms as $form) {
        $processed++;
        $result = processUploadedDocument($form['id']);
        if ($result) {
            $successful++;
        }
    }
    
    if ($successful > 0) {
        echo json_encode([
            'success' => true, 
            'message' => "Successfully processed $successful out of $processed documents",
            'processed' => $processed,
            'successful' => $successful
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => "Could not extract program information from any documents. The forms may not contain clear program information or may be in an unsupported format."
        ]);
    }
    
} catch (Exception $e) {
    error_log("Single program extraction error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>