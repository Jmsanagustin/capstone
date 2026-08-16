<?php
// FILE: submit_for_approval.php
// --- FINAL ALIGNED VERSION ---
// 1. Saves to 'term_grade' (100% grade) and 'final_grade' (40/60 blend).
// 2. Saves the 'class_id' so the Program Head dashboard can find it.
// 3. [FIXED] Replaced incorrect SUM/SUM grade calculation with correct transmutation logic.
// 4. [FIXED] Added Lab/Lec 3-step calculation logic.

session_start();
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    include 'db.php';

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
        throw new Exception('Unauthorized: Professor role required.');
    }

    $class_id = $_GET['class_id'] ?? 0;
    $term = $_GET['term'] ?? '';
    $professor_sid = $_SESSION['sid'] ?? 0;

    if (empty($class_id) || empty($term) || empty($professor_sid)) {
        throw new Exception('Missing Class ID, Term, or Professor ID.');
    }

    if (!$conn) {
        throw new Exception('Database connection failed.');
    }

    $conn->beginTransaction();

    // --- START OF FIX: Get has_lab status ---
    // 1. Get Subject ID, Program Head ID, and Lab Status
    $stmt_info = $conn->prepare("
        SELECT s.subject_id, s.program_head_id, s.has_lab 
        FROM classes c 
        JOIN subject s ON c.subject_id = s.subject_id 
        WHERE c.class_id = ?
    ");
    $stmt_info->execute([$class_id]);
    $info = $stmt_info->fetch(PDO::FETCH_ASSOC);

    if (!$info) {
        throw new Exception("Class information not found.");
    }
    $subject_id = $info['subject_id'];
    $program_head_id = $info['program_head_id'];
    $has_lab = $info['has_lab'];
    // --- END OF FIX ---

    // --- START OF FIX: Get component_type ---
    // 2. Get Components (weights)
    $stmt_comp = $conn->prepare("SELECT component_id, weight, component_type FROM grade_components WHERE class_id = ? AND term = ?");
    $stmt_comp->execute([$class_id, $term]);
    $components = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($components)) {
        throw new Exception("No grade components (weights) are set for this class/term.");
    }
    // --- END OF FIX ---
    
    // 3. Get Enrolled Students
    $stmt_stud = $conn->prepare("
        SELECT e.enrollment_id, up.student_id, u.username
        FROM enrollment e
        JOIN users u ON e.student_sid = u.sid
        JOIN user_profiles up ON u.sid = up.sid
        WHERE e.class_id = ?
    ");
    $stmt_stud->execute([$class_id]);
    $students = $stmt_stud->fetchAll(PDO::FETCH_ASSOC);
    if (empty($students)) {
        throw new Exception("No students are enrolled in this class.");
    }

    // --- START OF FIX: Use correct query to get individual items ---
    $stmt_scores = $conn->prepare("
        SELECT score, max_score
        FROM raw_scores
        WHERE enrollment_id = ? AND component_id = ?
    ");
    // --- END OF FIX ---
    
    // 4. Prepare statement to insert into the FINAL grades table
    $stmt_final_grade = $conn->prepare("
        INSERT INTO grades (student_id_string, subject_id, class_id, term, term_grade, final_grade, status, program_head_id, teacher_id, remarks, submission_date)
        VALUES (?, ?, ?, ?, ?, ?, 'Pending PH Approval', ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            class_id = VALUES(class_id),
            term_grade = VALUES(term_grade),
            final_grade = VALUES(final_grade),
            status = 'Pending PH Approval',
            program_head_id = VALUES(program_head_id),
            teacher_id = VALUES(teacher_id),
            remarks = VALUES(remarks),
            submission_date = NOW()
    ");

    // 5. Loop, Calculate, and Save Final Grades
    foreach ($students as $student) {
        $enrollment_id = $student['enrollment_id'];
        
        // --- START OF FIX: Lab/Lec Calculation & Correct Transmutation ---
        $lec_computed_grade = 0.0;
        $lec_total_weight = 0.0;
        $lab_computed_grade = 0.0;
        $lab_total_weight = 0.0;

        foreach ($components as $component) {
            $component_id = $component['component_id'];
            $weight = (float)$component['weight'];
            $type = $component['component_type']; // 'Lecture' or 'Laboratory'
            
            $stmt_scores->execute([$enrollment_id, $component_id]);
            $items = $stmt_scores->fetchAll(PDO::FETCH_ASSOC);
            
            $sum_of_transmuted_grades = 0;
            $item_count = 0;
            
            // Transmute each item individually
            foreach ($items as $item) {
                $transmuted_grade = 50.0; // Default for 0 max_score or no items
                if ($item['max_score'] > 0) {
                    $transmuted_grade = (($item['score'] / $item['max_score']) * 50) + 50;
                }
                $sum_of_transmuted_grades += $transmuted_grade;
                $item_count++;
            }
            
            // Get the average of the transmuted grades for this component
            $component_average = 0;
            if ($item_count > 0) {
                $component_average = $sum_of_transmuted_grades / $item_count;
            }
            
            // Add the weighted average to the correct type
            if ($type == 'Lecture') {
                $lec_computed_grade += ($component_average * $weight);
                $lec_total_weight += $weight;
            } else { // 'Laboratory'
                $lab_computed_grade += ($component_average * $weight);
                $lab_total_weight += $weight;
            }
        }
        
        // Normalize grades
        $lec_grade = ($lec_total_weight > 0) ? ($lec_computed_grade / $lec_total_weight) : 0;
        
        if ($has_lab) {
            $lab_grade = ($lab_total_weight > 0) ? ($lab_computed_grade / $lab_total_weight) : 0;
            // Apply 60% Lec, 40% Lab split
            $term_grade_calc = ($lec_grade * 0.60) + ($lab_grade * 0.40);
        } else {
            // No lab, so grade is just the lecture grade
            $term_grade_calc = $lec_grade;
        }
        
        $term_grade = round($term_grade_calc, 2); // This is the 100% grade for the term
        // --- END OF FIX ---
        
        $final_grade_for_approval = $term_grade;      

        if ($term === 'Final') {
            $stmt_midterm = $conn->prepare(
                // --- FIX: Get final_grade (blended) not term_grade ---
                "SELECT final_grade FROM grades 
                 WHERE student_id_string = ? AND subject_id = ? AND term = 'Midterm' 
                 AND (status = 'Approved by PH' OR status = 'Acknowledged by Registrar')"
            );
            // Use username (student_id) for grades table
            $stmt_midterm->execute([$student['student_id'], $subject_id]);
            $midterm_grade_val = $stmt_midterm->fetchColumn();

            if ($midterm_grade_val === false) {
                throw new Exception("Cannot submit Final grade for student {$student['student_id']}: Their Midterm grade is missing or not yet approved.");
            }

            $midterm_component = (float)$midterm_grade_val * 0.40;
            $final_component = (float)$term_grade * 0.60;      
            $final_grade_for_approval = $midterm_component + $final_component;
        }
        
        $final_grade = round($final_grade_for_approval, 2);
        $remarks = ($final_grade >= 75) ? 'Passed' : 'Failed';

        $stmt_final_grade->execute([
            $student['student_id'],
            $subject_id,
            $class_id,
            $term,
            $term_grade, // The 100% grade for THIS term
            $final_grade, // The blended (40/60) grade if Final, or same as term_grade if Midterm
            $program_head_id,
            $professor_sid,
            $remarks
        ]);
    }
    
    // 6. Lock the class for this term
    $lock_column = ($term === 'Midterm') ? 'midterm_finalized_at' : 'final_finalized_at';
    $stmt_lock = $conn->prepare("UPDATE classes SET $lock_column = NOW() WHERE class_id = ?");
    $stmt_lock->execute([$class_id]);

    $conn->commit();
    
    $_SESSION['upload_message'] = [
        'type' => 'success',
        'body' => 'Grades successfully submitted to the Program Head for approval.'
    ];
    echo json_encode(['status' => 'success', 'message' => 'Grades submitted and class locked.']);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage(), 'line' => $e->getLine()]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Submission Error: ' . $e->getMessage(), 'line' => $e->getLine()]);
}
?>