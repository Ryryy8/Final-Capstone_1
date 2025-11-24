<?php
/**
 * Session Security Validation
 * Prevents unauthorized access via URL copying
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set session timeout (30 minutes)
$session_timeout = 1800; // 30 minutes in seconds

// Function to validate session security
function validateSessionSecurity() {
    global $session_timeout;
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        redirectToLogin('No valid session found');
        return false;
    }
    
    // Check session timeout
    if (isset($_SESSION['last_activity'])) {
        $inactive_time = time() - $_SESSION['last_activity'];
        if ($inactive_time > $session_timeout) {
            session_destroy();
            redirectToLogin('Session expired due to inactivity');
            return false;
        }
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    // Check if session has required security tokens
    if (!isset($_SESSION['session_token']) || !isset($_SESSION['user_agent'])) {
        redirectToLogin('Invalid session security tokens');
        return false;
    }
    
    // Validate user agent (basic device fingerprinting)
    $current_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($_SESSION['user_agent'] !== $current_user_agent) {
        session_destroy();
        redirectToLogin('Session security violation - device mismatch detected');
        return false;
    }
    
    // Enhanced tab detection - check for concurrent access
    $current_time = time();
    $tab_check_window = 5; // 5 seconds window
    
    if (!isset($_SESSION['last_access_time'])) {
        $_SESSION['last_access_time'] = $current_time;
        $_SESSION['access_count'] = 1;
    } else {
        $time_diff = $current_time - $_SESSION['last_access_time'];
        
        // If multiple rapid requests from same session (different tabs)
        if ($time_diff < $tab_check_window) {
            $_SESSION['access_count'] = ($_SESSION['access_count'] ?? 0) + 1;
            
            // More than 3 rapid accesses suggests multiple tabs
            if ($_SESSION['access_count'] > 3) {
                session_destroy();
                redirectToLogin('Session security violation - multiple tab access detected');
                return false;
            }
        } else {
            // Reset counter if enough time passed
            $_SESSION['access_count'] = 1;
        }
        
        $_SESSION['last_access_time'] = $current_time;
    }
    
    // Check IP address if stored (optional - can be problematic with dynamic IPs)
    if (isset($_SESSION['ip_address'])) {
        $current_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        // Allow session but log if IP changed (don't block - could be legitimate)
        if ($_SESSION['ip_address'] !== $current_ip) {
            error_log("IP address changed for user {$_SESSION['username']}: {$_SESSION['ip_address']} -> {$current_ip}");
            // Update to new IP but keep session alive
            $_SESSION['ip_address'] = $current_ip;
        }
    }
    
    return true;
}

// Function to redirect to login with message
function redirectToLogin($reason = 'Authentication required') {
    // Log the security event
    error_log("Session security redirect: $reason - " . ($_SESSION['username'] ?? 'unknown user'));
    
    // Clear any existing session
    session_destroy();
    
    // Redirect to main page with login modal
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $base_url = "$protocol://$host" . dirname(dirname($_SERVER['SCRIPT_NAME']));
    
    // Use JavaScript redirect to show proper login modal
    echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Session Expired - AssessPro</title>
    <style>
        body { 
            font-family: 'Poppins', Arial, sans-serif; 
            text-align: center; 
            padding: 2rem; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            background: rgba(255,255,255,0.1);
            padding: 2rem;
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body>
    <div class='container'>
        <h2>🔒 Session Security</h2>
        <p>$reason</p>
        <p>Redirecting to login...</p>
    </div>
    <script>
        setTimeout(function() {
            window.location.href = '$base_url/index.html';
        }, 2000);
    </script>
</body>
</html>";
    exit();
}

// Validate session when this file is included
validateSessionSecurity();
?>