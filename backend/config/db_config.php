<?php
/**
 * Database Configuration Helper
 * Automatically detects environment and sets appropriate database settings
 */

// Function to detect if we're on localhost or hosting
function isLocalhost() {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    return (
        strpos($host, 'localhost') !== false || 
        strpos($host, '127.0.0.1') !== false ||
        $host === '' ||
        strpos($host, '.local') !== false
    );
}

// Set database configuration based on environment
if (isLocalhost()) {
    // Local development settings
    $db_host = 'localhost';
    $db_username = 'root';
    $db_password = '';
    $db_name = 'assesspro_db';
} else {
    // Hosting environment settings - update these with your hosting details
    $db_host = $_SERVER['DB_HOST'] ?? 'localhost'; // Most hosting uses localhost
    $db_username = $_SERVER['DB_USERNAME'] ?? 'your_hosting_username';
    $db_password = $_SERVER['DB_PASSWORD'] ?? 'your_hosting_password';
    $db_name = $_SERVER['DB_NAME'] ?? 'your_hosting_database';
}

// Common database settings
$db_charset = 'utf8mb4';

// For backward compatibility, define constants
define('DB_HOST', $db_host);
define('DB_USERNAME', $db_username);
define('DB_PASSWORD', $db_password);
define('DB_NAME', $db_name);
define('DB_CHARSET', $db_charset);
?>