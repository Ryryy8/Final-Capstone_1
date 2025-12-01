<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailNotification {
    private $mail;
    private $fromEmail;
    private $fromName;
    
    public function __construct() {
        $this->mail = new PHPMailer(true);
        $this->fromEmail = 'assesspro2025@gmail.com';
        $this->fromName = 'AssessPro - Municipal Property Assessment';
        
        $this->setupSMTP();
    }
    
    private function setupSMTP() {
        try {
            // Server settings
            $this->mail->isSMTP();
            $this->mail->Host       = 'smtp.gmail.com';
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = 'assesspro2025@gmail.com';
            $this->mail->Password   = 'uanx imsi paze iojh'; // Replace with your Gmail App Password
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->mail->Port       = 587;
            
            // Additional settings for better compatibility and reliability
            $this->mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            // Set timeout and keep alive for better batch sending
            $this->mail->Timeout = 60;
            $this->mail->SMTPKeepAlive = true;
            
            // Enable debug logging for troubleshooting
            $this->mail->SMTPDebug = 0; // Set to 2 for detailed SMTP debug logs
            
        } catch (Exception $e) {
            error_log("SMTP Setup Error: " . $e->getMessage());
        }
    }
    
    public function sendStatusUpdate($clientEmail, $clientName, $requestId, $status, $comments = '') {
        try {
            // Clear any previous recipients
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            
            // Recipients
            $this->mail->setFrom($this->fromEmail, $this->fromName);
            $this->mail->addAddress($clientEmail, $clientName);
            
            // Content
            $this->mail->isHTML(true);
            $this->mail->Subject = "AssessPro - Assessment Request Update #{$requestId}";
            
            // Email template
            $htmlBody = $this->getEmailTemplate($clientName, $requestId, $status, $comments);
            $this->mail->Body = $htmlBody;
            
            // Plain text version
            $this->mail->AltBody = $this->getPlainTextVersion($clientName, $requestId, $status, $comments);
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendRequestConfirmation($clientEmail, $clientName, $requestId, $propertyDetails) {
        try {
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            
            $this->mail->setFrom($this->fromEmail, $this->fromName);
            $this->mail->addAddress($clientEmail, $clientName);
            
            $this->mail->isHTML(true);
            $this->mail->Subject = "AssessPro - Request Confirmation #{$requestId}";
            
            $htmlBody = $this->getConfirmationTemplate($clientName, $requestId, $propertyDetails);
            $this->mail->Body = $htmlBody;
            
            $this->mail->AltBody = $this->getConfirmationPlainText($clientName, $requestId, $propertyDetails);
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Confirmation email failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function testEmailConfiguration($testEmail) {
        try {
            // Enable minimal debug output (only errors)
            $this->mail->SMTPDebug = 0; // Disable verbose debug for web requests
            
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            
            $this->mail->setFrom($this->fromEmail, $this->fromName);
            $this->mail->addAddress($testEmail);
            
            $this->mail->isHTML(true);
            $this->mail->Subject = "AssessPro - Email Configuration Test";
            
            $this->mail->Body = "
            <html>
            <body>
                <h2>Email Test Successful! ✅</h2>
                <p>This is a test email to verify that your email configuration is working properly.</p>
                <p><strong>Test Time:</strong> " . date('Y-m-d H:i:s') . "</p>
                <p>If you received this email, your AssessPro system is ready to send notifications!</p>
                <p>Sent from: AssessPro Municipal Property Assessment System</p>
            </body>
            </html>";
            
            $this->mail->AltBody = "AssessPro Email Test Successful! This test email was sent on " . date('Y-m-d H:i:s') . " from AssessPro Municipal Property Assessment System.";
            
            error_log("Attempting to send test email to: $testEmail");
            $this->mail->send();
            error_log("Test email sent successfully to: $testEmail");
            return true;
            
        } catch (Exception $e) {
            error_log("Test email failed: " . $e->getMessage());
            error_log("SMTP Error Info: " . $this->mail->ErrorInfo);
            return false;
        }
    }
    
    private function getEmailTemplate($clientName, $requestId, $status, $comments) {
        $statusColor = $this->getStatusColor($status);
        $statusMessage = $this->getStatusMessage($status);
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2d8659; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .status-badge { 
                    display: inline-block; 
                    padding: 5px 15px; 
                    border-radius: 20px; 
                    color: white; 
                    background: {$statusColor}; 
                    font-weight: bold; 
                }
                .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>AssessPro - Assessment Update</h1>
                </div>
                <div class='content'>
                    <h2>Dear {$clientName},</h2>
                    <p>We have an important update regarding your municipal property assessment request.</p>
                    
                    <p><strong>Request ID:</strong> #{$requestId}</p>
                    <p><strong>Current Status:</strong> <span class='status-badge'>{$status}</span></p>
                    
                    <h3>Status Update:</h3>
                    <p>{$statusMessage}</p>
                    
                    " . ($comments ? "<h3>Additional Comments:</h3><p>{$comments}</p>" : "") . "
                    
                    <p>If you have any questions, please don't hesitate to contact our office.</p>
                    
                    <p>Best regards,<br>
                    Municipal Property Assessment Office<br>
                    AssessPro System</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message from AssessPro Municipal Property Assessment System.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    private function getConfirmationTemplate($clientName, $requestId, $propertyDetails) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2d8659; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .property-details { background: white; padding: 15px; border-left: 4px solid #2d8659; margin: 15px 0; }
                .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>AssessPro - Request Confirmation</h1>
                </div>
                <div class='content'>
                    <h2>Thank you, {$clientName}!</h2>
                    <p>Your municipal property assessment request has been successfully submitted and is now under review.</p>
                    
                    <p><strong>Request ID:</strong> #{$requestId}</p>
                    <p><strong>Status:</strong> <span style='color: #f59e0b; font-weight: bold;'>Pending Review</span></p>
                    
                    <div class='property-details'>
                        <h3>Property Information:</h3>
                        <p><strong>Address:</strong> {$propertyDetails['address']}</p>
                        <p><strong>Property Type:</strong> {$propertyDetails['type']}</p>
                        <p><strong>Area:</strong> {$propertyDetails['area']} sq.m.</p>
                    </div>
                    
                    <p>We will review your request and contact you within 2-3 business days with the next steps.</p>
                    
                    <p>Best regards,<br>
                    Municipal Property Assessment Office<br>
                    AssessPro System</p>
                </div>
                <div class='footer'>
                    <p>This is an automated confirmation from AssessPro Municipal Property Assessment System.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    private function getStatusColor($status) {
        switch(strtolower($status)) {
            case 'pending': return '#f59e0b';
            case 'in progress': return '#3b82f6';
            case 'completed': return '#10b981';
            case 'cancelled': return '#ef4444';
            default: return '#6b7280';
        }
    }
    
    private function getStatusMessage($status) {
        switch(strtolower($status)) {
            case 'pending':
                return 'Your assessment request is currently under review by our municipal assessment team. We will process it according to our standard procedures.';
            case 'accepted':
                return 'Excellent news! Your assessment request has been approved and accepted. We will proceed with scheduling the property inspection.';
            case 'scheduled':
                return 'Your property inspection has been successfully scheduled. You will receive detailed scheduling information shortly.';
            case 'completed':
                return 'Your property assessment has been successfully completed. The assessment results and documentation are now ready.';
            case 'declined':
                return 'After careful review, we are unable to proceed with your assessment request at this time. Please see the additional comments below for details.';
            case 'cancelled':
                return 'Your assessment request has been cancelled as requested.';
            default:
                return 'Your request status has been updated in our system.';
        }
    }
    
    private function getPlainTextVersion($clientName, $requestId, $status, $comments) {
        $message = "Hello {$clientName},\n\n";
        $message .= "We have an update regarding your property assessment request.\n\n";
        $message .= "Request ID: #{$requestId}\n";
        $message .= "Current Status: {$status}\n\n";
        $message .= $this->getStatusMessage($status) . "\n\n";
        
        if ($comments) {
            $message .= "Additional Comments: {$comments}\n\n";
        }
        
        $message .= "Best regards,\nProperty Assessment Office";
        
        return $message;
    }
    
    private function getConfirmationPlainText($clientName, $requestId, $propertyDetails) {
        $message = "Thank you, {$clientName}!\n\n";
        $message .= "Your property assessment request has been submitted.\n\n";
        $message .= "Request ID: #{$requestId}\n";
        $message .= "Status: Pending Review\n\n";
        $message .= "Property Information:\n";
        $message .= "Address: {$propertyDetails['address']}\n";
        $message .= "Property Type: {$propertyDetails['type']}\n";
        $message .= "Area: {$propertyDetails['area']} sq.m.\n\n";
        $message .= "We will review your request within 2-3 business days.\n\n";
        $message .= "Sincerely,\nMunicipal Property Assessment Office\nAssessPro Municipal Assessment System";
        
        return $message;
    }
    
    /**
     * Get the last error message for debugging
     */
    public function getLastError() {
        return $this->mail->ErrorInfo;
    }
    
    /**
     * Send individual acceptance notification for Building & Machinery inspections
     */
    public function sendAcceptanceNotification($clientData, $formData) {
        try {
            $this->mail->SMTPDebug = 0;
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            
            $this->mail->setFrom($this->fromEmail, $this->fromName);
            $this->mail->addAddress($clientData['email'], $clientData['name']);
            
            $this->mail->isHTML(true);
            $this->mail->Subject = "AssessPro - {$formData['category']} Assessment Scheduled #{$formData['request_id']}";
            
            $htmlBody = $this->getAcceptanceTemplate($clientData, $formData);
            $this->mail->Body = $htmlBody;
            
            $this->mail->AltBody = $this->getAcceptancePlainText($clientData, $formData);
            
            $this->mail->send();
            error_log("Acceptance email sent successfully to: " . $clientData['email']);
            return true;
            
        } catch (Exception $e) {
            error_log("Acceptance email failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send decline notification for rejected requests
     */
    public function sendDeclineNotification($clientData, $formData, $declineReason) {
        try {
            $this->mail->SMTPDebug = 0;
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            
            $this->mail->setFrom($this->fromEmail, $this->fromName);
            $this->mail->addAddress($clientData['email'], $clientData['name']);
            
            $this->mail->isHTML(true);
            $this->mail->Subject = "AssessPro - Assessment Request Update #{$formData['request_id']}";
            
            $htmlBody = $this->getDeclineTemplate($clientData, $formData, $declineReason);
            $this->mail->Body = $htmlBody;
            
            $this->mail->AltBody = $this->getDeclinePlainText($clientData, $formData, $declineReason);
            
            $this->mail->send();
            error_log("Decline email sent successfully to: " . $clientData['email']);
            return true;
            
        } catch (Exception $e) {
            error_log("Decline email failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send acceptance notification to client when staff accepts their request
     * This is sent when status changes from 'pending' to 'accepted'
     */
    public function sendRequestAcceptedNotification($clientData) {
        try {
            $this->mail->SMTPDebug = 0;
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            
            $this->mail->setFrom($this->fromEmail, $this->fromName);
            $this->mail->addAddress($clientData['email'], $clientData['name']);
            
            $this->mail->isHTML(true);
            $this->mail->Subject = "AssessPro - Request Accepted #{$clientData['id']}";
            
            $htmlBody = $this->getRequestAcceptedTemplate($clientData);
            $this->mail->Body = $htmlBody;
            
            $this->mail->AltBody = $this->getRequestAcceptedPlainText($clientData);
            
            $this->mail->send();
            error_log("Request accepted email sent successfully to: " . $clientData['email']);
            return true;
            
        } catch (Exception $e) {
            error_log("Request accepted email failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send batch scheduling notification for Property inspections (when 5+ requests reached)
     */
    public function sendBatchSchedulingNotification($clientsData, $barangay, $scheduleInfo) {
        $successCount = 0;
        $failedEmails = [];
        $totalClients = count($clientsData);
        $sentEmails = []; // Track actually sent emails to prevent duplicates
        
        // Analyze batch composition for mixed categories
        $categoryBreakdown = [];
        foreach ($clientsData as $client) {
            $category = $client['inspection_category'] ?? 'Property';
            $categoryBreakdown[$category] = ($categoryBreakdown[$category] ?? 0) + 1;
        }
        
        // Prepare batch composition info
        $batchComposition = [
            'categories' => $categoryBreakdown,
            'is_mixed' => count($categoryBreakdown) > 1,
            'total_count' => $totalClients,
            'composition_text' => $this->getBatchCompositionText($categoryBreakdown, $barangay)
        ];
        
        // Debug: Log the client data received
        error_log("DEBUG: Starting batch email send to {$totalClients} clients for {$barangay}");
        error_log("DEBUG: Batch composition: " . json_encode($categoryBreakdown));
        foreach ($clientsData as $i => $client) {
            error_log("DEBUG: Client " . ($i + 1) . " - Name: " . ($client['name'] ?? 'N/A') . ", Email: " . ($client['email'] ?? 'N/A') . ", Category: " . ($client['inspection_category'] ?? 'N/A'));
        }
        
        foreach ($clientsData as $client) {
            $maxRetries = 2;
            $attempt = 0;
            $emailSent = false;
            
            while ($attempt < $maxRetries && !$emailSent) {
                try {
                    $attempt++;
                    
                    // Check if already sent to this email in this batch
                    $emailKey = strtolower(trim($client['email']));
                    if (in_array($emailKey, $sentEmails)) {
                        error_log("DUPLICATE PREVENTION: Email already sent to {$emailKey} in this batch, skipping...");
                        break;
                    }
                    
                    // Validate client data before attempting to send
                    if (empty($client['email']) || !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
                        $failedEmails[] = [
                            'email' => $client['email'] ?? 'NULL',
                            'name' => $client['name'] ?? 'Unknown',
                            'reason' => 'Invalid email address'
                        ];
                        error_log("BATCH EMAIL ERROR: Invalid email for client " . ($client['name'] ?? 'Unknown') . " - Email: " . ($client['email'] ?? 'NULL'));
                        break; // Don't retry invalid emails
                    }
                    
                    // Validate required client fields
                    if (empty($client['name'])) {
                        error_log("WARNING: Missing client name for email " . $client['email'] . ", using default");
                        $client['name'] = 'Valued Client';
                    }
                    
                    $this->mail->SMTPDebug = 0;
                    $this->mail->clearAddresses();
                    $this->mail->clearAttachments();
                    
                    $this->mail->setFrom($this->fromEmail, $this->fromName);
                    $this->mail->addAddress($client['email'], $client['name']);
                    
                    $this->mail->isHTML(true);
                    
                    // Dynamic subject based on batch composition
                    $subjectType = $batchComposition['is_mixed'] ? 'Property Assessment' : 
                                  (array_key_first($batchComposition['categories']) . ' Assessment');
                    $this->mail->Subject = "AssessPro - {$subjectType} Scheduled - {$barangay} Batch";
                    
                    $htmlBody = $this->getBatchSchedulingTemplate($client, $barangay, $scheduleInfo, $totalClients, $batchComposition);
                    $this->mail->Body = $htmlBody;
                    
                    $this->mail->AltBody = $this->getBatchSchedulingPlainText($client, $barangay, $scheduleInfo, $totalClients, $batchComposition);
                    
                    $this->mail->send();
                    $successCount++;
                    $emailSent = true;
                    $sentEmails[] = $emailKey; // Track this email as sent
                    error_log("✅ BATCH EMAIL SUCCESS: Sent to " . $client['email'] . " (" . $client['name'] . ")" . ($attempt > 1 ? " [Retry {$attempt}]" : ""));
                    
                } catch (Exception $e) {
                    error_log("❌ BATCH EMAIL ATTEMPT {$attempt} FAILED: " . $client['email'] . " - " . $e->getMessage());
                    
                    if ($attempt >= $maxRetries) {
                        $failedEmails[] = [
                            'email' => $client['email'] ?? 'NULL',
                            'name' => $client['name'] ?? 'Unknown',
                            'reason' => $e->getMessage() . " (Failed after {$maxRetries} attempts)"
                        ];
                    } else {
                        // Wait before retry
                        sleep(2);
                    }
                }
            }
            
            // Delay between different clients to avoid rate limiting
            usleep(300000); // 0.3 second delay
        }
        
        // Detailed completion log
        error_log("=== BATCH EMAIL SUMMARY ===");
        error_log("Total clients in batch: {$totalClients}");
        error_log("Unique emails sent: {$successCount}");
        error_log("Failed emails: " . count($failedEmails));
        error_log("Actually sent to: " . implode(', ', $sentEmails));
        error_log("Accuracy: " . ($totalClients > 0 ? round(($successCount / $totalClients) * 100, 2) : 0) . "%");
        
        if (!empty($failedEmails)) {
            error_log("=== FAILED EMAIL DETAILS ===");
            foreach ($failedEmails as $failed) {
                error_log("Failed: {$failed['email']} ({$failed['name']}) - Reason: {$failed['reason']}");
            }
        }
        
        // Close SMTP connection
        $this->mail->smtpClose();
        
        // Return comprehensive results
        return [
            'emails_sent' => $successCount,
            'total_clients' => $totalClients,
            'failed_emails' => $failedEmails,
            'sent_to' => $sentEmails,
            'accuracy' => $totalClients > 0 ? round(($successCount / $totalClients) * 100, 2) : 0
        ];
    }
    
    /**
     * Generate acceptance email template for Building & Machinery
     */
    private function getAcceptanceTemplate($clientData, $formData) {
        $currentDate = date('F j, Y');
        $scheduledDate = isset($formData['requested_inspection_date']) && $formData['requested_inspection_date'] !== 'To be scheduled' 
            ? date('F j, Y', strtotime($formData['requested_inspection_date'])) 
            : $currentDate;
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
                .container { max-width: 650px; margin: 20px auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #2d8659, #3da66b); color: white; padding: 30px 20px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 300; }
                .header p { margin: 10px 0 0 0; opacity: 0.9; font-size: 16px; }
                .content { padding: 30px; }
                .acceptance-badge { display: inline-block; padding: 10px 20px; background: #4caf50; color: white; border-radius: 25px; font-weight: bold; font-size: 16px; margin: 15px 0; }
                .form-details { background: #f8f9fa; padding: 25px; border-radius: 8px; margin: 25px 0; border-left: 5px solid #2d8659; }
                .form-details h3 { margin-top: 0; color: #2d8659; font-size: 20px; }
                .detail-row { margin: 12px 0; }
                .detail-label { font-weight: 600; color: #555; display: inline-block; min-width: 140px; }
                .detail-value { color: #333; }
                .next-steps { background: #e8f5e8; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #4caf50; }
                .footer { padding: 25px; text-align: center; font-size: 13px; color: #666; background: #f8f9fa; }
                .contact-info { background: #fff3cd; padding: 15px; border-radius: 6px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>&#128197; Assessment Scheduled!</h1>
                    <p>Your {$formData['category']} assessment has been scheduled</p>
                </div>
                
                <div class='content'>
                    <h2>Dear {$clientData['name']},</h2>
                    <p>We are pleased to inform you that your {$formData['category']} assessment request has been <strong>accepted and scheduled</strong> for your preferred date.</p>
                    
                    <div class='acceptance-badge'>&#10003; SCHEDULED</div>
                    
                    <div class='form-details'>
                        <h3>&#128196; Your Request Details</h3>
                        <div class='detail-row'><span class='detail-label'>Request ID:</span> <span class='detail-value'>#{$formData['request_id']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Inspection Category:</span> <span class='detail-value'>{$formData['category']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Property Location:</span> <span class='detail-value'>{$formData['property_address']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Property Classification:</span> <span class='detail-value'>{$formData['property_type']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Land Reference/ARP:</span> <span class='detail-value'>{$formData['land_reference']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Contact Person:</span> <span class='detail-value'>{$formData['contact_person']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Purpose:</span> <span class='detail-value'>{$formData['purpose']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Submitted Date:</span> <span class='detail-value'>{$formData['submission_date']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Scheduled Date:</span> <span class='detail-value'><strong style='color: #2d8659;'>{$scheduledDate}</strong></span></div>
                    </div>
                    
                    <div class='next-steps'>
                        <h3 style='margin-top: 0; color: #2e7d32;'>&#9654; Next Steps in the Assessment Process</h3>
                        <ul style='margin: 10px 0; padding-left: 25px;'>
                            <li><strong>&#10003; Assessment Scheduled:</strong> Your {$formData['category']} assessment has been officially scheduled for your preferred date</li>
                            <li><strong>&#9742; Pre-Assessment Contact:</strong> Our assessment team will contact you within 24-48 hours to confirm the exact time and provide preparation instructions</li>
                            <li><strong>&#8962; On-Site Assessment:</strong> Our certified municipal assessor will conduct the property assessment at the scheduled date and time</li>
                            <li><strong>&#128196; Assessment Report:</strong> You will receive the completed assessment report within 5-7 business days after the site visit</li>
                        </ul>
                        
                        <div style='background: #d4edda; padding: 15px; border-radius: 6px; margin-top: 15px; border-left: 4px solid #28a745;'>
                            <p style='margin: 0; font-weight: 600; color: #155724;'>
                                &#9888; <strong>Important:</strong> Please ensure someone is available at the scheduled time and have all necessary documents ready for the assessor.
                            </p>    
                        </div>
                    </div>
                    
                    <div class='contact-info'>
                        <h4 style='margin-top: 0; color: #856404;'>&#9742; Contact Information</h4>
                        <p style='margin-bottom: 0;'>
                            <strong>Assessment Office:</strong> Municipal Property Assessment Division<br>
                            <strong>Email:</strong> assesspro2025@gmail.com<br>
                            <strong>Office Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM<br>
                            <strong>Location:</strong> Municipal Hall, Assessment Division
                        </p>
                    </div>
                    
                    <p>Thank you for using AssessPro for your municipal property assessment needs. We are committed to providing professional and accurate assessment services.</p>
                    
                    <p><strong>Sincerely,</strong><br>
                    Municipal Property Assessment Office<br>
                    <em>AssessPro Municipal Assessment System</em></p>
                </div>
                
                <div class='footer'>
                    <p>This is an automated notification from AssessPro Municipal Property Assessment System.</p>
                    <p>Request ID: #{$formData['request_id']} | Accepted on {$currentDate}</p>
                    <p>© " . date('Y') . " Property Assessment Pro. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Generate batch scheduling email template for Property inspections (matching building/machinery format)
     */
    private function getBatchSchedulingTemplate($clientData, $barangay, $scheduleInfo, $totalClients, $batchComposition = null) {
        // Ensure required data fields exist with defaults
        $clientData['request_id'] = $clientData['request_id'] ?? 'BATCH-' . strtoupper($barangay) . '-' . date('Ymd');
        $clientData['name'] = $clientData['name'] ?? 'Valued Client';
        $clientData['property_address'] = $clientData['property_address'] ?? $barangay . ' (as per your submitted request)';
        
        // Dynamic content based on batch composition
        $clientCategory = $clientData['inspection_category'] ?? 'Property';
        $isMultiCategory = $batchComposition && $batchComposition['is_mixed'];
        
        if ($isMultiCategory) {
            $clientData['property_type'] = $clientCategory . ' Assessment (Mixed Batch)';
            $assessmentType = 'Property Assessment';
        } else {
            $clientData['property_type'] = $clientData['property_type'] ?? $clientCategory . ' Assessment';
            $assessmentType = $clientCategory . ' Assessment';
        }
        
        $clientData['submission_date'] = $clientData['submission_date'] ?? date('F j, Y');
        
        // Ensure schedule info exists with defaults
        $scheduleInfo['inspection_date'] = $scheduleInfo['inspection_date'] ?? 'To be confirmed';
        $scheduleInfo['time_window'] = $scheduleInfo['time_window'] ?? '8:00 AM - 5:00 PM';
        $scheduleInfo['duration'] = $scheduleInfo['duration'] ?? '30-45 minutes per property';
        
        // Get batch composition text
        $batchCompositionText = $batchComposition ? $batchComposition['composition_text'] : "{$totalClients} properties in {$barangay}";
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
                .container { max-width: 650px; margin: 20px auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #2d8659, #3da66b); color: white; padding: 30px 20px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 300; }
                .header p { margin: 10px 0 0 0; opacity: 0.9; font-size: 16px; }
                .content { padding: 30px; }
                .acceptance-badge { display: inline-block; padding: 10px 20px; background: #4caf50; color: white; border-radius: 25px; font-weight: bold; font-size: 16px; margin: 15px 0; }
                .form-details { background: #f8f9fa; padding: 25px; border-radius: 8px; margin: 25px 0; border-left: 5px solid #2d8659; }
                .form-details h3 { margin-top: 0; color: #2d8659; font-size: 20px; }
                .detail-row { margin: 12px 0; }
                .detail-label { font-weight: 600; color: #555; display: inline-block; min-width: 140px; }
                .detail-value { color: #333; }
                .next-steps { background: #e8f5e8; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #4caf50; }
                .footer { padding: 25px; text-align: center; font-size: 13px; color: #666; background: #f8f9fa; }
                .contact-info { background: #fff3cd; padding: 15px; border-radius: 6px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>&#128197; {$assessmentType} Scheduled!</h1>
                    <p>Your {$clientCategory} assessment has been scheduled</p>
                </div>
                
                <div class='content'>
                    <h2>Dear {$clientData['name']},</h2>
                    <p>We are pleased to inform you that your <strong>{$clientCategory} assessment request</strong> has been <strong>accepted and scheduled</strong> as part of our efficient batch assessment process for {$barangay}.</p>
                    
                    <div class='acceptance-badge'>&#10003; SCHEDULED</div>
                    
                    <div class='form-details'>
                        <h3>📋 Inspection Details</h3>
                        <div class='detail-row'><span class='detail-label'>Request ID:</span> <span class='detail-value'>#{$clientData['request_id']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Property Address:</span> <span class='detail-value'>{$clientData['property_address']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Property Type:</span> <span class='detail-value'>{$clientData['property_type']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Submission Date:</span> <span class='detail-value'>{$clientData['submission_date']}</span></div>
                    </div>
                    
                    <div class='form-details'>
                        <h3>🗓️ Schedule Information</h3>
                        <div class='detail-row'><span class='detail-label'>Inspection Date:</span> <span class='detail-value'>{$scheduleInfo['inspection_date']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Time Window:</span> <span class='detail-value'>{$scheduleInfo['time_window']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Estimated Duration:</span> <span class='detail-value'>{$scheduleInfo['duration']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Batch Size:</span> <span class='detail-value'>{$batchCompositionText}</span></div>
                    </div>
                    
                    <div class='next-steps'>
                        <h3 style='margin-top: 0; color: #2e7d32;'>📋 Assessment Preparation Steps</h3>
                        <p><strong>1. Property Preparation:</strong> Ensure the property is accessible and all relevant documents are available for review</p>
                        <p><strong>2. Availability:</strong> A property owner or authorized representative must be present during the scheduled assessment time</p>
                        <p><strong>3. Communication:</strong> Keep your contact information current and phone available for coordination with our assessment team</p>
                    </div>
                    
                    <div class='contact-info'>
                        <h4 style='margin-top: 0; color: #856404;'>📞 Assessment Office Contact</h4>
                        <p style='margin-bottom: 0;'>
                            <strong>Department:</strong> Municipal Assessor<br>
                            <strong>Email:</strong> assesspro2025@gmail.com<br>
                            <strong>Office Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM<br>
                            <strong>Location:</strong> Municipal Hall, Mabini, Batangas
                        </p>
                    </div>
                    
                    <p>Our batch assessment approach allows us to efficiently serve multiple properties in {$barangay} while maintaining the highest standards of municipal property assessment and ensuring fair and accurate evaluations.</p>
                    
                    <p><strong>Sincerely,</strong><br>
                    Municipal Property Assessment Office<br>
                    <em>AssessPro Municipal Assessment System</em></p>
                </div>
                
                <div class='footer'>
                    <p>This is an automated notification from AssessPro Municipal Property Assessment System.</p>
                    <p>Request ID: #{$clientData['request_id']} | Batch: {$barangay} ({$totalClients} properties) | Scheduled on {$scheduleInfo['inspection_date']}</p>
                    <p>© " . date('Y') . " Property Assessment Pro. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Plain text versions for accessibility
     */
    private function getAcceptancePlainText($clientData, $formData) {
        $currentDate = date('F j, Y');
        $scheduledDate = isset($formData['requested_inspection_date']) && $formData['requested_inspection_date'] !== 'To be scheduled' 
            ? date('F j, Y', strtotime($formData['requested_inspection_date'])) 
            : $currentDate;
        
        return "ASSESSMENT SCHEDULED - ASSESSPRO\n\n" .
               "Dear {$clientData['name']},\n\n" .
               "We are pleased to inform you that your {$formData['category']} assessment request has been ACCEPTED and SCHEDULED for your preferred date.\n\n" .
               "YOUR REQUEST DETAILS:\n" .
               "Request ID: #{$formData['request_id']}\n" .
               "Inspection Category: {$formData['category']}\n" .
               "Property Location: {$formData['property_address']}\n" .
               "Property Classification: {$formData['property_type']}\n" .
               "Land Reference/ARP: {$formData['land_reference']}\n" .
               "Contact Person: {$formData['contact_person']}\n" .
               "Purpose: {$formData['purpose']}\n" .
               "Submitted: {$formData['submission_date']}\n" .
               "Scheduled: {$scheduledDate}\n\n" .
               "NEXT STEPS IN THE ASSESSMENT PROCESS:\n" .
               "1. Assessment Scheduled: Your assessment has been officially scheduled for your preferred date\n" .
               "2. Pre-Assessment Contact: Our team will contact you within 24-48 hours with final details\n" .
               "3. On-Site Assessment: Our certified municipal assessor will conduct the property assessment\n" .
               "4. Assessment Report: You will receive the completed report within 5-7 business days\n\n" .
               "IMPORTANT: Please ensure someone is available at the scheduled time.\n\n" .
               "Contact: Municipal Property Assessment Division | assesspro2025@gmail.com\n" .
               "Office Hours: Monday - Friday, 8:00 AM - 5:00 PM\n" .
               "Location: Municipal Hall, Assessment Division\n\n" .
               "Sincerely,\n" .
               "Municipal Property Assessment Office\n" .
               "AssessPro Municipal Assessment System";
    }
    
    private function getBatchSchedulingPlainText($clientData, $barangay, $scheduleInfo, $totalClients, $batchComposition = null) {
        // Ensure required data fields exist with defaults
        $clientData['request_id'] = $clientData['request_id'] ?? 'BATCH-' . strtoupper($barangay) . '-' . date('Ymd');
        $clientData['name'] = $clientData['name'] ?? 'Valued Client';
        $clientData['property_address'] = $clientData['property_address'] ?? $barangay . ' (as per your submitted request)';
        
        // Dynamic content based on batch composition
        $clientCategory = $clientData['inspection_category'] ?? 'Property';
        $isMultiCategory = $batchComposition && $batchComposition['is_mixed'];
        
        if ($isMultiCategory) {
            $clientData['property_type'] = $clientCategory . ' Assessment (Mixed Batch)';
            $assessmentType = 'PROPERTY ASSESSMENT';
        } else {
            $clientData['property_type'] = $clientData['property_type'] ?? $clientCategory . ' Assessment';
            $assessmentType = strtoupper($clientCategory) . ' ASSESSMENT';
        }
        
        $clientData['submission_date'] = $clientData['submission_date'] ?? date('F j, Y');
        $clientData['area'] = $clientData['area'] ?? 'As specified in application';
        
        // Ensure schedule info exists with defaults
        $scheduleInfo['inspection_date'] = $scheduleInfo['inspection_date'] ?? 'To be confirmed';
        $scheduleInfo['time_window'] = $scheduleInfo['time_window'] ?? '8:00 AM - 5:00 PM';
        $scheduleInfo['duration'] = $scheduleInfo['duration'] ?? '30-45 minutes per property';
        
        // Get batch composition text
        $batchCompositionText = $batchComposition ? $batchComposition['composition_text'] : "{$totalClients} properties in {$barangay}";
        
        return "{$assessmentType} SCHEDULED - ASSESSPRO\n\n" .
               "Dear {$clientData['name']},\n\n" .
               "We are pleased to inform you that your {$clientCategory} assessment request has been ACCEPTED and SCHEDULED as part of our efficient batch assessment process for {$barangay}.\n\n" .
               "YOUR REQUEST DETAILS:\n" .
               "Request ID: #{$clientData['request_id']}\n" .
               "Property Location: {$clientData['property_address']}\n" .
               "Assessment Type: {$clientData['property_type']}\n" .
               "Area: {$clientData['area']}\n" .
               "Submission Date: {$clientData['submission_date']}\n\n" .
               "SCHEDULE INFORMATION:\n" .
               "Inspection Date: {$scheduleInfo['inspection_date']}\n" .
               "Time Window: {$scheduleInfo['time_window']}\n" .
               "Estimated Duration: {$scheduleInfo['duration']}\n" .
               "Batch Size: {$batchCompositionText}\n\n" .
               "WHAT'S NEXT:\n" .
               "1. Prepare for Inspection: Ensure property is accessible and documents are ready\n" .
               "2. Be Available: Someone should be present during the scheduled time window\n" .
               "3. Contact Information: Keep your phone available for any updates from our team\n" .
               "4. Post-Inspection: Results will be processed and sent to you within 5-7 business days\n\n" .
               "NEED HELP OR HAVE QUESTIONS?\n" .
               "Phone: 09989595966\n" .
               "Email: assesspro2025@gmail.com\n" .
               "Office Hours: Monday - Friday, 8:00 AM - 5:00 PM\n\n" .
               "Best regards,\n" .
               "Property Assessment Office\n" .
               "Professional Property Assessment Services";
    }

    /**
     * Generate decline email template
     */
    private function getDeclineTemplate($clientData, $formData, $declineReason) {
        $currentDate = date('F j, Y');
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
                .container { max-width: 650px; margin: 20px auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 30px 20px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 300; }
                .header p { margin: 10px 0 0 0; opacity: 0.9; font-size: 16px; }
                .content { padding: 30px; }
                .status-badge { display: inline-block; padding: 10px 20px; background: #dc3545; color: white; border-radius: 25px; font-weight: bold; font-size: 16px; margin: 15px 0; }
                .form-details { background: #f8f9fa; padding: 25px; border-radius: 8px; margin: 25px 0; border-left: 5px solid #dc3545; }
                .form-details h3 { margin-top: 0; color: #dc3545; font-size: 20px; }
                .detail-row { margin: 12px 0; }
                .detail-label { font-weight: 600; color: #555; display: inline-block; min-width: 140px; }
                .detail-value { color: #333; }
                .reason-section { background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107; }
                .next-steps { background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #007bff; }
                .footer { padding: 25px; text-align: center; font-size: 13px; color: #666; background: #f8f9fa; }
                .contact-info { background: #f8d7da; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #dc3545; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>&#10060; Request Update</h1>
                    <p>Update regarding your {$formData['category']} inspection request</p>
                </div>
                
                <div class='content'>
                    <h2>Dear {$clientData['name']},</h2>
                    <p>We have completed the review of your {$formData['category']} inspection request. Unfortunately, we are unable to proceed with your request at this time.</p>
                    
                    <div class='status-badge'>&#10060; REQUEST DECLINED</div>
                    
                    <div class='form-details'>
                        <h3>&#128196; Your Request Details</h3>
                        <div class='detail-row'><span class='detail-label'>Request ID:</span> <span class='detail-value'>#{$formData['request_id']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Inspection Category:</span> <span class='detail-value'>{$formData['category']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Property Location:</span> <span class='detail-value'>{$formData['property_address']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Submitted Date:</span> <span class='detail-value'>{$formData['submission_date']}</span></div>
                        <div class='detail-row'><span class='detail-label'>Review Date:</span> <span class='detail-value'>{$currentDate}</span></div>
                    </div>
                    
                    <div class='reason-section'>
                        <h3 style='margin-top: 0; color: #856404;'>&#9888; Reason for Decline</h3>
                        <p style='margin-bottom: 0; font-weight: 500;'>{$declineReason}</p>
                    </div>
                    
                    <div class='next-steps'>
                        <h3 style='margin-top: 0; color: #0056b3;'>&#9654; What You Can Do</h3>
                        <ul style='margin: 10px 0; padding-left: 25px;'>
                            <li><strong>&#128337; Review Requirements:</strong> Check if you can address the concerns mentioned above</li>
                            <li><strong>&#128393; Resubmit Request:</strong> You may submit a new request with corrected information</li>
                            <li><strong>&#9742; Contact Us:</strong> Call our office for clarification or assistance</li>
                        </ul>
                    </div>
                    
                    <div class='contact-info'>
                        <h4 style='margin-top: 0; color: #721c24;'>&#9742; Need Help? Contact Us</h4>
                        <p style='margin-bottom: 0;'>
                            <strong>Phone:</strong> 09989595966<br>
                            <strong>Email:</strong> assesspro2025@gmail.com<br>
                            <strong>Office Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM
                        </p>
                    </div>
                    
                    <p>We apologize for any inconvenience this may cause. Our team is committed to providing quality assessment services, and we appreciate your understanding.</p>
                    
                    <p><strong>Best regards,</strong><br>
                    Property Assessment Office<br>
                    <em>Professional Property Assessment Services</em></p>
                </div>
                
                <div class='footer'>
                    <p>This is an automated notification from the Property Assessment System.</p>
                    <p>Request ID: #{$formData['request_id']} | Declined on {$currentDate}</p>
                    <p>© " . date('Y') . " Property Assessment Pro. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Generate decline email plain text version
     */
    private function getDeclinePlainText($clientData, $formData, $declineReason) {
        $currentDate = date('F j, Y');
        
        return "REQUEST UPDATE - DECLINED\n\n" .
               "Dear {$clientData['name']},\n\n" .
               "We have completed the review of your {$formData['category']} inspection request. Unfortunately, we are unable to proceed with your request at this time.\n\n" .
               "YOUR REQUEST DETAILS:\n" .
               "Request ID: #{$formData['request_id']}\n" .
               "Inspection Category: {$formData['category']}\n" .
               "Property Location: {$formData['property_address']}\n" .
               "Submitted: {$formData['submission_date']}\n" .
               "Review Date: {$currentDate}\n\n" .
               "REASON FOR DECLINE:\n" .
               "{$declineReason}\n\n" .
               "WHAT YOU CAN DO:\n" .
               "1. Review Requirements: Check if you can address the concerns mentioned above\n" .
               "2. Resubmit Request: You may submit a new request with corrected information\n" .
               "3. Contact Us: Call our office for clarification or assistance\n\n" .
               "CONTACT INFORMATION:\n" .
               "Phone: (555) 123-4567\n" .
               "Email: assesspro2025@gmail.com\n" .
               "Office Hours: Monday - Friday, 8:00 AM - 5:00 PM\n\n" .
               "We apologize for any inconvenience. We appreciate your understanding.\n\n" .
               "Best regards,\n" .
               "Property Assessment Office";
    }

    /**
     * Test basic connectivity without authentication
     */
    public function testConnection() {
        try {
            $smtp = new SMTP();
            $smtp->connect('smtp.gmail.com', 587, 30);
            $smtp->quit();
            return true;
        } catch (Exception $e) {
            error_log("Connection test failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate human-readable batch composition text
     */
    private function getBatchCompositionText($categoryBreakdown, $barangay) {
        if (count($categoryBreakdown) === 1) {
            // Single category batch
            $category = array_key_first($categoryBreakdown);
            $count = $categoryBreakdown[$category];
            return "{$count} {$category} " . ($count > 1 ? 'assessments' : 'assessment') . " in {$barangay}";
        } else {
            // Mixed category batch
            $parts = [];
            foreach ($categoryBreakdown as $category => $count) {
                $parts[] = "{$count} {$category}";
            }
            $total = array_sum($categoryBreakdown);
            return implode(', ', $parts) . " (Total: {$total} assessments in {$barangay})";
        }
    }
    
    /**
     * Generate request accepted email template
     */
    private function getRequestAcceptedTemplate($clientData) {
        $currentDate = date('F j, Y');
        $category = $clientData['inspection_category'] ?? 'Property';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f5f5f5; }
                .container { max-width: 650px; margin: 20px auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 30px 20px; text-align: center; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 300; }
                .header p { margin: 10px 0 0 0; opacity: 0.9; font-size: 16px; }
                .content { padding: 30px; }
                .status-badge { display: inline-block; padding: 12px 24px; background: #28a745; color: white; border-radius: 25px; font-weight: bold; font-size: 16px; margin: 15px 0; text-transform: uppercase; letter-spacing: 1px; }
                .request-details { background: #f8f9fa; padding: 25px; border-radius: 8px; margin: 25px 0; border-left: 5px solid #28a745; }
                .request-details h3 { margin-top: 0; color: #28a745; font-size: 20px; }
                .detail-row { margin: 12px 0; }
                .detail-label { font-weight: 600; color: #555; display: inline-block; min-width: 140px; }
                .detail-value { color: #333; }
                .next-steps { background: #d1edff; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #007bff; }
                .next-steps h3 { margin-top: 0; color: #007bff; }
                .highlight-box { background: #fff3cd; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #ffc107; }
                .footer { padding: 25px; text-align: center; font-size: 13px; color: #666; background: #f8f9fa; }
                .contact-info { background: #d4edda; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #28a745; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✓ Request Accepted</h1>
                    <p>Your {$category} inspection request has been approved</p>
                </div>
                
                <div class='content'>
                    <h2>Dear {$clientData['name']},</h2>
                    <p>Great news! Our staff has reviewed and <strong>accepted</strong> your {$category} inspection request.</p>
                    
                    <div class='status-badge'>✓ Accepted</div>
                    
                    <div class='request-details'>
                        <h3>Request Details</h3>
                        <div class='detail-row'>
                            <span class='detail-label'>Request ID:</span>
                            <span class='detail-value'>#{$clientData['id']}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Category:</span>
                            <span class='detail-value'>{$category}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Location:</span>
                            <span class='detail-value'>{$clientData['location']}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Accepted Date:</span>
                            <span class='detail-value'>{$currentDate}</span>
                        </div>
                    </div>
                    
                    <div class='next-steps'>
                        <h3>What happens next?</h3>
                        <p><strong>1. Scheduling Process:</strong> Your request is now in the scheduling queue. Our staff will coordinate with you to arrange the inspection date.</p>
                        <p><strong>2. Notification:</strong> You will receive another email notification once your inspection has been scheduled with specific date.</p>
                        <p><strong>3. Preparation:</strong> Please ensure the property is accessible and any required documents are ready for the assessment.</p>
                    </div>
                    
                    
                    <div class='contact-info'>
                        <p><strong>Questions or concerns?</strong><br>
                        Contact us at: <strong>assesspro2025@gmail.com</strong><br>
                        Phone: <strong>09989595966</strong></p>
                    </div>
                    
                    <p>Thank you for choosing AssessPro for your property assessment needs.</p>
                    
                    <p style='margin-top: 30px;'>
                    Best regards,<br>
                    <strong>AssessPro Team</strong><br>
                    Municipal Assessor's Office<br>
                    Mabini, Batangas
                    </p>
                </div>
                
                <div class='footer'>
                    <p>This is an automated notification from AssessPro - Municipal Property Assessment System</p>
                    <p>&copy; " . date('Y') . " AssessPro. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Generate request accepted plain text version
     */
    private function getRequestAcceptedPlainText($clientData) {
        $currentDate = date('F j, Y');
        $category = $clientData['inspection_category'] ?? 'Property';
        
        return "
AssessPro - Request Accepted

Dear {$clientData['name']},

Great news! Our staff has reviewed and ACCEPTED your {$category} inspection request.

REQUEST DETAILS:
- Request ID: #{$clientData['id']}
- Category: {$category}
- Location: {$clientData['location']}
- Accepted Date: {$currentDate}

WHAT HAPPENS NEXT:

1. Scheduling Process: Your request is now in the scheduling queue. Our staff will coordinate with you to arrange the inspection date.

2. Notification: You will receive another email notification once your inspection has been scheduled with specific date and time details.

3. Preparation: Please ensure the property is accessible and any required documents are ready for the assessment.

IMPORTANT: Keep this email for your records. You will be contacted within 3-5 business days with your scheduled inspection details.

Questions or concerns?
Contact us at: assesspro2025@gmail.com
Phone: 09989595966

Thank you for choosing AssessPro for your property assessment needs.

Best regards,
AssessPro Team
Municipal Assessor's Office
Mabini, Batangas

---
This is an automated notification from AssessPro - Municipal Property Assessment System
© " . date('Y') . " AssessPro. All rights reserved.
        ";
    }
}
?>
