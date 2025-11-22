<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Include security classes
require_once '../security/AntiSpamManager.php';
require_once '../security/RequestSecurityMiddleware.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

try {
    // Log incoming request for debugging
    error_log("submit_request.php: Received " . $_SERVER['REQUEST_METHOD'] . " request at " . date('Y-m-d H:i:s'));
    
    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Only POST method allowed');
    }

    // Database connection
    $host = 'localhost';
    $dbname = 'assesspro_db';
    $username = 'root';
    $password = '';

    error_log("DEBUG: Attempting database connection...");
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("DEBUG: Database connection successful");

    // Support JSON input OR multipart/form-data (FormData with files)
    $input = null;
    $uploadedFilePath = null;

    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            throw new Exception('Invalid JSON input');
        }
    } else {
        // Try to read from $_POST for form submissions (multipart/form-data)
        $input = $_POST;
        error_log("DEBUG: Processing form data: " . print_r($input, true));
        
        $validIdData = null;
        $validIdType = null;
        $validIdName = null;

        // Handle file upload if present
        if (isset($_FILES['validId']) && $_FILES['validId']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['validId']['tmp_name'];
            $fileName = basename($_FILES['validId']['name']);
            $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
            $mimeType = $_FILES['validId']['type'];

            // Validate file size and type
            $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array(strtolower($fileExt), $allowedExt)) {
                throw new Exception('Invalid file type for valid ID. Only JPG, PNG and PDF are allowed.');
            }

            // Check file size (limit to 5MB)
            if ($_FILES['validId']['size'] > 5 * 1024 * 1024) {
                throw new Exception('File size too large. Maximum size is 5MB.');
            }

            // Read file content and encode as base64 for database storage
            $fileContent = file_get_contents($fileTmpPath);
            if ($fileContent === false) {
                throw new Exception('Failed to read uploaded file.');
            }

            $validIdData = base64_encode($fileContent);
            $validIdType = $mimeType;
            $validIdName = $fileName;
        } else if (isset($_FILES['validId']) && $_FILES['validId']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Handle upload errors
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
                UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory available',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            $errorCode = $_FILES['validId']['error'];
            $errorMessage = isset($uploadErrors[$errorCode]) ? $uploadErrors[$errorCode] : 'Unknown upload error';
            throw new Exception('File upload failed: ' . $errorMessage);
        }
    }

    // Validate required fields
    error_log("DEBUG: Starting validation...");
    $errors = [];

    // SECURITY LAYER 1: Initialize security components
    error_log("DEBUG: Initializing security components...");
    $antiSpam = new AntiSpamManager();
    $securityMiddleware = new RequestSecurityMiddleware();
    
    // SECURITY LAYER 2: Validate request through security middleware
    error_log("DEBUG: Running security middleware validation...");
    $securityValidation = $securityMiddleware->validateRequest($input);
    if (!$securityValidation['valid']) {
        error_log("DEBUG: Security middleware validation failed: " . $securityValidation['message']);
        http_response_code(400);
        throw new Exception('Security validation failed: ' . $securityValidation['message']);
    }
    error_log("DEBUG: Security middleware validation passed");

    // SECURITY LAYER 3: Check rate limits and anti-spam using the main method
    $clientEmail = isset($input['clientEmail']) ? trim($input['clientEmail']) : '';
    if (!empty($clientEmail)) {
        error_log("DEBUG: Running comprehensive anti-spam check for: " . $clientEmail);
        
        // Use the main isRequestAllowed method that handles all security checks
        $clientData = [
            'email' => $clientEmail,
            'name' => isset($input['clientName']) ? $input['clientName'] : '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        $requestData = [
            'type' => 'property_assessment',
            'location' => isset($input['location']) ? $input['location'] : '',
            'category' => isset($input['inspectionCategory']) ? $input['inspectionCategory'] : 'Property'
        ];
        
        $spamCheckResult = $antiSpam->isRequestAllowed($clientData, $requestData, 'assessment');
        if (!$spamCheckResult['allowed']) {
            error_log("DEBUG: Anti-spam check failed: " . $spamCheckResult['message']);
            http_response_code($spamCheckResult['http_code'] ?? 429);
            throw new Exception($spamCheckResult['message']);
        }
        error_log("DEBUG: Anti-spam checks passed - request allowed");
    }

    // Continue with existing validation...
    error_log("DEBUG: Starting validation...");
    $required_fields = [
        'clientName' => 'Client name',
        'clientEmail' => 'Email address', 
        'inspectionCategory' => 'Inspection category',
        'location' => 'Location',
        'landmark' => 'Landmark',
        'landRef' => 'Land reference/ARP',
        'contactPerson' => 'Contact person',
        'contactNumber' => 'Contact number',
        'purpose' => 'Purpose'
    ];

    $errors = [];
    foreach ($required_fields as $field => $label) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            $errors[] = "$label is required";
            error_log("DEBUG: Validation failed for $field");
        } else {
            error_log("DEBUG: Validation passed for $field: " . $input[$field]);
        }
    }

    // Conditional validation for propertyClass (only required if inspectionCategory is 'Property')
    error_log("DEBUG: Checking property class validation...");
    if (isset($input['inspectionCategory']) && $input['inspectionCategory'] === 'Property') {
        if (!isset($input['propertyClass']) || empty(trim($input['propertyClass']))) {
            $errors[] = "Property classification is required when inspection category is 'Property'";
            error_log("DEBUG: Property class validation failed");
        } else {
            error_log("DEBUG: Property class validation passed: " . $input['propertyClass']);
        }
    } else {
        error_log("DEBUG: Property class validation skipped (category: " . ($input['inspectionCategory'] ?? 'not set') . ")");
    }

    // Email validation
    if (isset($input['clientEmail']) && !empty($input['clientEmail'])) {
        if (!filter_var($input['clientEmail'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address';
        }
    }

    // Contact number validation
    if (isset($input['contactNumber']) && !empty($input['contactNumber'])) {
        $contactNum = trim($input['contactNumber']);
        // Remove common formatting characters
        $cleanNumber = preg_replace('/[\s\-\(\)\+]/', '', $contactNum);
        
        // Check if it's a valid Philippine mobile number format
        if (!preg_match('/^(09|639)\d{9}$/', $cleanNumber) && !preg_match('/^\d{7,11}$/', $cleanNumber)) {
            $errors[] = 'Please provide a valid contact number (e.g., 09123456789)';
        }
    }

    // Property classification validation (only validate if provided and inspection category is Property)
    error_log("DEBUG: Checking property classification values...");
    $validClassifications = ['Residential', 'Commercial', 'Agricultural', 'Industrial'];
    if (isset($input['propertyClass']) && !empty(trim($input['propertyClass']))) {
        error_log("DEBUG: Property class provided: '" . $input['propertyClass'] . "'");
        if (!in_array($input['propertyClass'], $validClassifications)) {
            $errors[] = 'Invalid property classification';
            error_log("DEBUG: Invalid property classification: " . $input['propertyClass']);
        } else {
            error_log("DEBUG: Property classification validation passed");
        }
    } else {
        error_log("DEBUG: Property class is empty or not set - skipping validation");
    }

    if (!empty($errors)) {
        error_log("DEBUG: Validation errors found: " . implode(', ', $errors));
        error_log("DEBUG: Received data: " . print_r($input, true));
        http_response_code(400);
        throw new Exception('Validation failed: ' . implode(', ', $errors));
    }
    
    error_log("DEBUG: All validation passed, proceeding with database insert...");

    // Handle contact person and contact number
    $contactPerson = trim($input['contactPerson']);
    
    // Check if contactNumber field exists (new separate field)
    if (isset($input['contactNumber']) && !empty(trim($input['contactNumber']))) {
        $contactNumber = trim($input['contactNumber']);
    } else {
        // Fallback: Extract contact number from contact person field if it contains both (for old format)
        $contactNumber = '';
        if (preg_match('/(\d{10,})/', $contactPerson, $matches)) {
            $contactNumber = $matches[1];
            $contactPerson = trim(preg_replace('/\d{10,}/', '', $contactPerson));
        }
        
        // If no contact number found, use default
        if (empty($contactNumber)) {
            $contactNumber = 'N/A';
        }
    }

    // Insert assessment request into database
    $sql = "INSERT INTO assessment_requests (
            name, email, inspection_category, requested_inspection_date, property_classification, location,
            landmark, land_reference_arp, contact_person, contact_number,
            purpose, valid_id_data, valid_id_type, valid_id_name, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        trim($input['clientName']),
        trim($input['clientEmail']),
        $input['inspectionCategory'],
        (isset($input['requestedDate']) && !empty(trim($input['requestedDate']))) ? $input['requestedDate'] : null,
        (isset($input['propertyClass']) && !empty(trim($input['propertyClass']))) ? $input['propertyClass'] : null,
        $input['location'],
        trim($input['landmark']),
        trim($input['landRef']),
        $contactPerson,
        $contactNumber,
        trim($input['purpose']),
        $validIdData,
        $validIdType,
        $validIdName
    ]);
    
    $request_id = $pdo->lastInsertId();

    // Return success response
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Assessment request submitted successfully',
        'data' => [
            'id' => $request_id,
            'name' => trim($input['clientName']),
            'email' => trim($input['clientEmail']),
            'location' => $input['location'],
            'status' => 'pending',
            'reference_number' => 'AR-' . str_pad($request_id, 6, '0', STR_PAD_LEFT)
        ]
    ]);

} catch (PDOException $e) {
    error_log("Database error in submit_request.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred. Please try again later.',
        'debug_error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error in submit_request.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    if (!http_response_code()) {
        http_response_code(400);
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'debug_error' => $e->getMessage()
    ]);
}
?>
