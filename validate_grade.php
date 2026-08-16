<?php
// FILE: validate_grade.php
// PURPOSE: Calculates the final term grade for preview.
// --- CHANGELOG ---
// 1. [FIXED] Replaced the incorrect (SUM(score)/SUM(max_score)) logic with the
//    correct transmutation logic (AVG of transmuted items) from professor_student_breakdown.php.
// 2. [FIXED] Added Lab/Lec 3-step calculation logic.

session_start();
include 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$class_id = $_GET['class_id'] ?? 0;
$term = $_GET['term'] ?? '';

if (empty($class_id) || empty($term)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing Class ID or Term.']);
    exit();
}

try {
    // --- START OF FIX: Get has_lab status ---
    $stmt_info = $conn->prepare("
        SELECT s.subject_id, s.has_lab 
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        WHERE c.class_id = ?
    ");
    $stmt_info->execute([$class_id]);
    $class_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
    if (!$class_info) {
        throw new Exception("Class information not found.");
    }
    $subject_id = $class_info['subject_id'];
    $has_lab = $class_info['has_lab'];
    // --- END OF FIX ---

    // --- START OF FIX: Get component_type ---
    // 2. Get Grade Components (weights)
    $stmt_comp = $conn->prepare("SELECT component_id, weight, component_type FROM grade_components WHERE class_id = ? AND term = ?");
    $stmt_comp->execute([$class_id, $term]);
    $components = $stmt_comp->fetchAll(PDO::FETCH_ASSOC);
    if (empty($components)) {
        throw new Exception("No grade components (weights) are set for this class/term.");
    }
    // --- END OF FIX ---
    
    // 3. Get Enrolled Students
    $stmt_stud = $conn->prepare("
        SELECT e.enrollment_id, up.student_id, up.sid AS student_sid, u.username
        FROM enrollment e
        JOIN users u ON e.student_sid = u.sid
        JOIN user_profiles up ON u.sid = up.sid
        WHERE e.class_id = ?
    ");
    $stmt_stud->execute([$class_id]);
    $students = $stmt_stud->fetchAll(PDO::FETCH_ASSOC);

    $validated_grades_preview = [];
    $risk_count = 0;
    
    // 4. Get individual item scores
    $stmt_scores = $conn->prepare("
        SELECT score, max_score
        FROM raw_scores
        WHERE enrollment_id = ? AND component_id = ?
    ");
    
    $stmt_intervention = $conn->prepare("
        INSERT INTO interventions (student_sid, class_id, type, task_description, status, created_by_sid)
        VALUES (?, ?, 'Academic', ?, 'Open', ?)
        ON DUPLICATE KEY UPDATE 
            task_description = VALUES(task_description), 
            status = 'Open'
    ");
    
    // 5. Loop through each student
    foreach ($students as $student) {
        $enrollment_id = $student['enrollment_id'];
        
        // --- START OF FIX: Lab/Lec Calculation ---
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
            $term_grade = ($lec_grade * 0.60) + ($lab_grade * 0.40);
        } else {
            // No lab, so grade is just the lecture grade
            $term_grade = $lec_grade;
        }
        
        $term_grade = round($term_grade, 2); 
        // --- END OF FIX ---
        
        $final_grade_to_preview = $term_grade;
        $validation_error = null;
        $computed_grade_display = number_format($term_grade, 2);

        // --- 40/60 LOGIC (This part is correct) ---
        if ($term === 'Final') {
            $stmt_midterm = $conn->prepare(
                "SELECT term_grade FROM grades 
                 WHERE student_id_string = ? AND subject_id = ? AND term = 'Midterm' 
                 AND (status = 'Approved by PH' OR status = 'Acknowledged by Registrar')"
            );
            // Use username (student_id_string) for grades table
            $stmt_midterm->execute([$student['username'], $subject_id]); 
            $midterm_grade_val = $stmt_midterm->fetchColumn();

            if ($midterm_grade_val === false) {
                $final_grade_to_preview = 0; 
                $validation_error = 'Midterm grade not found or not approved. Cannot calculate Final Grade.';
                $computed_grade_display = "<strong>- ERROR -</strong>";
            } else {
                $midterm_component = (float)$midterm_grade_val * 0.40;
                $final_component = (float)$term_grade * 0.60;
                $final_grade_to_preview = $midterm_component + $final_component;
                
                // Show both grades
                $computed_grade_display = "Term: " . number_format($term_grade, 2) . 
                                          "<br><strong>Sem Grade: " . number_format($final_grade_to_preview, 2) . "</strong>";
            }
        }
        // --- END NEW LOGIC ---

        $final_computed_grade = round($final_grade_to_preview, 2);

        // 5. Run Validation & Prediction Logic
        $risk_status = 'NOT_AT_RISK';

        if ($final_computed_grade < 75 && $final_computed_grade > 0 && !$validation_error) {
            $risk_status = 'AT_RISK';
            $validation_error = 'Failing Grade';
            $risk_count++;
            
            $desc = "Student is at risk of failing $term with a final grade of " . number_format($final_computed_grade, 2);
            $stmt_intervention->execute([$student['student_sid'], $class_id, $desc, $_SESSION['sid']]);
            
        } else if ($final_computed_grade == 0 && !$validation_error) {
            $risk_status = 'INCOMPLETE';
            $validation_error = 'Missing scores (Grade is 0)';
        }

        $validated_grades_preview[] = [
            'student_id' => $student['student_id'], // This is the string ID e.g., '2201070554'
            'computed_grade_display' => $computed_grade_display, // Use this for the table
            'validation_error' => $validation_error,
            'risk_status' => $risk_status
        ];
    }

    echo json_encode([
        'status' => 'success',
        'validated_grades_preview' => $validated_grades_preview,
        'risk_count' => $risk_count
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'line' => $e->getLine()]);
}
?>