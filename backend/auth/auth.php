<?php
/**
 * Authentication System for AssessPro
 * Handles login, logout, session management, and user roles
 */

require_once __DIR__ . '/../config/database.php';

class AuthSystem {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Start secure session
     */
    public function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            // Configure session security
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
            ini_set('session.use_strict_mode', 1);
            // Ensure cookie path and SameSite so it's available across the app and during redirects
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } else {
                // Fallback for older PHP (shouldn't be needed on PHP 8.2)
                session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
            }
            
            session_start();
            
            // Regenerate session ID periodically
            if (!isset($_SESSION['last_regeneration'])) {
                $_SESSION['last_regeneration'] = time();
            } elseif (time() - $_SESSION['last_regeneration'] > 300) { // 5 minutes
                session_regenerate_id(true);
                $_SESSION['last_regeneration'] = time();
            }
        }
    }
    
    /**
     * Authenticate user login
     */
    public function login($username, $password, $skipSuccessLogging = false) {
        try {
            // Check if user is currently locked out
            $lockoutCheck = $this->checkLoginLockout($username);
            if ($lockoutCheck['locked']) {
                return [
                    'success' => false, 
                    'message' => $lockoutCheck['message'],
                    'locked_until' => $lockoutCheck['locked_until'],
                    'retry_after' => $lockoutCheck['retry_after']
                ];
            }
            
            // Get user from database
            $user = $this->db->fetch(
                "SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'", 
                [$username, $username]
            );
            
            if (!$user) {
                // Log failed login attempt (user not found)
                $this->recordFailedLoginAttempt($username, 'User not found');
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                // Log failed login attempt (wrong password)
                $this->recordFailedLoginAttempt($username, 'Invalid password', $user['id']);
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Successful login - clear any previous failed attempts
            $this->clearFailedLoginAttempts($username);
            
            // Start session and set user data
            $this->startSession();
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['authenticated'] = true;
            $_SESSION['login_time'] = time();
            
            // Update last login time
            $this->db->query(
                "UPDATE users SET last_login = NOW() WHERE id = ?", 
                [$user['id']]
            );
            
            // Only log successful login if not skipping (to avoid duplication during role checks)
            if (!$skipSuccessLogging) {
                $this->logActivity($user['id'], 'login_success', 'users', $user['id']);
            }
            
            return [
                'success' => true, 
                'message' => 'Login successful',
                'role' => $user['role'],
                'user_id' => $user['id'],
                'redirect' => $this->getRedirectUrl($user['role'])
            ];
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            // Log system error login failure
            $this->logFailedLoginAttempt($username, 'System error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Login system error. Please try again.'];
        }
    }
    
    /**
     * Check if user is authenticated
     */
    public function isAuthenticated() {
        $this->startSession();
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }
    
    /**
     * Check if user has required role
     */
    public function hasRole($requiredRole) {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        $userRole = $_SESSION['role'];
        
        // Role hierarchy: admin > head > staff
        $roleHierarchy = ['admin' => 3, 'head' => 2, 'staff' => 1];
        
        return isset($roleHierarchy[$userRole]) && 
               isset($roleHierarchy[$requiredRole]) && 
               $roleHierarchy[$userRole] >= $roleHierarchy[$requiredRole];
    }
    
    /**
     * Get current user information
     */
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'full_name' => $_SESSION['full_name']
        ];
    }
    
    /**
     * Logout user
     */
    public function logout() {
        $this->startSession();
        
        if (isset($_SESSION['user_id'])) {
            // Log the logout activity
            $this->logActivity($_SESSION['user_id'], 'logout', 'users', $_SESSION['user_id']);
        }
        
        // Clear all session data
        $_SESSION = [];
        
        // Delete the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Destroy the session
        session_destroy();
        
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
    
    /**
     * Require authentication - redirect if not authenticated
     */
    public function requireAuth($requiredRole = null) {
        if (!$this->isAuthenticated()) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                // AJAX request
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Authentication required']);
                exit;
            } else {
                // Regular request
                header('Location: ../index.html?login_required=1');
                exit;
            }
        }
        
        if ($requiredRole && !$this->hasRole($requiredRole)) {
            http_response_code(403);
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
            } else {
                echo "Access denied. Insufficient permissions.";
            }
            exit;
        }
    }
    
    /**
     * Get redirect URL based on user role
     */
    private function getRedirectUrl($role) {
        switch ($role) {
            case 'admin':
                return 'admin/admin.html';
            case 'head':
                return 'head/head.html';
            case 'staff':
                return 'staff/staff-dashboard.html';
            default:
                return 'index.html';
        }
    }
    
    /**
     * Log user activity
     */
    public function logActivity($userId, $action, $tableName = null, $recordId = null, $oldValues = null, $newValues = null) {
        try {
            $this->db->query(
                "INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $action,
                    $tableName,
                    $recordId,
                    $oldValues ? json_encode($oldValues) : null,
                    $newValues ? json_encode($newValues) : null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]
            );
        } catch (Exception $e) {
            error_log("Activity logging error: " . $e->getMessage());
        }
    }
    
    /**
     * Check if user is currently locked out from login attempts
     */
    private function checkLoginLockout($username) {
        try {
            $lockoutDuration = 180; // 3 minutes in seconds
            $maxAttempts = 3;
            
            // Check current failed attempts within lockout period
            $failedAttempts = $this->db->fetch(
                "SELECT COUNT(*) as attempt_count, MAX(created_at) as last_attempt, UNIX_TIMESTAMP(MAX(created_at)) as last_timestamp 
                 FROM activity_logs 
                 WHERE action = 'login_failed_tracked' 
                 AND old_values LIKE ? 
                 AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
                ['%"username":"' . $username . '"%', $lockoutDuration]
            );
            
            if ($failedAttempts && $failedAttempts['attempt_count'] >= $maxAttempts) {
                $lastAttemptTime = $failedAttempts['last_timestamp'];
                $lockoutEndTime = $lastAttemptTime + $lockoutDuration;
                $currentTime = time();
                
                if ($currentTime < $lockoutEndTime) {
                    $remainingTime = $lockoutEndTime - $currentTime;
                    $minutes = floor($remainingTime / 60);
                    $seconds = $remainingTime % 60;
                    
                    return [
                        'locked' => true,
                        'message' => "Too many failed login attempts. Please wait {$minutes} minute(s) and {$seconds} second(s) before trying again.",
                        'locked_until' => date('Y-m-d H:i:s', $lockoutEndTime),
                        'retry_after' => $remainingTime
                    ];
                }
            }
            
            return ['locked' => false];
            
        } catch (Exception $e) {
            error_log("Login lockout check error: " . $e->getMessage());
            return ['locked' => false]; // Fail open for availability
        }
    }
    
    /**
     * Record a failed login attempt for violation tracking
     */
    private function recordFailedLoginAttempt($username, $reason, $userId = null) {
        try {
            // Record for violation tracking
            $this->db->query(
                "INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    'login_failed_tracked',
                    'users',
                    $userId,
                    json_encode(['username' => $username, 'reason' => $reason, 'attempt_time' => date('Y-m-d H:i:s')]),
                    null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]
            );
            
        } catch (Exception $e) {
            error_log("Failed login attempt recording error: " . $e->getMessage());
        }
    }
    
    /**
     * Clear failed login attempts after successful login
     */
    private function clearFailedLoginAttempts($username) {
        try {
            // Mark old failed attempts as cleared (don't delete for audit trail)
            $this->db->query(
                "UPDATE activity_logs 
                 SET new_values = JSON_SET(COALESCE(new_values, '{}'), '$.cleared', 'success_login', '$.cleared_at', NOW()) 
                 WHERE action = 'login_failed_tracked' 
                 AND old_values LIKE ? 
                 AND new_values IS NULL",
                ['%"username":"' . $username . '"%']
            );
            
        } catch (Exception $e) {
            error_log("Clear failed attempts error: " . $e->getMessage());
        }
    }
    
    /**
     * Log failed login attempts (basic logging for compatibility with existing activity_logs)
     */
    public function logFailedLoginAttempt($username, $reason, $userId = null) {
        try {
            $this->db->query(
                "INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    'login_failed',
                    'users',
                    $userId,
                    json_encode(['username' => $username, 'reason' => $reason]),
                    null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]
            );
        } catch (Exception $e) {
            error_log("Failed login logging error: " . $e->getMessage());
        }
    }
    
    /**
     * Hash password securely
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCSRFToken() {
        $this->startSession();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF token
     */
    public function verifyCSRFToken($token) {
        $this->startSession();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// Global authentication instance
$auth = new AuthSystem();

/**
 * Helper functions
 */
function requireAuth($role = null) {
    global $auth;
    $auth->requireAuth($role);
}

function getCurrentUser() {
    global $auth;
    return $auth->getCurrentUser();
}

function hasRole($role) {
    global $auth;
    return $auth->hasRole($role);
}

function isAuthenticated() {
    global $auth;
    return $auth->isAuthenticated();
}
