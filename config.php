<?php
declare(strict_types=1);
session_start();

$host = 'localhost';
$db   = 'bella_vista_spa';
$user = 'root';
$pass = ''; // Default XAMPP MySQL password is usually blank. Change if yours is different.
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check that Apache and MySQL are running and that database.sql has been imported.');
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function is_admin(): bool {
    return isset($_SESSION['admin_id']);
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: admin_login.php');
        exit;
    }
}
