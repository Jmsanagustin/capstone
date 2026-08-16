<?php
// api_get_class_details.php
// This script provides details for the "Grade Input" page.

session_start();
include 'db.php'; 

header('Content-Type: application/json');

// Security: Ensure user is a logged-in professor
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit();
}

// Get Class ID from the request
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$current_user_id = $_SESSION['sid'];

if (!$class_id) {
    echo json_encode(['status' => 'error', 'error' => 'Invalid Class ID']);
    exit();
}

try {
    // 1. Fetch class details, including lab status and the new timestamp columns
    $stmt_class = $conn->prepare("
        SELECT 
            c.*, 
            s.has_lab 
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        WHERE c.class_id = :class_id AND c.professor_sid = :professor_sid
    ");
    $stmt_class->execute([
        ':class_id' => $class_id,
        ':professor_sid' => $current_user_id
    ]);
    $class = $stmt_class->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        echo json_encode(['status' => 'error', 'error' => 'Class not found or you are not authorized.']);
        exit();
    }

    // 2. Fetch components for this class
    $stmt_comp = $conn->prepare("SELECT * FROM grade_components WHERE class_id = :class_id");
    $stmt_comp->execute([':class_id' => $class_id]);
    $components = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);

    // 3. ==========================================================
    //    CORE LOGIC: Determine the grading status window
    //    ==========================================================
    
    // Determine the current active term for this class
    // Simple logic: If midterms are locked, we are in finals. Otherwise, we are in midterms.
    $current_term = ($class['midterm_finalized_at'] !== null) ? 'Finals' : 'Midterms';

    $submitted_at = null;
    $finalized_at = null;

    if ($current_term === 'Midterms') {
        $submitted_at = $class['midterm_submission_start'];
        $finalized_at = $class['midterm_finalized_at'];
    } else {
        // We are in Finals term
        $submitted_at = $class['final_submission_start'];
        $finalized_at = $class['final_finalized_at'];
    }

    $is_locked = ($finalized_at !== null);
    $deadline = null;

    // If grades are submitted but NOT locked, calculate the 7-day deadline
    if ($submitted_at !== null && !$is_locked) {
        $deadline_timestamp = strtotime($submitted_at . ' + 7 days');
        $deadline = date('Y-m-d H:i:s', $deadline_timestamp);
    }

    // This is the payload the JavaScript is waiting for
    $grading_status_payload = [
        'is_locked'    => $is_locked,
        'term'         => $current_term,
        'submitted_at' => $submitted_at,
        'deadline'     => $deadline,
        'finalized_at' => $finalized_at
    ];

    // 4. Send all data back as JSON
    echo json_encode([
        'status'         => 'success',
        'has_lab'        => (bool)$class['has_lab'],
        'components'     => $components,
        'grading_status' => $grading_status_payload
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'error' => 'Database error: ' . $e->getMessage()]);
}
?>