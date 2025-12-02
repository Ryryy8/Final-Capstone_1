<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';

class HeadAnalytics {
    private $conn;
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAnalytics($period = 12, $periodType = 'month') {
        try {
            // Calculate date range based on period type
            $endDate = date('Y-m-d 23:59:59'); // Include full day (today)
            
            switch ($periodType) {
                case 'week':
                    // Last 7 days including today
                    $startDate = date('Y-m-d 00:00:00', strtotime('-6 days'));
                    break;
                case 'month':
                    // Last 30 days including today
                    $startDate = date('Y-m-d 00:00:00', strtotime('-29 days'));
                    break;
                case 'year':
                    // Last 365 days including today
                    $startDate = date('Y-m-d 00:00:00', strtotime('-364 days'));
                    break;
                default:
                    $startDate = date('Y-m-d 00:00:00', strtotime("-{$period} months"));
                    break;
            }
            
            error_log("HEAD ANALYTICS: Date range (" . $periodType . ") = " . $startDate . " to " . $endDate);
            
            $metrics = $this->getMetrics($startDate, $endDate, $period, $periodType);
            $trends = $this->getTrends($startDate, $endDate, $periodType);
            $barangayDistribution = $this->getBarangayDistribution($startDate, $endDate);
            $propertyClassification = $this->getPropertyClassification($startDate, $endDate);
            $inspectionTypeDistribution = $this->getInspectionTypeDistribution($startDate, $endDate);
            $insights = $this->generateInsights($metrics, $trends);
            
            return [
                'success' => true,
                'data' => [
                    'metrics' => $metrics,
                    'monthly_trends' => $trends, // Keep same key for frontend compatibility
                    'barangay_distribution' => $barangayDistribution,
                    'property_classification' => $propertyClassification,
                    'inspection_type_distribution' => $inspectionTypeDistribution,
                    'insights' => $insights,
                    'period_type' => $periodType
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Analytics generation failed: ' . $e->getMessage()
            ];
        }
    }
    
    private function getPreviousPeriodDates($startDate, $endDate, $periodType) {
        $currentStart = new DateTime($startDate);
        $currentEnd = new DateTime($endDate);
        
        switch ($periodType) {
            case 'week':
                // Calculate exactly 7 days before current period
                $prevEnd = clone $currentStart;
                $prevEnd->sub(new DateInterval('P1D')); // Day before current period starts
                $prevStart = clone $prevEnd;
                $prevStart->sub(new DateInterval('P6D')); // 7 days total (including end day)
                break;
            case 'month':
                // For month, compare with previous 30-day period
                $prevEnd = clone $currentStart;
                $prevEnd->sub(new DateInterval('P1D')); // Day before current period
                $prevStart = clone $prevEnd;
                $prevStart->sub(new DateInterval('P29D')); // 30 days total including end day
                break;
            case 'year':
                // Calculate same period but one year earlier
                $prevStart = clone $currentStart;
                $prevStart->sub(new DateInterval('P1Y'));
                $prevEnd = clone $currentEnd;
                $prevEnd->sub(new DateInterval('P1Y'));
                break;
            default:
                // Fallback to month calculation
                $interval = $currentStart->diff($currentEnd);
                $daysDiff = $interval->days;
                
                $prevEnd = clone $currentStart;
                $prevEnd->sub(new DateInterval('P1D'));
                $prevStart = clone $prevEnd;
                $prevStart->sub(new DateInterval('P' . $daysDiff . 'D'));
                break;
        }
        
        return [$prevStart->format('Y-m-d H:i:s'), $prevEnd->format('Y-m-d H:i:s')];
    }

    private function getMetrics($startDate, $endDate, $period, $periodType = 'month') {
        // First, check if we have any assessment_requests data at all
        $testQuery = "SELECT COUNT(*) as total_records, MIN(created_at) as earliest, MAX(created_at) as latest FROM assessment_requests";
        $stmt = $this->conn->prepare($testQuery);
        $stmt->execute();
        $testResult = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("ASSESSMENT_REQUESTS TABLE: Total records=" . $testResult['total_records'] . 
                 ", Earliest=" . $testResult['earliest'] . ", Latest=" . $testResult['latest']);
        
        // Get comprehensive data distribution analysis
        if ($periodType === 'month') {
            // Daily distribution for last 70 days
            $dailyQuery = "SELECT DATE(created_at) as date, COUNT(*) as count FROM assessment_requests 
                          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 70 DAY) 
                          GROUP BY DATE(created_at) 
                          ORDER BY date DESC";
            $stmt = $this->conn->prepare($dailyQuery);
            $stmt->execute();
            $dailyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("DAILY DISTRIBUTION (last 70 days): " . json_encode(array_slice($dailyData, 0, 15)));
            
            // Monthly distribution for better context
            $monthlyQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count 
                           FROM assessment_requests 
                           GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
                           ORDER BY month DESC LIMIT 6";
            $stmt = $this->conn->prepare($monthlyQuery);
            $stmt->execute();
            $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("MONTHLY DISTRIBUTION: " . json_encode($monthlyData));
            
            // Weekly distribution
            $weeklyQuery = "SELECT YEARWEEK(created_at) as week, COUNT(*) as count 
                          FROM assessment_requests 
                          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 WEEK)
                          GROUP BY YEARWEEK(created_at) 
                          ORDER BY week DESC";
            $stmt = $this->conn->prepare($weeklyQuery);
            $stmt->execute();
            $weeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("WEEKLY DISTRIBUTION: " . json_encode($weeklyData));
        }
        
        // Total requests metrics (from assessment_requests)
        $totalQuery = "SELECT COUNT(*) as current_total FROM assessment_requests WHERE created_at BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($totalQuery);
        $stmt->execute([$startDate, $endDate]);
        $currentTotal = $stmt->fetch(PDO::FETCH_ASSOC)['current_total'];
        
        // Calculate previous period for comparison based on period type
        list($prevStartDate, $prevEndDate) = $this->getPreviousPeriodDates($startDate, $endDate, $periodType);
        
        error_log("=== TREND CALCULATION DEBUG (" . strtoupper($periodType) . ") ===");
        error_log("Current period:  " . $startDate . " to " . $endDate);
        error_log("Previous period: " . $prevStartDate . " to " . $prevEndDate);
        
        // Calculate and log the number of days in each period for verification
        $currentDays = (strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24);
        $previousDays = (strtotime($prevEndDate) - strtotime($prevStartDate)) / (60 * 60 * 24);
        error_log("Current period days: " . round($currentDays, 1) . ", Previous period days: " . round($previousDays, 1));
        
        $stmt->execute([$prevStartDate, $prevEndDate]);
        $previousTotal = $stmt->fetch(PDO::FETCH_ASSOC)['current_total'];
        
        error_log("=== TOTAL TREND CALCULATION ===");
        error_log("Current: " . $currentTotal . ", Previous: " . $previousTotal . " (Type: " . $periodType . ")");
        error_log("Total dataset size: " . $testResult['total_records'] . " requests");
        
        if ($testResult['total_records'] <= 200) {
            error_log("SMALL DATASET DETECTED: Only " . $testResult['total_records'] . " total requests - trend calculations may be volatile");
        }
        
        // Verify we have realistic data
        if ($currentTotal == 0 && $previousTotal == 0 && $periodType === 'month') {
            error_log("WARNING: Both current and previous month show 0 total requests - this may indicate a data issue");
        }
        
        // Check for unrealistic percentage changes
        if ($previousTotal > 0) {
            $roughPercentage = (($currentTotal - $previousTotal) / $previousTotal) * 100;
            if (abs($roughPercentage) > 1000) {
                error_log("WARNING: Extremely high percentage change detected: " . round($roughPercentage, 1) . "% - Current:" . $currentTotal . ", Previous:" . $previousTotal);
            }
        }
        
        $totalTrend = $this->calculateTrend($currentTotal, $previousTotal, $periodType);
        
        // Completed requests (from scheduled_inspections with status = 'completed')
        $completedQuery = "SELECT COUNT(*) as current_completed FROM scheduled_inspections WHERE status = 'completed' AND created_at BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($completedQuery);
        $stmt->execute([$startDate, $endDate]);
        $currentCompleted = $stmt->fetch(PDO::FETCH_ASSOC)['current_completed'];
        
        $stmt->execute([$prevStartDate, $prevEndDate]);
        $previousCompleted = $stmt->fetch(PDO::FETCH_ASSOC)['current_completed'];
        
        error_log("COMPLETED TREND: Current=" . $currentCompleted . ", Previous=" . $previousCompleted);
        $completedTrend = $this->calculateTrend($currentCompleted, $previousCompleted, $periodType);
        
        // Pending inspections (only scheduled_inspections with status = 'scheduled')
        $pendingInspectionsQuery = "SELECT COUNT(*) as current_pending FROM scheduled_inspections WHERE status = 'scheduled' AND created_at BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($pendingInspectionsQuery);
        $stmt->execute([$startDate, $endDate]);
        $currentPending = $stmt->fetch(PDO::FETCH_ASSOC)['current_pending'];
        
        // Calculate previous period pending
        $stmt->execute([$prevStartDate, $prevEndDate]);
        $previousPending = $stmt->fetch(PDO::FETCH_ASSOC)['current_pending'];
        
        error_log("PENDING TREND: Current=" . $currentPending . ", Previous=" . $previousPending);
        $pendingTrend = $this->calculateTrend($currentPending, $previousPending, $periodType);
        
        // Top barangay (from assessment_requests)
        $topBarangayQuery = "SELECT location as barangay, COUNT(*) as count FROM assessment_requests WHERE created_at BETWEEN ? AND ? GROUP BY location ORDER BY count DESC LIMIT 1";
        $stmt = $this->conn->prepare($topBarangayQuery);
        $stmt->execute([$startDate, $endDate]);
        $topBarangay = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Active assessors (count active users with staff role)
        $activeAssessorsQuery = "SELECT COUNT(*) as active_count FROM users WHERE role = 'staff'";
        $stmt = $this->conn->prepare($activeAssessorsQuery);
        $stmt->execute();
        $activeAssessors = $stmt->fetch(PDO::FETCH_ASSOC)['active_count'];
        
        return [
            'total' => [
                'value' => $currentTotal,
                'trend' => $totalTrend
            ],
            'completed' => [
                'value' => $currentCompleted,
                'trend' => $completedTrend
            ],
            'pending' => [
                'value' => $currentPending,
                'trend' => $pendingTrend
            ],
            'top_barangay' => [
                'value' => $topBarangay['barangay'] ?? 'N/A',
                'count' => $topBarangay['count'] ?? 0
            ],
            'active_assessors' => [
                'value' => $activeAssessors
            ]
        ];
    }
    
    private function getTrends($startDate, $endDate, $periodType = 'month') {
        switch ($periodType) {
            case 'week':
                return $this->getWeeklyTrends($startDate, $endDate);
            case 'year':
                return $this->getYearlyTrends($startDate, $endDate);
            case 'month':
            default:
                return $this->getMonthlyTrends($startDate, $endDate);
        }
    }

    private function getWeeklyTrends($startDate, $endDate) {
        // Get daily data for the past week
        $requestsQuery = "
            SELECT 
                DATE(created_at) as day,
                COUNT(*) as total_requests
            FROM assessment_requests 
            WHERE created_at BETWEEN ? AND ? 
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ";
        
        $stmt = $this->conn->prepare($requestsQuery);
        $stmt->execute([$startDate, $endDate]);
        $requestsResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get daily completed data
        $completedQuery = "
            SELECT 
                DATE(created_at) as day,
                COUNT(*) as completed_requests
            FROM scheduled_inspections 
            WHERE status = 'completed' AND created_at BETWEEN ? AND ? 
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ";
        
        $stmt = $this->conn->prepare($completedQuery);
        $stmt->execute([$startDate, $endDate]);
        $completedResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create completed map
        $completedMap = [];
        foreach ($completedResults as $completed) {
            $completedMap[$completed['day']] = (int)$completed['completed_requests'];
        }
        
        // Generate all days in the range to show complete week
        $trends = [];
        $currentDate = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);
        
        while ($currentDate <= $endDateTime) {
            $dayStr = $currentDate->format('Y-m-d');
            $dayLabel = $currentDate->format('M j'); // e.g., "Nov 18"
            
            $totalForDay = 0;
            foreach ($requestsResults as $row) {
                if ($row['day'] === $dayStr) {
                    $totalForDay = (int)$row['total_requests'];
                    break;
                }
            }
            
            $trends[] = [
                'label' => $dayLabel,
                'total' => $totalForDay,
                'completed' => $completedMap[$dayStr] ?? 0
            ];
            
            $currentDate->add(new DateInterval('P1D'));
        }
        
        return $trends;
    }

    private function getYearlyTrends($startDate, $endDate) {
        // Get monthly data for the past year (more granular than yearly)
        $requestsQuery = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as total_requests
            FROM assessment_requests 
            WHERE created_at BETWEEN ? AND ? 
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ";
        
        $stmt = $this->conn->prepare($requestsQuery);
        $stmt->execute([$startDate, $endDate]);
        $requestsResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $completedQuery = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as completed_requests
            FROM scheduled_inspections 
            WHERE status = 'completed' AND created_at BETWEEN ? AND ? 
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ";
        
        $stmt = $this->conn->prepare($completedQuery);
        $stmt->execute([$startDate, $endDate]);
        $completedResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $completedMap = [];
        foreach ($completedResults as $completed) {
            $completedMap[$completed['month']] = (int)$completed['completed_requests'];
        }
        
        $trends = [];
        foreach ($requestsResults as $row) {
            $monthName = date('M Y', strtotime($row['month'] . '-01'));
            $trends[] = [
                'label' => $monthName,
                'total' => (int)$row['total_requests'],
                'completed' => $completedMap[$row['month']] ?? 0
            ];
        }
        
        return $trends;
    }

    private function getMonthlyTrends($startDate, $endDate) {
        // Get monthly data from assessment_requests (total requests)
        $requestsQuery = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as total_requests
            FROM assessment_requests 
            WHERE created_at BETWEEN ? AND ? 
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ";
        
        $stmt = $this->conn->prepare($requestsQuery);
        $stmt->execute([$startDate, $endDate]);
        $requestsResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get monthly completed data from scheduled_inspections
        $completedQuery = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as completed_requests
            FROM scheduled_inspections 
            WHERE status = 'completed' AND created_at BETWEEN ? AND ? 
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ";
        
        $stmt = $this->conn->prepare($completedQuery);
        $stmt->execute([$startDate, $endDate]);
        $completedResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Merge the data
        $trends = [];
        $completedMap = [];
        
        // Create a map of completed data by month
        foreach ($completedResults as $completed) {
            $completedMap[$completed['month']] = (int)$completed['completed_requests'];
        }
        
        // Build the trends array
        foreach ($requestsResults as $row) {
            $monthName = date('M Y', strtotime($row['month'] . '-01'));
            $trends[] = [
                'label' => $monthName,
                'total' => (int)$row['total_requests'],
                'completed' => $completedMap[$row['month']] ?? 0
            ];
        }
        
        return $trends;
    }
    
    private function getBarangayDistribution($startDate, $endDate) {
        $query = "
            SELECT location as label, COUNT(*) as count 
            FROM assessment_requests 
            WHERE created_at BETWEEN ? AND ? 
            AND location NOT LIKE '%Test%'
            AND location NOT LIKE '%test%'
            GROUP BY location 
            ORDER BY count DESC 
            LIMIT 10
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$startDate, $endDate]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $distribution = [];
        foreach ($results as $row) {
            $distribution[] = [
                'label' => $row['label'],
                'value' => (int)$row['count']
            ];
        }
        
        return $distribution;
    }
    
    private function getPropertyClassification($startDate, $endDate) {
        // Only get property classification for actual Land Property inspections
        $query = "
            SELECT 
                property_classification as classification_category,
                COUNT(*) as count 
            FROM assessment_requests 
            WHERE created_at BETWEEN ? AND ? 
            AND inspection_category = 'Land Property'
            AND property_classification IS NOT NULL 
            AND property_classification != ''
            GROUP BY classification_category 
            ORDER BY count DESC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$startDate, $endDate]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $classification = [];
        foreach ($results as $row) {
            // Map classification categories to more descriptive names
            $categoryName = $this->mapPropertyClassificationName($row['classification_category']);
            $classification[] = [
                'label' => $categoryName,
                'value' => (int)$row['count']
            ];
        }
        
        return $classification;
    }
    
    private function getInspectionTypeDistribution($startDate, $endDate) {
        $query = "
            SELECT 
                inspection_category,
                COUNT(*) as count 
            FROM assessment_requests 
            WHERE created_at BETWEEN ? AND ? 
            GROUP BY inspection_category 
            ORDER BY count DESC
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$startDate, $endDate]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $distribution = [];
        foreach ($results as $row) {
            $distribution[] = [
                'label' => $this->mapInspectionTypeName($row['inspection_category']),
                'value' => (int)$row['count']
            ];
        }
        
        return $distribution;
    }
    
    private function mapInspectionTypeName($type) {
        $mapping = [
            'Land Property' => 'Land Property Assessment',
            'Building' => 'Building Inspection', 
            'Machinery' => 'Machinery Inspection'
        ];
        
        return $mapping[$type] ?? ucfirst($type);
    }
    
    private function mapPropertyClassificationName($classification) {
        $mapping = [
            'Residential' => 'Residential Properties',
            'Commercial' => 'Commercial Properties', 
            'Agricultural' => 'Agricultural Land',
            'Industrial' => 'Industrial Properties'
        ];
        
        return $mapping[$classification] ?? ucfirst($classification);
    }
    
    private function generateInsights($metrics, $monthlyTrends) {
        $insights = [
            'trend' => $this->analyzeTrend($monthlyTrends),
            'peak_period' => $this->findPeakPeriod($monthlyTrends),
            'low_period' => $this->findLowPeriod($monthlyTrends),
            'performance_summary' => $this->generatePerformanceSummary($metrics),
            'recommendations' => $this->generateRecommendations($metrics, $monthlyTrends)
        ];
        
        return $insights;
    }
    
    private function analyzeTrend($monthlyTrends) {
        if (count($monthlyTrends) < 2) {
            return "Insufficient data for trend analysis";
        }
        
        $recent = array_slice($monthlyTrends, -3);
        $earlier = array_slice($monthlyTrends, 0, 3);
        
        $recentAvg = array_sum(array_column($recent, 'total')) / count($recent);
        $earlierAvg = array_sum(array_column($earlier, 'total')) / count($earlier);
        
        if ($recentAvg > $earlierAvg * 1.1) {
            return "Requests are trending upward significantly";
        } elseif ($recentAvg < $earlierAvg * 0.9) {
            return "Requests are declining compared to earlier periods";
        } else {
            return "Request volume is relatively stable";
        }
    }
    
    private function findPeakPeriod($monthlyTrends) {
        if (empty($monthlyTrends)) return "No data available";
        
        $maxRequests = max(array_column($monthlyTrends, 'total'));
        foreach ($monthlyTrends as $trend) {
            if ($trend['total'] == $maxRequests) {
                return $trend['label'] . " ({$maxRequests} requests)";
            }
        }
        return "Peak period not identified";
    }
    
    private function findLowPeriod($monthlyTrends) {
        if (empty($monthlyTrends)) return "No data available";
        
        $minRequests = min(array_column($monthlyTrends, 'total'));
        foreach ($monthlyTrends as $trend) {
            if ($trend['total'] == $minRequests) {
                return $trend['label'] . " ({$minRequests} requests)";
            }
        }
        return "Low period not identified";
    }
    
    private function generatePerformanceSummary($metrics) {
        $total = $metrics['total']['value'];
        $completed = $metrics['completed']['value'];
        
        if ($total == 0) {
            return "No requests processed in this period";
        }
        
        $completionRate = round(($completed / $total) * 100, 1);
        
        if ($completionRate >= 90) {
            return "Excellent completion rate of {$completionRate}%";
        } elseif ($completionRate >= 75) {
            return "Good completion rate of {$completionRate}%";
        } elseif ($completionRate >= 60) {
            return "Moderate completion rate of {$completionRate}% - room for improvement";
        } else {
            return "Low completion rate of {$completionRate}% - needs attention";
        }
    }
    
    private function generateRecommendations($metrics, $monthlyTrends) {
        $recommendations = [];
        
        // Check completion rate
        $total = $metrics['total']['value'];
        $completed = $metrics['completed']['value'];
        $completionRate = $total > 0 ? ($completed / $total) * 100 : 0;
        
        if ($completionRate < 75) {
            $recommendations[] = [
                'title' => 'Improve Completion Rate',
                'description' => 'Current completion rate is below optimal. Consider reviewing bottlenecks in the assessment process.',
                'priority' => 'high'
            ];
        }
        
        // Check pending requests
        $pending = $metrics['pending']['value'];
        if ($pending > $completed * 0.5) {
            $recommendations[] = [
                'title' => 'Address Pending Backlog',
                'description' => 'High number of pending requests may indicate resource constraints or process delays.',
                'priority' => 'medium'
            ];
        }
        
        // Check trend
        if ($metrics['total']['trend']['direction'] === 'up' && $metrics['total']['trend']['percentage'] > 20) {
            $recommendations[] = [
                'title' => 'Scale Resources',
                'description' => 'Significant increase in requests may require additional assessor capacity.',
                'priority' => 'medium'
            ];
        }
        
        // Check assessor utilization
        $activeAssessors = $metrics['active_assessors']['value'];
        if ($pending > $activeAssessors * 10) {
            $recommendations[] = [
                'title' => 'Optimize Assessor Assignment',
                'description' => 'Consider redistributing workload or hiring additional assessors.',
                'priority' => 'high'
            ];
        }
        
        return $recommendations;
    }
    
    private function calculateTrend($current, $previous, $periodType = 'month') {
        $periodText = $this->getPeriodText($periodType);
        
        // Log the calculation for debugging
        error_log("CALCULATE TREND: Current=$current, Previous=$previous, Type=$periodType");
        
        if ($previous == 0) {
            if ($current == 0) {
                // Both periods have no data
                return [
                    'direction' => 'neutral',
                    'percentage' => 0,
                    'display' => '0%',
                    'description' => 'No change (no data in both periods)'
                ];
            } else {
                // Previous had no data, current has data - this is a new increase
                return [
                    'direction' => 'up',
                    'percentage' => 100,
                    'display' => 'New',
                    'description' => 'New activity (no data in previous ' . $periodText . ')'
                ];
            }
        }
        
        if ($current == 0 && $previous > 0) {
            // Current has no data but previous did - this is a decrease
            return [
                'direction' => 'down',
                'percentage' => 100,
                'display' => '-100%',
                'description' => '100% decrease from previous ' . $periodText
            ];
        }
        
        $percentChange = round((($current - $previous) / $previous) * 100, 1);
        $direction = $percentChange > 0 ? 'up' : ($percentChange < 0 ? 'down' : 'neutral');
        
        error_log("CALCULATE TREND RESULT: Direction=$direction, Change=$percentChange% (Current=$current, Previous=$previous)");
        
        // Handle cases with small numbers more intelligently
        $totalNumbers = $current + $previous;
        
        // For very small datasets, show absolute change instead of percentage
        if ($totalNumbers <= 10 || ($previous <= 5 && abs($percentChange) > 500)) {
            error_log("SMALL DATASET DETECTED: Total=$totalNumbers, showing absolute change instead of percentage");
            
            $absoluteChange = $current - $previous;
            if ($absoluteChange > 0) {
                return [
                    'direction' => 'up',
                    'percentage' => abs($absoluteChange),
                    'display' => '+' . $absoluteChange,
                    'description' => 'Increased by ' . $absoluteChange . ' requests vs previous ' . $periodText . ' (' . $previous . ' → ' . $current . ')',
                    'raw_change' => $absoluteChange
                ];
            } else if ($absoluteChange < 0) {
                return [
                    'direction' => 'down',
                    'percentage' => abs($absoluteChange),
                    'display' => $absoluteChange,
                    'description' => 'Decreased by ' . abs($absoluteChange) . ' requests vs previous ' . $periodText . ' (' . $previous . ' → ' . $current . ')',
                    'raw_change' => $absoluteChange
                ];
            } else {
                return [
                    'direction' => 'neutral',
                    'percentage' => 0,
                    'display' => '±0',
                    'description' => 'No change vs previous ' . $periodText . ' (' . $current . ' requests both periods)',
                    'raw_change' => 0
                ];
            }
        }
        
        // Handle extreme percentages for larger datasets
        if (abs($percentChange) > 1000) {
            error_log("EXTREME PERCENTAGE DETECTED: $percentChange% - Capping and providing alternative display");
            
            if ($percentChange > 1000) {
                return [
                    'direction' => 'up',
                    'percentage' => 999,
                    'display' => 'Major ↑',
                    'description' => 'Significant increase vs previous ' . $periodText . ' (from ' . $previous . ' to ' . $current . ')',
                    'raw_change' => $percentChange
                ];
            } else {
                return [
                    'direction' => 'down',
                    'percentage' => 999,
                    'display' => 'Major ↓',
                    'description' => 'Significant decrease vs previous ' . $periodText . ' (from ' . $previous . ' to ' . $current . ')',
                    'raw_change' => $percentChange
                ];
            }
        }
        
        return [
            'direction' => $direction,
            'percentage' => abs($percentChange),
            'display' => ($percentChange >= 0 ? '+' : '') . $percentChange . '%',
            'description' => abs($percentChange) . '% ' . ($percentChange >= 0 ? 'increase' : 'decrease') . ' vs previous ' . $periodText . ' (' . $previous . ' → ' . $current . ')',
            'raw_change' => $percentChange
        ];
    }
    
    private function getPeriodText($periodType) {
        switch ($periodType) {
            case 'week':
                return 'week';
            case 'month':
                return 'month';
            case 'year':
                return 'year';
            default:
                return 'period';
        }
    }
}

// Handle the request
try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'get_analytics') {
        $period = $input['period'] ?? 12;
        $periodType = $input['period_type'] ?? 'month';
        
        error_log("HEAD ANALYTICS: Received period = " . $period . ", type = " . $periodType);
        
        $analytics = new HeadAnalytics();
        $result = $analytics->getAnalytics($period, $periodType);
        
        if ($result['success']) {
            error_log("HEAD ANALYTICS: Results - Total: " . $result['data']['metrics']['total']['value'] . 
                     ", Pending: " . $result['data']['metrics']['pending']['value'] . 
                     ", Completed: " . $result['data']['metrics']['completed']['value']);
        }
        
        echo json_encode($result);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>