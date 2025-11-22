<?php
/**
 * Admin Dashboard API
 * Handles all admin dashboard functionality
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../auth/auth.php';

// Ensure PHP warnings/notices don't get printed into JSON responses
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');

// Initialize a shared DB instance for functions that use `global $db`
$db = Database::getInstance();

// Development mode check - disable auth for localhost development
$isDevelopment = (
    $_SERVER['SERVER_NAME'] === 'localhost' || 
    $_SERVER['SERVER_ADDR'] === '127.0.0.1' ||
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false
);

// Require admin authentication (disabled in development)
if (!$isDevelopment) {
    requireAuth('admin');
} else {
    // In development mode, ensure session is started for compatibility
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Set a mock admin user for development
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 'dev_admin';
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_name'] = 'Development Admin';
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

/**
 * Activate user (set status to 'active')
 */
function activateUser($userId) {
    $db = Database::getInstance();
    global $auth;
    if (!$userId) {
        return ['success' => false, 'message' => 'User ID is required'];
    }
    $user = $db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }
    // If already active, treat as success (idempotent)
    if (isset($user['status']) && strtolower($user['status']) === 'active') {
        return ['success' => true, 'message' => 'User already active'];
    }

    $result = $db->query("UPDATE users SET status = 'active' WHERE id = ?", [$userId]);
    if ($result->rowCount() > 0) {
        $currentUser = $auth->getCurrentUser();
        $auth->logActivity($currentUser['id'], 'activate_user', 'users', $userId, $user, null);
        return ['success' => true, 'message' => 'User activated successfully'];
    }
    // Double-check: if DB reports 0 but status is now active, still success
    $check = $db->fetch("SELECT status FROM users WHERE id = ?", [$userId]);
    if ($check && strtolower($check['status']) === 'active') {
        return ['success' => true, 'message' => 'User activated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to activate user'];
}

try {
    switch ($action) {
        case 'dashboard_stats':
            echo json_encode(getDashboardStats());
            break;
            
        case 'recent_activities':
            echo json_encode(getRecentActivities());
            break;
            
        case 'assessment_requests':
            if ($method === 'GET') {
                echo json_encode(getAssessmentRequests());
            } elseif ($method === 'PUT') {
                $input = json_decode(file_get_contents('php://input'), true);
                echo json_encode(updateAssessmentRequest($input));
            }
            break;
            
        case 'users':
            if ($method === 'GET') {
                echo json_encode(getUsers());
            } elseif ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                error_log("Create user input: " . print_r($input, true)); // Debug logging
                echo json_encode(createUser($input));
            } elseif ($method === 'PUT') {
                $input = json_decode(file_get_contents('php://input'), true);
                if (isset($input['action'])) {
                    if ($input['action'] === 'suspend') {
                        echo json_encode(suspendUser($input['id']));
                    } elseif ($input['action'] === 'activate') {
                        echo json_encode(activateUser($input['id']));
                    } elseif ($input['action'] === 'restore') {
                        // Restore archived/inactive users by activating them
                        echo json_encode(activateUser($input['id']));
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Unknown user action']);
                    }
                } else {
                    error_log("EditUser: Processing request - " . json_encode($input));
                    $result = editUser($input);
                    error_log("EditUser: Result - " . json_encode($result));
                    echo json_encode($result);
                }
            } elseif ($method === 'DELETE') {
                $input = json_decode(file_get_contents('php://input'), true);
                echo json_encode(deleteUser($input['id']));
            }
            break;
            
        case 'appointments':
            if ($method === 'GET') {
                echo json_encode(getAppointments());
            } elseif ($method === 'POST') {
                $input = json_decode(file_get_contents('php://input'), true);
                echo json_encode(createAppointment($input));
            } elseif ($method === 'PUT') {
                $input = json_decode(file_get_contents('php://input'), true);
                echo json_encode(updateAppointment($input));
            }
            break;
            
        case 'system_settings':
            if ($method === 'GET') {
                echo json_encode(getSystemSettings());
            } elseif ($method === 'PUT') {
                $input = json_decode(file_get_contents('php://input'), true);
                echo json_encode(updateSystemSettings($input));
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Admin API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

/**
 * Get dashboard statistics
 */
function getDashboardStats() {
    global $db;
    
    $stats = [];

    // Helper to safely run COUNT queries and return 0 on failure
    $safeCount = function(string $sql, array $params = []) use ($db): int {
        try {
            $row = $db->fetch($sql, $params);
            return isset($row['count']) ? (int)$row['count'] : 0;
        } catch (Exception $e) {
            error_log('Dashboard stats query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return 0;
        }
    };

    // Total requests
    $stats['total_requests'] = $safeCount("SELECT COUNT(*) as count FROM assessment_requests");
    // Pending requests
    $stats['pending_requests'] = $safeCount("SELECT COUNT(*) as count FROM assessment_requests WHERE status = 'pending'");
    // Total users (all roles)
    $stats['total_users'] = $safeCount("SELECT COUNT(*) as count FROM users");
    // Active users (all roles)
    $stats['active_users'] = $safeCount("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
    // Active staff (staff and head only)
    $stats['active_staff'] = $safeCount("SELECT COUNT(*) as count FROM users WHERE role IN ('staff', 'head') AND status = 'active'");
    // Today's appointments
    $stats['todays_appointments'] = $safeCount("SELECT COUNT(*) as count FROM appointments WHERE appointment_date = CURDATE()");
    // Monthly completed assessments
    $stats['monthly_completed'] = $safeCount("SELECT COUNT(*) as count FROM assessment_requests WHERE status = 'completed' AND MONTH(completed_at) = MONTH(CURRENT_DATE())");

    return ['success' => true, 'data' => $stats];
}

/**
 * Get recent activities - filtered to show only internal staff activities
 */
function getRecentActivities() {
    global $db;
    
    // Only get activities from users with admin, staff, or head roles
    // Exclude client activities from the system activity log
    $activities = $db->fetchAll("
        SELECT al.*, u.first_name, u.last_name, u.username, u.role 
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.role IN ('admin', 'staff', 'head') 
           OR (al.user_id IS NULL AND al.action IN ('system_startup', 'system_maintenance', 'automated_task'))
        ORDER BY al.created_at DESC
        LIMIT 25
    ");
    
    return ['success' => true, 'data' => $activities];
}

/**
 * Get assessment requests
 */
function getAssessmentRequests() {
    global $db;
    
    $requests = $db->fetchAll("
        SELECT ar.*, 
               CONCAT(s.first_name, ' ', s.last_name) as assigned_staff_name,
               s.username as staff_username
        FROM assessment_requests ar
        LEFT JOIN users s ON ar.assigned_staff_id = s.id
        ORDER BY ar.created_at DESC
    ");
    
    return ['success' => true, 'data' => $requests];
}

/**
 * Update assessment request
 */
function updateAssessmentRequest($data) {
    global $db, $auth;
    
    $requiredFields = ['id', 'status'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            return ['success' => false, 'message' => "Missing required field: $field"];
        }
    }
    
    $allowedStatuses = ['pending', 'approved', 'scheduled', 'in_progress', 'completed', 'cancelled'];
    if (!in_array($data['status'], $allowedStatuses)) {
        return ['success' => false, 'message' => 'Invalid status'];
    }
    
    // Get old values for logging
    $oldValues = $db->fetch("SELECT * FROM assessment_requests WHERE id = ?", [$data['id']]);
    
    $updateFields = ['status = ?'];
    $params = [$data['status']];
    
    if (isset($data['assigned_staff_id'])) {
        $updateFields[] = 'assigned_staff_id = ?';
        $params[] = $data['assigned_staff_id'];
    }
    
    if (isset($data['scheduled_date'])) {
        $updateFields[] = 'scheduled_date = ?';
        $params[] = $data['scheduled_date'];
    }
    
    if (isset($data['notes'])) {
        $updateFields[] = 'notes = ?';
        $params[] = $data['notes'];
    }
    
    if ($data['status'] === 'completed') {
        $updateFields[] = 'completed_at = NOW()';
    }
    
    $params[] = $data['id'];
    
    $result = $db->query(
        "UPDATE assessment_requests SET " . implode(', ', $updateFields) . " WHERE id = ?",
        $params
    );
    
    if ($result->rowCount() > 0) {
        // Log the activity
        $currentUser = $auth->getCurrentUser();
        $auth->logActivity($currentUser['id'], 'update_assessment_request', 'assessment_requests', $data['id'], $oldValues, $data);
        
        return ['success' => true, 'message' => 'Assessment request updated successfully'];
    } else {
        return ['success' => false, 'message' => 'No changes made or request not found'];
    }
}

/**
 * Get users
 */
function getUsers() {
    $db = Database::getInstance();
    try {
        $users = $db->fetchAll("
            SELECT id, username, email, role, first_name, last_name, phone, status, 
                   created_at, last_login
            FROM users
            ORDER BY created_at DESC
        ");
        return ['success' => true, 'data' => $users];
    } catch (Exception $e) {
        error_log('getUsers error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to fetch users: ' . $e->getMessage()];
    }
}

/**
 * Create new user
 */
function createUser($data) {
    global $auth;
    
    try {
        $db = Database::getInstance();
        
        error_log("CreateUser called with data: " . print_r($data, true)); // Debug
        
        $requiredFields = ['username', 'email', 'password', 'role', 'first_name', 'last_name'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                error_log("Missing field: $field");
                return ['success' => false, 'message' => "Missing required field: $field"];
            }
        }
    
    // Validate role
    $validRoles = ['admin', 'head', 'staff'];
    if (!in_array($data['role'], $validRoles)) {
        return ['success' => false, 'message' => 'Invalid role'];
    }
    
    // Check if username or email already exists
    $existing = $db->fetch("SELECT id FROM users WHERE username = ? OR email = ?", [$data['username'], $data['email']]);
    if ($existing) {
        return ['success' => false, 'message' => 'Username or email already exists'];
    }
    
    // Hash password
    $passwordHash = AuthSystem::hashPassword($data['password']);
    
    $result = $db->query("
        INSERT INTO users (username, email, password_hash, role, first_name, last_name, phone, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
    ", [
        $data['username'],
        $data['email'],
        $passwordHash,
        $data['role'],
        $data['first_name'],
        $data['last_name'],
        $data['phone'] ?? null
    ]);
    
    if ($result->rowCount() > 0) {
        $userId = $db->lastInsertId();
        
        // Log the activity
        $currentUser = $auth->getCurrentUser();
        $auth->logActivity($currentUser['id'], 'create_user', 'users', $userId, null, $data);
        
        return ['success' => true, 'message' => 'User created successfully', 'user_id' => $userId];
    } else {
        return ['success' => false, 'message' => 'Failed to create user'];
    }
    
    } catch (Exception $e) {
        error_log("CreateUser error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}


/**
 * Suspend user (set status to 'suspended')
 */
function suspendUser($userId) {
    $db = Database::getInstance();
    global $auth;
    if (!$userId) {
        return ['success' => false, 'message' => 'User ID is required'];
    }
    $user = $db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }
    $result = $db->query("UPDATE users SET status = 'suspended' WHERE id = ?", [$userId]);
    if ($result->rowCount() > 0) {
        $currentUser = $auth->getCurrentUser();
        $auth->logActivity($currentUser['id'], 'suspend_user', 'users', $userId, $user, null);
        return ['success' => true, 'message' => 'User suspended successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to suspend user'];
    }
}

/**
 * Edit user details
 */
function editUser($data) {
    $db = Database::getInstance();
    global $auth;
    
    error_log("EditUser: Starting with data - " . json_encode($data));
    
    if (!isset($data['id'])) {
        error_log("EditUser: Missing ID");
        return ['success' => false, 'message' => 'User ID is required'];
    }
    
    $oldValues = $db->fetch("SELECT * FROM users WHERE id = ?", [$data['id']]);
    if (!$oldValues) {
        error_log("EditUser: User not found with ID " . $data['id']);
        return ['success' => false, 'message' => 'User not found'];
    }
    
    error_log("EditUser: Found user - " . $oldValues['email']);
    
    $updateFields = [];
    $params = [];
    $allowedFields = ['username', 'email', 'role', 'first_name', 'last_name', 'phone', 'status'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateFields[] = "$field = ?";
            $params[] = $data[$field];
            error_log("EditUser: Will update $field to " . $data[$field]);
        }
    }
    
    if (isset($data['password']) && !empty($data['password'])) {
        // Verify current password if provided
        if (isset($data['currentPassword'])) {
            if (!password_verify($data['currentPassword'], $oldValues['password_hash'])) {
                error_log("EditUser: Current password verification failed");
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            error_log("EditUser: Current password verified successfully");
        }
        
        $updateFields[] = "password_hash = ?";
        $params[] = AuthSystem::hashPassword($data['password']);
        error_log("EditUser: Will update password");
    }
    
    if (empty($updateFields)) {
        error_log("EditUser: No fields to update");
        return ['success' => false, 'message' => 'No fields to update'];
    }
    
    $params[] = $data['id'];
    $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
    error_log("EditUser: SQL - " . $sql);
    error_log("EditUser: Params - " . json_encode($params));
    
    $result = $db->query($sql, $params);
    $rowCount = $result->rowCount();
    
    error_log("EditUser: Rows affected - " . $rowCount);
    
    if ($rowCount > 0) {
        $currentUser = $auth->getCurrentUser();
        $auth->logActivity($currentUser['id'], 'edit_user', 'users', $data['id'], $oldValues, $data);
        error_log("EditUser: Success - user updated");
        return ['success' => true, 'message' => 'User updated successfully'];
    } else {
        error_log("EditUser: No changes made");
        return ['success' => false, 'message' => 'No changes made or user not found'];
    }
}

/**
 * Delete user
 */
function deleteUser($userId) {
    global $db, $auth;
    
    if (!$userId) {
        return ['success' => false, 'message' => 'User ID is required'];
    }
    
    // Get user data for logging
    $user = $db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }
    
    // Don't allow deleting the current user
    $currentUser = $auth->getCurrentUser();
    if ($currentUser['id'] == $userId) {
        return ['success' => false, 'message' => 'Cannot delete your own account'];
    }
    
    // Soft delete by setting status to inactive
    $result = $db->query("UPDATE users SET status = 'inactive' WHERE id = ?", [$userId]);
    
    if ($result->rowCount() > 0) {
        // Log the activity
        $auth->logActivity($currentUser['id'], 'delete_user', 'users', $userId, $user, null);
        
        return ['success' => true, 'message' => 'User deactivated successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to deactivate user'];
    }
}

/**
 * Get appointments
 */
function getAppointments() {
    global $db;
    
    $appointments = $db->fetchAll("
        SELECT a.*, 
               ar.client_name, ar.location, ar.property_classification,
               CONCAT(s.first_name, ' ', s.last_name) as staff_name,
               CONCAT(c.first_name, ' ', c.last_name) as created_by_name
        FROM appointments a
        JOIN assessment_requests ar ON a.request_id = ar.id
        JOIN users s ON a.staff_id = s.id
        JOIN users c ON a.created_by = c.id
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
    ");
    
    return ['success' => true, 'data' => $appointments];
}

/**
 * Create appointment
 */
function createAppointment($data) {
    global $db, $auth;
    
    $requiredFields = ['request_id', 'staff_id', 'appointment_date', 'appointment_time'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            return ['success' => false, 'message' => "Missing required field: $field"];
        }
    }
    
    $currentUser = $auth->getCurrentUser();
    
    $result = $db->query("
        INSERT INTO appointments (request_id, staff_id, appointment_date, appointment_time, 
                                duration_minutes, location, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ", [
        $data['request_id'],
        $data['staff_id'],
        $data['appointment_date'],
        $data['appointment_time'],
        $data['duration_minutes'] ?? 120,
        $data['location'] ?? '',
        $data['notes'] ?? '',
        $currentUser['id']
    ]);
    
    if ($result->rowCount() > 0) {
        $appointmentId = $db->lastInsertId();
        
        // Update assessment request status
        $db->query(
            "UPDATE assessment_requests SET status = 'scheduled', scheduled_date = ?, assigned_staff_id = ? WHERE id = ?",
            [$data['appointment_date'], $data['staff_id'], $data['request_id']]
        );
        
        // Log the activity
        $auth->logActivity($currentUser['id'], 'create_appointment', 'appointments', $appointmentId, null, $data);
        
        return ['success' => true, 'message' => 'Appointment created successfully', 'appointment_id' => $appointmentId];
    } else {
        return ['success' => false, 'message' => 'Failed to create appointment'];
    }
}

/**
 * Update appointment
 */
function updateAppointment($data) {
    global $db, $auth;
    
    if (!isset($data['id'])) {
        return ['success' => false, 'message' => 'Appointment ID is required'];
    }
    
    // Get old values for logging
    $oldValues = $db->fetch("SELECT * FROM appointments WHERE id = ?", [$data['id']]);
    
    $updateFields = [];
    $params = [];
    
    $allowedFields = ['appointment_date', 'appointment_time', 'duration_minutes', 'status', 'notes'];
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateFields[] = "$field = ?";
            $params[] = $data[$field];
        }
    }
    
    if (empty($updateFields)) {
        return ['success' => false, 'message' => 'No fields to update'];
    }
    
    $params[] = $data['id'];
    
    $result = $db->query(
        "UPDATE appointments SET " . implode(', ', $updateFields) . " WHERE id = ?",
        $params
    );
    
    if ($result->rowCount() > 0) {
        // Log the activity
        $currentUser = $auth->getCurrentUser();
        $auth->logActivity($currentUser['id'], 'update_appointment', 'appointments', $data['id'], $oldValues, $data);
        
        return ['success' => true, 'message' => 'Appointment updated successfully'];
    } else {
        return ['success' => false, 'message' => 'No changes made or appointment not found'];
    }
}

/**
 * Get system settings
 */
function getSystemSettings() {
    global $db;
    
    $settings = $db->fetchAll("SELECT * FROM system_settings ORDER BY setting_key");
    
    return ['success' => true, 'data' => $settings];
}

/**
 * Update system settings
 */
function updateSystemSettings($data) {
    global $db, $auth;
    
    if (!isset($data['settings']) || !is_array($data['settings'])) {
        return ['success' => false, 'message' => 'Settings data is required'];
    }
    
    $currentUser = $auth->getCurrentUser();
    $updatedCount = 0;
    
    foreach ($data['settings'] as $setting) {
        if (!isset($setting['setting_key']) || !isset($setting['setting_value'])) {
            continue;
        }
        
        $result = $db->query(
            "UPDATE system_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?",
            [$setting['setting_value'], $currentUser['id'], $setting['setting_key']]
        );
        
        if ($result->rowCount() > 0) {
            $updatedCount++;
        }
    }
    
    if ($updatedCount > 0) {
        // Log the activity
        $auth->logActivity($currentUser['id'], 'update_system_settings', 'system_settings', null, null, $data);
        
        return ['success' => true, 'message' => "$updatedCount settings updated successfully"];
    } else {
        return ['success' => false, 'message' => 'No settings were updated'];
    }
}
