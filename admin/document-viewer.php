<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

if (!isset($_GET['file'])) {
    http_response_code(400);
    echo "No file specified";
    exit;
}

$file_path = $_GET['file'];
$full_path = $file_path;

// Security check - ensure file is within uploads directory
if (!str_contains($file_path, 'uploads/application_forms/')) {
    http_response_code(403);
    echo "Access denied";
    exit;
}

// Check if file exists
if (!file_exists($full_path)) {
    http_response_code(404);
    echo "File not found";
    exit;
}

// Get file info
$file_info = pathinfo($full_path);
$file_extension = strtolower($file_info['extension']);

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
    default:
        header('Content-Type: application/octet-stream');
        break;
}

// Output file
readfile($full_path);
exit;
?>