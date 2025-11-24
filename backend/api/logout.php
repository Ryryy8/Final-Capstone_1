<?php
/**
 * Logout API Endpoint
 * Enhanced with security cleanup
 */

session_start(); // Make sure session is started

// Log the logout activity before destroying session
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    error_log("User logout: {$_SESSION['username']} (ID: {$_SESSION['user_id']}) from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

// Destroy all session data
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear any cached data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Redirect to login page
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = "$protocol://$host" . dirname(dirname($_SERVER['SCRIPT_NAME']));

header("Location: $base_url/index.html");
exit();
?>