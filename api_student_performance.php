<?php
// PHP 8.1+ API Endpoint: /api/student/performance
// This script provides the JSON data needed for the React StudentDashboard component.

session_start();
// Include database connection (make sure db.php establishes $conn as a PDO object)
include 'db.php'; 

// Set header for JSON response
header('Content-Type: application/json');

// --- 1. Authentication Guard (Ensures only the student can access their data) ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Student') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$student_id = $_SESSION['user_id'];

// --- Grading Logic (Copy/Paste the GWA Equivalent function from student_dashboard.php) ---
function get_gwa_equivalent($numerical_grade): float {
    if ($numerical_grade >= 97 && $numerical_grade <= 100) return 1.00; 
    if ($numerical_grade >= 94 && $numerical_grade <= 96) return 1.25; 
    // ... [Include the rest of the GWA mapping here] ...
    if ($numerical_grade <= 74) return 5.00; 
    return 5.00;
}

try {
    // --- 2. Database Query: Fetch all grades for the current student ---
    // JOIN is necessary to link Grades table (scores) with Classes table (units)
    $stmt = $conn->prepare("
        SELECT
            g.final_grade,
            g.status,
            g.ai_risk_status, -- Assumes this column exists after AI validation
            c.course_code,
            c.title,
            c.units,
            c.instructor_id
        FROM Grades g
        JOIN Classes c ON g.class_id = c.class_id
        WHERE g.student_id = :student_id
    ");
    $stmt->bindParam(':student_id', $student_id);
    $stmt->execute();
    $raw_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $current_grades = [];
    $total_grade_points = 0;
    $total_units = 0;

    // --- 3. Process Data and Calculate Overall GWA ---
    foreach ($raw_grades as $grade) {
        $gwa_equivalent = get_gwa_equivalent($grade['final_grade']);
        
        // Only APPROVED grades typically count toward official GWA
        if ($grade['status'] === 'Approved') {
            $total_grade_points += $gwa_equivalent * $grade['units'];
            $total_units += $grade['units'];
        }

        $current_grades[] = [
            'id' => $grade['course_code'],
            'name' => $grade['title'],
            'current_grade' => (float)number_format($grade['final_grade'], 2),
            'gwa_equivalent' => $gwa_equivalent,
            'status' => $grade['status'],
            'is_at_risk' => $grade['ai_risk_status'] === 'AT_RISK',
            'progress' => min(100, (($grade['final_grade'] - 75) / 25) * 100) // Simple progress bar logic
        ];
    }

    $overall_gwa = ($total_units > 0) ? (float)number_format($total_grade_points / $total_units, 2) : 0.00;
    $at_risk_count = count(array_filter($current_grades, fn($g) => $g['is_at_risk']));

    // --- 4. Final JSON Output for React Frontend ---
    echo json_encode([
        'status' => 'success',
        'student_info' => ['id' => $student_id, 'gwa' => $overall_gwa],
        'current_grades' => $current_grades,
        'ai_risk_status' => $at_risk_count > 0 ? 'HIGH' : 'LOW'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Grade fetch error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database operation failed.']);
}
?>