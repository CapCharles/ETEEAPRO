<?php
// config/database.php

if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['HTTP_HOST'] === '127.0.0.1') {
    // Local development
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306'); // change if you use a different port locally
    define('DB_NAME', 'eteeap_db');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // Production server
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'eteeap_db');
    define('DB_USER', 'eteeap_db');
    define('DB_PASS', 'Eteeap_db1'); // change to actual prod password
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Optional: Set timezone
    $pdo->exec("SET time_zone = '+08:00'");

} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}