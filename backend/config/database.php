<?php
/**
 * Database Configuration for AssessPro System
 * Environment-aware MySQL Connection
 */

// Load dynamic database configuration
require_once __DIR__ . '/db_config.php';

// Connection options
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    // Create PDO connection
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
        DB_USERNAME,
        DB_PASSWORD,
        $options
    );
    
    // Set timezone
    $pdo->exec("SET time_zone = '+08:00'"); // Philippines timezone
    
} catch (PDOException $e) {
    // Log error and show user-friendly message
    error_log("Database connection failed: " . $e->getMessage());
    die("Connection failed. Please contact the system administrator.");
}

// Database helper functions
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        global $pdo;
        $this->connection = $pdo;
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query failed: " . $e->getMessage());
            throw new Exception("Database query failed");
        }
    }
    
    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
}

// Function to check database connection
function checkDatabaseConnection() {
    global $pdo;
    try {
        $stmt = $pdo->query('SELECT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
