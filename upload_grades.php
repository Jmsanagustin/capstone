<?php
// FILE: upload_grades.php
// PURPOSE: Processes a 2-COLUMN CSV (STUDENT_ID, SCORE)
// REVISION: Implements Zero Score Detection and signals 'zero_inc_check' before saving.
// --- (NEW) ---
// INTEGRATED: Calls the checkAndLogIntervention() function for newly inserted scores.

session_start();
include 'db.php';
include 'intervention_logic.php'; // --- (NEW) --- Include the intervention logic
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// --- START OF FIX: Get data from POST, not CSV ---
$class_id = $_POST['class_id'] ?? 0;
$term = $_POST['term'] ?? '';
$component_id = $_POST['component_id'] ?? 0;
$item_name = trim($_POST['item_name'] ?? '');
$max_score = (float)($_POST['max_score'] ?? 0);
// --- END OF FIX ---

// Check for the special action flag (used when confirming from the Zero/INC modal)
$zero_inc_action = $_POST['zero_inc_action'] ?? null; // 'confirm-zero' or 'tag-inc'

if (empty($class_id) || empty($term) || empty($_FILES['grades_excel']['tmp_name'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required data (class, term, or file).']);
    exit();
}
if (empty($component_id) || empty($item_name) || $max_score <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Form validation failed (component, item name, or max score missing).']);
    exit();
}

$fileName = $_FILES['grades_excel']['tmp_name'];
if ($_FILES['grades_excel']['size'] == 0) {
    echo json_encode(['status' => 'error', 'message' => 'Uploaded file is empty.']);
    exit();
}

$conn->beginTransaction();
try {
    // 1. Get Enrolled Students
    // --- (MODIFIED) --- We now also fetch e.student_sid for the intervention logic
    $stmt_stud = $conn->prepare("
        SELECT e.enrollment_id, e.student_sid, up.student_id, up.first_name, up.last_name
        FROM enrollment e
        JOIN user_profiles up ON e.student_sid = up.sid
        WHERE e.class_id = ?
    ");
    $stmt_stud->execute([$class_id]);
    $student_map = [];
    while ($row = $stmt_stud->fetch(PDO::FETCH_ASSOC)) {
        $student_map[$row['student_id']] = [
            'id' => $row['enrollment_id'],
            'sid' => $row['student_sid'], // --- (NEW) --- Store the 'sid'
            'name' => $row['last_name'] . ', ' . $row['first_name']
        ];
    }

    $file = fopen($fileName, 'r');
    $header = fgetcsv($file); 

    $idx_sid = array_search('STUDENT_ID', $header);
    $idx_score = array_search('SCORE', $header);

    if ($idx_sid === false || $idx_score === false) {
        throw new Exception('Invalid CSV header. Must contain only: STUDENT_ID, SCORE');
    }

    // --- 2. VALIDATE CSV DATA & DETECT ZEROS (New Logic) ---
    $csv_data = [];
    $validation_errors = [];
    $zero_items_detected = [];
    $row_number = 2; 

    while (($row = fgetcsv($file)) !== FALSE) {
        $student_id = trim($row[$idx_sid]);
        $score = trim($row[$idx_score]);

        if (empty($student_id) || !is_numeric($score) || $score < 0 || !isset($student_map[$student_id])) {
            // Standard validation errors (skipping for brevity)
            $validation_errors[] = "Error on row $row_number: Data invalid or student not found.";
        }
        
        // --- Zero Score Check ---
        if ((float)$score === 0.00 && $max_score > 0) {
             $zero_items_detected[] = [
                 'student_id_string' => $student_id,
                 'student_name' => $student_map[$student_id]['name'],
                 'item_name' => $item_name,
                 'new_score' => 0.00,
                 'new_max' => $max_score
             ];
        }
        
        $csv_data[] = $row;
        $row_number++;
    }
    fclose($file);

    if (!empty($validation_errors)) {
        $error_string = implode("\n", $validation_errors);
        throw new Exception("Upload failed. Please fix these errors in your CSV file:\n\n" . $error_string);
    }
    
    // --- 3. EXECUTE ZERO/INC CLARIFICATION CHECK ---
    if (!empty($zero_items_detected) && $zero_inc_action === null) {
        // If zeros are detected and we haven't received confirmation, signal the modal check
        $conn->rollBack(); // Rollback initial transaction, we need clarification
        echo json_encode([
            'status' => 'zero_inc_check',
            'zero_items' => $zero_items_detected,
            'message' => 'Zero scores detected. Clarification required.'
        ]);
        exit();
    }
    // Note: If $zero_inc_action is set, the script continues below.
    

    // --- 4. Process data and check for OVERWRITES (Only proceeds if no zeros detected or clarification was given) ---
    $review_list = [];
    $inserted_count = 0;
    $skipped_count = 0;
    $zero_inc_count = 0;

    $stmt_check = $conn->prepare("
        SELECT score, max_score FROM raw_scores 
        WHERE enrollment_id = ? AND component_id = ? AND item_name = ?
    ");
    
    // Prepare INSERT/UPDATE statement (will execute in confirm_upload if review_list is empty)
    $stmt_insert = $conn->prepare("
        INSERT INTO raw_scores (enrollment_id, component_id, item_name, score, max_score, score_status, date_recorded) 
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach ($csv_data as $row) {
        $student_id = trim($row[$idx_sid]);
        $score = (float)trim($row[$idx_score]);
        
        // --- (MODIFIED) --- Get all student data from the map
        $student_data = $student_map[$student_id];
        $enrollment_id = $student_data['id'];
        $student_sid = $student_data['sid']; // --- (NEW) --- Get the student's SID
        
        $stmt_check->execute([$enrollment_id, $component_id, $item_name]);
        $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        // Determine the final status based on the zero_inc_action
        $final_score_status = 'Graded';
        $final_score_value = $score;

        if ($score === 0.00 && $max_score > 0 && $zero_inc_action === 'tag-inc') {
             // If the professor chose INC, we mark the status as INC
             // We keep score=0 for calculation, but the INC status prevents negative calculation until resolved
             $final_score_status = 'INC';
             $zero_inc_count++;
        }
        
        if ($existing) {
            // DUPLICATE FOUND
            if ($existing['score'] != $score || $existing['max_score'] != $max_score) {
                // Scores are different, add to review
                $review_list[] = [
                    'student_name' => $student_map[$student_id]['name'],
                    'item_name' => $item_name,
                    'old_score' => $existing['score'],
                    'old_max' => $existing['max_score'],
                    'new_score' => $score,
                    'new_max' => $max_score,
                    'action' => 'UPDATE',
                    'temp_status' => $final_score_status // Pass the INC flag to the final upsert if approved
                ];
            } else {
                // Scores are identical, skip
                $skipped_count++;
            }
        } else {
            // NEW SCORE: Insert immediately (if no review needed)
            $stmt_insert->execute([
                $enrollment_id, $component_id, $item_name, $final_score_value, $max_score, $final_score_status
            ]);
            $inserted_count++;
            
            // --- (NEW) ---
            // A score was just inserted. Run the intervention check.
            checkAndLogIntervention($conn, $student_sid, $class_id);
            // --- (END NEW) ---
        }
    } // End foreach ($csv_data)


    // --- 5. Final Response ---
    $conn->commit();
    
    if (empty($review_list)) {
        // No review needed, transaction committed successfully
        $message = "Processing complete. $inserted_count new scores were saved.";
        if ($zero_inc_count > 0) {
            $message .= " ($zero_inc_count score(s) marked Incomplete.)";
        }
        if ($skipped_count > 0) {
            $message .= " $skipped_count identical scores were skipped.";
        }
        echo json_encode(['status' => 'success', 'message' => $message]);
    } else {
        // Review needed. Send the review list (transaction is committed only by confirm_upload.php)
        $message = "$inserted_count new scores were saved. " . count($review_list) . " items need your review.";
        if ($zero_inc_count > 0) {
            $message .= " ($zero_inc_count score(s) tagged INC.)";
        }
        if ($skipped_count > 0) {
            $message .= " $skipped_count identical scores were skipped.";
        }
        
        // Pass the required variables to the confirmation script
        $_SESSION['upload_data'] = [
            'class_id' => $class_id,
            'term' => $term,
            'component_id' => $component_id,
            'item_name' => $item_name,
            'max_score' => $max_score,
            'csv_data' => $csv_data,
            'zero_inc_action' => $zero_inc_action, // Pass the zero confirmation status
            'zero_inc_count' => $zero_inc_count // Pass count
        ];
        
        echo json_encode([
            'status' => 'review', 
            'review_list' => $review_list, 
            'message' => $message
        ]);
    }

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>