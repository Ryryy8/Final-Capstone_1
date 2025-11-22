<?php
// Database connection configuration
$host = 'localhost';
$dbname = 'assesspro_db'; // Using your existing database
$username = 'root'; // Update with your database username
$password = ''; // Update with your database password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Don't exit here, let the calling script handle the error
    throw new Exception('Database connection failed: ' . $e->getMessage());
}
?>