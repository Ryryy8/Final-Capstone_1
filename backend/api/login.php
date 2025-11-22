<?php
/**
 * Login API Endpoint
 * Handles login requests from the frontend
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../auth/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!isset($input['username']) || !isset($input['password']) || !isset($input['role'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$username = trim($input['username']);
$password = $input['password'];
$requestedRole = $input['role'];

// Basic validation
if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username and password are required']);
    exit;
}

// Validate role
$validRoles = ['admin', 'head', 'staff'];
if (!in_array($requestedRole, $validRoles)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid role selected']);
    exit;
}

try {
    // Attempt login with role checking - skip success logging to avoid duplication
    $result = $auth->login($username, $password, true);
    
    if ($result['success']) {
        // Check if the user's role matches the requested role
        if ($result['role'] !== $requestedRole) {
            $auth->logout(); // Logout the user
            
            // Log role access denied attempt
            $auth->logFailedLoginAttempt($username, "Role access denied: requested {$requestedRole}, user has {$result['role']}", $result['user_id']);
            
            http_response_code(403);
            echo json_encode([
                'success' => false, 
                'message' => 'You do not have access to the ' . ucfirst($requestedRole) . ' dashboard'
            ]);
            exit;
        }
        
        // Role check passed - now log the successful login
        $auth->logActivity($result['user_id'], 'login_success', 'users', $result['user_id']);
        
        // Successful login with correct role
        http_response_code(200);
        echo json_encode($result);
    } else {
        // Failed login (already logged in auth.php)
        http_response_code(401);
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    error_log("Login API error: " . $e->getMessage());
    // Log API error
    $auth->logFailedLoginAttempt($username, "API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

?>