<?php
session_start();
require_once '../config/database.php';
require_once '../config/constants.php';
require_once '../includes/functions.php';
requireAuth(['admin']);

// TEMPORARY while debugging only — pwede mong i-comment pag ok na:
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage('users.php', 'Invalid request.', 'error');
    exit;
}

// Collect + sanitize
$first_name = sanitizeInput($_POST['first_name'] ?? '');
$last_name  = sanitizeInput($_POST['last_name'] ?? '');
$middle_name= sanitizeInput($_POST['middle_name'] ?? '');
$email      = sanitizeInput($_POST['email'] ?? '');
$phone      = sanitizeInput($_POST['phone'] ?? '');
$address    = sanitizeInput($_POST['address'] ?? '');
$user_type  = $_POST['user_type'] ?? '';
$status     = $_POST['status'] ?? 'active';
$password   = $_POST['password'] ?? '';
$program_id = trim($_POST['program_id'] ?? '');  // optional; only for evaluator

$errors = [];

// Basic validation
if ($first_name === '' || $last_name === '' || $email === '' || $password === '' || $user_type === '') {
    $errors[] = 'All required fields must be filled';
}
if (!validateEmail($email)) {
    $errors[] = 'Invalid email format';
}
$pwcheck = checkPasswordStrength($password);
if (!$pwcheck['valid']) {
    $errors[] = $pwcheck['message']; // uses your helper
}

// Email duplicate
if (empty($errors)) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'Email already exists';
    } catch (PDOException $e) {
        $errors[] = 'Database error while checking email';
    }
}

if (!empty($errors)) {
    redirectWithMessage('users.php', implode(' | ', $errors), 'error');
    exit;
}

try {
    $pdo->beginTransaction();

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (first_name, last_name, middle_name, email, phone, address, password, user_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $first_name, $last_name, $middle_name, $email, $phone, $address, $hashed, $user_type, $status
    ]);
    $new_user_id = (int)$pdo->lastInsertId();

    // Assign program if evaluator and program chosen
    if ($user_type === 'evaluator' && $program_id !== '') {
        $ins = $pdo->prepare("INSERT INTO evaluator_programs (evaluator_id, program_id) VALUES (?, ?)");
        $ins->execute([$new_user_id, (int)$program_id]);
    }

    $pdo->commit();

    // Log
    logActivity($pdo, 'user_created', $_SESSION['user_id'], 'users', $new_user_id, null, [
        'email' => $email, 'user_type' => $user_type, 'status' => $status, 'program_id' => $program_id
    ]);

    redirectWithMessage('users.php', 'User created successfully!', 'success');
    exit;
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('add_user_process failed: '.$e->getMessage());
    redirectWithMessage('users.php', 'Failed to create user. Please try again.', 'error');
    exit;
}
