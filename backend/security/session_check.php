<?php
/**
 * Session Check API
 * Validates current session and returns status
 */

header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if basic session exists
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No valid session found'
    ]);
    exit;
}

// Check session timeout
$session_timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity'])) {
    $time_elapsed = time() - $_SESSION['last_activity'];
    if ($time_elapsed > $session_timeout) {
        // Session expired
        session_destroy();
        echo json_encode([
            'success' => false,
            'message' => 'Session expired due to inactivity'
        ]);
        exit;
    }
}

// Update last activity
$_SESSION['last_activity'] = time();

$warning_threshold = 300; // 5 minutes before timeout
$response = ['success' => true, 'message' => 'Session valid'];

// Calculate remaining time for warning
if (isset($_SESSION['last_activity'])) {
    $time_elapsed = time() - $_SESSION['last_activity'];
    $remaining_time = $session_timeout - $time_elapsed;
    
    if ($remaining_time <= $warning_threshold) {
        $response['warning'] = true;
        $response['remaining_time'] = max(0, floor($remaining_time / 60)); // Minutes remaining
        $response['message'] = 'Session expires soon';
    }
}

echo json_encode($response);
?>