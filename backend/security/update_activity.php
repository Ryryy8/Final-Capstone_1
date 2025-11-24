<?php
/**
 * Update Activity Timestamp
 * Updates last activity time to extend session
 */

header('Content-Type: application/json');
require_once __DIR__ . '/session_validator.php';

// Update last activity time
$_SESSION['last_activity'] = time();

echo json_encode([
    'success' => true,
    'message' => 'Activity timestamp updated',
    'last_activity' => $_SESSION['last_activity']
]);
?>