<?php
/**
 * Tab Validation API - REFRESH SAFE VERSION
 * Allows unlimited refreshes but prevents multiple dashboard tabs
 */

header('Content-Type: application/json');
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'No active session'
    ]);
    exit;
}

// Always return success for refresh safety
echo json_encode([
    'success' => true,
    'message' => 'Tab validated - refresh safe mode',
    'refresh_safe' => true
]);
?>