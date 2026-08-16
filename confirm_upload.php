<?php
// FILE: upload_grades.php
// PURPOSE: Processes a 2-COLUMN CSV (STUDENT_ID, SCORE).
// REVISION: MODIFIED to DEFER ALL database actions (new inserts and updates) until final confirmation.
//           This ensures the professor can always review the upload before saving.

session_start();
include 'db.php';
include 'intervention_logic.php'; 
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$class_id = $_POST['class_id'] ?? 0;
$term = $_POST['term'] ?? '';
$component_id = $_POST['component_id'] ?? 0;
$item_name = trim($_POST['item_name'] ?? '');
$max_score = (float)($_POST['max_score'] ?? 0);

$zero_inc_action = $_POST['zero_inc_action'] ?? null;

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
            'sid' => $row['student_sid'], 
            'name' => $row['last_name'] . ', ' . $row['first_name']
        ];
    }

    $file = fopen($fileName, 'r');
    $header = fgetcsv($file); 

    $idx_sid = array_search('STUDENT_ID', $header);
    $idx_score = array_search('SCORE', $header);

    if ($idx_sid === false || $idx_score === false) {
        throw new Exception('Invalid CSV header. Must contain: STUDENT_ID, SCORE');
    }

    // --- 2. VALIDATE CSV DATA & DETECT ZEROS ---
    $csv_data = [];
    $validation_errors = [];
    $zero_items_detected = [];
    $row_number = 2; 

    while (($row = fgetcsv($file)) !== FALSE) {
        $student_id = trim($row[$idx_sid]);
        $score = trim($row[$idx_score]);

        if (empty($student_id) || !is_numeric($score) || $score < 0 || !isset($student_map[$student_id])) {
            // If the row is not valid, skip further checks but flag the error
            if (!empty($student_id) || !empty($score)) {
                $validation_errors[] = "Error on row $row_number: Data invalid or student not found for ID $student_id.";
            }
        } else {
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
        $conn->rollBack(); 
        
        // Store data temporarily for the front-end to call confirm_upload.php later
        $_SESSION['upload_data'] = [
            'class_id' => $class_id,
            'term' => $term,
            'zero_inc_action' => null, 
            'items_to_process' => [[
                'component_id' => $component_id,
                'item_name' => $item_name,
                'max_score' => $max_score,
                'csv_data' => $csv_data
            ]]
        ];
        
        echo json_encode([
            'status' => 'zero_inc_check',
            'zero_items' => $zero_items_detected,
            'message' => 'Zero scores detected. Clarification required.'
        ]);
        exit();
    }
    

    // --- 4. Process data for REVIEW LIST (NO IMMEDIATE DATABASE ACTION) ---
    $review_list = [];
    $inserted_count = 0;
    $skipped_count = 0;
    $zero_inc_count = 0;

    $stmt_check = $conn->prepare("
        SELECT score, max_score FROM raw_scores 
        WHERE enrollment_id = ? AND component_id = ? AND item_name = ?
    ");
    
    // Note: $stmt_insert preparation removed, as all inserts are deferred.

    foreach ($csv_data as $row) {
        $student_id = trim($row[$idx_sid]);
        $score = (float)trim($row[$idx_score]);
        
        if (!isset($student_map[$student_id])) continue;
        
        $student_data = $student_map[$student_id];
        $enrollment_id = $student_data['id'];
        
        $stmt_check->execute([$enrollment_id, $component_id, $item_name]);
        $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        // Determine the final status based on the zero_inc_action
        $final_score_status = 'Graded';
        $final_score_value = $score;

        if ($score === 0.00 && $max_score > 0 && $zero_inc_action === 'tag-inc') {
             $final_score_status = 'INC';
             $zero_inc_count++;
        }
        
        if ($existing) {
            // DUPLICATE FOUND (Check for overwrite)
            if ($existing['score'] != $score || $existing['max_score'] != $max_score) {
                // Overwrite detected: Add to review_list as UPDATE
                $review_list[] = [
                    'student_name' => $student_map[$student_id]['name'],
                    'item_name' => $item_name,
                    'old_score' => $existing['score'],
                    'old_max' => $existing['max_score'],
                    'new_score' => $score,
                    'new_max' => $max_score,
                    'action' => 'UPDATE',
                    'temp_status' => $final_score_status
                ];
            } else {
                $skipped_count++;
            }
        } else {
            // NEW SCORE: Add to review_list as INSERT (THIS IS THE KEY CHANGE)
            $review_list[] = [
                'student_name' => $student_map[$student_id]['name'],
                'item_name' => $item_name,
                'old_score' => 'N/A', // Clearly mark as new
                'old_max' => 'N/A',
                'new_score' => $score,
                'new_max' => $max_score,
                'action' => 'INSERT', 
                'temp_status' => $final_score_status
            ];
            $inserted_count++;
        }
    } 


    // --- 5. Final Response (MODIFIED: Always defer commit) ---
    
    // Rollback the transaction. Nothing is saved until confirm_upload.php is called.
    $conn->rollBack(); 

    // Prepare the current item to be stored in session
    $current_item_to_process = [
        'component_id' => $component_id,
        'item_name' => $item_name,
        'max_score' => $max_score,
        'csv_data' => $csv_data, 
    ];
    
    // --- MULTIPLE FILE/ITEM ACCUMULATION LOGIC (Preserved) ---
    $upload_session = $_SESSION['upload_data'] ?? ['items_to_process' => []];
    
    if (!isset($upload_session['class_id'])) {
        $upload_session['class_id'] = $class_id;
        $upload_session['term'] = $term;
        $upload_session['zero_inc_action'] = $zero_inc_action;
    }
    
    // Add the current item to the array for processing by confirm_upload.php
    $upload_session['items_to_process'][] = $current_item_to_process;

    // Store the review list for the front-end to display
    if (!empty($review_list)) {
        // Store the review list keyed by item name
        $upload_session['review_items'][$item_name] = $review_list;
    }

    $_SESSION['upload_data'] = $upload_session;
    
    // --- FINAL RESPONSE (Always signal review if items were processed) ---
    $message = "Successfully processed **$item_name**. " . count($review_list) . " scores pending review/insertion.";
    if ($zero_inc_count > 0) {
        $message .= " ($zero_inc_count score(s) tagged INC.)";
    }
    if ($skipped_count > 0) {
        $message .= " $skipped_count identical scores were skipped.";
    }

    // Send back the specific review list for this file AND the total pending list
    echo json_encode([
        'status' => 'review', 
        'review_list' => $review_list, // Only for the current file
        'all_review_items' => $upload_session['review_items'], // All accumulated pending updates/inserts
        'message' => $message
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    unset($_SESSION['upload_data']); 
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>