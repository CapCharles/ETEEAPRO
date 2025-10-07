<?php
require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];
    $program_id = $_POST['program_id'] ?? null;

    // Insert user
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, user_type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $email, $password, $role]);
    $user_id = $pdo->lastInsertId();

    // If evaluator, assign program
    if ($role === 'evaluator' && !empty($program_id)) {
        $assign = $pdo->prepare("INSERT INTO evaluator_programs (evaluator_id, program_id) VALUES (?, ?)");
        $assign->execute([$user_id, $program_id]);
    }

    header('Location: users.php?success=1');
    exit;
}
?>
