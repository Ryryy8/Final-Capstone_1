<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'error' => 'Only POST method allowed'
    ]);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }

    // Validate required fields
    $requestId = $input['request_id'] ?? null;
    $status = $input['status'] ?? null;
    $declineReason = $input['decline_reason'] ?? null;

    if (!$requestId || !$status) {
        throw new Exception('Missing required fields: request_id and status');
    }

    // Validate status values
    $allowedStatuses = ['pending', 'accepted', 'declined'];
    if (!in_array($status, $allowedStatuses)) {
        throw new Exception('Invalid status. Allowed values: ' . implode(', ', $allowedStatuses));
    }

    // Database connection - Environment aware
    require_once __DIR__ . '/../config/db_config.php';
    
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USERNAME;
    $password = DB_PASSWORD;

    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Update request status
    $sql = "UPDATE assessment_requests SET status = :status, updated_at = NOW()";
    $params = [
        ':status' => $status,
        ':request_id' => $requestId
    ];

    // Add decline reason if provided
    if ($status === 'declined' && $declineReason) {
        $sql .= ", decline_reason = :decline_reason";
        $params[':decline_reason'] = $declineReason;
    }

    $sql .= " WHERE id = :request_id";

    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute($params);

    if (!$success) {
        throw new Exception('Failed to update request status');
    }

    // Check if any rows were affected
    if ($stmt->rowCount() === 0) {
        throw new Exception('Request not found or no changes made');
    }

    // Fetch the updated request
    $stmt = $pdo->prepare("SELECT * FROM assessment_requests WHERE id = :request_id");
    $stmt->execute([':request_id' => $requestId]);
    $updatedRequest = $stmt->fetch(PDO::FETCH_ASSOC);

    // Send email notification if request was accepted
    if ($status === 'accepted' && $updatedRequest) {
        try {
            require_once __DIR__ . '/../email/EmailNotification.php';
            $emailNotification = new EmailNotification();
            
            // Send acceptance notification email
            $emailSuccess = $emailNotification->sendRequestAcceptedNotification($updatedRequest);
            
            if ($emailSuccess) {
                error_log("Acceptance notification sent successfully for request ID: " . $requestId);
            } else {
                error_log("Failed to send acceptance notification for request ID: " . $requestId);
            }
        } catch (Exception $emailError) {
            // Don't fail the status update if email fails, just log the error
            error_log("Email notification error for request ID {$requestId}: " . $emailError->getMessage());
        }
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Request status updated successfully',
        'data' => $updatedRequest,
        'email_sent' => isset($emailSuccess) ? $emailSuccess : false
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>