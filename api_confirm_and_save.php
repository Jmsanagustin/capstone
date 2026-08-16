<?php
// PHP 8.1+
// /api_confirm_and_save.php (POST) - Role: Instructor
//
// This is the FINAL step of your 3-step AI upload workflow.
// 1. Reads the AI-validated grades from the session.
// 2. Saves them permanently to the 'grades' table.
// 3. Starts the 7-day finalization timer.

session_start();
include 'db.php'; // Your database connection

header('Content-Type: application/json');

// 1. Security Check: Use 'role', which is set in your main dashboard file.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. Instructor role required.']);
    exit;
}

// 2. Check for AI-VALIDATED data in the session
// This data should have been set by your 'api/grades/validate' (Python AI) script.
if (!isset($_SESSION['validated_grades'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'No validated grade data found. Please upload the file again.']);
    exit;
}

// 3. Get all necessary data from the session
$validatedGrades = $_SESSION['validated_grades'];
$classId = (int)$_SESSION['temp_grades_for_validation']['class_id']; // Get class_id from Step 1
$current_user_id = $_SESSION['sid'];

if ($classId === 0 || empty($validatedGrades)) {
     http_response_code(400);
     echo json_encode(['error' => 'Invalid session data.']);
     exit;
}

try {
    $conn->beginTransaction();

    // 4. Get Class Info (to check authorization and determine the current term)
    // We select the new timer columns you added to your 'classes' table.
    $stmt_class = $conn->prepare("
        SELECT midterm_finalized_at, final_finalized_at 
        FROM classes 
        WHERE class_id = :class_id AND professor_sid = :professor_sid
    ");
    $stmt_class->execute([
        ':class_id' => $classId, 
        ':professor_sid' => $current_user_id
    ]);
    $class = $stmt_class->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        throw new Exception('Class not found or you are not authorized.');
    }

    // Determine which term we are submitting for.
    // Logic: If midterms are locked, we must be submitting for finals.
    $current_term = ($class['midterm_finalized_at'] !== null) ? 'Finals' : 'Midterms';

    // 5. Loop and Save Grades to Database
    // This query updates the 'grades' table based on the student's username (student_id_string)
    // and the class_id.
    $stmt_save = $conn->prepare("
        UPDATE grades g
        JOIN users u ON g.student_id_string = u.username
        JOIN enrollment e ON u.sid = e.student_sid
        SET 
            g.midterm_grade = :midterm_grade,
            g.final_grade = :final_grade,
            g.teacher_id = :teacher_id,
            g.status = 'Pending PH Approval', -- Reset status on new submission
            g.term = :term -- Set the term (Midterm/Final)
        WHERE e.class_id = :class_id AND g.student_id_string = :student_id_str
    ");

    foreach ($validatedGrades as $grade) {
        // From your upload_grades.php:
        // 'quiz_score' is Midterm Grade
        // 'exam_score' is Final Grade
        $stmt_save->execute([
            ':midterm_grade' => $grade['quiz_score'], 
            ':final_grade'   => $grade['exam_score'], 
            ':teacher_id'    => $current_user_id,
            ':term'          => $current_term,
            ':class_id'      => $classId,
            ':student_id_str'=> $grade['student_id']
        ]);
    }

    // 6. ==========================================================
    //    START THE 7-DAY TIMER
    //    ==========================================================
    if ($current_term === 'Midterms') {
        // Start the Midterm timer (only if it hasn't been started)
        $stmt_timer = $conn->prepare("
            UPDATE classes 
            SET midterm_submission_start = NOW() 
            WHERE class_id = :class_id AND midterm_submission_start IS NULL
        ");
    } else {
        // Start the Final timer (only if it hasn't been started)
        $stmt_timer = $conn->prepare("
            UPDATE classes 
            SET final_submission_start = NOW() 
            WHERE class_id = :class_id AND final_submission_start IS NULL
        ");
    }
    $stmt_timer->execute([':class_id' => $classId]);


    // 7. Clear ALL session data and commit
    unset($_SESSION['temp_grades_for_validation']);
    unset($_SESSION['validated_grades']);
    $conn->commit();

    // 8. Send final success message
    echo json_encode([
        'status' => 'success',
        'message' => "Grades have been successfully confirmed, saved, and sent for approval.",
        'timer_started_for_term' => $current_term,
        'redirect_url' => "teacher_dashboard.php?view=dashboard" 
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save grades: ' . $e->getMessage()]);
}
?>