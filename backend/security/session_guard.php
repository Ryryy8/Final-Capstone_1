<?php
/**
 * Immediate Session Guard
 * Include this at the very top of dashboard pages
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if session exists and is valid
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // No session - redirect immediately
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $base_url = "$protocol://$host" . dirname(dirname($_SERVER['SCRIPT_NAME']));
    
    header("Location: $base_url/index.html");
    exit();
}

// Check session timeout
$session_timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity'])) {
    $time_elapsed = time() - $_SESSION['last_activity'];
    if ($time_elapsed > $session_timeout) {
        // Session expired
        session_destroy();
        
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $base_url = "$protocol://$host" . dirname(dirname($_SERVER['SCRIPT_NAME']));
        
        header("Location: $base_url/index.html");
        exit();
    }
}

// Update last activity
$_SESSION['last_activity'] = time();
?>