<?php
session_start();
require_once '../config/db_config.php';

header('Content-Type: application/json');

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['tab_token'])) {
    echo json_encode(['success' => false, 'message' => 'Tab token required']);
    exit;
}

$tab_token = $input['tab_token'];
$user_id = $_SESSION['user_id'];

try {
    // Remove specific tab token for the user
    $stmt = $pdo->prepare("DELETE FROM user_tabs WHERE user_id = ? AND tab_token = ?");
    $stmt->execute([$user_id, $tab_token]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Tab cleaned up successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Cleanup failed: ' . $e->getMessage()
    ]);
}
?>