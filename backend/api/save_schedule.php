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

    // Database connection
    $host = 'localhost';
    $dbname = 'assesspro_db';
    $username = 'root';
    $password = '';

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

    // Update assessment_requests to mark them as scheduled
    $update_stmt = $pdo->prepare("
        UPDATE assessment_requests 
        SET status = 'scheduled' 
        WHERE location = ? AND status = 'accepted'
    ");
    
    $update_stmt->execute([$barangay]);
    
    $scheduled_count = $update_stmt->rowCount();
    
    // Send batch scheduling emails to all clients
    $emails_sent = 0;
    $email_errors = [];
    $clients = [];
    
    try {
        error_log("Starting email notification process...");
        
        // Load email notification system
        require_once '../email/EmailNotification.php';
        $emailNotification = new EmailNotification();
        error_log("EmailNotification class loaded successfully");
        
        // Get all clients with scheduled requests for this barangay (remove DISTINCT to ensure all requests are included)
        $client_stmt = $pdo->prepare("
            SELECT name, email, land_reference_arp, purpose, contact_person, id as request_id
            FROM assessment_requests 
            WHERE location = ? AND status = 'scheduled'
        ");
        $client_stmt->execute([$barangay]);
        $all_clients = $client_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Remove duplicates manually while preserving all unique email addresses
        $clients = [];
        $seen_emails = [];
        foreach ($all_clients as $client) {
            if (!in_array(strtolower($client['email']), $seen_emails)) {
                $clients[] = $client;
                $seen_emails[] = strtolower($client['email']);
            }
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
                if (empty($client['email']) || !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
                    error_log("Invalid email for client: " . $client['name'] . " - " . $client['email']);
                    $email_errors[] = "Invalid email for " . $client['name'] . ": " . $client['email'];
                    continue;
                }
                
                $clientsData[] = [
                    'name' => $client['name'] ?: 'Unknown Client',
                    'email' => $client['email'],
                    'request_id' => $client['request_id'] ?: ('BATCH-' . strtoupper($barangay) . '-' . date('Ymd')),
                    'property_address' => $barangay . ' (exact address as per your submitted request)',
                    'property_type' => 'Property Assessment - ' . ($client['purpose'] ?: 'General Assessment'),
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
                    $emails_sent = $emailNotification->sendBatchSchedulingNotification(
                        $clientsData, $barangay, $scheduleInfo
                    );
                    error_log("Batch email sending completed. Emails sent: {$emails_sent}");
                } catch (Exception $e) {
                    error_log("Batch email sending failed: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    $email_errors[] = "Email system error: " . $e->getMessage();
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
        'emails_sent' => $emails_sent,
        'total_clients' => count($clients ?? []),
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