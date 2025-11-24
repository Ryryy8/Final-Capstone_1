<?php
/**
 * Tab Validation API
 * Prevents multiple tabs from using the same session
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

$input = json_decode(file_get_contents('php://input'), true);
$tab_token = $input['tab_token'] ?? '';
$page_url = $input['page_url'] ?? '';

// Initialize active tabs tracking
if (!isset($_SESSION['active_tabs'])) {
    $_SESSION['active_tabs'] = [];
}

$current_time = time();

// Clean up expired tabs (older than 30 seconds)
$_SESSION['active_tabs'] = array_filter($_SESSION['active_tabs'], function($tab) use ($current_time) {
    return ($current_time - $tab['last_seen']) < 30;
});

// Check if tab token already exists
$tab_exists = false;
foreach ($_SESSION['active_tabs'] as &$tab) {
    if ($tab['token'] === $tab_token) {
        $tab['last_seen'] = $current_time;
        $tab['page_url'] = $page_url;
        $tab_exists = true;
        break;
    }
}

// If new tab and we already have an active tab, reject
if (!$tab_exists) {
    if (count($_SESSION['active_tabs']) > 0) {
        // Multiple tabs detected - just reject, don't destroy session
        echo json_encode([
            'success' => false,
            'message' => 'Session security violation: Multiple tabs detected. Please use only one browser tab.'
        ]);
        exit;
    } else {
        // Register new tab
        $_SESSION['active_tabs'][] = [
            'token' => $tab_token,
            'last_seen' => $current_time,
            'page_url' => $page_url
        ];
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Tab validated',
    'active_tabs' => count($_SESSION['active_tabs'])
]);
?>