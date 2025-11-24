<?php
/**
 * Access Validation API
 * Validates user has access to specific page/role
 */

header('Content-Type: application/json');
require_once __DIR__ . '/session_validator.php';

$input = json_decode(file_get_contents('php://input'), true);
$required_role = $input['required_role'] ?? '';
$page = $input['page'] ?? '';

// Check if user has the required role
if ($_SESSION['role'] !== $required_role) {
    echo json_encode([
        'success' => false,
        'message' => "Access denied. This page requires {$required_role} role. You have {$_SESSION['role']} role."
    ]);
    exit;
}

// Log page access
error_log("Page access: {$_SESSION['username']} ({$_SESSION['role']}) accessed {$page}");

echo json_encode([
    'success' => true,
    'message' => 'Access granted',
    'user' => [
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'full_name' => $_SESSION['full_name']
    ]
]);
?>