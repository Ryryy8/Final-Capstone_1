<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // Database connection
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "assesspro_db";

    $conn = new mysqli($servername, $username, $password, $dbname);

    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Get the month and year from query parameters (default to current month)
    $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
    $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

    // Validate month and year
    if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
        throw new Exception("Invalid month or year");
    }

    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate));

    // Get individual Building/Machinery inspections that can be batched
    $individualQuery = "SELECT inspection_date, COUNT(*) as individual_count,
                               GROUP_CONCAT(DISTINCT barangay ORDER BY barangay SEPARATOR ', ') as barangays
                        FROM scheduled_inspections 
                        WHERE inspection_date BETWEEN ? AND ? 
                        AND status = 'scheduled'
                        AND request_count = 1
                        AND (notes LIKE '%Machinery%' OR notes LIKE '%Building%')
                        AND notes LIKE '%Individual inspection request%'
                        GROUP BY inspection_date";  // Show all dates with individual inspections (request_count = 1)
    
    $stmt = $conn->prepare($individualQuery);
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $batchablesDates = [];
    while ($row = $result->fetch_assoc()) {
        $batchablesDates[$row['inspection_date']] = [
            'individual_count' => (int)$row['individual_count'],
            'barangays' => $row['barangays']
        ];
    }

    // Get existing batch inspections to exclude those dates
    // Detect batch inspections by request_count >= 10 (more reliable than notes pattern)
    $batchQuery = "SELECT inspection_date, COUNT(*) as batch_count, 
                          GROUP_CONCAT(CONCAT('Count: ', request_count, ' - ', notes) SEPARATOR ' | ') as all_notes
                   FROM scheduled_inspections 
                   WHERE inspection_date BETWEEN ? AND ? 
                   AND status = 'scheduled'
                   AND request_count >= 10
                   GROUP BY inspection_date";
    
    $stmt = $conn->prepare($batchQuery);
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $batchScheduledDates = [];
    while ($row = $result->fetch_assoc()) {
        $batchScheduledDates[$row['inspection_date']] = [
            'batch_count' => $row['batch_count'],
            'notes' => $row['all_notes']
        ];
    }

    // Get holidays for the specified month
    $holidayQuery = "SELECT name, date FROM holidays 
                     WHERE date BETWEEN ? AND ? 
                     OR (is_recurring = 1 AND MONTH(date) = ? AND DAY(date) BETWEEN 1 AND 31)";
    
    $stmt = $conn->prepare($holidayQuery);
    $stmt->bind_param("ssi", $startDate, $endDate, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    $holidays = [];
    while ($row = $result->fetch_assoc()) {
        $holidayDate = $row['date'];
        // For recurring holidays, adjust to current year
        if (strpos($holidayDate, $year) === false) {
            $holidayDate = sprintf('%04d-%02d-%02d', $year, date('m', strtotime($row['date'])), date('d', strtotime($row['date'])));
        }
        
        if ($holidayDate >= $startDate && $holidayDate <= $endDate) {
            $holidays[$holidayDate] = $row['name'];
        }
    }

    $daysInMonth = date('t', strtotime($startDate));
    $availability = [];
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $dayOfWeek = date('N', strtotime($currentDate));
        
        $isWeekday = ($dayOfWeek >= 1 && $dayOfWeek <= 5);
        $today = date('Y-m-d');
        $isPast = ($currentDate <= $today);
        
        // Check if this date has individual inspections that can be batched
        $hasIndividualInspections = isset($batchablesDates[$currentDate]);
        $individualCount = $hasIndividualInspections ? $batchablesDates[$currentDate]['individual_count'] : 0;
        $barangays = $hasIndividualInspections ? $batchablesDates[$currentDate]['barangays'] : '';
        
        // Check if batch inspection is already scheduled
        $hasBatchScheduled = isset($batchScheduledDates[$currentDate]);
        
        // Determine availability status for batch scheduling
        $status = 'unavailable';
        $reason = '';
        
        if ($isPast) {
            $status = 'past';
            $reason = 'Past date';
        } elseif (isset($holidays[$currentDate])) {
            $status = 'holiday';
            $reason = 'Holiday: ' . $holidays[$currentDate];
        } elseif (!$isWeekday) {
            $status = 'weekend';
            $reason = 'Weekend';
        } elseif ($hasBatchScheduled) {
            $status = 'unavailable';  // Batch already scheduled = unavailable
            $reason = 'Batch inspection already scheduled';
        } elseif ($hasIndividualInspections) {
            $status = 'available';  // Has individual inspections = available
            $reason = $individualCount . ' individual inspections available for batching';
        } else {
            $status = 'available';  // No individual inspections = also available
            $reason = 'Available for scheduling';
        }
        
        $dayData = [
            'date' => $currentDate,
            'day' => $day,
            'dayOfWeek' => $dayOfWeek,
            'dayName' => date('D', strtotime($currentDate)),
            'status' => $status,
            'individualCount' => $individualCount,
            'barangays' => $barangays,
            'hasBatchScheduled' => $hasBatchScheduled,
            'isSelectable' => ($status === 'available'),
            'reason' => $reason,
            'batchDetails' => $hasBatchScheduled ? $batchScheduledDates[$currentDate] : null
        ];

        if ($status === 'holiday') {
            $dayData['holidayName'] = $holidays[$currentDate];
        }

        $availability[] = $dayData;
    }

    $response = [
        'success' => true,
        'month' => $month,
        'year' => $year,
        'monthName' => date('F', strtotime($startDate)),
        'daysInMonth' => $daysInMonth,
        'availability' => $availability,
        'legend' => [
            'available' => 'Available for batch scheduling (has individual inspections)',
            'unavailable' => 'Unavailable (batch scheduled, blocked, holiday, or no individual inspections)',
            'weekend' => 'Weekend - Not available',
            'holiday' => 'Holiday - Not available',
            'past' => 'Past date'
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>