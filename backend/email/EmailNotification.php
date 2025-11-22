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
        $this->fromName = 'Property Assessment Pro';
        
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
            $this->mail->Subject = "Assessment Request Update - #{$requestId}";
            
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
            $this->mail->Subject = "Assessment Request Confirmation - #{$requestId}";
            
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
            $this->mail->Subject = "Email Configuration Test";
            
            $this->mail->Body = "
            <html>
            <body>
                <h2>Email Test Successful! ✅</h2>
                <p>This is a test email to verify that your email configuration is working properly.</p>
                <p><strong>Test Time:</strong> " . date('Y-m-d H:i:s') . "</p>
                <p>If you received this email, your system is ready to send notifications!</p>
                <p>Sent from: Property Assessment Pro</p>
            </body>
            </html>";
            
            $this->mail->AltBody = "Email Test Successful! This is a test email sent on " . date('Y-m-d H:i:s');
            
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
                    <h1>Property Assessment Update</h1>
                </div>
                <div class='content'>
                    <h2>Hello {$clientName},</h2>
                    <p>We have an update regarding your property assessment request.</p>
                    
                    <p><strong>Request ID:</strong> #{$requestId}</p>
                    <p><strong>Current Status:</strong> <span class='status-badge'>{$status}</span></p>
                    
                    <h3>Status Update:</h3>
                    <p>{$statusMessage}</p>
                    
                    " . ($comments ? "<h3>Additional Comments:</h3><p>{$comments}</p>" : "") . "
                    
                    <p>If you have any questions, please don't hesitate to contact our office.</p>
                    
                    <p>Best regards,<br>
                    Property Assessment Office</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the Property Assessment System.</p>
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
                    <h1>Request Confirmation</h1>
                </div>
                <div class='content'>
                    <h2>Thank you, {$clientName}!</h2>
                    <p>Your property assessment request has been successfully submitted.</p>
                    
                    <p><strong>Request ID:</strong> #{$requestId}</p>
                    <p><strong>Status:</strong> <span style='color: #f59e0b; font-weight: bold;'>Pending Review</span></p>
                    
                    <div class='property-details'>
                        <h3>Property Information:</h3>
                        <p><strong>Address:</strong> {$propertyDetails['address']}</p>
                        <p><strong>Property Type:</strong> {$propertyDetails['type']}</p>
                        <p><strong>Area:</strong> {$propertyDetails['area']} sq.m.</p>
                    </div>
                    
                    <p>We will review your request and contact you within 3-5 business days.</p>
                    
                    <p>Best regards,<br>
                    Property Assessment Office</p>
                </div>
                <div class='footer'>
                    <p>This is an automated confirmation from the Property Assessment System.</p>
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
                return 'Your request is currently pending review. We will begin processing it shortly.';
            case 'in progress':
                return 'Our team is actively working on your property assessment.';
            case 'completed':
                return 'Great news! Your property assessment has been completed.';
            case 'cancelled':
                return 'Your assessment request has been cancelled.';
            default:
                return 'Your request status has been updated.';
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
        $message .= "We will review your request within 3-5 business days.\n\n";
        $message .= "Best regards,\nProperty Assessment Office";
        
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
            $this->mail->Subject = "Inspection Scheduled - {$formData['category']} #{$formData['request_id']}";
            
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
            $this->mail->Subject = "Request Update - {$formData['category']} #{$formData['request_id']}";
            
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
     * Send batch scheduling notification for Property inspections (when 10+ requests reached)
     */
    public function sendBatchSchedulingNotification($clientsData, $barangay, $scheduleInfo) {
        $successCount = 0;
        $failedEmails = [];
        $totalClients = count($clientsData);
        
        // Debug: Log the client data received
        error_log("DEBUG: Starting batch email send to {$totalClients} clients for {$barangay}");
        foreach ($clientsData as $i => $client) {
            error_log("DEBUG: Client " . ($i + 1) . " - Name: " . ($client['name'] ?? 'N/A') . ", Email: " . ($client['email'] ?? 'N/A') . ", Request ID: " . ($client['request_id'] ?? 'N/A'));
        }
        
        foreach ($clientsData as $client) {
            $maxRetries = 2;
            $attempt = 0;
            $emailSent = false;
            
            while ($attempt < $maxRetries && !$emailSent) {
                try {
                    $attempt++;
                    
                    // Validate email before attempting to send
                    if (empty($client['email']) || !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
                        $failedEmails[] = [
                            'email' => $client['email'] ?? 'NULL',
                            'name' => $client['name'] ?? 'Unknown',
                            'reason' => 'Invalid email address'
                        ];
                        error_log("BATCH EMAIL ERROR: Invalid email for client " . ($client['name'] ?? 'Unknown') . " - Email: " . ($client['email'] ?? 'NULL'));
                        break; // Don't retry invalid emails
                    }
                    
                    $this->mail->SMTPDebug = 0;
                    $this->mail->clearAddresses();
                    $this->mail->clearAttachments();
                    
                    $this->mail->setFrom($this->fromEmail, $this->fromName);
                    $this->mail->addAddress($client['email'], $client['name']);
                    
                    $this->mail->isHTML(true);
                    $this->mail->Subject = "Property Inspection Scheduled - {$barangay} Batch";
                    
                    $htmlBody = $this->getBatchSchedulingTemplate($client, $barangay, $scheduleInfo, $totalClients);
                    $this->mail->Body = $htmlBody;
                    
                    $this->mail->AltBody = $this->getBatchSchedulingPlainText($client, $barangay, $scheduleInfo, $totalClients);
                    
                    $this->mail->send();
                    $successCount++;
                    $emailSent = true;
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
        error_log("Total clients: {$totalClients}");
        error_log("Successful emails: {$successCount}");
        error_log("Failed emails: " . count($failedEmails));
        
        if (!empty($failedEmails)) {
            error_log("=== FAILED EMAIL DETAILS ===");
            foreach ($failedEmails as $failed) {
                error_log("Failed: {$failed['email']} ({$failed['name']}) - Reason: {$failed['reason']}");
            }
        }
        
        // Close SMTP connection
        $this->mail->smtpClose();
        
        return $successCount;
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
                    <h1>&#128197; Inspection Scheduled!</h1>
                    <p>Your {$formData['category']} inspection has been scheduled</p>
                </div>
                
                <div class='content'>
                    <h2>Great news, {$clientData['name']}!</h2>
                    <p>Your {$formData['category']} inspection request has been <strong>accepted and scheduled</strong> on your requested date.</p>
                    
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
                        <h3 style='margin-top: 0; color: #2e7d32;'>&#9654; What Happens Next?</h3>
                        <ul style='margin: 10px 0; padding-left: 25px;'>
                            <li><strong>&#10003; Inspection Scheduled:</strong> Your {$formData['category']} inspection has been scheduled for your requested date</li>
                            <li><strong>&#9742; Confirmation Call:</strong> Our team will contact you within 24-48 hours to confirm the exact time and provide final details</li>
                            <li><strong>&#8962; Site Inspection:</strong> Our certified assessor will visit your property at the scheduled date and time</li>
                        </ul>
                        
                        <div style='background: #d4edda; padding: 15px; border-radius: 6px; margin-top: 15px; border-left: 4px solid #28a745;'>
                            <p style='margin: 0; font-weight: 600; color: #155724;'>
                                &#9888; <strong>Important:</strong> Please ensure someone is available at the scheduled time and have all necessary documents ready for the assessor.
                            </p>
                        </div>
                    </div>
                    
                    <div class='contact-info'>
                        <h4 style='margin-top: 0; color: #856404;'>&#9742; Important Contact Information</h4>
                        <p style='margin-bottom: 0;'>
                            <strong>Phone:</strong> (555) 123-4567<br>
                            <strong>Email:</strong> assesspro2025@gmail.com<br>
                            <strong>Office Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM
                        </p>
                    </div>
                    
                    <p>Thank you for choosing Property Assessment Pro. We look forward to serving you!</p>
                    
                    <p><strong>Best regards,</strong><br>
                    Property Assessment Office<br>
                    <em>Professional Property Assessment Services</em></p>
                </div>
                
                <div class='footer'>
                    <p>This is an automated notification from the Property Assessment System.</p>
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
    private function getBatchSchedulingTemplate($clientData, $barangay, $scheduleInfo, $totalClients) {
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
                    <h1>&#128197; Inspection Scheduled!</h1>
                    <p>Your Property Assessment inspection has been scheduled</p>
                </div>
                
                <div class='content'>
                    <h2>Great news, {$clientData['name']}!</h2>
                    <p>Your Property Assessment inspection request has been <strong>accepted and scheduled</strong> as part of our efficient batch processing for {$barangay}.</p>
                    
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
                        <div class='detail-row'><span class='detail-label'>Batch Size:</span> <span class='detail-value'>{$totalClients} properties in {$barangay}</span></div>
                    </div>
                    
                    <div class='next-steps'>
                        <h3 style='margin-top: 0; color: #2e7d32;'>📋 What's Next?</h3>
                        <p><strong>1. Prepare for Inspection:</strong> Ensure property is accessible and documents are ready</p>
                        <p><strong>2. Be Available:</strong> Someone should be present during the scheduled time window</p>
                        <p><strong>3. Contact Information:</strong> Keep your phone available for any updates from our team</p>
                        <p><strong>4. Post-Inspection:</strong> Results will be processed and sent to you within 5-7 business days</p>
                    </div>
                    
                    <div class='contact-info'>
                        <h4 style='margin-top: 0; color: #856404;'>📞 Need Help or Have Questions?</h4>
                        <p style='margin-bottom: 0;'>
                            <strong>Phone:</strong> (555) 123-4567<br>
                            <strong>Email:</strong> assesspro2025@gmail.com<br>
                            <strong>Office Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM
                        </p>
                    </div>
                    
                    <p>This batch scheduling approach allows us to efficiently serve multiple properties in {$barangay} while maintaining the highest standards of property assessment.</p>
                    
                    <p><strong>Best regards,</strong><br>
                    Property Assessment Office<br>
                    <em>Professional Property Assessment Services</em></p>
                </div>
                
                <div class='footer'>
                    <p>This is an automated notification from the Property Assessment System.</p>
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
        
        return "INSPECTION SCHEDULED\n\n" .
               "Hello {$clientData['name']},\n\n" .
               "Great news! Your {$formData['category']} inspection request has been ACCEPTED and SCHEDULED on your requested date.\n\n" .
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
               "WHAT HAPPENS NEXT:\n" .
               "1. Inspection Scheduled: Your inspection has been scheduled for your requested date\n" .
               "2. Confirmation Call: Our team will contact you within 24-48 hours with final details\n" .
               "3. Site Inspection: Our certified assessor will visit at the scheduled time\n\n" .
               "IMPORTANT: Please ensure someone is available at the scheduled time.\n\n" .
               "Contact: (555) 123-4567 | assesspro2025@gmail.com\n" .
               "Office Hours: Monday - Friday, 8:00 AM - 5:00 PM\n\n" .
               "Best regards,\n" .
               "Property Assessment Office";
    }
    
    private function getBatchSchedulingPlainText($clientData, $barangay, $scheduleInfo, $totalClients) {
        return "INSPECTION SCHEDULED\n\n" .
               "Hello {$clientData['name']},\n\n" .
               "Great news! Your Property Assessment inspection request has been ACCEPTED and SCHEDULED as part of our efficient batch processing for {$barangay}.\n\n" .
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
               "Batch Size: {$totalClients} properties in {$barangay}\n\n" .
               "WHAT'S NEXT:\n" .
               "1. Prepare for Inspection: Ensure property is accessible and documents are ready\n" .
               "2. Be Available: Someone should be present during the scheduled time window\n" .
               "3. Contact Information: Keep your phone available for any updates from our team\n" .
               "4. Post-Inspection: Results will be processed and sent to you within 5-7 business days\n\n" .
               "NEED HELP OR HAVE QUESTIONS?\n" .
               "Phone: (555) 123-4567\n" .
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
                            <strong>Phone:</strong> (555) 123-4567<br>
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
}
?>
