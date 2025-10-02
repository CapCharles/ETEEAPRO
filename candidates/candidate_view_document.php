<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once '../config/database.php';

// must be candidate
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'candidate') {
    http_response_code(403); exit('Access denied');
}

$user_id = $_SESSION['user_id'];
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$doc_id) { http_response_code(400); exit('Invalid document ID'); }

try {
    // join to confirm ownership
    $stmt = $pdo->prepare("
        SELECT d.*
        FROM documents d
        JOIN applications a ON d.application_id = a.id
        WHERE d.id = ? AND a.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$doc_id, $user_id]);
    $doc = $stmt->fetch();
    if (!$doc) { http_response_code(404); exit('Document not found'); }

    $file_path = $doc['file_path']; // you stored full relative path like ../uploads/documents/xxx.ext
    if (!file_exists($file_path)) { http_response_code(404); exit('File not found on server'); }

    $ext  = strtolower(pathinfo($doc['original_filename'], PATHINFO_EXTENSION));
    $mime = $doc['mime_type'] ?: 'application/octet-stream';

    // map some common mimes if needed
    $map = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',  'gif' => 'image/gif',
        'bmp' => 'image/bmp',  'webp'=> 'image/webp', 'svg' => 'image/svg+xml',
        'txt' => 'text/plain',
        'doc' => 'application/msword',
        'docx'=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (isset($map[$ext])) $mime = $map[$ext];

    $isDownload = isset($_GET['dl']) && $_GET['dl'] == '1';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="'.basename($doc['original_filename']).'"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($file_path);
} catch (Throwable $e) {
    error_log('candidate_view_document error: '.$e->getMessage());
    http_response_code(500); exit('Server error');
}
