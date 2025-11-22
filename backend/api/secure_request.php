<?php
/**
 * Secure Request Handler - Simplified (No CAPTCHA)
 * Handles security validation, rate limiting, and bot detection
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once '../security/RequestSecurityMiddleware.php';
require_once '../security/AntiSpamManager.php';

class SecureRequestHandler {
    private $securityMiddleware;
    private $antiSpamManager;
    
    public function __construct() {
        $this->securityMiddleware = new RequestSecurityMiddleware();
        $this->antiSpamManager = new AntiSpamManager();
    }
    
    public function handleRequest() {
        try {
            // Only allow POST requests
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                return $this->sendError(405, 'METHOD_NOT_ALLOWED', 'Only POST requests are allowed');
            }
            
            // Get JSON input
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['action'])) {
                return $this->sendError(400, 'MISSING_ACTION', 'Action parameter is required');
            }
            
            // Route to appropriate handler
            switch ($input['action']) {
                case 'validate_request':
                    return $this->handleValidateRequest($input);
                    
                case 'get_security_token':
                    return $this->handleGetSecurityToken($input);
                    
                case 'check_rate_limit':
                    return $this->handleCheckRateLimit($input);
                    
                case 'get_security_stats':
                    return $this->handleGetSecurityStats($input);
                    
                case 'test_connection':
                    return $this->handleTestConnection($input);
                    
                default:
                    return $this->sendError(400, 'INVALID_ACTION', 'Invalid action specified');
            }
            
        } catch (Exception $e) {
            error_log("Secure Request Handler Error: " . $e->getMessage());
            return $this->sendError(500, 'INTERNAL_ERROR', 'An internal error occurred');
        }
    }
    
    private function handleTestConnection($input) {
        return $this->sendSuccess([
            'message' => 'Security system is operational',
            'timestamp' => date('Y-m-d H:i:s'),
            'features' => [
                'rate_limiting' => true,
                'bot_detection' => true,
                'input_validation' => true,
                'captcha' => false
            ]
        ]);
    }
    
    private function handleValidateRequest($input) {
        $requestData = $input['request_data'] ?? [];
        $requestType = $input['request_type'] ?? 'assessment';
        
        // Validate through security middleware
        $securityValidation = $this->securityMiddleware->validateRequest($requestData, $requestType);
        
        if (!$securityValidation['valid']) {
            return $this->sendError(400, 'SECURITY_VALIDATION_FAILED', $securityValidation['message']);
        }
        
        // Check rate limiting if email is provided
        if (isset($requestData['email']) || isset($requestData['clientEmail'])) {
            $email = $requestData['email'] ?? $requestData['clientEmail'];
            $clientData = [
                'email' => $email,
                'name' => $requestData['name'] ?? $requestData['clientName'] ?? '',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ];
            
            $spamCheck = $this->antiSpamManager->isRequestAllowed($clientData, $requestData, $requestType);
            
            if (!$spamCheck['allowed']) {
                return $this->sendError(
                    429, 
                    'RATE_LIMIT_EXCEEDED', 
                    $spamCheck['message'],
                    ['retry_after' => $spamCheck['retry_after'] ?? 3600]
                );
            }
        }
        
        return $this->sendSuccess([
            'message' => 'Request validation passed',
            'client_data' => $requestData,
            'security_check' => 'passed',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    private function handleGetSecurityToken($input) {
        $token = $this->generateSecurityToken();
        
        return $this->sendSuccess([
            'token' => $token,
            'expires_in' => 3600, // 1 hour
            'message' => 'Security token generated'
        ]);
    }
    
    private function handleCheckRateLimit($input) {
        if (!isset($input['email'])) {
            return $this->sendError(400, 'MISSING_EMAIL', 'Email parameter is required');
        }
        
        $clientData = [
            'email' => $input['email'],
            'name' => $input['name'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        $requestData = $input['request_data'] ?? [];
        $requestType = $input['request_type'] ?? 'assessment';
        
        $result = $this->antiSpamManager->isRequestAllowed($clientData, $requestData, $requestType);
        
        return $this->sendSuccess([
            'allowed' => $result['allowed'],
            'message' => $result['message'],
            'remaining_requests' => $result['remaining_requests'] ?? 0,
            'retry_after' => $result['retry_after'] ?? null
        ]);
    }
    
    private function handleGetSecurityStats($input) {
        // Verify admin token
        $token = $input['admin_token'] ?? '';
        if (!$this->isValidAdminToken($token)) {
            return $this->sendError(403, 'INVALID_TOKEN', 'Invalid admin token');
        }
        
        $antiSpamStats = $this->antiSpamManager->getSecurityStats();
        
        return $this->sendSuccess([
            'anti_spam_stats' => $antiSpamStats,
            'security_features' => [
                'rate_limiting' => '3 requests/hour, 15/day',
                'bot_detection' => 'Honeypot fields, timing analysis',
                'input_validation' => 'XSS and SQL injection prevention',
                'captcha' => 'Disabled for better UX'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    private function generateSecurityToken() {
        return hash('sha256', uniqid('security_', true) . time() . random_bytes(16));
    }
    
    private function isValidAdminToken($token) {
        // In production, use proper token validation
        return $token === 'admin-secret-token-change-this';
    }
    
    // Helper methods for response formatting
    private function sendSuccess($data = null) {
        $response = [
            'success' => true,
            'timestamp' => date('c'),
            'data' => $data
        ];
        
        echo json_encode($response);
        return true;
    }
    
    private function sendError($httpCode, $errorCode, $message, $additionalData = []) {
        http_response_code($httpCode);
        
        $response = [
            'success' => false,
            'timestamp' => date('c'),
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'http_code' => $httpCode
            ] + $additionalData
        ];
        
        echo json_encode($response);
        return false;
    }
}

// Initialize and handle the request
$handler = new SecureRequestHandler();
$handler->handleRequest();
?>