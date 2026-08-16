<?php
// PHP 8.1+
// /api_confirm_and_save.php (POST) - Role: Instructor
//
// This is the FINAL step.
// It reads the AI-validated grades from the session, saves them to the database,
// and starts the 7-day finalization timer.

session_start();
include 'db.php'; // Database connection

header('Content-Type: application/json');

// 1. Security Check (Using the CORRECT session variable)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    http_response_code(403); 
    echo json_encode(['error' => 'Access denied. Instructor role required.']);
    exit;
}

// 2. Check for VALIDATED data in the session
if (!isset($_SESSION['validated_grades'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No validated grade data found. Please upload the file again.']);
    exit;
}

// 3. Get data from session
$validatedGrades = $_SESSION['validated_grades'];
$classId = (int)$_SESSION['temp_grades_for_validation']['class_id']; // Get class_id
$current_user_id = $_SESSION['sid'];

if ($classId === 0 || empty($validatedGrades)) {
     http_response_code(400);
     echo json_encode(['error' => 'Invalid session data.']);
     exit;
}

try {
    $conn->beginTransaction();

    // 4. Get Class Info (to check which term it is)
    // We select the new columns you just added
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

    // Determine which term we are submitting for
    $current_term = ($class['midterm_finalized_at'] !== null) ? 'Finals' : 'Midterms';

    // 5. Loop and Save Grades to Database
    // This query matches your 'grades' table schema
    $stmt_save = $conn->prepare("
        UPDATE grades g
        JOIN users u ON g.student_id_string = u.username
        JOIN enrollment e ON u.sid = e.student_sid
        SET 
            g.midterm_grade = :midterm_grade,
            g.final_grade = :final_grade,
            g.teacher_id = :teacher_id
        WHERE e.class_id = :class_id AND g.student_id_string = :student_id_str
    ");

    foreach ($validatedGrades as $grade) {
        $stmt_save->execute([
            ':midterm_grade' => $grade['quiz_score'], // From your upload script
            ':final_grade'   => $grade['exam_score'], // From your upload script
            ':teacher_id'    => $current_user_id,
            ':class_id'      => $classId,
            ':student_id_str'=> $grade['student_id']
        ]);
    }

    // 6. ==========================================================
    //    START THE 7-DAY TIMER (Using the new DB columns)
    //    ==========================================================
    if ($current_term === 'Midterms') {
        $stmt_timer = $conn->prepare("
            UPDATE classes 
            SET midterm_submission_start = NOW() 
            WHERE class_id = :class_id AND midterm_submission_start IS NULL
        ");
    } else {
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
        'message' => "Grades have been successfully confirmed and saved.",
        'timer_started_for_term' => $current_term,
        'redirect_url' => "teacher_dashboard.php?view=dashboard" 
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save grades: ' . $e->getMessage()]);
}
?>