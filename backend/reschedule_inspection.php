<?php
// Enable error logging to file
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/reschedule_errors.log');

// Start output buffering FIRST
ob_start();

// Catch ALL errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' line ' . $error['line']
        ]);
        ob_end_flush();
    }
});

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

try {
    // Include required files
    require_once __DIR__ . '/config/db_config.php';
    require_once __DIR__ . '/vendor/autoload.php';
    
    // Create PDO connection
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USERNAME,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $inspection_id = $input['inspection_id'] ?? null;
    $new_date = $input['new_date'] ?? null;
    $reason = $input['reason'] ?? 'Schedule adjustment';
    $barangay = $input['barangay'] ?? null;
    
    if (!$inspection_id || !$new_date) {
        throw new Exception('Missing required fields: inspection_id and new_date');
    }
    
    // Validate date format
    $reschedule_date = DateTime::createFromFormat('Y-m-d', $new_date);
    if (!$reschedule_date) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD');
    }
    
    // Check if date is in the future
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    
    if ($reschedule_date <= $today) {
        throw new Exception('Reschedule date must be in the future');
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Get the current inspection details
        $stmt = $pdo->prepare("SELECT * FROM scheduled_inspections WHERE id = ?");
        $stmt->execute([$inspection_id]);
        $inspection = $stmt->fetch();
        
        if (!$inspection) {
            throw new Exception('Inspection not found');
        }
        
        $old_date = $inspection['inspection_date'];
        $barangay_name = $inspection['barangay'];
        
        // Check availability (max 2 inspections per day)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM scheduled_inspections 
            WHERE inspection_date = ? AND status = 'scheduled' AND id != ?
        ");
        $stmt->execute([$new_date, $inspection_id]);
        
        $existing_count = $stmt->fetchColumn();
        if ($existing_count >= 2) {
            throw new Exception('The selected date is fully booked');
        }
        
        // UPDATE THE INSPECTION DATE - THIS IS THE CRITICAL PART
        $stmt = $pdo->prepare("
            UPDATE scheduled_inspections 
            SET inspection_date = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$new_date, $inspection_id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Failed to update inspection');
        }
        
        // NOW update the assessment_requests that were scheduled for the OLD date in this barangay
        // This links them to the NEW date
        $stmt = $pdo->prepare("
            SELECT id, name, email, location 
            FROM assessment_requests 
            WHERE status = 'scheduled'
            AND inspection_category IN ('Building', 'Machinery', 'Land Property')
        ");
        $stmt->execute();
        $all_requests = $stmt->fetchAll();
        
        // Filter requests that belong to this barangay
        $batch_requests = [];
        $unique_emails = []; // Track unique email addresses
        
        foreach ($all_requests as $req) {
            if (stripos($req['location'], $barangay_name) !== false) {
                // Only add if email is unique (or if no email)
                if (empty($req['email']) || !in_array($req['email'], $unique_emails)) {
                    $batch_requests[] = $req;
                    if (!empty($req['email'])) {
                        $unique_emails[] = $req['email'];
                    }
                }
            }
        }
        
        // Get the actual count from scheduled_inspections to know how many are in this batch
        $expected_count = $inspection['request_count'];
        
        // Limit to the exact number in this batch
        $batch_requests = array_slice($batch_requests, 0, $expected_count);
        
        // Note: assessment_requests may need manual update if column exists in your DB
        
        // Log the activity (optional, won't break if fails)
        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values) 
                VALUES (?, 'inspection_rescheduled', 'scheduled_inspections', ?, ?, ?)
            ");
            $old_values = json_encode(['inspection_date' => $old_date]);
            $new_values = json_encode(['inspection_date' => $new_date, 'reason' => $reason]);
            $stmt->execute([1, $inspection_id, $old_values, $new_values]);
        } catch (Exception $e) {
            error_log("Activity log failed: " . $e->getMessage());
        }
        
        // Commit transaction FIRST - ensure database is updated
        $pdo->commit();
        
        // Use the batch_requests we already found (exact number from this batch, already unique)
        $property_owners = $batch_requests;
        
        // Send individual emails to ONLY the owners in THIS specific batch
        $email_sent = 0;
        $email_failed = 0;
        
        if (!empty($property_owners)) {
            foreach ($property_owners as $owner) {
                // Skip if no email
                if (empty($owner['email'])) {
                    error_log("Skipping owner {$owner['name']} - no email address");
                    $email_failed++;
                    continue;
                }
                
                try {
                    // Create NEW PHPMailer instance for EACH individual email
                    $mail = new PHPMailer(false);
                    
                    $mail->SMTPDebug = 0;
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'assesspro2025@gmail.com';
                    $mail->Password = 'uanx imsi paze iojh';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->Timeout = 15; // Increase timeout further
                    $mail->SMTPKeepAlive = false;
                    
                    $mail->SMTPOptions = array(
                        'ssl' => array(
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        )
                    );
                    
                    // ONE recipient per email - their individual information
                    $mail->setFrom('assesspro2025@gmail.com', 'Municipal Assessor\'s Office');
                    $mail->addAddress($owner['email'], $owner['name']);
                    $mail->isHTML(true);
                    $mail->Subject = 'NOTICE: Property Assessment Inspection Rescheduled';
                    
                    $formatted_date = date('l, F j, Y', strtotime($new_date));
                    $current_date = date('F j, Y');
                    
                    $mail->Body = "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset='UTF-8'>
                        <style>
                            body { font-family: 'Arial', sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                            .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                            .header { background: linear-gradient(135deg, #2d8659 0%, #1a5c3a 100%); color: white; padding: 30px 20px; text-align: center; }
                            .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
                            .header p { margin: 5px 0 0 0; font-size: 14px; opacity: 0.95; }
                            .content { padding: 30px 25px; }
                            .greeting { font-size: 16px; margin-bottom: 20px; }
                            .notice-box { background: #fff8e1; border-left: 4px solid #ff9800; padding: 15px 20px; margin: 20px 0; }
                            .notice-box p { margin: 0; font-weight: 600; color: #e65100; }
                            .info-section { background: #f5f5f5; border-radius: 8px; padding: 20px; margin: 20px 0; }
                            .info-section h3 { margin: 0 0 15px 0; color: #2d8659; font-size: 18px; border-bottom: 2px solid #2d8659; padding-bottom: 8px; }
                            .info-row { margin: 10px 0; }
                            .info-label { font-weight: 600; color: #555; display: inline-block; width: 140px; }
                            .info-value { color: #333; }
                            .schedule-box { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border: 2px solid #4caf50; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center; }
                            .schedule-box h3 { margin: 0 0 15px 0; color: #2e7d32; font-size: 20px; }
                            .schedule-date { font-size: 24px; font-weight: bold; color: #1b5e20; margin: 10px 0; }
                            .schedule-time { font-size: 16px; color: #2e7d32; }
                            .requirements { background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0; }
                            .requirements h4 { color: #2d8659; margin-top: 0; }
                            .requirements ul { margin: 10px 0; padding-left: 25px; }
                            .requirements li { margin: 8px 0; color: #555; }
                            .important-notes { background: #ffebee; border-left: 4px solid #c62828; padding: 15px 20px; margin: 20px 0; }
                            .important-notes h4 { margin: 0 0 10px 0; color: #c62828; }
                            .important-notes ul { margin: 10px 0; padding-left: 25px; }
                            .important-notes li { margin: 8px 0; color: #555; }
                            .contact-section { background: #e3f2fd; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
                            .contact-section h4 { margin: 0 0 10px 0; color: #1976d2; }
                            .contact-info { color: #555; font-size: 14px; }
                            .footer { background: #f5f5f5; padding: 20px; text-align: center; border-top: 3px solid #2d8659; }
                            .footer p { margin: 5px 0; font-size: 13px; color: #666; }
                            .signature { margin: 20px 0; font-style: italic; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h1>🏛️ MUNICIPAL ASSESSOR'S OFFICE</h1>
                                <p>Municipality of Mabini, Province of Batangas</p>
                            </div>
                            
                            <div class='content'>
                                <p style='text-align: right; color: #666; font-size: 14px;'>{$current_date}</p>
                                
                                <div class='greeting'>
                                    <p>Dear <strong>{$owner['name']}</strong>,</p>
                                </div>
                                
                                <p>Greetings from the Municipal Assessor's Office!</p>
                                
                                <div class='notice-box'>
                                    <p>⚠️ NOTICE OF SCHEDULE CHANGE</p>
                                </div>
                                
                                <p>This is to formally notify you that your scheduled property assessment inspection has been <strong>rescheduled</strong> to a new date and time.</p>
                                
                                <div class='schedule-box'>
                                    <h3>📅 NEW INSPECTION SCHEDULE</h3>
                                    <div class='schedule-date'>{$formatted_date}</div>
                                    <div class='schedule-time'>Between 8:00 AM and 5:00 PM</div>
                                </div>
                                
                                <div class='info-section'>
                                    <h3>Property Information</h3>
                                    <div class='info-row'>
                                        <span class='info-label'>Property Location:</span>
                                        <span class='info-value'>{$owner['location']}</span>
                                    </div>
                                    <div class='info-row'>
                                        <span class='info-label'>Barangay:</span>
                                        <span class='info-value'>{$barangay_name}</span>
                                    </div>
                                </div>
                                
                                <div class='info-section'>
                                    <h3>Reason for Rescheduling</h3>
                                    <p style='margin: 0; color: #555;'>{$reason}</p>
                                </div>
                                
                                <div class='contact-section'>
                                    <h4>📞 Need Assistance?</h4>
                                    <div class='contact-info'>
                                        <p><strong>Municipal Assessor's Office</strong></p>
                                        <p>Municipal Hall, Poblacion, Mabini, Batangas</p>
                                        <p>Phone: 09989595966</p>
                                        <p>Email: assesspro2025@gmail.com</p>
                                        <p>Office Hours: Monday to Friday, 8:00 AM - 5:00 PM</p>
                                    </div>
                                </div>
                                
                                <div class='signature'>
                                    <p>We apologize for any inconvenience this rescheduling may cause. Your cooperation and understanding are greatly appreciated.</p>
                                    <p style='margin-top: 20px;'><strong>Very truly yours,</strong></p>
                                    <p style='margin-top: 30px;'><strong>MUNICIPAL ASSESSOR'S OFFICE</strong><br>
                                    Municipality of Mabini, Batangas</p>
                                </div>
                            </div>
                            
                            <div class='footer'>
                                <p><strong>This is an official communication from the Municipal Assessor's Office</strong></p>
                                <p>Please do not reply to this email. For inquiries, contact us through the provided contact information above.</p>
                                <p style='margin-top: 10px; font-size: 12px;'>© 2025 Municipal Government of Mabini, Batangas. All rights reserved.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                    ";
                    
                    // Send to THIS owner only
                    if (@$mail->send()) {
                        $email_sent++;
                        error_log("Email successfully sent to {$owner['email']} ({$owner['name']})");
                    } else {
                        $email_failed++;
                        error_log("Email send failed for {$owner['email']}: " . $mail->ErrorInfo);
                    }
                    
                    // Clean up and small delay to prevent rate limiting
                    $mail->smtpClose();
                    unset($mail);
                    usleep(250000); // 0.25 seconds delay between emails
                    
                } catch (Exception $e) {
                    $email_failed++;
                    error_log("Email exception for {$owner['email']}: " . $e->getMessage());
                }
                
                // Small delay to avoid rate limiting
                usleep(100000); // 0.1 second delay between emails
            }
        }
        
        // Clean output buffer and send response
        ob_clean();
        
        $response = [
            'success' => true,
            'message' => 'Inspection rescheduled successfully',
            'email_sent' => $email_sent > 0,
            'emails_sent_count' => $email_sent,
            'emails_failed_count' => $email_failed,
            'total_owners' => count($property_owners),
            'new_schedule' => [
                'date' => $new_date,
                'formatted_date' => date('l, F j, Y', strtotime($new_date))
            ]
        ];
        
        echo json_encode($response);
        ob_end_flush();
        exit;

    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }

} catch (Exception $e) {
    // Clean any output buffer
    if (ob_get_level()) ob_clean();
    
    error_log("Reschedule error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    ob_end_flush();
    exit;
}
?>