<?php
// config/database.php
$host = 'localhost';
$dbname = 'eteeap_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Optional: Set timezone
    $pdo->exec("SET time_zone = '+08:00'");
    
} catch(PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
?>