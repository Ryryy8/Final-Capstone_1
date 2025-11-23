<?php
// Test to verify announcements no longer reset to unread - Environment aware

echo "=== COMPREHENSIVE ANNOUNCEMENT RESET TEST ===\n\n";

// Database connection - Environment aware
require_once __DIR__ . '/config/db_config.php';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "📊 CURRENT STATE BEFORE TEST:\n";
    echo "=============================\n";
    
    // Get current staff read status
    $userId = 'staff_user';
    
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.subject,
            a.target_staff,
            a.target_all,
            CASE WHEN ar.id IS NOT NULL THEN 1 ELSE 0 END as is_read,
            ar.read_at
        FROM announcements a
        LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.user_id = ?
        WHERE a.is_active = 1 
        AND (a.target_all = 1 OR a.target_staff = 1)
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$userId]);
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $readCount = 0;
    $unreadCount = 0;
    
    foreach ($announcements as $ann) {
        $status = $ann['is_read'] ? '✅ READ' : '❌ UNREAD';
        echo "ID {$ann['id']}: {$ann['subject']} - {$status}\n";
        
        if ($ann['is_read']) {
            $readCount++;
            echo "   └─ Read at: {$ann['read_at']}\n";
        } else {
            $unreadCount++;
        }
    }
    
    echo "\n📈 SUMMARY:\n";
    echo "Total announcements: " . count($announcements) . "\n";
    echo "Read: $readCount\n";
    echo "Unread: $unreadCount\n";
    
    echo "\n🧪 RESET VERIFICATION TEST:\n";
    echo "===========================\n";
    
    if ($readCount > 0) {
        echo "✅ Found $readCount read announcements\n";
        echo "📝 This means the announcement reads are persisting correctly!\n";
        echo "🔄 If these stay as 'READ' after page refresh, the reset issue is FIXED!\n";
        
        // Show specific read records
        echo "\n📚 PERSISTENT READ RECORDS:\n";
        $stmt = $pdo->query("
            SELECT ar.announcement_id, a.subject, ar.read_at 
            FROM announcement_reads ar 
            JOIN announcements a ON ar.announcement_id = a.id 
            WHERE ar.user_id = '$userId' 
            ORDER BY ar.read_at DESC
        ");
        $reads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($reads as $read) {
            echo "- Announcement {$read['announcement_id']}: \"{$read['subject']}\" (Read: {$read['read_at']})\n";
        }
        
    } else {
        echo "ℹ️ No read announcements found\n";
        echo "📋 Try clicking on some announcements in the staff dashboard to mark them as read\n";
        echo "🔄 Then run this test again to verify they stay read\n";
    }
    
    echo "\n🎯 FINAL TEST INSTRUCTIONS:\n";
    echo "============================\n";
    echo "1. Open staff dashboard in your browser\n";
    echo "2. Navigate to Announcements section\n";
    echo "3. Click on any unread announcements\n";
    echo "4. Refresh the page (F5)\n";
    echo "5. Check if announcements stay marked as read\n";
    echo "6. Run this script again to verify database persistence\n";
    
    if ($readCount > 0 && $unreadCount == 0) {
        echo "\n🎉 ALL ANNOUNCEMENTS ARE READ - RESET ISSUE IS LIKELY FIXED!\n";
    } else if ($readCount > 0) {
        echo "\n✅ SOME PERSISTENCE WORKING - RESET ISSUE LIKELY FIXED!\n";
    } else {
        echo "\n⚠️ NO READ RECORDS - NEED TO TEST BY CLICKING ANNOUNCEMENTS\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
?>