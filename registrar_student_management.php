<?php
// FILE: registrar_student_management.php
// VERSION: FINAL - Production Ready with Detailed Post-Commit Summary

// Enable error reporting for debugging (RECOMMENDED TO REMOVE THIS LINE AFTER DEPLOYMENT)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Ensure output buffering is active (important if this is included or takes over)
if (ob_get_level() === 0) {
    ob_start(); 
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------------
// --- REQUIRED INCLUDES AND SETUP (MUST USE *ONCE* to prevent redeclaration) ---
// ---------------------------------------------------------------------------------
require_once 'db.php'; 
require_once 'email_config.php'; // This now handles PHPMailer requirements internally

// 1. ROLE-BASED ACCESS CONTROL
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Registrar'])) {
    header("Location: index.php");
    exit();
}
$current_user_id = $_SESSION['sid'] ?? 'REG001';
$current_user_name = $_SESSION['username'] ?? 'Registrar';
$view = 'students'; 

// --- Initialize Variables ---
$message = '';
$message_type = '';
$db_error = '';
$upload_history = []; 
$total_unread_messages = 0; 
$grouped_student_list_hierarchical = []; 

// --- NEW VARIABLE FOR STUDENT RECORD VIEW ---
$enrolled_roster_hierarchical = []; 
$all_student_profiles = [];
$program_filter = $_GET['program_filter'] ?? 'All'; // Filter for the new records table
$current_tab = $_GET['tab'] ?? 'upload'; // Controls which main content area is visible

// Check for temp session messages
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}


// =================================================================================
// 1. HELPER FUNCTION: STRICT NAME PARSING (MOVED UP)
// =================================================================================

/**
 * Parses a full name string into First, Middle, and Last names.
 * Flags ambiguity if any middle name part is a single character/initial.
 * @return array with keys: full_student_name, first_name, middle_name, last_name, needs_verification
 */
function parse_student_name_strict(string $full_name): array
{
    $name_raw = trim(preg_replace('/\s+/', ' ', $full_name));
    $name_parts = array_filter(explode(' ', $name_raw));
    
    $is_confused = false;
    
    // 1. Handle Extensions (Jr., Sr., III)
    $extensions = ['JR', 'SR', 'I', 'II', 'III', 'IV', 'V'];
    $extension = null;
    $last_part = array_pop($name_parts);
    $potential_extension = strtoupper(str_replace('.', '', $last_part));

    if (in_array($potential_extension, $extensions) && count($name_parts) >= 1) {
        $extension = $last_part; 
        $last_name = array_pop($name_parts); 
    } else {
        $last_name = $last_part;
    }
    
    // 2. Determine First Name
    $first_name = array_shift($name_parts);
    
    // 3. The rest are Middle Names
    $middle_name = null;
    if (!empty($name_parts)) {
        $middle_name = implode(' ', $name_parts);
    }
    
    // 4. Validation: Check middle name for initials/abbreviations (C. or C)
    if ($middle_name) {
        $middle_name_parts = explode(' ', $middle_name);
        foreach ($middle_name_parts as $part) {
            // Check if the part (after removing dot) is a single letter (C, B, A, etc.)
            if (strlen(str_replace('.', '', $part)) == 1) { 
                $is_confused = true;
                break;
            }
        }
    }
    
    // Re-assemble last name if extension was present
    if ($extension) {
        $last_name .= " " . $extension;
    }
    
    // Final cleanup and default
    $first_name = $first_name ?? 'N/A';
    $last_name = $last_name ?? 'N/A';

    return [
        'full_student_name' => $name_raw,
        'first_name' => $first_name,
        'middle_name' => empty($middle_name) ? null : $middle_name,
        'last_name' => $last_name,
        'needs_verification' => $is_confused
    ];
}


// =================================================================================
// 2. PROCESSING LOGIC
// =================================================================================

// --- A. CSV Template Download ---
if (isset($_GET['download_template']) && $_GET['download_template'] == 1) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['student_id', 'student_name', 'email', 'program', 'year_level', 'section']);
    fputcsv($output, ['20251001', 'Juan Carlos Dela Cruz', 'jdelacruz@dfcamclp.edu.ph', 'BSIS', '1st Year', '1']);
    fputcsv($output, ['20251002', 'Maria Santos', 'msantos@dfcamclp.edu.ph', 'BSCPE', '2', '2']);
    
    fclose($output);
    exit();
}

// 1. Clear Session Data
if (isset($_GET['clear']) && $_GET['clear'] == 1) {
    if (isset($_SESSION['student_import_data'])) {
        unset($_SESSION['student_import_data']);
        unset($_SESSION['student_import_context']); 
        unset($_SESSION['last_upload_skips']); 
        unset($_SESSION['last_commit_summary']); // Clear commit summary
        $_SESSION['temp_message'] = ['text' => 'Student upload data cleared.', 'type' => 'success'];
    }
    header("Location: registrar_student_management.php?view=students&tab=upload"); 
    exit();
}

// 2. Handle Real-Time Edits & Verification (Granular Editing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_review_data']) && isset($_SESSION['student_import_data'])) {
    $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
    
    if (isset($_SESSION['student_import_data'][$index])) {
        
        $e_student_id = trim($_POST['e_student_id'] ?? '');
        $e_email = trim($_POST['e_email'] ?? '');
        $e_first_name = trim($_POST['e_first_name'] ?? '');
        $e_middle_name = trim($_POST['e_middle_name'] ?? '');
        $e_last_name = trim($_POST['e_last_name'] ?? '');

        $e_full_name = trim($e_first_name . ' ' . ($e_middle_name ? $e_middle_name . ' ' : '') . $e_last_name);
        
        // Update the session data with corrected fields
        $_SESSION['student_import_data'][$index]['student_id'] = $e_student_id;
        $_SESSION['student_import_data'][$index]['email'] = $e_email;
        $_SESSION['student_import_data'][$index]['first_name'] = $e_first_name;
        $_SESSION['student_import_data'][$index]['middle_name'] = $e_middle_name;
        $_SESSION['student_import_data'][$index]['last_name'] = $e_last_name;
        $_SESSION['student_import_data'][$index]['full_student_name'] = $e_full_name;
        $_SESSION['student_import_data'][$index]['needs_verification'] = false; // Flag cleared

        $_SESSION['temp_message'] = ['text' => "Record ID {$e_student_id} updated and verified manually. Ready for final commit.", 'type' => 'success'];
    } else {
        $_SESSION['temp_message'] = ['text' => "Error: Invalid record index for update.", 'type' => 'error'];
    }

    header("Location: registrar_student_management.php?view=students&tab=upload");
    exit();
}

// 3. Confirm Upload (Database Commit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_upload']) && isset($_SESSION['student_import_data'])) {
    global $conn;
    
    set_time_limit(0); 
    $import_data = $_SESSION['student_import_data'];
    $current_academic_year = $_SESSION['student_import_context']['academic_year'];
    $current_semester = $_SESSION['student_import_context']['semester'];

    $processed_count = 0;
    $errors = [];
    $commit_summary_list = []; // NEW: List of successfully committed records
    $has_unverified = false;
    $is_revision_commit = filter_input(INPUT_POST, 'commit_revisions', FILTER_VALIDATE_BOOLEAN) ?? false;

    
    $new_count = 0;
    $revision_count = 0;

    // *** FIX: Safely calculate new and revision counts to prevent Type Error ***
    foreach ($import_data as $row) {
        if (($row['needs_verification'] ?? false) === true) {
            $has_unverified = true;
        }
        if (($row['is_new'] ?? false) === true) {
            $new_count++;
        }
        if (($row['is_revision'] ?? false) === true) {
            $revision_count++;
        }
    }
    // *** END FIX ***


    // Check 1: Halt commit if any name verification flag is still set
    if ($has_unverified) {
           $_SESSION['temp_message'] = ['text' => "Commit failed: Please manually review and edit/verify all red-flagged records.", 'type' => 'error'];
           header("Location: registrar_student_management.php?view=students&tab=upload");
           exit();
    }

    // Check 2: If revisions exist, but the confirmation flag is missing, halt.
    if ($revision_count > 0 && !$is_revision_commit) {
        $_SESSION['temp_message'] = ['text' => "Commit halted: {$revision_count} existing records detected. Please check the 'I confirm updating the {$revision_count} existing records' box to proceed with updates.", 'type' => 'error'];
        header("Location: registrar_student_management.php?view=students&tab=upload");
        exit();
    }

    // Check 3: If there are no new records AND we are NOT committing revisions, fail gracefully (Safety Check).
    if ($new_count === 0 && ($revision_count === 0 || !$is_revision_commit)) {
        $_SESSION['temp_message'] = ['text' => "Operation skipped. No new records found to create, and no updates were authorized.", 'type' => 'info'];
        unset($_SESSION['student_import_data']);
        unset($_SESSION['student_import_context']);
        header("Location: registrar_student_management.php?view=students&tab=upload");
        exit();
    }
    
    foreach ($import_data as $row) {
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
        $is_revision = $row['is_revision'];
        $is_new = $row['is_new'];
        
        // Skip records marked as revisions if we aren't confirming revisions
        if ($is_revision && !$is_revision_commit) continue;

        // Generate password. This password will be committed to the database.
        // It is reset for all NEW accounts and all CONFIRMED REVISIONS.
        $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
        $username = $student_id; 

        // --- Database Inserts/Updates (User, Profile, Enrollment) ---
        try {
            // 1. User Account (INSERT or UPDATE: Updates email, password, and sets must_change_password=1 for both new and updated records)
            $stmt_user = $conn->prepare("INSERT INTO users (sid, username, password, email, role, is_verified, created_at, must_change_password) VALUES (:sid, :username, :password, :email, 'student', 1, NOW(), 1) ON DUPLICATE KEY UPDATE email = VALUES(email), password = VALUES(password), must_change_password = 1");
            $stmt_user->execute([':sid' => $student_id, ':username' => $username, ':password' => $hashed_password, ':email' => $email]);
        } catch (PDOException $e) {
            $errors[] = "DB Error (User Insert Failed) for ID {$student_id}: " . $e->getMessage();
            continue; 
        }
        
        try {
            $conn->beginTransaction();

            // 2. User Profile (INSERT or UPDATE: Updates name, program, section, academic_year)
            $stmt_profile = $conn->prepare("INSERT INTO user_profiles (sid, student_id, first_name, middle_name, last_name, program, section, academic_year) VALUES (:sid, :student_id_str, :first_name, :middle_name, :last_name, :program, :section, :academic_year) ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), middle_name = VALUES(middle_name), last_name = VALUES(last_name), program = VALUES(program), section = VALUES(section), academic_year = VALUES(academic_year)");
            $stmt_profile->execute([':sid' => $student_id, ':student_id_str' => $student_id, ':first_name' => $first_name, ':middle_name' => $middle_name, ':last_name' => $last_name, ':program' => $program, ':section' => $section, ':academic_year' => $academic_year]);

            // 3. Enrollment (Auto-enroll only if classes exist)
            $section_key = $program . '-' . $year_level_num . $section; 
            $stmt_find_classes = $conn->prepare("SELECT class_id FROM classes WHERE section = :section_key AND academic_year = :acad_year AND semester = :semester");
            $stmt_find_classes->execute([':section_key' => $section_key, ':acad_year' => $current_academic_year, ':semester' => $current_semester]);
            $classes_to_enroll = $stmt_find_classes->fetchAll(PDO::FETCH_ASSOC);

            if (count($classes_to_enroll) > 0) {
                // Enrollment uses ON DUPLICATE KEY UPDATE to prevent duplicate enrollment in the same class
                $stmt_enroll = $conn->prepare("INSERT INTO enrollment (student_sid, class_id, date_enrolled) VALUES (:sid, :class_id, NOW()) ON DUPLICATE KEY UPDATE class_id = VALUES(class_id)");
                foreach ($classes_to_enroll as $class_row) {
                    $stmt_enroll->execute([':sid' => $student_id, ':class_id' => $class_row['class_id']]);
                }
            } else {
                $errors[] = "Warning for ID {$student_id}: Account created/updated, but no matching classes found for Section Key '{$section_key}'.";
            }

            // 4. Email sending logic (CONDITIONAL)
            // ONLY send the email if it is a brand new account.
            if ($is_new) {
                try {
                    $email_sent = send_credentials_email($email, $full_student_name, $username, $temp_password);
                    if (!$email_sent) { 
                        $errors[] = "Failed to send NEW account credentials to ID {$student_id}. Check SMTP config."; 
                    }
                } catch (Exception $email_e) {
                    $errors[] = "Email system error (New Account) for ID {$student_id}: " . $email_e->getMessage();
                }
            }
            
            $conn->commit(); 
            $processed_count++;
            
            // NEW: Add to successful commit list
            $commit_summary_list[] = [
                'id' => $student_id,
                'name' => $full_student_name,
                'status' => $is_new ? 'New Account' : 'Profile Updated',
                'program' => $program,
                'section' => $section
            ];

        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = "DB Error (Profile/Enrollment) for ID {$student_id}: " . $e->getMessage();
        }
    }

    $error_count = count($errors);
    
    // Recalculate summary counts based on the actual commit list
    $new_committed = count(array_filter($commit_summary_list, function($record) {
        return $record['status'] == 'New Account';
    }));

    $revision_committed = count(array_filter($commit_summary_list, function($record) {
        return $record['status'] == 'Profile Updated';
    }));


    $temp_message = ['text' => '', 'type' => ''];
    if ($processed_count > 0) {
        $msg_parts = [];
        if ($new_committed > 0) $msg_parts[] = "{$new_committed} new accounts created";
        if ($revision_committed > 0) $msg_parts[] = "{$revision_committed} existing accounts updated";

        $temp_message['text'] = "Success: <strong>" . implode(" and ", $msg_parts) . ".</strong> Credentials sent (only for new accounts) and students auto-enrolled. See summary below.";
        $temp_message['type'] = 'success';
        if ($error_count > 0) {
             $temp_message['text'] .= " ({$error_count} errors/warnings occurred. Check details.)";
             $temp_message['type'] = 'warning'; 
        }
    } else {
        $unique_errors = array_unique($errors);
        $temp_message['text'] = "Operation failed. No records were committed. Errors: " . implode(" | ", array_slice($unique_errors, 0, 3));
        $temp_message['type'] = 'error';
    }

    // Store the detailed summary list before redirecting
    $_SESSION['last_commit_summary'] = $commit_summary_list;
    
    unset($_SESSION['student_import_data']);
    unset($_SESSION['student_import_context']);

    $_SESSION['temp_message'] = $temp_message;
    header("Location: registrar_student_management.php?view=students&tab=upload");
    exit();
}


// 4. File Upload and Parsing (POST Upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_students']) && !isset($_SESSION['student_import_data'])) {
    global $conn;
    
    $current_academic_year = $_POST['current_academic_year'] ?? null;
    $current_semester = $_POST['current_semester'] ?? null;
    $temp_upload_data = [];
    $errors = [];
    $skipped_rows = []; // NEW ARRAY for skipped rows
    $valid_upload = true;

    if (empty($current_academic_year) || empty($current_semester)) {
           $errors[] = "Academic Year and Semester must be selected.";
           $valid_upload = false;
    }

    if ($valid_upload && isset($_FILES['student_file']) && $_FILES['student_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['student_file']['tmp_name'];
        $file_mime_type = mime_content_type($file_tmp_path);
        
        if ($file_mime_type === 'text/csv' || $file_mime_type === 'text/plain' || str_ends_with($_FILES['student_file']['name'], '.csv')) {
            $file = fopen($file_tmp_path, "r");
            $header = fgetcsv($file); 
            
            $expected_headers = ['student_id', 'student_name', 'email', 'program', 'year_level', 'section'];
            
            if (count(array_diff($expected_headers, $header)) > 0) {
                $errors[] = "Invalid CSV format. Expected columns: " . implode(', ', $expected_headers);
                $valid_upload = false;
            } else {
                
                // Prepare statement for existence check (by sid or email)
                $stmt_check = $conn->prepare("SELECT sid FROM users WHERE sid = :sid OR email = :email");
                // Prepare statement for profile existence check (for final skip logic)
                $stmt_profile_check = $conn->prepare("SELECT student_id FROM user_profiles WHERE student_id = :student_id AND program = :program AND academic_year = :academic_year AND section = :section");


                $row_index = 0;
                while (($data = fgetcsv($file)) !== FALSE) {
                    $row = array_combine($header, $data);
                    
                    if (empty($row['student_id']) || empty($row['student_name']) || empty($row['email']) || empty(trim($row['program']))) {
                        $errors[] = "Skipped row {$row_index} due to missing required fields (ID, Name, Email, or Program).";
                        $row_index++;
                        continue; 
                    }

                    $student_id = trim($row['student_id']);
                    $full_student_name = trim($row['student_name']);
                    $email = trim($row['email']);
                    $program = strtoupper(trim($row['program'])); 
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@dfcamclp\.edu\.ph$/i', $email)) {
                        $errors[] = "Validation error for ID {$student_id}: Email is invalid or must end with @dfcamclp.edu.ph.";
                        $row_index++;
                        continue; 
                    }
                    
                    $year_level_input = trim($row['year_level'] ?? '1');
                    $section = trim($row['section'] ?? 'N/A');
                    
                    if (!preg_match('/^\d+$/', $student_id)) {
                        $errors[] = "Validation error for ID {$student_id}: Student ID must contain digits only.";
                        $row_index++;
                        continue;
                    }
                    
                    if (!preg_match('/^[A-Z]{2,5}$/', $program)) {
                            $errors[] = "Validation error for ID {$student_id}: Program '{$program}' looks invalid.";
                            $row_index++;
                            continue;
                    }
                    
                    if (!preg_match('/^[1-9]$/', $section)) { 
                        $errors[] = "Validation error for ID {$student_id}: Section must be a single digit (1-9) only. Letters are not allowed.";
                        $row_index++;
                        continue;
                    }
                    
                    // Year level normalization
                    $year_level_digit = '1';
                    if (preg_match('/[1-5]/', $year_level_input, $matches)) {
                        $year_level_digit = $matches[0];
                    }
                    $academic_year_map = ['1' => '1st Year', '2' => '2nd Year', '3' => '3rd Year', '4' => '4th Year', '5' => '5th Year'];
                    $academic_year = $academic_year_map[$year_level_digit] ?? '1st Year';
                    
                    // Name splitting
                    $parsed_names = parse_student_name_strict($full_student_name);
                    
                    
                    // --- DUPILCATE/EXISTENCE CHECK ---
                    $stmt_check->execute([':sid' => $student_id, ':email' => $email]);
                    $existing_user = $stmt_check->fetch();
                    $is_revision = $existing_user !== false;
                    
                    
                    // --- SKIP CHECK (If user exists AND profile info matches exactly) ---
                    if ($is_revision) {
                        $stmt_profile_check->execute([
                            ':student_id' => $student_id,
                            ':program' => $program,
                            ':academic_year' => $academic_year,
                            ':section' => $section
                        ]);
                        $profile_matches = $stmt_profile_check->fetchColumn();

                        // This skips the record entirely if all identifying and grouping data matches the DB.
                        if ($profile_matches) {
                            $skipped_rows[] = "Row {$row_index} (ID: {$student_id}, Name: {$full_student_name}): Record already exists with matching profile data. No update needed.";
                            $row_index++;
                            continue;
                        }
                    }

                    
                    $temp_upload_data[] = [
                        'student_id' => $student_id, 
                        'full_student_name' => $full_student_name, 
                        'email' => $email,
                        'program' => $program, 
                        'section' => $section, 
                        'academic_year' => $academic_year,
                        'year_level_num' => $year_level_digit, 
                        'first_name' => $parsed_names['first_name'], 
                        'last_name' => $parsed_names['last_name'],
                        'middle_name' => $parsed_names['middle_name'],
                        'needs_verification' => $parsed_names['needs_verification'], 
                        'is_revision' => $is_revision, 
                        'is_new' => !$is_revision, 
                    ];
                    $row_index++;
                }
                fclose($file);
            }
        } else {
            $errors[] = "Invalid file type. Please upload a CSV file.";
            $valid_upload = false;
        }
    } else {
        if (!$valid_upload) { /* Error already set */ }
        elseif (isset($_FILES['student_file']) && $_FILES['student_file']['error'] !== UPLOAD_ERR_NO_FILE) { $errors[] = "File upload failed (Code: " . $_FILES['student_file']['error'] . ")."; }
        else { $errors[] = "No file selected for upload."; }
        $valid_upload = false;
    }
    
    // --- Store Skipped Rows in Session ---
    if (!empty($skipped_rows)) {
        $_SESSION['last_upload_skips'] = $skipped_rows;
    }


    $temp_message = ['text' => '', 'type' => ''];
    if (!empty($temp_upload_data) && empty($errors)) {
        
        $unverified_count = 0;
        $new_count = 0;
        $revision_count = 0;
        foreach($temp_upload_data as $data) {
             if (($data['needs_verification'] ?? false) === true) $unverified_count++;
             if (($data['is_new'] ?? false) === true) $new_count++;
             if (($data['is_revision'] ?? false) === true) $revision_count++;
        }
        
        $total_ready_for_commit = count($temp_upload_data);

        $msg_text = "CSV uploaded and ready for review. <strong>{$total_ready_for_commit}</strong> records ready for commit (<strong>{$new_count}</strong> New, <strong>{$revision_count}</strong> Revision).";
        $msg_type = 'warning';
        
        if ($unverified_count > 0) {
             $msg_text .= " <span style='font-weight: 700; color: var(--critical);'>({$unverified_count} records flagged for name verification!)</span>";
             $msg_type = 'error'; // Elevate message type if manual verification is needed
        }
        
        $_SESSION['student_import_context'] = ['academic_year' => $current_academic_year, 'semester' => $current_semester];
        $_SESSION['student_import_data'] = $temp_upload_data;
        $_SESSION['temp_message'] = ['text' => $msg_text, 'type' => $msg_type];
        
    } elseif (!empty($errors) && empty($temp_upload_data)) {
        // Case 1: Only validation errors exist OR skipped rows caused ALL to be dropped.
        if (!empty($skipped_rows) && count($skipped_rows) == $row_index) {
            $temp_message['text'] = "File processed, but <strong>no new or revised records</strong> were found for commitment. See summary below for skipped rows.";
            $temp_message['type'] = 'info';
        } else {
             // Case 2: Fatal validation errors
            $temp_message['text'] = "Upload failed. " . implode(" | ", array_slice(array_unique($errors), 0, 3));
            $temp_message['type'] = 'error';
        }
        $_SESSION['temp_message'] = $temp_message;
    } else {
        // Case 3: File processed, but no rows at all (empty CSV, etc.)
        $temp_message['text'] = "File processed, but no valid student data was found.";
        $temp_message['type'] = 'warning';
        $_SESSION['temp_message'] = $temp_message;
    }

    header("Location: registrar_student_management.php?view=students&tab=upload");
    exit();
}


// =================================================================================
// 3. DATA FETCHING (Student Roster and Enrollment Roster)
// =================================================================================
global $conn;

// 3.1. Prepare Upload History data from session 
$upload_history = [];
if (isset($_SESSION['student_import_data']) && is_array($_SESSION['student_import_data'])) {
    $upload_history = $_SESSION['student_import_data'];
    $grouped_student_list_hierarchical = [];
    // Grouping the Upload History by Program, Academic Year, and Section for hierarchical display
    foreach ($upload_history as $student) {
        $program = $student['program'] ?? 'Other/Unassigned';
        $year = $student['academic_year'] ?? 'N/A';
        $section = $student['section'] ?? 'N/A';

        // Initialize structure if it doesn't exist
        if (!isset($grouped_student_list_hierarchical[$program])) {
            $grouped_student_list_hierarchical[$program] = [];
        }
        if (!isset($grouped_student_list_hierarchical[$program][$year])) {
            $grouped_student_list_hierarchical[$program][$year] = [];
        }
        if (!isset($grouped_student_list_hierarchical[$program][$year][$section])) {
            $grouped_student_list_hierarchical[$program][$year][$section] = [];
        }
        $grouped_student_list_hierarchical[$program][$year][$section][] = $student;
    }
}

// 3.2. Fetch Full Enrollment Roster (FOR TAB: 'roster')
try {
    $sql_roster = "
        SELECT
            up.program, up.academic_year, up.section,
            CONCAT(up.last_name, ', ', up.first_name, ' ', IFNULL(up.middle_name, '')) AS student_full_name,
            s.subject_code, s.subject_name,
            CONCAT(upt.first_name, ' ', upt.last_name) AS professor_full_name
        FROM enrollment e
        JOIN user_profiles up ON e.student_sid = up.sid
        JOIN classes c ON e.class_id = c.class_id
        JOIN subject s ON c.subject_id = s.subject_id
        LEFT JOIN user_profiles upt ON c.professor_sid = upt.sid
        ORDER BY up.program, up.academic_year, up.section, up.last_name, s.subject_name
    ";

    $stmt_roster = $conn->prepare($sql_roster);
    $stmt_roster->execute();
    $roster_data = $stmt_roster->fetchAll(PDO::FETCH_ASSOC);

    // Grouping for Hierarchical Display: Program > Year > Section > Subject
    foreach ($roster_data as $row) {
        $program = $row['program'] ?? 'Unassigned Program';
        $year = $row['academic_year'] ?? 'Unassigned Year';
        $section = $row['section'] ?? 'Unassigned Section';
        
        $professor_name = trim($row['professor_full_name']) ?: 'N/A';
        $subject_key = $row['subject_code'] . ' - ' . $row['subject_name'] . ' (Prof: ' . $professor_name . ')';

        if (!isset($enrolled_roster_hierarchical[$program])) { $enrolled_roster_hierarchical[$program] = []; }
        if (!isset($enrolled_roster_hierarchical[$program][$year])) { $enrolled_roster_hierarchical[$program][$year] = []; }
        if (!isset($enrolled_roster_hierarchical[$program][$year][$section])) { $enrolled_roster_hierarchical[$program][$year][$section] = []; }
        if (!isset($enrolled_roster_hierarchical[$program][$year][$section][$subject_key])) { $enrolled_roster_hierarchical[$program][$year][$section][$subject_key] = []; }
        
        $enrolled_roster_hierarchical[$program][$year][$section][$subject_key][] = $row['student_full_name'];
    }

} catch (PDOException $e) {
    $db_error = "Database error fetching enrollment roster: " . $e->getMessage();
}


// 3.3. Fetch All Student Profiles (FOR TAB: 'records')
try {
    $sql_profiles = "
        SELECT
            up.student_id,
            CONCAT(up.last_name, ', ', up.first_name, ' ', IFNULL(up.middle_name, '')) AS student_full_name,
            u.email,
            up.program,
            up.academic_year,
            up.section
        FROM user_profiles up
        JOIN users u ON up.sid = u.sid
        WHERE u.role = 'student' AND up.student_id IS NOT NULL 
    ";
    
    $params = [];
    if ($program_filter !== 'All') {
        $sql_profiles .= " AND up.program = :program_filter";
        $params[':program_filter'] = $program_filter;
    }
    
    $sql_profiles .= " ORDER BY up.program, up.academic_year, up.last_name";

    $stmt_profiles = $conn->prepare($sql_profiles);
    $stmt_profiles->execute($params);
    $all_student_profiles = $stmt_profiles->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database error fetching student profiles: " . $e->getMessage();
}


// ---------------------------------------------------------------------------------
// --- HTML RENDERING (Start)
// ---------------------------------------------------------------------------------

// 1. Include the Header File
require_once 'registrar_header.php';
?>

<div class="tab-controls">
                <button 
                    class="tab-button <?= ($current_tab === 'upload' || isset($_SESSION['student_import_data'])) ? 'active' : ''; ?>" 
                    onclick="window.location.href='registrar_student_management.php?view=students&tab=upload'"
                >
                    <i class="fa-solid fa-cloud-upload-alt"></i> Bulk Registration
                </button>
                <button 
                    class="tab-button <?= ($current_tab === 'records' && !isset($_SESSION['student_import_data'])) ? 'active' : ''; ?>" 
                    onclick="window.location.href='registrar_student_management.php?view=students&tab=records&program_filter=<?= htmlspecialchars($program_filter); ?>'"
                >
                    <i class="fa-solid fa-address-book"></i> Student Records & Roster
                </button>
            </div>


            <div class="tab-content" id="upload-content" style="display: <?= ($current_tab === 'upload' || isset($_SESSION['student_import_data'])) ? 'block' : 'none'; ?>;">
                <section class="content-section mb-8">
                    <h2>
                        <i class="fa-solid fa-cloud-upload-alt"></i> Bulk Student Registration & Enrollment
                        <span class="info-tooltip">
                            <i class="fa-solid fa-circle-info"></i>
                            <span class="tooltip-text">
                                <p>Upload a CSV file containing student details (ID, Name, Email, Program, Year Level, Section) to automatically create their accounts.</p>
                                <p><strong>Required Headers:</strong> <code>student_id</code>, <code>student_name</code>, <code>email</code>, <code>program</code>, <code>year_level</code>, <code>section</code>.</p>
                                <p><strong>Revision Check:</strong> Records matching an existing Student ID or Email are marked as <strong>Revision</strong> and require confirmation before updating existing user data.</p>
                                <p><strong>Name Parsing Logic:</strong> Names are strictly parsed. If a middle name appears to be an abbreviation (single letter), the record is flagged in red for manual Edit & Verification.</p>
                                <a href="registrar_dashboard.php?download_template=1" style="margin-top: 10px; display: inline-block; color: var(--accent-light);">Download Template</a>
                            </span>
                        </span>
                    </h2>
                    
                    <?php if (!isset($_SESSION['student_import_data'])): ?>
                        <form 
                            action="registrar_student_management.php?view=students&tab=upload" 
                            method="POST" 
                            enctype="multipart/form-data" 
                            class="upload-form" 
                            style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; background: var(--bg-light); padding: 20px; border-radius: 8px; margin-top: 1rem;"
                        >
                            <div style="flex: 1; min-width: 150px;">
                                <label for="academic_year_select" style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 5px;">Academic Year<span class="required-asterisk">*</span>:</label>
                                <select name="current_academic_year" id="academic_year_select" required style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
                                    <option value="">Select Year...</option>
                                    <option value="2025-2026">2025-2026</option>
                                    <option value="2026-2027">2026-2027</option>
                                    <option value="2027-2028">2027-2028</option>
                                </select>
                            </div>
                            
                            <div style="flex: 1; min-width: 150px;">
                                <label for="semester_select" style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 5px;">Semester<span class="required-asterisk">*</span>:</label>
                                <select name="current_semester" id="semester_select" required style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
                                    <option value="">Select Semester...</option>
                                    <option value="1st Semester">1st Semester</option>
                                    <option value="2nd Semester">2nd Semester</option>
                                </select>
                            </div>
                            
                            <div style="flex: 2; min-width: 200px;">
                                <label for="student_file_input" style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 5px;">CSV File<span class="required-asterisk">*</span>:</label>
                                <input type="file" name="student_file" id="student_file_input" accept=".csv" required style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
                            </div>
                            
                            <button type="submit" name="upload_students" class="approve-btn" style="background-color: var(--success-green); padding: 10px 20px; height: 40px;"><i class="fa-solid fa-upload"></i> Upload & Parse</button>
                        </form>
                        
                    <?php else: 
                        // Phase 2 Review Table (Shows Uploaded Data)
                        $review_data = $_SESSION['student_import_data'];
                        $review_context = $_SESSION['student_import_context'];
                        
                        $unverified_count = 0;
                        $revision_count = 0;
                        $new_count = 0;
                        
                        foreach($review_data as $data) {
                             if (($data['needs_verification'] ?? false) === true) $unverified_count++;
                             if (($data['is_new'] ?? false) === true) $new_count++;
                             if (($data['is_revision'] ?? false) === true) $revision_count++;
                        }


                        $is_commit_disabled = ($unverified_count > 0);
                        
                    ?>
                    <div class="review-panel" style="margin-top: 20px;">
                        <p class="description" style="color: var(--text-dark); font-weight: 600;">
                            Reviewing <strong><?= count($review_data); ?></strong> records for <strong><?= htmlspecialchars($review_context['academic_year']); ?>, <?= htmlspecialchars($review_context['semester']); ?></strong>. 
                        </p>
                        
                        <?php if ($unverified_count > 0): ?>
                           <p class="message error"><i class="fa-solid fa-triangle-exclamation"></i> <strong>ATTENTION</strong>: <strong><?= $unverified_count; ?></strong> records flagged for name verification! Please Edit & Verify them manually before committing.</p>
                        <?php endif; ?>
                        <?php if ($revision_count > 0): ?>
                           <p class="message warning"><i class="fa-solid fa-user-pen"></i> <strong>NOTICE</strong>: <strong><?= $revision_count; ?></strong> existing accounts will be <strong>updated/revised</strong> (names, program, section, and password reset) upon final commitment. **New credentials will NOT be emailed for revisions.**</p>
                        <?php endif; ?>


                        <div class="upload-review-list">
                            <?php 
                            // Display the grouped upload data with collapsible sections
                            ksort($grouped_student_list_hierarchical); 
                            foreach ($grouped_student_list_hierarchical as $program_name => $years): ?>
                                <div class="group-header" onclick="toggleDirectory('upload-group-<?= md5($program_name); ?>')">
                                    <span><i class="fa-solid fa-graduation-cap"></i> Program: <strong><?= htmlspecialchars($program_name); ?></strong></span>
                                    <i id="upload-group-<?= md5($program_name); ?>-icon" class="fa-solid fa-chevron-right" style="float: right;"></i>
                                </div>
                                <div id="upload-group-<?= md5($program_name); ?>" class="group-content" style="display: none; padding: 15px;">
                                    <?php 
                                    ksort($years);
                                    foreach ($years as $year_name => $sections): ?>
                                        <h4 style="margin-top: 10px; margin-bottom: 5px; color: var(--panel-blue);">
                                            <i class="fa-solid fa-calendar-alt"></i> <?= htmlspecialchars($year_name); ?>
                                        </h4>
                                        <?php 
                                        ksort($sections);
                                        foreach ($sections as $section_name => $students_in_section): ?>
                                            <h5 style="margin-top: 10px; color: var(--text-dark);">
                                                <i class="fa-solid fa-door-open"></i> Section: <strong><?= htmlspecialchars($section_name); ?></strong> (<?= count($students_in_section); ?> students)
                                            </h5>
                                            <div class="table-container" style="max-height: 300px; overflow-y: auto; margin-bottom: 20px; border: none;">
                                                <table class="review-table">
                                                    <thead>
                                                        <tr>
                                                            <th>ID</th>
                                                            <th>Status</th>
                                                            <th>Full Name (CSV)</th>
                                                            <th>Email</th>
                                                            <th>First Name</th>
                                                            <th>M. Name</th>
                                                            <th>Last Name</th>
                                                            <th style="width: 100px;">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($students_in_section as $i => $student): 
                                                            $is_flagged = $student['needs_verification'] ?? false;
                                                            $is_new = $student['is_new'] ?? true;
                                                            $status_badge = $is_new ? '<span class="badge-new">NEW</span>' : '<span class="badge-revision"><i class="fa-solid fa-user-pen"></i> REVISION</span>';
                                                            
                                                            // Find the actual array index for the modal reference
                                                            // This index is crucial as it refers to the position in $_SESSION['student_import_data']
                                                            $session_index = array_search($student, $review_data, true); 
                                                            if ($session_index === false) continue;
                                                        ?>
                                                        <tr>
                                                            <td><?= htmlspecialchars($student['student_id']); ?></td>
                                                            <td><?= $status_badge; ?></td>
                                                            <td class="<?= $is_flagged ? 'needs-verification data-name' : ''; ?>" title="Original CSV Input"><?= htmlspecialchars($student['full_student_name']); ?></td>
                                                            <td><?= htmlspecialchars($student['email']); ?></td>
                                                            <td class="<?= $is_flagged ? 'needs-verification data-name' : ''; ?>"><?= htmlspecialchars($student['first_name']); ?></td>
                                                            <td class="<?= $is_flagged ? 'needs-verification data-name' : ''; ?>" title="Parsed Middle Name (N/A or Full Name)"><?= htmlspecialchars($student['middle_name'] ?? 'N/A'); ?></td>
                                                            <td class="<?= $is_flagged ? 'needs-verification data-name' : ''; ?>"><?= htmlspecialchars($student['last_name']); ?></td>
                                                            <td class="action-buttons">
                                                                <button 
                                                                    type="button" 
                                                                    class="action-link edit-btn" 
                                                                    style="background-color: <?= $is_flagged ? 'var(--critical)' : 'var(--info-blue)'; ?>;"
                                                                    onclick="openEditModal(<?= $session_index ?>, '<?= htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8'); ?>')"
                                                                >
                                                                    <i class="fa-solid fa-edit"></i> Edit
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form action="registrar_student_management.php?view=students&tab=upload" method="POST" style="display: flex; gap: 15px; justify-content: flex-end; align-items: center; margin-top: 20px;">
                            <input type="hidden" name="confirm_upload" value="1">

                            <?php if ($revision_count > 0): ?>
                                <div style="display: flex; align-items: center; background: #ffeeba; padding: 8px 15px; border-radius: 6px; border: 1px solid var(--warning-orange);">
                                    <input type="checkbox" name="commit_revisions" id="commit_revisions" value="1" style="margin-right: 8px;">
                                    <label for="commit_revisions" style="font-weight: 600; color: #856404; font-size: 0.9rem;">
                                        I confirm updating the <strong><?= $revision_count ?></strong> existing records.
                                    </label>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="approve-btn" style="background-color: var(--success-green); padding: 10px 20px;" <?= $is_commit_disabled ? 'disabled' : ''; ?> title="<?= $is_commit_disabled ? 'Cannot commit until all red records are manually verified/edited.' : 'Ready to commit records.'; ?>">
                                <i class="fa-solid fa-check"></i> Commit All & Enroll
                            </button>
                            <button type="button" onclick="window.location.href='registrar_student_management.php?view=students&clear=1'" class="reject-btn" style="background-color: var(--critical); padding: 10px 20px;"><i class="fa-solid fa-times"></i> Cancel & Clear
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </section>
            </div>


            <div class="tab-content" id="records-content" style="display: <?= ($current_tab === 'records' && !isset($_SESSION['student_import_data'])) ? 'block' : 'none'; ?>;">
                
                <section class="content-section mb-8">
                    <h2><i class="fa-solid fa-address-book"></i> Student Master List (All Accounts)</h2>
                    <p class="description">A complete list of all student accounts created in the system, filterable by Program.</p>
                    
                    <form action="registrar_student_management.php" method="GET" class="report-form" style="display: flex; gap: 15px; align-items: flex-end; padding: 15px; background: var(--bg-light); border-radius: 8px; margin-bottom: 20px;">
                        <input type="hidden" name="view" value="students">
                        <input type="hidden" name="tab" value="records">
                        <div class="form-group" style="flex: 0 0 200px;">
                            <label for="program_filter" style="font-weight: 600;">Filter by Program:</label>
                            <select name="program_filter" id="program_filter" onchange="this.form.submit()" style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 100%;">
                                <option value="All" <?= ($program_filter === 'All') ? 'selected' : ''; ?>>All Programs</option>
                                <?php 
                                    // Dynamically list programs from roster/profiles data for cleaner display
                                    $all_programs = array_unique(array_column($roster_data, 'program'));
                                    foreach ($all_programs as $prog):
                                        if (empty($prog)) continue; // Skip empty program names
                                ?>
                                    <option value="<?= htmlspecialchars($prog); ?>" <?= ($program_filter === $prog) ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($prog); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <div class="table-container">
                        <?php if (!empty($all_student_profiles)): ?>
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th style="width: 15%;">Student ID</th>
                                        <th style="width: 35%;">Full Name (Last, First)</th>
                                        <th style="width: 25%;">Email</th>
                                        <th style="width: 15%;">Acad. Year</th>
                                        <th style="width: 10%;">Section</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_student_profiles as $profile): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($profile['student_id']); ?></td>
                                        <td style="font-weight: 500;"><?= htmlspecialchars($profile['student_full_name']); ?></td>
                                        <td><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($profile['email']); ?></td>
                                        <td><?= htmlspecialchars($profile['academic_year']); ?></td>
                                        <td><?= htmlspecialchars($profile['section'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="no-data">No student accounts found matching the filter '<?= htmlspecialchars($program_filter); ?>'.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="content-section"> 
                    <div class="roster-header">
                        <i class="fa-solid fa-clipboard-list"></i> Current Class Enrollment Roster
                    </div>
                    <p class="description" style="margin-top: 15px;">
                        Active Enrollment: Details which students are in which classes, grouped by Program > Year > Section > Subject/Professor.
                    </p>
                    
                    <?php if (!empty($enrolled_roster_hierarchical)): ?>
                        <div class="roster-list" id="roster-list-content">
                            <?php 
                            ksort($enrolled_roster_hierarchical); 
                            foreach ($enrolled_roster_hierarchical as $program_name => $years): ?>
                                <div class="group-header" onclick="toggleDirectory('roster-group-<?= md5($program_name); ?>')">
                                    <span><i class="fa-solid fa-graduation-cap"></i> Program: <strong><?= htmlspecialchars($program_name); ?></strong></span>
                                    <i id="roster-group-<?= md5($program_name); ?>-icon" class="fa-solid fa-chevron-right" style="float: right;"></i>
                                </div>

                                <div id="roster-group-<?= md5($program_name); ?>" class="group-content" style="display: none; padding: 15px;">
                                    <?php 
                                    ksort($years);
                                    foreach ($years as $year_name => $sections): ?>
                                        <div class="group-header" style="background: var(--bg-light); color: var(--text-dark); border-bottom: 1px solid var(--border-color); margin-top: 5px;" onclick="toggleDirectory('roster-year-<?= md5($program_name . $year_name); ?>')">
                                            <span><i class="fa-solid fa-calendar-alt"></i> Academic Year: <strong><?= htmlspecialchars($year_name); ?></strong> (<?= count($sections); ?> sections)</span>
                                            <i id="roster-year-<?= md5($program_name . $year_name); ?>-icon" class="fa-solid fa-chevron-right" style="float: right;"></i>
                                        </div>
                                        <div id="roster-year-<?= md5($program_name . $year_name); ?>" style="display: none; padding: 10px; border: 1px solid #f1f1f1; border-top: none;">
                                            <?php 
                                            ksort($sections);
                                            foreach ($sections as $section_name => $subjects): ?>
                                                <div style="margin-bottom: 20px; border-left: 3px solid var(--info-blue); padding-left: 10px; padding-top: 10px;">
                                                    <h4 style="font-size: 1rem; color: var(--text-dark); margin-bottom: 10px;"><i class="fa-solid fa-door-open"></i> Section: <strong><?= htmlspecialchars($section_name); ?></strong></h4>
                                                    
                                                    <div class="subject-list">
                                                        <?php 
                                                        ksort($subjects);
                                                        foreach ($subjects as $subject_info => $students): 
                                                            $parts = explode(' (Prof: ', $subject_info);
                                                            $subject_display = htmlspecialchars($parts[0] ?? 'N/A');
                                                            $professor_display = htmlspecialchars(rtrim($parts[1] ?? 'N/A', ')'));
                                                        ?>
                                                            <div class="subject-item">
                                                                <div class="subject-title" onclick="toggleDirectory('roster-subject-<?= md5($subject_info . $program_name . $year_name . $section_name); ?>')">
                                                                    <div style="flex-grow: 1;">
                                                                        <i class="fa-solid fa-book-open"></i> <strong>Subject:</strong> <?= $subject_display; ?> 
                                                                    </div>
                                                                    <span class="professor-name">Professor: <?= $professor_display; ?></span>
                                                                    <i id="roster-subject-<?= md5($subject_info . $program_name . $year_name . $section_name); ?>-icon" class="fa-solid fa-chevron-right" style="font-size: 0.8rem; margin-left: 10px;"></i>
                                                                </div>
                                                                <div id="roster-subject-<?= md5($subject_info . $program_name . $year_name . $section_name); ?>" class="student-roster" style="display: none;">
                                                                    <?php 
                                                                    foreach ($students as $student_name): ?>
                                                                        <div><i class="fa-regular fa-user"></i> <?= htmlspecialchars($student_name); ?></div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="no-data" style="margin-top: 20px;">No active student enrollment records found in the database.</p>
                    <?php endif; ?>
                </section>
                </div>
<?php
// 2. Include the Footer File
require_once 'registrar_footer.php';

// Final cleanup: flush output buffer and exit
ob_end_flush();
exit();
?>