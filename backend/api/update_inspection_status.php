<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

try {
    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Only POST method allowed');
    }

    // Database connection
    $host = 'localhost';
    $dbname = 'assesspro_db';
    $username = 'root';
    $password = '';

    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        throw new Exception('Invalid JSON input');
    }

    // Validate required fields
    if (!isset($input['inspection_id']) || !isset($input['status'])) {
        http_response_code(400);
        throw new Exception('inspection_id and status are required');
    }

    $inspectionId = intval($input['inspection_id']);
    $status = trim($input['status']);
    
    // Validate status values
    $validStatuses = ['scheduled', 'in_progress', 'completed', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        http_response_code(400);
        throw new Exception('Invalid status. Valid values: ' . implode(', ', $validStatuses));
    }

    // Update inspection status
    $stmt = $pdo->prepare("
        UPDATE scheduled_inspections 
        SET status = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    
    $result = $stmt->execute([$status, $inspectionId]);
    
    if ($result && $stmt->rowCount() > 0) {
        // Get the updated inspection details
        $stmt = $pdo->prepare("
            SELECT id, barangay, inspection_date, request_count, notes, status, updated_at
            FROM scheduled_inspections 
            WHERE id = ?
        ");
        $stmt->execute([$inspectionId]);
        $inspection = $stmt->fetch(PDO::FETCH_ASSOC);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => "Inspection status updated to '{$status}' successfully",
            'data' => $inspection
        ]);
    } else {
        throw new Exception('Inspection not found or no changes made');
    }

} catch (PDOException $e) {
    error_log("Database error in update_inspection_status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error in update_inspection_status.php: " . $e->getMessage());
    if (!http_response_code()) {
        http_response_code(400);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>