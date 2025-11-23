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

    // Check if a specific request ID is requested
    $requestId = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if ($requestId) {
        // Fetch single request by ID
        $stmt = $pdo->prepare("
            SELECT 
                id,
                name,
                email,
                inspection_category,
                requested_inspection_date,
                property_classification,
                location,
                landmark,
                land_reference_arp,
                contact_person,
                contact_number,
                purpose,
                valid_id_name,
                status,
                decline_reason,
                created_at,
                updated_at
            FROM assessment_requests 
            WHERE id = ?
        ");
        
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$request) {
            throw new Exception('Request not found');
        }
        
        $requests = $request; // Return single request object
    } else {
        // Fetch all assessment requests
        $stmt = $pdo->prepare("
            SELECT 
                id,
                name,
                email,
                inspection_category,
                requested_inspection_date,
                property_classification,
                location,
                landmark,
                land_reference_arp as land_reference,
                contact_person,
                contact_number,
                purpose,
                valid_id_name,
                status,
                decline_reason,
                created_at,
                updated_at
            FROM assessment_requests 
            ORDER BY created_at DESC
        ");
        
        $stmt->execute();
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $requests,
        'count' => $requestId ? 1 : count($requests)
    ]);

} catch (PDOException $e) {
    // Return error response
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // Return general error response
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>