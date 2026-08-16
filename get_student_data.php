<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['error' => 'Unauthorized access or session expired.']);
    exit();
}

// --- Configuration & Includes ---
include 'db.php'; // Assumes $conn PDO object is initialized here
$student_id = $_SESSION['student_id'] ?? "BSIS-2024-0001"; // Default to a known ID from grades table
$current_term = '1st Semester'; // Assuming you target subjects in 1st Semester for the 2025-2026 year
$academic_year = '2025-2026';
$python_script_path = '/path/to/your/grade_analyzer.py'; // <--- MUST UPDATE THIS PATH

// --- Grading System Logic (Required for PHP-side GWA calculation) ---
function get_gwa_equivalent($numerical_grade) {
    if ($numerical_grade >= 97 && $numerical_grade <= 100) return 1.00;
    if ($numerical_grade >= 94 && $numerical_grade <= 96) return 1.25;
    if ($numerical_grade >= 91 && $numerical_grade <= 93) return 1.50;
    if ($numerical_grade >= 88 && $numerical_grade <= 90) return 1.75;
    if ($numerical_grade >= 85 && $numerical_grade <= 87) return 2.00;
    if ($numerical_grade >= 82 && $numerical_grade <= 84) return 2.25;
    if ($numerical_grade >= 79 && $numerical_grade <= 81) return 2.50;
    if ($numerical_grade >= 76 && $numerical_grade <= 78) return 2.75;
    if ($numerical_grade == 75) return 3.00;
    if ($numerical_grade <= 74) return 5.00;
    return 5.00;
}


// --- 1. Fetch Student Name (CONCATENATING fields for Full Name) ---
$student_name = "Loading Student";
try {
    $sql_profile = "
        SELECT 
            up.first_name, up.middle_name, up.last_name 
        FROM user_profiles up
        WHERE up.student_id = ?
    ";
    $stmt = $conn->prepare($sql_profile);
    $stmt->execute([$student_id]);
    if ($profile_data = $stmt->fetch()) {
        $student_name = trim("{$profile_data['first_name']} {$profile_data['middle_name']} {$profile_data['last_name']}");
    }
} catch (PDOException $e) { /* Error handling... */ }


// --- 2. Fetch Raw Subjects Data (Joined with Classes and Instructor) ---
$subjects_data_raw = [];
try {
    $sql_subjects = "
        SELECT 
            t3.subject_code AS code, 
            t3.subject_name AS name, 
            t3.subject_id,
            t1.midterm_grade AS current_grade, 
            t1.status,
            t3.units,
            t4.first_name AS instructor_fname, 
            t4.last_name AS instructor_lname,
            t2.academic_year,
            t2.class_id 
        FROM grades t1
        JOIN subject t3 ON t1.subject_id = t3.subject_id
        -- Join to classes to get the professor's SID (t2.professor_sid)
        JOIN classes t2 ON t1.subject_id = t2.subject_id AND t1.term = t2.term AND t1.academic_year = t2.academic_year
        -- Join to user_profiles to get the professor's name (t4)
        JOIN user_profiles t4 ON t2.professor_sid = t4.sid 
        WHERE t1.student_id_string = ?
          AND t1.academic_year = ? 
          AND t1.term = ?
    ";
    
    $stmt_subjects = $conn->prepare($sql_subjects);
    // Bind all necessary parameters
    $stmt_subjects->execute([$student_id, $academic_year, $current_term]);
    $subjects_data_raw = $stmt_subjects->fetchAll();

} catch (PDOException $e) {
    error_log("Subjects fetch failed: " . $e->getMessage());
}


// --- 3. Execute Python Analysis ---
$subjects_data_processed = [];

if (!empty($subjects_data_raw)) {
    // a. Prepare and clean data types for Python
    $data_for_python = array_map(function($subject) {
        $subject['current_grade'] = (float)($subject['current_grade'] ?? 0.0);
        $subject['status'] = (string)($subject['status'] ?? 'In Progress');
        
        // **REMOVED MOCKING:** Use fetched instructor name
        $subject['instructor'] = trim("{$subject['instructor_fname']} {$subject['instructor_lname']}");
        
        // MOCK COMPONENT DATA (Python/JS input requires this structure - adjust as needed)
        $subject['components'] = [
            ['name' => 'Completed Work (60%)', 'weight' => 0.60, 'raw_average' => $subject['current_grade'], 'weighted_score' => $subject['current_grade'] * 0.6, 'scores' => [80, 75]],
            ['name' => 'Remaining Work (40%)', 'weight' => 0.40, 'raw_average' => 0, 'weighted_score' => 0, 'scores' => []],
        ];
        
        // Remove temporary instructor name fields before Python execution
        unset($subject['instructor_fname'], $subject['instructor_lname']);
        return $subject;
    }, $subjects_data_raw);
    
    // b. Shell execution
    $input_json = json_encode($data_for_python);
    $escaped_input = escapeshellarg($input_json);
    $command = "python3 " . $python_script_path . " " . $escaped_input;
    $json_output = shell_exec($command);
    
    $subjects_data_processed = json_decode($json_output, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($subjects_data_processed)) {
        error_log("Python Analysis Error: " . $json_output);
        $subjects_data_processed = $data_for_python; // Fallback to raw data
    }
}


// --- 4. Final GWA Calculation (PHP side) ---
$total_grade_points = 0;
$total_units = 0;
$at_risk_count = 0;

foreach ($subjects_data_processed as &$subject) {
    $subject['units'] = (int)($subject['units'] ?? 3); // Ensure units is set
    
    // Check if the subject is at risk based on the Python forecast result
    if (isset($subject['forecast']['needs_help']) && $subject['forecast']['needs_help']) {
        $at_risk_count++;
    }

    // Only Approved/Final grades count toward GWA
    if ($subject['status'] === 'Approved by PH' || $subject['status'] === 'Approved') {
        $numerical_grade = (float)($subject['current_grade'] ?? 0.0);
        $gwa_equivalent = get_gwa_equivalent($numerical_grade);
        $total_grade_points += $gwa_equivalent * $subject['units'];
        $total_units += $subject['units'];
    }
}
unset($subject);

$current_gwa = ($total_units > 0) ? number_format($total_grade_points / $total_units, 2) : 'N/A';

// --- 5. Prepare final JSON response ---
$response_data = [
    'student_name' => $student_name,
    'student_id' => $student_id,
    'current_gwa' => $current_gwa,
    'at_risk_count' => $at_risk_count,
    'subjects' => $subjects_data_processed
];

echo json_encode($response_data);
exit();