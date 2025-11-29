<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Enable error logging for debugging
ini_set('log_errors', 1);
error_log("=== BATCH SCHEDULING API REQUEST ===");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

try {
    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }

    // Database connection - Environment aware
    require_once __DIR__ . '/../config/db_config.php';
    
    $host = DB_HOST;
    $dbname = DB_NAME;
    $username = DB_USERNAME;
    $password = DB_PASSWORD;

    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get JSON input
    $raw_input = file_get_contents('php://input');
    error_log("Raw input: " . $raw_input);
    
    $input = json_decode($raw_input, true);
    
    if (!$input) {
        error_log("JSON decode error: " . json_last_error_msg());
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }

    error_log("Parsed input: " . print_r($input, true));

    // Validate required fields with detailed error messages
    $required_fields = [
        'barangay' => 'Barangay name',
        'inspection_date' => 'Inspection date', 
        'request_count' => 'Request count',
        'notes' => 'Schedule notes'
    ];
    
    foreach ($required_fields as $field => $description) {
        if (!isset($input[$field]) || (is_string($input[$field]) && empty(trim($input[$field])))) {
            throw new Exception("Missing or empty required field: {$description} ({$field})");
        }
    }

    $barangay = trim($input['barangay']);
    $inspection_date = $input['inspection_date'];
    $request_count = intval($input['request_count']);
    $notes = trim($input['notes']);

    error_log("Processing schedule for: {$barangay}, Date: {$inspection_date}, Count: {$request_count}");

    // Enhanced date validation
    $date = DateTime::createFromFormat('Y-m-d', $inspection_date);
    if (!$date || $date->format('Y-m-d') !== $inspection_date) {
        throw new Exception('Invalid date format. Expected YYYY-MM-DD, received: ' . $inspection_date);
    }

    // Check if inspection already scheduled for this barangay on this date
    $check_stmt = $pdo->prepare("SELECT id FROM scheduled_inspections WHERE barangay = ? AND inspection_date = ? AND status = 'scheduled'");
    $check_stmt->execute([$barangay, $inspection_date]);
    
    if ($check_stmt->fetch()) {
        throw new Exception('Inspection already scheduled for this barangay on this date');
    }

    // Insert scheduled inspection
    $stmt = $pdo->prepare("
        INSERT INTO scheduled_inspections (barangay, inspection_date, request_count, notes, status) 
        VALUES (?, ?, ?, ?, 'scheduled')
    ");
    
    $stmt->execute([$barangay, $inspection_date, $request_count, $notes]);
    
    $inspection_id = $pdo->lastInsertId();

    // Get requests that will be scheduled in this batch (only 'accepted' ones)
    $batch_clients_stmt = $pdo->prepare("
        SELECT name, email, land_reference_arp, purpose_and_preferred_date as purpose, contact_person, id as request_id, inspection_category
        FROM assessment_requests 
        WHERE location = ? AND status = 'accepted'
    ");
    $batch_clients_stmt->execute([$barangay]);
    $batch_clients = $batch_clients_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($batch_clients) . " 'accepted' requests to schedule for {$barangay}");

    // Update assessment_requests to mark them as scheduled
    $update_stmt = $pdo->prepare("
        UPDATE assessment_requests 
        SET status = 'scheduled' 
        WHERE location = ? AND status = 'accepted'
    ");
    
    $update_stmt->execute([$barangay]);
    
    $scheduled_count = $update_stmt->rowCount();
    
        error_log("Successfully updated {$scheduled_count} requests to 'scheduled' status");
    
    // Validate that the counts match
    if ($scheduled_count !== count($batch_clients)) {
        error_log("WARNING: Mismatch between batch clients (" . count($batch_clients) . ") and updated count ({$scheduled_count})");
    }
    
    // Ensure we match the expected request count from the UI
    if ($request_count !== $scheduled_count) {
        error_log("NOTICE: UI reported {$request_count} requests, but {$scheduled_count} were actually scheduled");
    }

    // Send batch scheduling emails to ONLY the clients that were just scheduled
    $emails_sent = 0;
    $email_errors = [];
    $clients = $batch_clients; // Use the batch clients data

    try {
        error_log("Starting email notification process for {$scheduled_count} newly scheduled requests...");
        
        // Load email notification system
        require_once '../email/EmailNotification.php';
        $emailNotification = new EmailNotification();
        error_log("EmailNotification class loaded successfully");
        
        // Use the batch clients (only newly scheduled ones)
        $all_clients = $batch_clients;
        error_log("Processing " . count($all_clients) . " clients from newly scheduled requests");
        
        // Enhanced duplicate prevention using email + request_id combination
        $clients = [];
        $seen_combinations = [];
        $seen_emails = [];
        
        foreach ($all_clients as $client) {
            $email_key = strtolower(trim($client['email']));
            $request_key = $client['request_id'];
            $combination_key = $email_key . '|' . $request_key;
            
            // Skip if invalid email
            if (empty($client['email']) || !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
                error_log("Skipping invalid email: " . ($client['email'] ?? 'NULL') . " for client: " . ($client['name'] ?? 'Unknown'));
                continue;
            }
            
            // Skip if duplicate combination (same email + request_id)
            if (in_array($combination_key, $seen_combinations)) {
                error_log("Skipping duplicate request: Email {$email_key}, Request ID: {$request_key}");
                continue;
            }
            
            // Warn about same email with different request IDs (but still include)
            if (in_array($email_key, $seen_emails)) {
                error_log("WARNING: Same email {$email_key} found with different request ID: {$request_key}");
            }
            
            $clients[] = $client;
            $seen_combinations[] = $combination_key;
            $seen_emails[] = $email_key;
        }
        
        error_log("Found " . count($clients) . " clients for barangay: {$barangay}");
        
        if (count($clients) > 0) {
            // Prepare schedule information for email
            $scheduleInfo = [
                'inspection_date' => date('F j, Y', strtotime($inspection_date)),
                'time_window' => '8:00 AM - 5:00 PM',
                'duration' => '30-45 minutes per property',
                'team_contact' => 'Assessment Team - Contact via office',
                'notes' => $notes
            ];
            
            error_log("Schedule info prepared: " . print_r($scheduleInfo, true));
            
            // Prepare client data for batch email
            $clientsData = [];
            foreach ($clients as $client) {
                // Additional validation (should not be needed due to filtering above, but safety check)
                if (empty($client['email']) || !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
                    error_log("UNEXPECTED: Invalid email found in filtered client list: " . ($client['name'] ?? 'Unknown') . " - " . ($client['email'] ?? 'NULL'));
                    $email_errors[] = "Invalid email for " . ($client['name'] ?? 'Unknown') . ": " . ($client['email'] ?? 'NULL');
                    continue;
                }
                
                // Validate required fields
                if (empty($client['name'])) {
                    error_log("WARNING: Missing client name for email " . $client['email'] . ", using fallback");
                    $client['name'] = 'Valued Client';
                }
                
                $clientsData[] = [
                    'name' => $client['name'] ?: 'Unknown Client',
                    'email' => $client['email'],
                    'request_id' => $client['request_id'] ?: ('BATCH-' . strtoupper($barangay) . '-' . date('Ymd')),
                    'property_address' => $barangay . ' (exact address as per your submitted request)',
                    'property_type' => 'Property Assessment - ' . ($client['purpose'] ?: 'General Assessment'),
                    'inspection_category' => $client['inspection_category'] ?: 'Property',
                    'area' => 'As specified in your application',
                    'submission_date' => date('F j, Y'),
                    'land_reference' => $client['land_reference_arp'] ?: 'N/A',
                    'purpose' => $client['purpose'] ?: 'Property Assessment',
                    'contact_person' => $client['contact_person'] ?: 'N/A'
                ];
            }
            
            error_log("Prepared client data for " . count($clientsData) . " valid emails");
            
            if (count($clientsData) > 0) {
                // Send batch notification emails
                try {
                    error_log("Calling sendBatchSchedulingNotification...");
                    $email_results = $emailNotification->sendBatchSchedulingNotification(
                        $clientsData, $barangay, $scheduleInfo
                    );
                    
                    // Handle the new return format
                    if (is_array($email_results)) {
                        $emails_sent = $email_results['emails_sent'];
                        $total_clients = $email_results['total_clients'];
                        $email_errors = array_merge($email_errors, array_column($email_results['failed_emails'], 'reason'));
                        error_log("Batch email completed. Sent: {$emails_sent}/{$total_clients} (Accuracy: {$email_results['accuracy']}%)");
                    } else {
                        // Backward compatibility
                        $emails_sent = $email_results;
                        $total_clients = count($clientsData);
                        error_log("Batch email sending completed. Emails sent: {$emails_sent}");
                    }
                } catch (Exception $e) {
                    error_log("Batch email sending failed: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    $email_errors[] = "Email system error: " . $e->getMessage();
                    $emails_sent = 0;
                    $total_clients = count($clientsData);
                }
            } else {
                error_log("No valid email addresses found for batch notification");
                $email_errors[] = "No valid email addresses found";
            }
        } else {
            error_log("No clients found for scheduled notifications");
        }
    } catch (Exception $e) {
        error_log("Email notification system error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $email_errors[] = "Email system unavailable: " . $e->getMessage();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Inspection scheduled successfully',
        'emails_sent' => $emails_sent ?? 0,
        'total_clients' => $total_clients ?? count($clients ?? []),
        'email_errors' => $email_errors,
        'data' => [
            'id' => $inspection_id,
            'barangay' => $barangay,
            'inspection_date' => $inspection_date,
            'request_count' => $request_count,
            'scheduled_count' => $scheduled_count,
            'notes' => $notes,
            'status' => 'scheduled'
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>