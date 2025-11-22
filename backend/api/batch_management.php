<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required classes
require_once '../utils/BarangayBatchTracker.php';
require_once '../email/EmailNotification.php';

// Get request method and input
$method = $_SERVER['REQUEST_METHOD'];
$input = null;

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
} else if ($method === 'GET') {
    $input = $_GET;
}

try {
    $batchTracker = new BarangayBatchTracker();
    $emailNotification = new EmailNotification();
    $response = ['success' => false, 'message' => 'Unknown action'];

    if ($method === 'GET') {
        // Handle GET requests for checking status
        $action = isset($input['action']) ? $input['action'] : '';
        
        switch ($action) {
            case 'barangay_summary':
                $summary = $batchTracker->getBarangaySummary();
                $response = [
                    'success' => true,
                    'data' => $summary,
                    'message' => 'Barangay summary retrieved successfully'
                ];
                break;
                
            case 'ready_batches':
                $readyBatches = $batchTracker->processReadyBatches();
                $response = [
                    'success' => true,
                    'data' => $readyBatches,
                    'message' => 'Ready batches retrieved successfully',
                    'count' => count($readyBatches)
                ];
                break;
                
            case 'barangay_requests':
                $barangay = isset($input['barangay']) ? $input['barangay'] : '';
                if (!$barangay) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Missing barangay parameter']);
                    exit;
                }
                
                $requests = $batchTracker->getBarangayRequests($barangay);
                $response = [
                    'success' => true,
                    'data' => $requests,
                    'barangay' => $barangay,
                    'count' => count($requests),
                    'ready_for_batch' => count($requests) >= 10
                ];
                break;
                
            default:
                http_response_code(400);
                $response = ['success' => false, 'message' => 'Invalid GET action'];
                break;
        }
        
    } else if ($method === 'POST') {
        // Handle POST requests for actions
        if (!isset($input['action'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing action parameter']);
            exit;
        }

        switch ($input['action']) {
            case 'add_property_request':
                // Add a new property inspection request
                $required = ['barangay', 'client_data', 'form_data'];
                foreach ($required as $field) {
                    if (!isset($input[$field])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                        exit;
                    }
                }

                $result = $batchTracker->addPropertyRequest(
                    $input['barangay'],
                    $input['client_data'],
                    $input['form_data']
                );

                if ($result['trigger_batch']) {
                    // Threshold reached! Prepare for batch scheduling
                    $response = [
                        'success' => true,
                        'message' => 'Request added - batch threshold reached!',
                        'trigger_batch' => true,
                        'barangay' => $result['barangay'],
                        'total_requests' => $result['total_requests'],
                        'action_required' => 'Schedule batch inspection for ' . $result['barangay']
                    ];
                } else {
                    $response = [
                        'success' => true,
                        'message' => 'Request added successfully',
                        'trigger_batch' => false,
                        'barangay' => $result['barangay'],
                        'total_requests' => $result['total_requests'],
                        'remaining_needed' => $result['remaining_needed']
                    ];
                }
                break;

            case 'trigger_batch_scheduling':
                // Manually trigger batch scheduling for a barangay
                $required = ['barangay', 'schedule_info'];
                foreach ($required as $field) {
                    if (!isset($input[$field])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                        exit;
                    }
                }

                $barangay = $input['barangay'];
                $scheduleInfo = $input['schedule_info'];
                
                // Get all pending requests for this barangay
                $clients = $batchTracker->getBarangayRequests($barangay);
                
                if (count($clients) < 10) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Insufficient requests for batch scheduling',
                        'current_count' => count($clients),
                        'minimum_required' => 10
                    ]);
                    exit;
                }

                // Send batch scheduling emails
                $successCount = $emailNotification->sendBatchSchedulingNotification(
                    $clients,
                    $barangay,
                    $scheduleInfo
                );

                // Mark requests as scheduled
                $batchTracker->markRequestsAsScheduled($barangay);

                $response = [
                    'success' => $successCount > 0,
                    'message' => "Batch scheduling completed for {$barangay}",
                    'barangay' => $barangay,
                    'total_clients' => count($clients),
                    'emails_sent' => $successCount,
                    'failed_emails' => count($clients) - $successCount,
                    'schedule_date' => $scheduleInfo['inspection_date']
                ];
                break;

            case 'send_building_acceptance':
                // Send individual acceptance for Building & Machinery
                $required = ['client_data', 'form_data'];
                foreach ($required as $field) {
                    if (!isset($input[$field])) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => "Missing required field: {$field}"]);
                        exit;
                    }
                }

                // Validate this is for Building & Machinery
                if (!in_array($input['form_data']['category'], ['Building', 'Machinery', 'Building and Machinery'])) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false, 
                        'message' => 'This endpoint is only for Building and Machinery inspections',
                        'received_category' => $input['form_data']['category']
                    ]);
                    exit;
                }

                $success = $emailNotification->sendAcceptanceNotification(
                    $input['client_data'],
                    $input['form_data']
                );

                if ($success) {
                    $response = [
                        'success' => true,
                        'message' => 'Building/Machinery acceptance notification sent',
                        'request_id' => $input['form_data']['request_id'],
                        'category' => $input['form_data']['category'],
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

            default:
                http_response_code(400);
                $response = ['success' => false, 'message' => 'Invalid POST action'];
                break;
        }
    } else {
        http_response_code(405);
        $response = ['success' => false, 'message' => 'Method not allowed'];
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