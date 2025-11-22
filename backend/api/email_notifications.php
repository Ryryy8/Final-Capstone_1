<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include the email notification class
require_once '../email/EmailNotification.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing action parameter']);
    exit;
}

try {
    $emailNotification = new EmailNotification();
    $response = ['success' => false, 'message' => 'Unknown action'];

    switch ($input['action']) {
        case 'send_confirmation':
            // Required fields for confirmation email
            $required = ['client_email', 'client_name', 'request_id', 'property_details'];
            foreach ($required as $field) {
                if (!isset($input[$field])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                    exit;
                }
            }

            // Validate email format
            if (!filter_var($input['client_email'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                exit;
            }

            $success = $emailNotification->sendRequestConfirmation(
                $input['client_email'],
                $input['client_name'],
                $input['request_id'],
                $input['property_details']
            );

            if ($success) {
                $response = [
                    'success' => true, 
                    'message' => 'Confirmation email sent successfully',
                    'request_id' => $input['request_id']
                ];
            } else {
                http_response_code(500);
                $response = [
                    'success' => false, 
                    'message' => 'Failed to send confirmation email'
                ];
            }
            break;

        case 'send_status_update':
            // Required fields for status update
            $required = ['client_email', 'client_name', 'request_id', 'status'];
            foreach ($required as $field) {
                if (!isset($input[$field])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                    exit;
                }
            }

            // Validate email format
            if (!filter_var($input['client_email'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                exit;
            }

            $comments = isset($input['comments']) ? $input['comments'] : '';

            $success = $emailNotification->sendStatusUpdate(
                $input['client_email'],
                $input['client_name'],
                $input['request_id'],
                $input['status'],
                $comments
            );

            if ($success) {
                $response = [
                    'success' => true, 
                    'message' => 'Status update email sent successfully',
                    'request_id' => $input['request_id'],
                    'status' => $input['status']
                ];
            } else {
                http_response_code(500);
                $response = [
                    'success' => false, 
                    'message' => 'Failed to send status update email'
                ];
            }
            break;

        case 'send_acceptance':
            // Send individual acceptance notification for Building & Machinery
            $required = ['client_data', 'form_data'];
            foreach ($required as $field) {
                if (!isset($input[$field])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                    exit;
                }
            }

            // Validate client email
            if (!filter_var($input['client_data']['email'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid client email format']);
                exit;
            }

            $success = $emailNotification->sendAcceptanceNotification(
                $input['client_data'],
                $input['form_data']
            );

            if ($success) {
                $response = [
                    'success' => true, 
                    'message' => 'Acceptance notification sent successfully',
                    'request_id' => $input['form_data']['request_id'],
                    'client_email' => $input['client_data']['email']
                ];
            } else {
                http_response_code(500);
                $response = [
                    'success' => false, 
                    'message' => 'Failed to send acceptance notification',
                    'error_details' => $emailNotification->getLastError()
                ];
            }
            break;

        case 'send_batch_scheduling':
            // Send batch scheduling notification for Property inspections
            $required = ['clients_data', 'barangay', 'schedule_info'];
            foreach ($required as $field) {
                if (!isset($input[$field])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                    exit;
                }
            }

            // Validate that we have at least 10 clients
            if (count($input['clients_data']) < 10) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Minimum 10 clients required for batch scheduling']);
                exit;
            }

            // Validate client emails
            foreach ($input['clients_data'] as $client) {
                if (!filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid email format for client: ' . $client['email']]);
                    exit;
                }
            }

            $successCount = $emailNotification->sendBatchSchedulingNotification(
                $input['clients_data'],
                $input['barangay'],
                $input['schedule_info']
            );

            $totalClients = count($input['clients_data']);
            $response = [
                'success' => $successCount > 0,
                'message' => "Batch scheduling notifications sent: {$successCount}/{$totalClients}",
                'barangay' => $input['barangay'],
                'total_clients' => $totalClients,
                'successful_sends' => $successCount,
                'failed_sends' => $totalClients - $successCount
            ];
            break;

        case 'test_email':
            // Test email configuration
            if (!isset($input['test_email'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing test_email parameter']);
                exit;
            }

            if (!filter_var($input['test_email'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                exit;
            }

            $success = $emailNotification->testEmailConfiguration($input['test_email']);

            if ($success) {
                $response = [
                    'success' => true, 
                    'message' => 'Test email sent successfully',
                    'test_email' => $input['test_email']
                ];
            } else {
                http_response_code(500);
                $lastError = $emailNotification->getLastError();
                $response = [
                    'success' => false, 
                    'message' => 'Failed to send test email',
                    'error_details' => $lastError ? $lastError : 'Unknown error occurred',
                    'debug_info' => 'Check PHP error log for detailed SMTP debug output'
                ];
            }
            break;

        case 'send_decline':
            // Send decline notification
            $required = ['client_data', 'form_data', 'decline_reason'];
            foreach ($required as $field) {
                if (!isset($input[$field])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                    exit;
                }
            }

            // Validate client email
            if (!filter_var($input['client_data']['email'], FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid client email format']);
                exit;
            }

            // Validate decline reason is not empty
            if (empty(trim($input['decline_reason']))) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Decline reason cannot be empty']);
                exit;
            }

            $success = $emailNotification->sendDeclineNotification(
                $input['client_data'],
                $input['form_data'],
                $input['decline_reason']
            );

            if ($success) {
                $response = [
                    'success' => true, 
                    'message' => 'Decline notification sent successfully',
                    'request_id' => $input['form_data']['request_id'],
                    'client_email' => $input['client_data']['email']
                ];
            } else {
                http_response_code(500);
                $response = [
                    'success' => false, 
                    'message' => 'Failed to send decline notification',
                    'error_details' => $emailNotification->getLastError()
                ];
            }
            break;

        default:
            http_response_code(400);
            $response = ['success' => false, 'message' => 'Invalid action'];
            break;
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
