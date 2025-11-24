<?php
/**
 * Extend Session API
 * Manually extends the session when user chooses to continue
 */

header('Content-Type: application/json');
require_once __DIR__ . '/session_validator.php';

// Extend session by resetting last activity
$_SESSION['last_activity'] = time();

// Regenerate session ID for security
session_regenerate_id(true);

// Update session token
$_SESSION['session_token'] = bin2hex(random_bytes(32));

echo json_encode([
    'success' => true,
    'message' => 'Session extended successfully',
    'extended_until' => date('Y-m-d H:i:s', time() + 1800) // 30 minutes from now
]);
?>