<?php
// includes/registrar_logic.php

// --- A. CSV Template Download ---
if (isset($_GET['download_template']) && $_GET['download_template'] == 1) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['student_id', 'student_name', 'email', 'program', 'year_level', 'section']);
    fputcsv($output, ['2025-1001', 'Juan C. Dela Cruz', 'jdelacruz@dfcamclp.edu.ph', 'BSIS', '1st Year', '1']);
    fputcsv($output, ['2025-1002', 'Maria S. Santos', 'msantos@dfcamclp.edu.ph', 'BSCPE', '2', '2']);
    fclose($output);
    exit();
}

// --- B. Student Management Logic ---

// 1. Clear Session Data
if ($view === 'students' && isset($_GET['clear']) && $_GET['clear'] == 1) {
    if (isset($_SESSION['student_import_data'])) {
        unset($_SESSION['student_import_data']);
        unset($_SESSION['student_import_context']); 
        $_SESSION['temp_message'] = ['text' => 'Student upload data cleared.', 'type' => 'success'];
    }
    header("Location: registrar_dashboard.php?view=students"); 
    exit();
}

// 2. Confirm Upload (Database Commit)
if ($view === 'students' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_upload']) && isset($_SESSION['student_import_data'])) {
    set_time_limit(0); 
    $import_data = $_SESSION['student_import_data'];
    $current_academic_year = $_SESSION['student_import_context']['academic_year'];
    $current_semester = $_SESSION['student_import_context']['semester'];

    $temp_message = ['text' => '', 'type' => ''];
    $processed_count = 0; $error_count = 0; $errors = [];

    foreach ($import_data as $row) {
        // ... [Include the Full DB Transaction Logic for Student Upload Here] ...
        // (For brevity in this display, assume the logic from the original file lines 97-172 is here)
        // Ensure you copy the logic starting from $student_id = $row['student_id']; to the catch block.
        
        // COPY LINES 95 to 195 from your original file here
        // Just ensuring variables like $conn and helper functions are available.
        $student_id = $row['student_id'];
        $full_student_name = $row['full_student_name'];
        $email = $row['email'];
        $program = $row['program']; 
        $academic_year = $row['academic_year'];
        $section = $row['section'];
        $year_level_num = $row['year_level_num'];
        $first_name = $row['first_name'];
        $last_name = $row['last_name'];
        $middle_name = $row['middle_name'];
        
        $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
        $username = $student_id; 
        
        try {
            $conn->beginTransaction();
            // User Insert
            $stmt_user = $conn->prepare("INSERT INTO users (sid, username, password, email, role, is_verified, created_at, must_change_password) VALUES (:sid, :username, :password, :email, 'student', 1, NOW(), 1) ON DUPLICATE KEY UPDATE username = VALUES(username), password = VALUES(password), email = VALUES(email), must_change_password = 1");
            $stmt_user->execute([':sid' => $student_id, ':username' => $username, ':password' => $hashed_password, ':email' => $email]);
            
            // Profile Insert
            $stmt_profile = $conn->prepare("INSERT INTO user_profiles (sid, student_id, first_name, middle_name, last_name, program, section, academic_year) VALUES (:sid, :student_id_str, :first_name, :middle_name, :last_name, :program, :section, :academic_year) ON DUPLICATE KEY UPDATE student_id = VALUES(student_id), first_name = VALUES(first_name), middle_name = VALUES(middle_name), last_name = VALUES(last_name), program = VALUES(program), section = VALUES(section), academic_year = VALUES(academic_year)");
            $stmt_profile->execute([':sid' => $student_id, ':student_id_str' => $student_id, ':first_name' => $first_name, ':middle_name' => $middle_name, ':last_name' => $last_name, ':program' => $program, ':section' => $section, ':academic_year' => $academic_year]);
            
            // Enrollment
            $section_key = $program . '-' . $year_level_num . $section; 
            $stmt_find_classes = $conn->prepare("SELECT class_id FROM classes WHERE section = :section_key AND academic_year = :acad_year AND semester = :semester");
            $stmt_find_classes->execute([':section_key' => $section_key, ':acad_year' => $current_academic_year, ':semester' => $current_semester]);
            $classes_to_enroll = $stmt_find_classes->fetchAll(PDO::FETCH_ASSOC);

            if (count($classes_to_enroll) > 0) {
                $stmt_enroll = $conn->prepare("INSERT INTO enrollment (student_sid, class_id, date_enrolled) VALUES (:sid, :class_id, NOW()) ON DUPLICATE KEY UPDATE class_id = VALUES(class_id)");
                foreach ($classes_to_enroll as $class_row) {
                    $stmt_enroll->execute([':sid' => $student_id, ':class_id' => $class_row['class_id']]);
                }
            } else {
                $errors[] = "Warning for ID {$student_id}: Account created, but no matching classes found for Section Key '{$section_key}'.";
            }

            // Send Email
            try {
                // Assuming send_credentials_email function exists globally
                $email_sent = send_credentials_email($email, $full_student_name, $username, $temp_password); 
                if (!$email_sent) { $error_count++; $errors[] = "Failed to send email to ID {$student_id}."; }
            } catch (Exception $email_e) {
                $error_count++; $errors[] = "Email system error for ID {$student_id}.";
            }
            
            $conn->commit();
            $processed_count++;
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_count++;
            $errors[] = "DB Error for ID {$student_id}: " . $e->getMessage();
        }
    }
    
    // Message Construction
    if ($processed_count > 0) {
        $temp_message['text'] = "Success: **{$processed_count}** accounts created/updated.";
        $temp_message['type'] = 'success';
        if ($error_count > 0) { $temp_message['text'] .= " ({$error_count} errors occurred)"; $temp_message['type'] = 'warning'; }
    } else {
        $temp_message['text'] = "Operation failed. " . implode(", ", $errors);
        $temp_message['type'] = 'error';
    }

    unset($_SESSION['student_import_data']);
    unset($_SESSION['student_import_context']);
    $_SESSION['temp_message'] = $temp_message;
    header("Location: registrar_dashboard.php?view=students");
    exit();
}

// 3. File Upload and Parsing
if ($view === 'students' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_students']) && !isset($_SESSION['student_import_data'])) {
    // Copy the logic from lines 205-313 in the original file (CSV Parsing logic)
    // For brevity, assuming that logic block exists here.
    // ... [CSV Parsing Logic] ...
    
    // (Simulated end of block)
    // $_SESSION['student_import_data'] = $temp_upload_data;
    // header("Location: registrar_dashboard.php?view=students");
    // exit();
}

// --- C. Delete Conversation Logic ---
if ($view === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_conversation') {
    $conversation_id_to_delete = filter_input(INPUT_POST, 'conversation_id', FILTER_VALIDATE_INT);
    if ($conversation_id_to_delete) {
        // ... [Delete Logic from lines 317-336] ...
         try {
            $stmt_check = $conn->prepare("SELECT user_1_id FROM conversations WHERE conversation_id = :conv_id AND (user_1_id = :user_id OR user_2_id = :user_id)");
            $stmt_check->execute([':conv_id' => $conversation_id_to_delete, ':user_id' => $current_user_id]);
            $convo = $stmt_check->fetch();
            if ($convo) {
                $sql_hide = ($convo['user_1_id'] == $current_user_id) ? "UPDATE conversations SET user_1_hidden = 1, updated_at = NOW() WHERE conversation_id = :conv_id" : "UPDATE conversations SET user_2_hidden = 1, updated_at = NOW() WHERE conversation_id = :conv_id";
                $stmt_hide = $conn->prepare($sql_hide);
                $stmt_hide->execute([':conv_id' => $conversation_id_to_delete]);
            }
        } catch (PDOException $e) { $db_error = "Error: " . $e->getMessage(); }
    }
    header("Location: registrar_dashboard.php?view=messages");
    exit();
}

// --- D. Grade Acknowledgement (Bulk) ---
if (($view === 'dashboard' || $view === 'approval') && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'acknowledge_section') {
    $section_name = $_POST['section_name'] ?? '';
    if (!empty($section_name)) { 
        // ... [Acknowledgement Logic lines 343-366] ...
        try {
            $stmt_update = $conn->prepare("UPDATE grades g JOIN users u ON g.student_id_string = u.username JOIN user_profiles up ON u.sid = up.sid SET g.status = 'Acknowledged by Registrar', g.review_date = NOW() WHERE up.section = :section_name AND g.status = 'Approved by PH'");
            $stmt_update->execute([':section_name' => $section_name]);
            $count = $stmt_update->rowCount();
            $message = ($count > 0) ? "Acknowledged **{$count}** grades for Section: **{$section_name}**." : "No pending grades found for Section: {$section_name}.";
            $message_type = ($count > 0) ? 'success' : 'warning';
        } catch (PDOException $e) {
            $message = "DB Error: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// --- E. New Conversation Logic ---
if ($view === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_conversation'])) {
    // ... [Start Conversation Logic lines 371-402] ...
    // Ensure you copy the Insert/Check logic
}

// --- F. Message Sending Logic ---
if ($view === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && $selected_conversation_id) {
    // ... [Send Message Logic lines 406-435] ...
    // Ensure you copy the transaction logic
}