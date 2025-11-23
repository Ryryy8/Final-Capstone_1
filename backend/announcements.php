<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration - Environment aware
require_once __DIR__ . '/config/db_config.php';

$host = DB_HOST;
$dbname = DB_NAME; // Change to your database name
$username = DB_USERNAME;
$password = DB_PASSWORD;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Function to detect user type based on request context
function getUserId() {
    // Always start session first
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Get the referer to determine user type
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    
    // Force correct user ID based on referer (most reliable method)
    if (strpos($referer, '/admin/') !== false || strpos($referer, 'admin.html') !== false) {
        // Force admin session
        $_SESSION['user_id'] = 'admin_user';
        $_SESSION['user_role'] = 'admin';
        return 'admin_user';
    } elseif (strpos($referer, '/staff/') !== false || strpos($referer, 'staff-dashboard.html') !== false) {
        // Force staff session  
        $_SESSION['user_id'] = 'staff_user';
        $_SESSION['user_role'] = 'staff';
        return 'staff_user';
    }
    
    // Check existing session as fallback
    if (isset($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    // Default fallback
    return 'unknown_user';
}

$method = $_SERVER['REQUEST_METHOD'];
$path = isset($_GET['path']) ? $_GET['path'] : '';

switch($method) {
    case 'GET':
        handleGetRequest($pdo, $path);
        break;
    case 'POST':
        handlePostRequest($pdo);
        break;
    case 'PUT':
        handlePutRequest($pdo, $path);
        break;
    case 'DELETE':
        handleDeleteRequest($pdo, $path);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function handleGetRequest($pdo, $path) {
    if ($path === 'stats') {
        getAnnouncementStats($pdo);
    } elseif ($path === 'admin') {
        getAdminAnnouncements($pdo);
    } elseif ($path === 'staff') {
        getStaffAnnouncements($pdo);
    } elseif (strpos($path, 'mark-read/') === 0) {
        $id = (int)str_replace('mark-read/', '', $path);
        markAnnouncementAsRead($pdo, $id);
    } else {
        getAllAnnouncements($pdo);
    }
}

function handlePostRequest($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON input']);
        return;
    }
    
    createAnnouncement($pdo, $input);
}

function handlePutRequest($pdo, $path) {
    $id = (int)$path;
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input or ID']);
        return;
    }
    
    updateAnnouncement($pdo, $id, $input);
}

function handleDeleteRequest($pdo, $path) {
    $id = (int)$path;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        return;
    }
    
    deleteAnnouncement($pdo, $id);
}

function getAllAnnouncements($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                subject,
                message,
                priority,
                category,
                expiry_date,
                target_all,
                target_staff,
                target_admins,
                author_id,
                author_name,
                is_active,
                view_count,
                created_at,
                updated_at
            FROM announcements 
            WHERE is_active = 1 
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for frontend compatibility
        $formattedAnnouncements = array_map(function($announcement) {
            return [
                'id' => $announcement['id'],
                'subject' => $announcement['subject'],
                'message' => $announcement['message'],
                'priority' => $announcement['priority'],
                'category' => $announcement['category'],
                'expiry' => $announcement['expiry_date'],
                'targetRecipients' => [
                    'all' => (bool)$announcement['target_all'],
                    'staff' => (bool)$announcement['target_staff'],
                    'admins' => (bool)$announcement['target_admins']
                ],
                'timestamp' => $announcement['created_at'],
                'authorId' => $announcement['author_id'],
                'authorName' => $announcement['author_name'],
                'isActive' => (bool)$announcement['is_active'],
                'viewCount' => (int)$announcement['view_count'],
                'createdAt' => $announcement['created_at'],
                'updatedAt' => $announcement['updated_at']
            ];
        }, $announcements);
        
        echo json_encode([
            'success' => true,
            'data' => $formattedAnnouncements
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function createAnnouncement($pdo, $data) {
    try {
        // Validate required fields
        if (empty($data['subject']) || empty($data['message'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Subject and message are required']);
            return;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO announcements (
                subject, 
                message, 
                priority, 
                category, 
                expiry_date,
                target_all,
                target_staff,
                target_admins,
                author_id,
                author_name,
                is_active,
                view_count,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())
        ");
        
        $result = $stmt->execute([
            $data['subject'],
            $data['message'],
            $data['priority'] ?? 'normal',
            $data['category'] ?? 'general',
            $data['expiry'] ?? null,
            ($data['targetAll'] ?? true) ? 1 : 0,
            ($data['targetStaff'] ?? false) ? 1 : 0,
            ($data['targetAdmins'] ?? false) ? 1 : 0,
            $data['authorId'] ?? 'head-assessor',
            $data['authorName'] ?? 'Head Assessor'
        ]);
        
        if ($result) {
            $announcementId = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'message' => 'Announcement created successfully',
                'id' => $announcementId
            ]);
        }
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function updateAnnouncement($pdo, $id, $data) {
    try {
        $stmt = $pdo->prepare("
            UPDATE announcements SET 
                subject = ?, 
                message = ?, 
                priority = ?, 
                category = ?, 
                expiry_date = ?,
                target_all = ?,
                target_staff = ?,
                target_admins = ?,
                updated_at = NOW()
            WHERE id = ? AND is_active = 1
        ");
        
        $result = $stmt->execute([
            $data['subject'],
            $data['message'],
            $data['priority'] ?? 'normal',
            $data['category'] ?? 'general',
            $data['expiry'] ?? null,
            $data['targetAll'] ?? true,
            $data['targetStaff'] ?? false,
            $data['targetAdmins'] ?? false,
            $id
        ]);
        
        if ($result && $stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Announcement updated successfully'
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Announcement not found']);
        }
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function deleteAnnouncement($pdo, $id) {
    try {
        // Soft delete - just mark as inactive
        $stmt = $pdo->prepare("
            UPDATE announcements 
            SET is_active = 0, updated_at = NOW() 
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$id]);
        
        if ($result && $stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Announcement deleted successfully'
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Announcement not found']);
        }
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getAnnouncementStats($pdo) {
    try {
        // Total announcements
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM announcements WHERE is_active = 1");
        $stmt->execute();
        $total = $stmt->fetchColumn();
        
        // This month's announcements
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as monthly 
            FROM announcements 
            WHERE is_active = 1 
            AND MONTH(created_at) = MONTH(CURRENT_DATE()) 
            AND YEAR(created_at) = YEAR(CURRENT_DATE())
        ");
        $stmt->execute();
        $monthly = $stmt->fetchColumn();
        
        // Urgent announcements
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as urgent 
            FROM announcements 
            WHERE is_active = 1 AND priority = 'urgent'
        ");
        $stmt->execute();
        $urgent = $stmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total' => (int)$total,
                'monthly' => (int)$monthly,
                'urgent' => (int)$urgent
            ]
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getAdminAnnouncements($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                subject,
                message,
                priority,
                category,
                expiry_date,
                target_all,
                target_staff,
                target_admins,
                author_id,
                author_name,
                is_active,
                view_count,
                created_at,
                updated_at
            FROM announcements 
            WHERE is_active = 1 
            AND (target_all = 1 OR target_admins = 1)
            ORDER BY 
                CASE priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'normal' THEN 3 
                END, 
                created_at DESC
        ");
        $stmt->execute();
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for frontend compatibility
        $formattedAnnouncements = array_map(function($announcement) {
            return [
                'id' => $announcement['id'],
                'subject' => $announcement['subject'],
                'message' => $announcement['message'],
                'priority' => $announcement['priority'],
                'category' => $announcement['category'],
                'expiry' => $announcement['expiry_date'],
                'targetRecipients' => [
                    'all' => (bool)$announcement['target_all'],
                    'staff' => (bool)$announcement['target_staff'],
                    'admins' => (bool)$announcement['target_admins']
                ],
                'timestamp' => $announcement['created_at'],
                'authorId' => $announcement['author_id'],
                'authorName' => $announcement['author_name'],
                'isActive' => (bool)$announcement['is_active'],
                'viewCount' => (int)$announcement['view_count'],
                'createdAt' => $announcement['created_at'],
                'updatedAt' => $announcement['updated_at']
            ];
        }, $announcements);
        
        echo json_encode([
            'success' => true,
            'data' => $formattedAnnouncements
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getStaffAnnouncements($pdo) {
    try {
        // Detect user type based on referrer or session
        $userId = getUserId();
        
        $stmt = $pdo->prepare("
            SELECT 
                a.id,
                a.subject,
                a.message,
                a.priority,
                a.category,
                a.expiry_date,
                a.target_all,
                a.target_staff,
                a.target_admins,
                a.author_id,
                a.author_name,
                a.is_active,
                a.view_count,
                a.created_at,
                a.updated_at,
                CASE WHEN ar.id IS NOT NULL THEN 1 ELSE 0 END as is_read,
                ar.read_at
            FROM announcements a
            LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.user_id = ?
            WHERE a.is_active = 1 
            AND (a.target_all = 1 OR a.target_staff = 1)
            ORDER BY 
                CASE a.priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'normal' THEN 3 
                END, 
                a.created_at DESC
        ");
        $stmt->execute([$userId]);
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format the data for frontend compatibility
        $formattedAnnouncements = array_map(function($announcement) {
            return [
                'id' => $announcement['id'],
                'subject' => $announcement['subject'],
                'message' => $announcement['message'],
                'priority' => $announcement['priority'],
                'category' => $announcement['category'],
                'expiry' => $announcement['expiry_date'],
                'targetRecipients' => [
                    'all' => (bool)$announcement['target_all'],
                    'staff' => (bool)$announcement['target_staff'],
                    'admins' => (bool)$announcement['target_admins']
                ],
                'timestamp' => $announcement['created_at'],
                'authorId' => $announcement['author_id'],
                'authorName' => $announcement['author_name'],
                'isActive' => (bool)$announcement['is_active'],
                'viewCount' => (int)$announcement['view_count'],
                'isRead' => (bool)$announcement['is_read'],
                'readAt' => $announcement['read_at'],
                'createdAt' => $announcement['created_at'],
                'updatedAt' => $announcement['updated_at']
            ];
        }, $announcements);
        
        echo json_encode([
            'success' => true,
            'data' => $formattedAnnouncements
        ]);
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function markAnnouncementAsRead($pdo, $id) {
    try {
        // Detect user type based on referrer or session
        $userId = getUserId();
        
        // Check if already marked as read
        $checkStmt = $pdo->prepare("
            SELECT id FROM announcement_reads 
            WHERE user_id = ? AND announcement_id = ?
        ");
        $checkStmt->execute([$userId, $id]);
        
        if ($checkStmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Already marked as read',
                'already_read' => true
            ]);
            return;
        }
        
        // Insert read record for this user
        $insertStmt = $pdo->prepare("
            INSERT INTO announcement_reads (user_id, announcement_id) 
            VALUES (?, ?)
        ");
        
        $result = $insertStmt->execute([$userId, $id]);
        
        if ($result) {
            // Also increment view count for statistics
            $updateStmt = $pdo->prepare("
                UPDATE announcements 
                SET view_count = view_count + 1 
                WHERE id = ? AND is_active = 1
            ");
            $updateStmt->execute([$id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Announcement marked as read',
                'already_read' => false
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Failed to mark as read']);
        }
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>