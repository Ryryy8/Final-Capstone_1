<?php
// Backend API for managing blocked dates
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
require_once 'database_connection.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                getBlockedDates($pdo);
            } elseif ($action === 'check') {
                checkDateBlocked($pdo);
            } else {
                throw new Exception('Invalid action for GET request');
            }
            break;
            
        case 'POST':
            if ($action === 'add') {
                addBlockedDate($pdo);
            } else {
                throw new Exception('Invalid action for POST request');
            }
            break;
            
        case 'DELETE':
            if ($action === 'remove') {
                removeBlockedDate($pdo);
            } else {
                throw new Exception('Invalid action for DELETE request');
            }
            break;
            
        default:
            throw new Exception('Unsupported HTTP method');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

// Get all blocked dates
function getBlockedDates($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, date, reason, created_by, created_at 
            FROM blocked_dates 
            WHERE date >= CURDATE() 
            ORDER BY date ASC
        ");
        $stmt->execute();
        $blockedDates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $blockedDates
        ]);
    } catch (PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

// Check if a specific date is blocked
function checkDateBlocked($pdo) {
    $date = $_GET['date'] ?? '';
    
    if (empty($date)) {
        throw new Exception('Date parameter is required');
    }
    
    try {
        $sql = "SELECT id, reason FROM blocked_dates WHERE date = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date]);
        $blocked = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'is_blocked' => !empty($blocked),
            'block_info' => $blocked ?: null
        ]);
    } catch (PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

// Add a new blocked date
function addBlockedDate($pdo) {
    $rawInput = file_get_contents('php://input');
    error_log('Raw input: ' . $rawInput);
    
    $input = json_decode($rawInput, true);
    error_log('Decoded input: ' . print_r($input, true));
    
    if ($input === null) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }
    
    $required = ['date', 'reason'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Field '$field' is required. Received: " . print_r($input, true));
        }
    }
    
    // Validate date format
    if (!strtotime($input['date'])) {
        throw new Exception('Invalid date format');
    }
    
    try {
        // Check if date is already blocked
        $checkStmt = $pdo->prepare("SELECT id, reason FROM blocked_dates WHERE date = ?");
        $checkStmt->execute([$input['date']]);
        $existing = $checkStmt->fetch();
        
        if ($existing) {
            throw new Exception('Date ' . $input['date'] . ' is already blocked with reason: ' . $existing['reason']);
        }
        
        // Insert new blocked date
        $stmt = $pdo->prepare("
            INSERT INTO blocked_dates (date, reason, created_by, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        
        $createdBy = $input['created_by'] ?? 'Staff';
        $stmt->execute([
            $input['date'],
            $input['reason'],
            $createdBy
        ]);
        
        $blockedId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Date blocked successfully',
            'blocked_id' => $blockedId
        ]);
    } catch (PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}

// Remove a blocked date
function removeBlockedDate($pdo) {
    $blockedId = $_GET['id'] ?? '';
    
    if (empty($blockedId)) {
        throw new Exception('Blocked date ID is required');
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM blocked_dates WHERE id = ?");
        $stmt->execute([$blockedId]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Blocked date not found');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Blocked date removed successfully'
        ]);
    } catch (PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}
?>