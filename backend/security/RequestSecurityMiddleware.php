<?php

require_once __DIR__ . '/AntiSpamManager.php';

class RequestSecurityMiddleware {
    private $antiSpam;
    private $trustedIPs;
    private $honeypotFields;
    
    public function __construct() {
        $this->antiSpam = new AntiSpamManager();
        $this->trustedIPs = [
            '127.0.0.1',
            '::1',
            // Add your admin/office IP addresses here
        ];
        
        // Honeypot fields that should remain empty
        $this->honeypotFields = ['website', 'url', 'company_name', 'fax'];
    }
    
    /**
     * Main security validation for incoming requests
     */
    public function validateRequest($requestData, $requestType = 'assessment') {
        try {
            // Step 1: Basic security checks
            $basicSecurityCheck = $this->performBasicSecurityChecks($requestData);
            if (!$basicSecurityCheck['valid']) {
                return $basicSecurityCheck;
            }
            
            // Step 2: Extract and validate client data
            $clientData = $this->extractClientData($requestData);
            if (!$clientData) {
                return [
                    'valid' => false,
                    'error' => 'INVALID_CLIENT_DATA',
                    'message' => 'Invalid client information provided.'
                ];
            }
            
            // Step 3: Check anti-spam rules
            $spamCheck = $this->antiSpam->isRequestAllowed($clientData, $requestData, $requestType);
            if (!$spamCheck['allowed']) {
                return [
                    'valid' => false,
                    'error' => $spamCheck['reason'],
                    'message' => $spamCheck['message'],
                    'retry_after' => $spamCheck['retry_after'] ?? null
                ];
            }
            
            // Step 4: Additional security validations
            $advancedCheck = $this->performAdvancedSecurityChecks($requestData, $clientData);
            if (!$advancedCheck['valid']) {
                return $advancedCheck;
            }
            
            return [
                'valid' => true,
                'message' => 'Request validation passed',
                'client_data' => $clientData
            ];
            
        } catch (Exception $e) {
            error_log("Security validation error: " . $e->getMessage());
            return [
                'valid' => false,
                'error' => 'SECURITY_ERROR',
                'message' => 'Security validation failed. Please try again.'
            ];
        }
    }
    
    private function performBasicSecurityChecks($requestData) {
        // Check for honeypot fields (bot detection)
        foreach ($this->honeypotFields as $field) {
            if (!empty($requestData[$field])) {
                $this->logSuspiciousActivity('HONEYPOT_TRIGGERED', "Field '$field' was filled: " . $requestData[$field]);
                return [
                    'valid' => false,
                    'error' => 'BOT_DETECTED',
                    'message' => 'Automated submission detected. Please try again.'
                ];
            }
        }
        
        // Check request timing (too fast = bot)
        if (isset($requestData['form_start_time'])) {
            $formStartTime = (int)$requestData['form_start_time'];
            $currentTime = time();
            $fillTime = $currentTime - $formStartTime;
            
            // If form was filled in less than 10 seconds, likely a bot
            if ($fillTime < 10) {
                $this->logSuspiciousActivity('FAST_SUBMISSION', "Form filled in {$fillTime} seconds");
                return [
                    'valid' => false,
                    'error' => 'SUBMISSION_TOO_FAST',
                    'message' => 'Please take your time filling out the form.'
                ];
            }
        }
        
        // Check for suspicious patterns in text fields
        $suspiciousPatterns = [
            '/\b(script|javascript|onload|onclick|onerror)\b/i',
            '/(<script|<iframe|<object|<embed)/i',
            '/(union|select|insert|update|delete|drop)\s+(all|from|table|database)/i',
            '/\b(viagra|casino|poker|lottery|pills|medication)\b/i'
        ];
        
        foreach ($requestData as $field => $value) {
            if (is_string($value)) {
                foreach ($suspiciousPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $this->logSuspiciousActivity('SUSPICIOUS_CONTENT', "Suspicious pattern in field '$field'");
                        return [
                            'valid' => false,
                            'error' => 'SUSPICIOUS_CONTENT',
                            'message' => 'Invalid content detected in submission.'
                        ];
                    }
                }
            }
        }
        
        // Check for excessively long inputs (potential buffer overflow attempts)
        foreach ($requestData as $field => $value) {
            if (is_string($value) && strlen($value) > 2000) {
                $this->logSuspiciousActivity('OVERSIZED_INPUT', "Field '$field' exceeds size limit: " . strlen($value) . " characters");
                return [
                    'valid' => false,
                    'error' => 'INPUT_TOO_LONG',
                    'message' => 'Input data exceeds maximum allowed length.'
                ];
            }
        }
        
        return ['valid' => true];
    }
    
    private function extractClientData($requestData) {
        $clientData = [];
        
        // Extract client information from different possible field names
        $emailFields = ['email', 'client_email', 'applicant_email', 'contact_email', 'clientEmail'];
        $nameFields = ['name', 'full_name', 'applicant_name', 'client_name', 'first_name', 'clientName'];
        $phoneFields = ['phone', 'contact_number', 'mobile', 'phone_number', 'contactNumber'];
        
        // Get email
        foreach ($emailFields as $field) {
            if (!empty($requestData[$field])) {
                $clientData['email'] = strtolower(trim($requestData[$field]));
                break;
            }
        }
        
        // Get name
        foreach ($nameFields as $field) {
            if (!empty($requestData[$field])) {
                $clientData['name'] = trim($requestData[$field]);
                if (empty($clientData['name']) && !empty($requestData['last_name'])) {
                    $clientData['name'] .= ' ' . trim($requestData['last_name']);
                }
                break;
            }
        }
        
        // Get phone
        foreach ($phoneFields as $field) {
            if (!empty($requestData[$field])) {
                $clientData['phone'] = preg_replace('/\D/', '', $requestData[$field]);
                break;
            }
        }
        
        // Validate required fields
        if (empty($clientData['email']) || !filter_var($clientData['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        if (empty($clientData['name']) || strlen($clientData['name']) < 2) {
            return false;
        }
        
        return $clientData;
    }
    
    private function performAdvancedSecurityChecks($requestData, $clientData) {
        // Check for disposable email addresses
        if ($this->isDisposableEmail($clientData['email'])) {
            $this->logSuspiciousActivity('DISPOSABLE_EMAIL', $clientData['email']);
            return [
                'valid' => false,
                'error' => 'DISPOSABLE_EMAIL',
                'message' => 'Please use a valid, permanent email address.'
            ];
        }
        
        // Check for suspicious email patterns
        if ($this->isSuspiciousEmail($clientData['email'])) {
            $this->logSuspiciousActivity('SUSPICIOUS_EMAIL', $clientData['email']);
            return [
                'valid' => false,
                'error' => 'SUSPICIOUS_EMAIL',
                'message' => 'Email address appears to be invalid or suspicious.'
            ];
        }
        
        // Check for name patterns that suggest fake entries
        if ($this->isSuspiciousName($clientData['name'])) {
            $this->logSuspiciousActivity('SUSPICIOUS_NAME', $clientData['name']);
            return [
                'valid' => false,
                'error' => 'SUSPICIOUS_NAME',
                'message' => 'Please provide your real name.'
            ];
        }
        
        // Check IP reputation
        $ipCheck = $this->checkIPReputation();
        if (!$ipCheck['valid']) {
            return $ipCheck;
        }
        
        // Check for geographical inconsistencies
        $geoCheck = $this->checkGeographicalConsistency($requestData);
        if (!$geoCheck['valid']) {
            return $geoCheck;
        }
        
        return ['valid' => true];
    }
    
    private function isDisposableEmail($email) {
        $disposableDomains = [
            '10minutemail.com', 'tempmail.org', 'guerrillamail.com', 'mailinator.com',
            'throwawaymails.com', '33mail.com', 'fakemailgenerator.com', 'yopmail.com',
            'tempail.com', 'getnada.com', 'tempr.email', 'maildrop.cc'
        ];
        
        $domain = substr(strrchr($email, '@'), 1);
        return in_array(strtolower($domain), $disposableDomains);
    }
    
    private function isSuspiciousEmail($email) {
        // Check for suspicious patterns in email
        $suspiciousPatterns = [
            '/\d{8,}/', // Too many consecutive digits
            '/(.)\1{4,}/', // Repeated characters
            '/^[a-z]{1,2}@/', // Very short local part
            '/\+.*\+/', // Multiple plus signs
            '/[^a-z0-9@.\-_]/' // Invalid characters
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, strtolower($email))) {
                return true;
            }
        }
        
        return false;
    }
    
    private function isSuspiciousName($name) {
        $name = strtolower(trim($name));
        
        // Check for obviously fake names
        $fakeNamePatterns = [
            '/^(test|dummy|fake|admin|user)\s*\d*$/i',
            '/^[a-z]{1,2}\s+[a-z]{1,2}$/i', // Very short names
            '/\d{4,}/', // Contains many numbers
            '/(.)\1{3,}/', // Repeated characters
            '/^[^a-z\s]+$/', // No letters except spaces
            '/\b(qwerty|asdf|zxcv|abcd|1234)\b/i'
        ];
        
        foreach ($fakeNamePatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function checkIPReputation() {
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Skip check for trusted IPs
        if (in_array($clientIP, $this->trustedIPs)) {
            return ['valid' => true];
        }
        
        // Check for private/local IPs
        if (filter_var($clientIP, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            // Private IP detected
            return ['valid' => true]; // Allow private IPs for development
        }
        
        // Check for known malicious IPs (simple blacklist)
        $blacklistedIPs = $this->getBlacklistedIPs();
        if (in_array($clientIP, $blacklistedIPs)) {
            $this->logSuspiciousActivity('BLACKLISTED_IP', $clientIP);
            return [
                'valid' => false,
                'error' => 'BLACKLISTED_IP',
                'message' => 'Access denied from this location.'
            ];
        }
        
        return ['valid' => true];
    }
    
    private function getBlacklistedIPs() {
        // In production, this should come from a database or external service
        return [
            // Add known malicious IPs here
        ];
    }
    
    private function checkGeographicalConsistency($requestData) {
        // Check if the provided barangay/location data is consistent
        $providedBarangay = $requestData['barangay'] ?? '';
        $propertyAddress = $requestData['property_address'] ?? '';
        
        // List of valid barangays for Mabini, Batangas (complete list from form)
        $validBarangays = [
            'Barangay Poblacion',
            'Barangay Anilao Proper',
            'Barangay Anilao East',
            'Barangay Bagalangit',
            'Barangay Bulacan',
            'Barangay Calamias',
            'Barangay Estrella',
            'Barangay Gasang',
            'Barangay Laurel',
            'Barangay Ligaya',
            'Barangay Mainaga',
            'Barangay Mainit',
            'Barangay Majuben',
            'Barangay Malimatoc-1',
            'Barangay Malimatoc-2',
            'Barangay Nag-Iba',
            'Barangay Pilahan',
            'Barangay P. Anahao',
            'Barangay P. Balibaguhan',
            'Barangay P. Lupa',
            'Barangay P. Niogan',
            'Barangay Saguing',
            'Barangay Sampaguita',
            'Barangay San Francisco',
            'Barangay San Jose',
            'Barangay San Juan',
            'Barangay San Teodoro',
            'Barangay Solo',
            'Barangay Sta. Ana',
            'Barangay Sta. Mesa',
            'Barangay Sto. Niño',
            'Barangay Sto. Tomas',
            'Barangay Talaga Proper',
            'Barangay Talaga East'
        ];
        
        if (!empty($providedBarangay) && !in_array($providedBarangay, $validBarangays)) {
            $this->logSuspiciousActivity('INVALID_BARANGAY', $providedBarangay);
            return [
                'valid' => false,
                'error' => 'INVALID_LOCATION',
                'message' => 'Invalid barangay specified. Please select a valid location.'
            ];
        }
        
        return ['valid' => true];
    }
    
    private function logSuspiciousActivity($type, $details) {
        // Validate parameters
        $type = $type ?? 'UNKNOWN';
        $details = $details ?? 'No details provided';
        
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'details' => $details,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'unknown'
        ];
        
        error_log("SECURITY_ALERT: " . json_encode($logData));
        
        // Also log to security file
        $securityLogFile = __DIR__ . '/security.log';
        file_put_contents($securityLogFile, json_encode($logData) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Generate a secure token for form validation
     */
    public function generateSecureToken($formId = 'default') {
        // Ensure formId has a default value
        $formId = $formId ?? 'default';
        
        $tokenData = [
            'form_id' => $formId,
            'timestamp' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent_hash' => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')
        ];
        
        $token = base64_encode(json_encode($tokenData));
        $signature = hash_hmac('sha256', $token, $this->getSecretKey());
        
        return $token . '.' . $signature;
    }
    
    /**
     * Validate a secure token
     */
    public function validateSecureToken($token, $maxAge = 3600) {
        // Ensure parameters have default values
        $maxAge = $maxAge ?? 3600;
        
        if (empty($token)) {
            return false;
        }
        
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        
        list($tokenData, $signature) = $parts;
        
        // Verify signature
        $expectedSignature = hash_hmac('sha256', $tokenData, $this->getSecretKey());
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }
        
        // Decode and validate token data
        $data = json_decode(base64_decode($tokenData), true);
        if (!$data) {
            return false;
        }
        
        // Check token age
        if ((time() - ($data['timestamp'] ?? 0)) > $maxAge) {
            return false;
        }
        
        // Verify IP and user agent for extra security
        $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentUserAgentHash = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        
        if ($data['ip'] !== $currentIP || $data['user_agent_hash'] !== $currentUserAgentHash) {
            $this->logSuspiciousActivity('TOKEN_MISMATCH', 'IP or User Agent mismatch');
            return false;
        }
        
        return true;
    }
    
    private function getSecretKey() {
        // In production, store this in environment variables or secure config
        return 'your-secret-key-change-this-in-production-' . date('Y-m-d');
    }
    
    /**
     * Get client fingerprint for additional tracking
     */
    public function getClientFingerprint() {
        $data = [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
        ];
        
        return hash('sha256', json_encode($data));
    }
}
?>