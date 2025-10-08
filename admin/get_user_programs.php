<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';
requireAuth(['admin']);

header('Content-Type: application/json; charset=utf-8');

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!$userId) { echo json_encode([]); exit; }

try {
  $stmt = $pdo->prepare("SELECT program_id FROM evaluator_programs WHERE evaluator_id = ?");
  $stmt->execute([$userId]);
  $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
  echo json_encode(array_map('strval', $rows));
} catch (PDOException $e) {
  echo json_encode([]);
}
