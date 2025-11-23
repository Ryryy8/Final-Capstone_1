<?php
/**
 * Serve Valid ID files from database
 * This endpoint retrieves file data stored in the database and serves it as the original file type
 */

try {
    // Get request ID from URL parameter
    $requestId = isset($_GET['id']) ? intval($_GET['id']) : null;
    
    if (!$requestId) {
        http_response_code(400);
        die('Request ID is required');
    }

    // Database connection - Environment aware
    require_once __DIR__ . '/../config/db_config.php';
    
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USERNAME;
    $password = DB_PASSWORD;

    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch file data from database
    $stmt = $pdo->prepare("SELECT valid_id_data, valid_id_type, valid_id_name 
                          FROM assessment_requests 
                          WHERE id = ? AND valid_id_data IS NOT NULL");
    $stmt->execute([$requestId]);
    $fileData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fileData) {
        http_response_code(404);
        die('File not found');
    }

    // Decode the base64 data
    $fileContent = base64_decode($fileData['valid_id_data']);
    if ($fileContent === false) {
        http_response_code(500);
        die('Failed to decode file data');
    }

    // Set appropriate headers
    header('Content-Type: ' . $fileData['valid_id_type']);
    header('Content-Length: ' . strlen($fileContent));
    header('Content-Disposition: inline; filename="' . $fileData['valid_id_name'] . '"');
    header('Cache-Control: private, max-age=3600');
    
    // Prevent XSS for HTML content
    if (strpos($fileData['valid_id_type'], 'text/html') !== false) {
        header('X-Content-Type-Options: nosniff');
    }

    // Output the file content
    echo $fileContent;

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database error in view_valid_id.php: " . $e->getMessage());
    die('Database error occurred');
} catch (Exception $e) {
    http_response_code(500);
    error_log("Error in view_valid_id.php: " . $e->getMessage());
    die('Error occurred while retrieving file');
}
?>