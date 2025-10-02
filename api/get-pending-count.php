<?php
// api/get-pending-count.php
session_start();
require_once '../../config/database.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM application_forms WHERE status = 'pending_review'");
    $result = $stmt->fetch();
    
    header('Content-Type: application/json');
    echo json_encode(['count' => intval($result['count'])]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>