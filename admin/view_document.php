<?php
// view_document.php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once '../config/database.php';

// Check if user is logged in and is admin/evaluator
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'evaluator'])) {
    http_response_code(403);
    exit('Access denied');
}

// Get document ID
$document_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$document_id) {
    http_response_code(400);
    exit('Invalid document ID');
}

try {
    // Get document information
    $stmt = $pdo->prepare("
        SELECT d.*, a.user_id as applicant_id 
        FROM documents d 
        JOIN applications a ON d.application_id = a.id 
        WHERE d.id = ?
    ");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        http_response_code(404);
        exit('Document not found');
    }
    
    // Adjust the path based on your file structure
    $file_path = '../uploads/' . $document['file_path']; // or whatever your upload path is
    
    if (!file_exists($file_path)) {
        http_response_code(404);
        exit('File not found on server');
    }
    
    $file_extension = strtolower(pathinfo($document['original_filename'], PATHINFO_EXTENSION));
    
    // Set appropriate headers based on file type
    switch ($file_extension) {
        case 'pdf':
            header('Content-Type: application/pdf');
            break;
        case 'jpg':
        case 'jpeg':
            header('Content-Type: image/jpeg');
            break;
        case 'png':
            header('Content-Type: image/png');
            break;
        case 'gif':
            header('Content-Type: image/gif');
            break;
        case 'bmp':
            header('Content-Type: image/bmp');
            break;
        case 'txt':
            header('Content-Type: text/plain');
            break;
        case 'doc':
            header('Content-Type: application/msword');
            break;
        case 'docx':
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            break;
        default:
            header('Content-Type: application/octet-stream');
    }
    
    // Set headers for inline viewing (not download)
    header('Content-Disposition: inline; filename="' . $document['original_filename'] . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Output file content
    readfile($file_path);
    
} catch (PDOException $e) {
    error_log('Document view error: ' . $e->getMessage());
    http_response_code(500);
    exit('Database error');
} catch (Exception $e) {
    error_log('Document view error: ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
?>