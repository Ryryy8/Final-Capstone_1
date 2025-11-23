<?php
// Database connection configuration - Environment aware
require_once __DIR__ . '/config/db_config.php';

$host = DB_HOST;
$dbname = DB_NAME;
$username = DB_USERNAME;
$password = DB_PASSWORD;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Don't exit here, let the calling script handle the error
    throw new Exception('Database connection failed: ' . $e->getMessage());
}
?>