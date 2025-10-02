<?php
// admin/get_programs.php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['admin', 'evaluator'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT id, program_name, program_code, description, status
        FROM programs 
        WHERE status = 'active' 
        ORDER BY program_name ASC
    ");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode($programs);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>