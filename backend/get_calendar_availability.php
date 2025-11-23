<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
// Database connection - Environment aware
require_once __DIR__ . '/config/db_config.php';

$servername = DB_HOST;
$username = DB_USERNAME;
$password = DB_PASSWORD;
$dbname = DB_NAME;    $conn = new mysqli($servername, $username, $password, $dbname);

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

    // Get all scheduled inspections for the specified month (only active/scheduled ones)
    // Only count individual Machinery and Building category requests
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate)); // Last day of the month

    $query = "SELECT inspection_date, COUNT(*) as machinery_building_count, 
                     GROUP_CONCAT(barangay SEPARATOR ', ') as locations
              FROM scheduled_inspections 
              WHERE inspection_date BETWEEN ? AND ? AND status = 'scheduled'
              AND (notes LIKE '%Machinery%' OR notes LIKE '%Building%')
              AND notes LIKE '%Individual inspection request%'
              GROUP BY inspection_date";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();

    $scheduledDates = [];
    while ($row = $result->fetch_assoc()) {
        $scheduledDates[$row['inspection_date']] = [
            'machinery_building_count' => (int)$row['machinery_building_count'],
            'locations' => $row['locations']
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
        
        // Only include if it's within our month range
        if ($holidayDate >= $startDate && $holidayDate <= $endDate) {
            $holidays[$holidayDate] = $row['name'];
        }
    }

    // Get the number of days in the month
    $daysInMonth = date('t', strtotime($startDate));
    
    // Generate availability data for each day
    $availability = [];
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $dayOfWeek = date('N', strtotime($currentDate)); // 1 = Monday, 7 = Sunday
        
        // Check if it's a weekday (Monday = 1 to Friday = 5)
        $isWeekday = ($dayOfWeek >= 1 && $dayOfWeek <= 5);
        
        // Check if the date is in the past
        $today = date('Y-m-d');
        $isPast = ($currentDate <= $today);
        
        // Check if there are scheduled inspections
        $scheduledData = isset($scheduledDates[$currentDate]) ? $scheduledDates[$currentDate] : null;
        $machineryBuildingCount = $scheduledData ? $scheduledData['machinery_building_count'] : 0;
        $locations = $scheduledData ? $scheduledData['locations'] : '';
        
        // No limit on Machinery/Building requests per day (unlimited)
        $isFullyBooked = false; // Never fully booked for Machinery/Building
        
        // Determine availability status
        $status = 'unavailable'; // Default
        
        if ($isPast) {
            $status = 'past';
        } elseif (isset($holidays[$currentDate])) {
            $status = 'holiday';
        } elseif (!$isWeekday) {
            $status = 'weekend';
        } else {
            $status = 'available';
        }
        
        $dayData = [
            'date' => $currentDate,
            'day' => $day,
            'dayOfWeek' => $dayOfWeek,
            'dayName' => date('D', strtotime($currentDate)),
            'status' => $status,
            'machineryBuildingCount' => $machineryBuildingCount,
            'locations' => $locations,
            'isSelectable' => ($status === 'available'),
            'hasScheduledInspections' => ($machineryBuildingCount > 0)
        ];

        // Add holiday name if it's a holiday
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
            'available' => 'Available for Machinery/Building bookings (Weekdays)',
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