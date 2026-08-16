<?php
// compute_final_grades.php (MODIFIED)

session_start();
include 'db.php'; 
header('Content-Type: application/json');

// Security checks
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit();
}
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$current_user_id = $_SESSION['sid'];

if (!$class_id) {
    echo json_encode(['status' => 'error', 'error' => 'Invalid Class ID']);
    exit();
}

try {
    $conn->beginTransaction();

    // 1. Get class info to check authorization and term
    $stmt_class = $conn->prepare("SELECT * FROM classes WHERE class_id = :class_id AND professor_sid = :professor_sid");
    $stmt_class->execute([':class_id' => $class_id, ':professor_sid' => $current_user_id]);
    $class = $stmt_class->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        throw new Exception('Class not found or you are not authorized.');
    }

    // ================================================================
    // YOUR EXISTING GRADE CALCULATION LOGIC GOES HERE...
    // ...
    // ... (e.g., fetch students, loop through them, calculate weighted scores)
    // ... (e.g., INSERT or UPDATE the `grades` table)
    //
    // After your logic successfully saves grades for all students...
    // ================================================================


    // 2. ==========================================================
    //    NEW CHANGE: Start the 7-day finalization timer
    //    ==========================================================
    
    // Determine which term to start the timer for
    $current_term = ($class['midterm_finalized_at'] !== null) ? 'Finals' : 'Midterms';
    
    if ($current_term === 'Midterms') {
        // Start the Midterm timer (only if it hasn't been started before)
        $stmt_timer = $conn->prepare("
            UPDATE classes 
            SET midterm_submission_start = NOW() 
            WHERE class_id = :class_id AND midterm_submission_start IS NULL
        ");
    } else {
        // Start the Final timer (only if it hasn't been started before)
        $stmt_timer = $conn->prepare("
            UPDATE classes 
            SET final_submission_start = NOW() 
            WHERE class_id = :class_id AND final_submission_start IS NULL
        ");
    }
    $stmt_timer->execute([':class_id' => $class_id]);


    // 3. Commit and send success message
    $conn->commit();
    echo json_encode([
        'status' => 'success', 
        'message' => "Grades for $current_term computed and submitted. The 7-day finalization window has started."
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
?>