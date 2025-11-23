<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

try {
    // Database connection - Environment aware
    require_once __DIR__ . '/../config/db_config.php';
    
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USERNAME;
    $password = DB_PASSWORD;

    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get all scheduled inspections
    $stmt = $pdo->prepare("
        SELECT 
            id,
            barangay,
            inspection_date,
            request_count,
            notes,
            status,
            created_at,
            updated_at
        FROM scheduled_inspections 
        ORDER BY inspection_date ASC
    ");
    
    $stmt->execute();
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the data for frontend consumption
    $formatted_inspections = [];
    foreach ($inspections as $inspection) {
        $formatted_inspections[] = [
            'id' => $inspection['id'],
            'date' => $inspection['inspection_date'],
            'barangay' => $inspection['barangay'],
            'requestCount' => $inspection['request_count'],
            'note' => $inspection['notes'],
            'status' => $inspection['status'],
            'created_at' => $inspection['created_at'],
            'updated_at' => $inspection['updated_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $formatted_inspections,
        'count' => count($formatted_inspections)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>