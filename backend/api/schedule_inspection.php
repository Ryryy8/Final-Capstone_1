<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }

    $pdo = new PDO("mysql:host=localhost;dbname=assesspro_db", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    $requestId = $input['request_id'];
    $inspectionDate = $input['inspection_date'];
    $barangay = $input['barangay'];
    
    if (!$requestId || !$inspectionDate || !$barangay) {
        throw new Exception('Missing required fields: request_id, inspection_date, barangay');
    }

    // Check if scheduled_inspections table exists, create if not
    $tableExists = $pdo->query("SHOW TABLES LIKE 'scheduled_inspections'")->fetch();
    
    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE scheduled_inspections (
                id INT PRIMARY KEY AUTO_INCREMENT,
                barangay VARCHAR(100) NOT NULL,
                inspection_date DATE NOT NULL,
                request_count INT DEFAULT 1,
                notes TEXT,
                status ENUM('scheduled', 'completed', 'cancelled') DEFAULT 'scheduled',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
    }

    // Insert the scheduled inspection
    $stmt = $pdo->prepare("
        INSERT INTO scheduled_inspections 
        (barangay, inspection_date, request_count, notes, status) 
        VALUES (?, ?, 1, ?, 'scheduled')
    ");
    
    $notes = "Individual inspection request for " . ($input['inspection_category'] ?? 'Unknown') . " category - Request ID: " . $requestId;
    $stmt->execute([$barangay, $inspectionDate, $notes]);

    // Update the assessment request with scheduled date
    $updateStmt = $pdo->prepare("
        UPDATE assessment_requests 
        SET status = 'scheduled' 
        WHERE id = ?
    ");
    $updateStmt->execute([$requestId]);

    echo json_encode([
        'success' => true,
        'message' => 'Inspection scheduled successfully',
        'inspection_id' => $pdo->lastInsertId(),
        'date' => $inspectionDate,
        'barangay' => $barangay
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>