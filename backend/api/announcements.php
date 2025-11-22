<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database connection
try {
    $host = 'localhost';
    $dbname = 'assesspro_db';
    $username = 'root';
    $password = '';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Get request method and data
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

try {
    switch ($method) {
        case 'GET':
            // Get all active announcements
            $user_role = $_GET['role'] ?? 'all'; // all, staff, admin
            
            $sql = "SELECT id, subject, message, priority, category, expiry_date, 
                           author_name, view_count, created_at, updated_at 
                    FROM announcements 
                    WHERE is_active = 1 
                    AND (expiry_date IS NULL OR expiry_date >= CURDATE())";
            
            // Filter by target audience
            if ($user_role === 'staff') {
                $sql .= " AND (target_all = 1 OR target_staff = 1)";
            } elseif ($user_role === 'admin') {
                $sql .= " AND (target_all = 1 OR target_admins = 1)";
            }
            
            $sql .= " ORDER BY 
                     CASE priority 
                         WHEN 'urgent' THEN 1 
                         WHEN 'high' THEN 2 
                         WHEN 'normal' THEN 3 
                     END,
                     created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format dates for frontend
            foreach ($announcements as &$announcement) {
                $announcement['timestamp'] = $announcement['created_at'];
                $announcement['formattedDate'] = date('M j, Y g:i A', strtotime($announcement['created_at']));
            }
            
            echo json_encode([
                'success' => true,
                'data' => $announcements,
                'count' => count($announcements)
            ]);
            break;
            
        case 'POST':
            // Create new announcement
            if (!$input) {
                throw new Exception('No data provided');
            }
            
            $required = ['subject', 'message'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Missing required field: {$field}");
                }
            }
            
            $sql = "INSERT INTO announcements 
                    (subject, message, priority, category, expiry_date, 
                     target_all, target_staff, target_admins, author_name) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                $input['subject'],
                $input['message'],
                $input['priority'] ?? 'normal',
                $input['category'] ?? 'general',
                !empty($input['expiry']) ? $input['expiry'] : null,
                $input['targetAll'] ?? true,
                $input['targetStaff'] ?? false,
                $input['targetAdmins'] ?? false,
                $input['authorName'] ?? 'Head Assessor'
            ]);
            
            if ($result) {
                $announcementId = $pdo->lastInsertId();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Announcement created successfully',
                    'id' => $announcementId
                ]);
            } else {
                throw new Exception('Failed to create announcement');
            }
            break;
            
        case 'PUT':
            // Update announcement
            if (!$input || !isset($input['id'])) {
                throw new Exception('Announcement ID required');
            }
            
            $sql = "UPDATE announcements SET 
                    subject = ?, message = ?, priority = ?, category = ?, 
                    expiry_date = ?, target_all = ?, target_staff = ?, target_admins = ?,
                    updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                $input['subject'],
                $input['message'], 
                $input['priority'] ?? 'normal',
                $input['category'] ?? 'general',
                !empty($input['expiry']) ? $input['expiry'] : null,
                $input['targetAll'] ?? true,
                $input['targetStaff'] ?? false,
                $input['targetAdmins'] ?? false,
                $input['id']
            ]);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Announcement updated successfully' : 'Failed to update announcement'
            ]);
            break;
            
        case 'DELETE':
            // Soft delete announcement
            if (!$input || !isset($input['id'])) {
                throw new Exception('Announcement ID required');
            }
            
            $sql = "UPDATE announcements SET is_active = 0 WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$input['id']]);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Announcement deleted successfully' : 'Failed to delete announcement'
            ]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>