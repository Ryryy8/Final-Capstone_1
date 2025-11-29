<?php
/**
 * Barangay Batch Tracker for Property Inspections
 * Manages counting and triggering of batch notifications when 5+ requests are reached
 */

class BarangayBatchTracker {
    private $db;
    
    public function __construct($database = null) {
        // Initialize database connection
        if ($database) {
            $this->db = $database;
        } else {
            // Include the database connection
            global $pdo;
            require_once dirname(__FILE__) . '/../database_connection.php';
            $this->db = $pdo;
        }
    }
    
    /**
     * Add a property inspection request and check if batch threshold is reached
     * Returns true if threshold reached and batch should be triggered
     */
    public function addPropertyRequest($barangay, $clientData, $formData) {
        try {
            // Store the request (you'll need to adapt this to your database schema)
            $this->storeRequest($barangay, $clientData, $formData);
            
            // Count current requests for this barangay
            $count = $this->getBarangayRequestCount($barangay);
            
            // Check if we've reached the threshold (10+)
            if ($count >= 10) {
                return [
                    'trigger_batch' => true,
                    'total_requests' => $count,
                    'barangay' => $barangay
                ];
            }
            
            return [
                'trigger_batch' => false,
                'total_requests' => $count,
                'barangay' => $barangay,
                'remaining_needed' => 10 - $count
            ];
            
        } catch (Exception $e) {
            error_log("Batch tracker error: " . $e->getMessage());
            return ['trigger_batch' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get all pending property requests for a specific barangay
     */
    public function getBarangayRequests($barangay, $limit = null) {
        try {
            // Get real data from assessment_requests table
            $sql = "SELECT id as request_id, full_name as name, email, property_address, 
                           property_type, created_at as submission_date, status, location as barangay
                    FROM assessment_requests 
                    WHERE location = ? AND status = 'pending'
                    ORDER BY created_at ASC";
            
            if ($limit) {
                $sql .= " LIMIT ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$barangay, $limit]);
            } else {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$barangay]);
            }
            
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format the data to match expected structure
            foreach ($requests as &$request) {
                $request['category'] = 'Property';
                $request['area'] = null; // Add area field if needed
            }
            
            error_log("Retrieved " . count($requests) . " real requests for barangay: {$barangay}");
            return $requests;
            
        } catch (Exception $e) {
            error_log("Error getting barangay requests: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark requests as scheduled (after batch email sent)
     */
    public function markRequestsAsScheduled($barangay, $requestIds = null) {
        try {
            if ($requestIds === null) {
                // Get all pending request IDs for this barangay
                $stmt = $this->db->prepare(
                    "SELECT id FROM assessment_requests WHERE location = ? AND status = 'pending'"
                );
                $stmt->execute([$barangay]);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $requestIds = array_column($results, 'id');
            }
            
            if (empty($requestIds)) {
                error_log("No request IDs to mark as scheduled for barangay: {$barangay}");
                return false;
            }
            
            // Update all requests to scheduled status
            $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
            $sql = "UPDATE assessment_requests SET status = 'scheduled', updated_at = NOW() WHERE id IN ({$placeholders})";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($requestIds);
            
            $updatedCount = $stmt->rowCount();
            error_log("Marked {$updatedCount} requests as scheduled for barangay: {$barangay}");
            return true;
            
        } catch (Exception $e) {
            error_log("Error marking requests as scheduled: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get count of pending property requests for a barangay
     */
    private function getBarangayRequestCount($barangay) {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM assessment_requests WHERE location = ? AND status = 'pending'"
            );
            $stmt->execute([$barangay]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $count = $result ? (int)$result['count'] : 0;
            error_log("Real request count for {$barangay}: {$count}");
            return $count;
            
        } catch (Exception $e) {
            error_log("Error getting request count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Log request tracking (requests are stored via main submission process)
     */
    private function storeRequest($barangay, $clientData, $formData) {
        // Requests are already stored in assessment_requests table via submit_request.php
        // This method is just for batch tracking logging
        error_log("Tracking request {$formData['request_id']} for batch processing in barangay {$barangay}");
        return true;
    }
    
    /**
     * Update request status
     */
    private function updateRequestStatus($requestId, $status) {
        try {
            $stmt = $this->db->prepare(
                "UPDATE assessment_requests SET status = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$status, $requestId]);
            
            $updatedRows = $stmt->rowCount();
            error_log("Updated request {$requestId} status to {$status} - Rows affected: {$updatedRows}");
            return $updatedRows > 0;
            
        } catch (Exception $e) {
            error_log("Error updating request status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get summary of all barangays and their request counts
     */
    public function getBarangaySummary() {
        try {
            // Get all unique barangays with pending requests
            $stmt = $this->db->prepare(
                "SELECT location as barangay, COUNT(*) as pending_requests 
                 FROM assessment_requests 
                 WHERE status = 'pending' 
                 GROUP BY location 
                 ORDER BY pending_requests DESC"
            );
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $summary = [];
            foreach ($results as $row) {
                $count = intval($row['pending_requests']);
                $summary[] = [
                    'barangay' => $row['barangay'],
                    'pending_requests' => $count,
                    'ready_for_batch' => $count >= 10,
                    'remaining_needed' => max(0, 10 - $count)
                ];
            }
            
            return $summary;
            
        } catch (Exception $e) {
            error_log("Error getting barangay summary: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Process all barangays that are ready for batch scheduling
     */
    public function processReadyBatches() {
        $summary = $this->getBarangaySummary();
        $processedBatches = [];
        
        foreach ($summary as $barangayInfo) {
            if ($barangayInfo['ready_for_batch']) {
                $processedBatches[] = [
                    'barangay' => $barangayInfo['barangay'],
                    'request_count' => $barangayInfo['pending_requests'],
                    'ready_for_scheduling' => true
                ];
            }
        }
        
        return $processedBatches;
    }
}
?>