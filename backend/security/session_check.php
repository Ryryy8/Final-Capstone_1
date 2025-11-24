<?php
/**
 * Session Check API
 * Validates current session and returns status
 */

header('Content-Type: application/json');
require_once __DIR__ . '/session_validator.php';

$session_timeout = 1800; // 30 minutes
$warning_threshold = 300; // 5 minutes before timeout

$response = ['success' => true, 'message' => 'Session valid'];

// Calculate remaining time
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