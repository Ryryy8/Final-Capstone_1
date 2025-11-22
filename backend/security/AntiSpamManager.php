<?php

class AntiSpamManager {
    private $db;
    private $rateLimitFile;
    private $duplicateCheckFile;
    
    // Rate limiting constants
    const MAX_REQUESTS_PER_HOUR = 3;        // Maximum 3 requests per hour per client
    const MAX_REQUESTS_PER_DAY = 15;        // Maximum 15 requests per day per client
    const MAX_DUPLICATE_ATTEMPTS = 3;        // Max duplicate requests allowed
    const COOLDOWN_PERIOD = 3600;           // 1 hour cooldown after rate limit hit
    const DUPLICATE_DETECTION_WINDOW = 1800; // 30 minutes window for duplicate detection
    
    public function __construct() {
        $this->initializeDatabase();
        $this->rateLimitFile = __DIR__ . '/rate_limits.json';
        $this->duplicateCheckFile = __DIR__ . '/duplicate_checks.json';
        $this->initializeFiles();
    }
    
    private function initializeDatabase() {
        try {
            $host = 'localhost';
            $dbname = 'assesspro_db';
            $username = 'root';
            $password = '';
            
            $this->db = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create spam protection tables
            $this->createSpamProtectionTables();
        } catch (PDOException $e) {
            error_log("AntiSpam Database Error: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    private function createSpamProtectionTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS request_rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_identifier VARCHAR(255) NOT NULL,
            request_type VARCHAR(100) NOT NULL,
            request_count INT DEFAULT 1,
            first_request_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_request_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_blocked BOOLEAN DEFAULT FALSE,
            blocked_until DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client_type (client_identifier, request_type),
            INDEX idx_blocked (is_blocked),
            INDEX idx_time (last_request_time)
        );
        
        CREATE TABLE IF NOT EXISTS duplicate_request_checks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_identifier VARCHAR(255) NOT NULL,
            request_hash VARCHAR(255) NOT NULL,
            request_data_hash VARCHAR(255) NOT NULL,
            attempt_count INT DEFAULT 1,
            first_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_flagged BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client_hash (client_identifier, request_hash),
            INDEX idx_flagged (is_flagged),
            INDEX idx_time (last_attempt)
        );
        
        CREATE TABLE IF NOT EXISTS security_violations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_identifier VARCHAR(255) NOT NULL,
            violation_type ENUM('RATE_LIMIT', 'DUPLICATE_SPAM', 'SUSPICIOUS_ACTIVITY', 'BLOCKED_REQUEST') NOT NULL,
            severity ENUM('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client (client_identifier),
            INDEX idx_violation_type (violation_type),
            INDEX idx_severity (severity),
            INDEX idx_time (created_at)
        );
        ";
        
        $this->db->exec($sql);
    }
    
    private function initializeFiles() {
        if (!file_exists($this->rateLimitFile)) {
            file_put_contents($this->rateLimitFile, json_encode([]));
        }
        if (!file_exists($this->duplicateCheckFile)) {
            file_put_contents($this->duplicateCheckFile, json_encode([]));
        }
    }
    
    /**
     * Check if a request is allowed based on anti-spam rules
     */
    public function isRequestAllowed($clientData, $requestData, $requestType = 'assessment') {
        $clientIdentifier = $this->generateClientIdentifier($clientData);
        
        // Check if client is currently blocked
        if ($this->isClientBlocked($clientIdentifier)) {
            $this->logSecurityViolation($clientIdentifier, 'BLOCKED_REQUEST', 'HIGH', 
                'Attempted request while blocked');
            return [
                'allowed' => false,
                'reason' => 'CLIENT_BLOCKED',
                'message' => 'Your account has been temporarily blocked due to suspicious activity. Please try again later.',
                'retry_after' => $this->getBlockedUntilTime($clientIdentifier)
            ];
        }
        
        // Check rate limits
        $rateLimitCheck = $this->checkRateLimit($clientIdentifier, $requestType);
        if (!$rateLimitCheck['allowed']) {
            return $rateLimitCheck;
        }
        
        // Check for duplicate requests
        $duplicateCheck = $this->checkDuplicateRequest($clientIdentifier, $requestData);
        if (!$duplicateCheck['allowed']) {
            return $duplicateCheck;
        }
        
        // Check for suspicious patterns
        $suspiciousCheck = $this->checkSuspiciousActivity($clientIdentifier, $requestData);
        if (!$suspiciousCheck['allowed']) {
            return $suspiciousCheck;
        }
        
        // Record the successful request
        $this->recordRequest($clientIdentifier, $requestType, $requestData);
        
        return [
            'allowed' => true,
            'message' => 'Request approved'
        ];
    }
    
    private function generateClientIdentifier($clientData) {
        // Generate unique identifier based on email + phone + name
        $identifiers = [
            strtolower(trim($clientData['email'] ?? '')),
            preg_replace('/\D/', '', $clientData['phone'] ?? ''),
            strtolower(trim($clientData['name'] ?? ''))
        ];
        
        return hash('sha256', implode('|', $identifiers));
    }
    
    private function isClientBlocked($clientIdentifier) {
        $stmt = $this->db->prepare(
            "SELECT blocked_until FROM request_rate_limits 
             WHERE client_identifier = ? AND is_blocked = TRUE AND blocked_until > NOW()"
        );
        $stmt->execute([$clientIdentifier]);
        return $stmt->rowCount() > 0;
    }
    
    private function getBlockedUntilTime($clientIdentifier) {
        $stmt = $this->db->prepare(
            "SELECT blocked_until FROM request_rate_limits 
             WHERE client_identifier = ? AND is_blocked = TRUE"
        );
        $stmt->execute([$clientIdentifier]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['blocked_until'] : null;
    }
    
    private function checkRateLimit($clientIdentifier, $requestType) {
        $now = new DateTime();
        $hourAgo = (clone $now)->modify('-1 hour');
        $dayAgo = (clone $now)->modify('-24 hours');
        
        // Check hourly limit
        $hourlyCount = $this->getRequestCount($clientIdentifier, $requestType, $hourAgo);
        if ($hourlyCount >= self::MAX_REQUESTS_PER_HOUR) {
            $this->logSecurityViolation($clientIdentifier, 'RATE_LIMIT', 'MEDIUM', 
                "Exceeded hourly limit: $hourlyCount requests");
            
            return [
                'allowed' => false,
                'reason' => 'HOURLY_RATE_LIMIT',
                'message' => 'Too many requests. You can submit up to ' . self::MAX_REQUESTS_PER_HOUR . ' requests per hour.',
                'retry_after' => $this->calculateRetryAfter($clientIdentifier, 'hourly')
            ];
        }
        
        // Check daily limit
        $dailyCount = $this->getRequestCount($clientIdentifier, $requestType, $dayAgo);
        if ($dailyCount >= self::MAX_REQUESTS_PER_DAY) {
            // Block client for the rest of the day
            $this->blockClient($clientIdentifier, 'daily_limit_exceeded');
            
            $this->logSecurityViolation($clientIdentifier, 'RATE_LIMIT', 'HIGH', 
                "Exceeded daily limit: $dailyCount requests");
            
            return [
                'allowed' => false,
                'reason' => 'DAILY_RATE_LIMIT',
                'message' => 'Daily request limit exceeded. You can submit up to ' . self::MAX_REQUESTS_PER_DAY . ' requests per day.',
                'retry_after' => $now->modify('+1 day')->format('Y-m-d H:i:s')
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function getRequestCount($clientIdentifier, $requestType, $since) {
        $stmt = $this->db->prepare(
            "SELECT request_count FROM request_rate_limits 
             WHERE client_identifier = ? AND request_type = ? AND last_request_time >= ?"
        );
        $stmt->execute([$clientIdentifier, $requestType, $since->format('Y-m-d H:i:s')]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['request_count'] : 0;
    }
    
    private function checkDuplicateRequest($clientIdentifier, $requestData) {
        $requestHash = $this->generateRequestHash($requestData);
        $window = (new DateTime())->modify('-' . self::DUPLICATE_DETECTION_WINDOW . ' seconds');
        
        $stmt = $this->db->prepare(
            "SELECT attempt_count FROM duplicate_request_checks 
             WHERE client_identifier = ? AND request_hash = ? AND last_attempt >= ?"
        );
        $stmt->execute([$clientIdentifier, $requestHash, $window->format('Y-m-d H:i:s')]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $attemptCount = (int)$result['attempt_count'];
            if ($attemptCount >= self::MAX_DUPLICATE_ATTEMPTS) {
                // Flag as suspicious and temporarily block
                $this->flagDuplicateSpam($clientIdentifier, $requestHash);
                
                $this->logSecurityViolation($clientIdentifier, 'DUPLICATE_SPAM', 'HIGH', 
                    "Duplicate request attempts: $attemptCount");
                
                return [
                    'allowed' => false,
                    'reason' => 'DUPLICATE_REQUEST',
                    'message' => 'Duplicate request detected. Please wait before submitting similar requests.',
                    'retry_after' => (new DateTime())->modify('+30 minutes')->format('Y-m-d H:i:s')
                ];
            }
            
            // Update attempt count
            $this->updateDuplicateAttempt($clientIdentifier, $requestHash);
        }
        
        return ['allowed' => true];
    }
    
    private function generateRequestHash($requestData) {
        // Create hash based on key request properties
        $hashData = [
            'property_type' => $requestData['property_type'] ?? '',
            'property_address' => strtolower(trim($requestData['property_address'] ?? '')),
            'assessment_type' => $requestData['assessment_type'] ?? '',
            'barangay' => $requestData['barangay'] ?? ''
        ];
        
        return hash('sha256', json_encode($hashData));
    }
    
    private function checkSuspiciousActivity($clientIdentifier, $requestData) {
        // Check for rapid-fire requests (multiple requests within 5 minutes)
        $recentRequests = $this->getRecentRequestCount($clientIdentifier, 5);
        if ($recentRequests >= 3) {
            $this->logSecurityViolation($clientIdentifier, 'SUSPICIOUS_ACTIVITY', 'MEDIUM', 
                "Rapid requests: $recentRequests in 5 minutes");
            
            return [
                'allowed' => false,
                'reason' => 'SUSPICIOUS_ACTIVITY',
                'message' => 'Too many requests in a short time. Please wait a few minutes before trying again.',
                'retry_after' => (new DateTime())->modify('+5 minutes')->format('Y-m-d H:i:s')
            ];
        }
        
        return ['allowed' => true];
    }
    
    private function getRecentRequestCount($clientIdentifier, $minutes) {
        $since = (new DateTime())->modify("-{$minutes} minutes");
        $stmt = $this->db->prepare(
            "SELECT SUM(request_count) as total FROM request_rate_limits 
             WHERE client_identifier = ? AND last_request_time >= ?"
        );
        $stmt->execute([$clientIdentifier, $since->format('Y-m-d H:i:s')]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['total'] : 0;
    }
    
    private function recordRequest($clientIdentifier, $requestType, $requestData) {
        $requestHash = $this->generateRequestHash($requestData);
        
        // Update rate limit tracking
        $stmt = $this->db->prepare(
            "INSERT INTO request_rate_limits (client_identifier, request_type, request_count, last_request_time)
             VALUES (?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE 
             request_count = request_count + 1, 
             last_request_time = NOW()"
        );
        $stmt->execute([$clientIdentifier, $requestType]);
        
        // Record duplicate check
        $stmt = $this->db->prepare(
            "INSERT INTO duplicate_request_checks (client_identifier, request_hash, request_data_hash, last_attempt)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE 
             attempt_count = attempt_count + 1, 
             last_attempt = NOW()"
        );
        $stmt->execute([$clientIdentifier, $requestHash, hash('sha256', json_encode($requestData))]);
    }
    
    private function blockClient($clientIdentifier, $reason) {
        $blockedUntil = (new DateTime())->modify('+' . self::COOLDOWN_PERIOD . ' seconds');
        
        $stmt = $this->db->prepare(
            "UPDATE request_rate_limits 
             SET is_blocked = TRUE, blocked_until = ?
             WHERE client_identifier = ?"
        );
        $stmt->execute([$blockedUntil->format('Y-m-d H:i:s'), $clientIdentifier]);
    }
    
    private function flagDuplicateSpam($clientIdentifier, $requestHash) {
        $stmt = $this->db->prepare(
            "UPDATE duplicate_request_checks 
             SET is_flagged = TRUE 
             WHERE client_identifier = ? AND request_hash = ?"
        );
        $stmt->execute([$clientIdentifier, $requestHash]);
    }
    
    private function updateDuplicateAttempt($clientIdentifier, $requestHash) {
        $stmt = $this->db->prepare(
            "UPDATE duplicate_request_checks 
             SET attempt_count = attempt_count + 1, last_attempt = NOW()
             WHERE client_identifier = ? AND request_hash = ?"
        );
        $stmt->execute([$clientIdentifier, $requestHash]);
    }
    
    private function calculateRetryAfter($clientIdentifier, $type) {
        $now = new DateTime();
        switch ($type) {
            case 'hourly':
                return $now->modify('+1 hour')->format('Y-m-d H:i:s');
            case 'daily':
                return $now->modify('+1 day')->format('Y-m-d H:i:s');
            default:
                return $now->modify('+30 minutes')->format('Y-m-d H:i:s');
        }
    }
    
    private function logSecurityViolation($clientIdentifier, $violationType, $severity, $details) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $this->db->prepare(
            "INSERT INTO security_violations (client_identifier, violation_type, severity, details, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$clientIdentifier, $violationType, $severity, $details, $ipAddress, $userAgent]);
    }
    
    /**
     * Get security statistics for monitoring
     */
    public function getSecurityStats() {
        $stats = [];
        
        // Rate limit violations in last 24 hours
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM security_violations 
             WHERE violation_type = 'RATE_LIMIT' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->execute();
        $stats['rate_limit_violations_24h'] = $stmt->fetchColumn();
        
        // Duplicate spam attempts in last 24 hours
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM security_violations 
             WHERE violation_type = 'DUPLICATE_SPAM' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->execute();
        $stats['duplicate_spam_24h'] = $stmt->fetchColumn();
        
        // Currently blocked clients
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT client_identifier) as count FROM request_rate_limits 
             WHERE is_blocked = TRUE AND blocked_until > NOW()"
        );
        $stmt->execute();
        $stats['blocked_clients'] = $stmt->fetchColumn();
        
        return $stats;
    }
    
    /**
     * Clean up old records (run periodically)
     */
    public function cleanup() {
        $oldDate = (new DateTime())->modify('-7 days');
        
        // Clean old rate limit records
        $stmt = $this->db->prepare(
            "DELETE FROM request_rate_limits WHERE last_request_time < ? AND is_blocked = FALSE"
        );
        $stmt->execute([$oldDate->format('Y-m-d H:i:s')]);
        
        // Clean old duplicate checks
        $stmt = $this->db->prepare(
            "DELETE FROM duplicate_request_checks WHERE last_attempt < ?"
        );
        $stmt->execute([$oldDate->format('Y-m-d H:i:s')]);
        
        // Clean old security violations (keep for 30 days)
        $veryOldDate = (new DateTime())->modify('-30 days');
        $stmt = $this->db->prepare(
            "DELETE FROM security_violations WHERE created_at < ?"
        );
        $stmt->execute([$veryOldDate->format('Y-m-d H:i:s')]);
    }
    
    /**
     * Unblock a client (admin function)
     */
    public function unblockClient($clientEmail) {
        $clientIdentifier = $this->generateClientIdentifier(['email' => $clientEmail]);
        
        $stmt = $this->db->prepare(
            "UPDATE request_rate_limits 
             SET is_blocked = FALSE, blocked_until = NULL 
             WHERE client_identifier = ?"
        );
        $stmt->execute([$clientIdentifier]);
        
        return $stmt->rowCount() > 0;
    }
}
?>