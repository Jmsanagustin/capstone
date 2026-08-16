<?php
// FILE: get_attendance_data.php
// API: Fetches student roster for a class and merges existing attendance data for a specific date.
// NOTE: This uses the new 'attendance' table with 'enrollment_id'

session_start();
include 'db.php';

header('Content-Type: application/json');

// --- 1. Security and Validation ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
if (!isset($_GET['class_id']) || !isset($_GET['date'])) {
    echo json_encode(['error' => 'Missing required parameters.']);
    exit();
}

$class_id = $_GET['class_id'];
$attendance_date = $_GET['date'];
$professor_sid = $_SESSION['sid'];
$students_data = [];

try {
    // --- 2. Verify professor teaches this class (Security) ---
    $stmt_verify = $conn->prepare("SELECT class_id FROM classes WHERE class_id = :cid AND professor_sid = :psid");
    $stmt_verify->execute([':cid' => $class_id, ':psid' => $professor_sid]);
    if ($stmt_verify->rowCount() == 0) {
        echo json_encode(['error' => 'Access denied to this class.']);
        exit();
    }

    // --- 3. Fetch Student Roster and Existing Attendance Data ---
    // This query joins the student list (enrollment) with any existing attendance
    // records for the specified date.
    $stmt_students = $conn->prepare("
        SELECT 
            u.sid,
            p.first_name,
            p.last_name,
            a.status,
            a.remarks
        FROM enrollment e
        JOIN users u ON e.student_sid = u.sid
        JOIN user_profiles p ON u.sid = p.sid
        LEFT JOIN attendance a ON e.enrollment_id = a.enrollment_id 
                               AND a.attendance_date = :att_date
        WHERE e.class_id = :class_id
        ORDER BY p.last_name, p.first_name
    ");
    
    $stmt_students->execute([
        ':class_id' => $class_id,
        ':att_date' => $attendance_date
    ]);

    $results = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $row) {
        $students_data[] = [
            'sid' => $row['sid'], // We use sid as the unique ID for the radio buttons
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'status' => $row['status'] ?? 'Present', // Default to 'Present' if no record exists
            'remarks' => $row['remarks'] ?? ''
        ];
    }

    echo json_encode($students_data);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database query failed: ' . $e->getMessage()]);
}
?>