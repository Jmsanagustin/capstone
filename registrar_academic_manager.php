<?php
// FILE: registrar_academic_manager.php
// VERSION: Integrated Controller for Academic/Staff Management (Final UI/Layout)
// DESCRIPTION: Manages profile changes, batch student promotion, and staff account creation via CSV.
// MODIFICATION: Implemented CSV review screen with in-line editing capability.
// CLEANUP: Streamlined PHP logic, removed redundant variable checks.

// ---------------------------------------------------------------------------------
// --- INITIALIZATION AND DEPENDENCIES ---
// ---------------------------------------------------------------------------------
if (ob_get_level() === 0) {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';
require_once 'email_config.php'; // Contains send_credentials_email()

// Authorization: Must be Registrar
if (!isset($_SESSION['sid']) || ($_SESSION['role'] ?? '') !== 'Registrar') {
    header("Location: index.php");
    exit();
}

global $conn;
$current_user_id = $_SESSION['sid'];
$current_user_name = $_SESSION['username'] ?? 'Registrar';
$view = $_GET['view'] ?? 'change_requests';

// --- Initialize Variables ---
$message = '';
$message_type = '';
$db_error = '';
$staff_management_ui = '';
$promotion_data = [];
$total_unread_messages = 0; // Placeholder

// Check for temp session messages
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}


// =================================================================================
// 2. CSV TEMPLATE DOWNLOAD & POST PROCESSING LOGIC
// =================================================================================

// --- 2.0. Faculty CSV Template Download ---
if (isset($_GET['download_template']) && $_GET['download_template'] == 'faculty') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="faculty_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    // Ensure the header columns match the logic in 2.3
    fputcsv($output, ['full_name', 'username_id', 'email', 'role']);
    fputcsv($output, ['Jane Doe', 'prof.jane.d', 'j.doe@school.edu', 'professor']);
    fputcsv($output, ['John Smith', 'ph.john.s', 'jsmith@school.edu', 'Programhead']);
    
    fclose($output);
    exit();
}


// --- 2.1. Profile Change Requests Processing ---
if ($view === 'change_requests' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['request_id'])) {
    
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'];
    
    if ($request_id) {
        try {
            $conn->beginTransaction();

            if ($action === 'approve') {
                $stmt_fetch = $conn->prepare("SELECT * FROM profile_change_requests WHERE request_id = ? AND status = 'Pending'");
                $stmt_fetch->execute([$request_id]);
                $request = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

                if ($request) {
                    $student_sid = $request['student_sid'];
                    $updates_profile = [];
                    $updates_user = [];

                    if (!empty($request['new_first_name'])) $updates_profile['first_name'] = $request['new_first_name'];
                    if (!empty($request['new_middle_name'])) $updates_profile['middle_name'] = $request['new_middle_name'];
                    if (!empty($request['new_last_name'])) $updates_profile['last_name'] = $request['new_last_name'];
                    if (!empty($request['new_email'])) $updates_user['email'] = $request['new_email'];

                    if (!empty($updates_profile)) {
                        $set_clauses = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($updates_profile)));
                        $stmt_profile = $conn->prepare("UPDATE user_profiles SET $set_clauses WHERE sid = :sid");
                        $stmt_profile->execute(array_merge($updates_profile, [':sid' => $student_sid]));
                    }

                    if (!empty($updates_user)) {
                        $set_clauses = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($updates_user)));
                        $stmt_user = $conn->prepare("UPDATE users SET $set_clauses WHERE sid = :sid");
                        $stmt_user->execute(array_merge($updates_user, [':sid' => $student_sid]));
                    }
                    
                    $stmt_update_status = $conn->prepare("UPDATE profile_change_requests SET status = 'Approved', reviewed_by_sid = ?, review_date = NOW() WHERE request_id = ?");
                    $stmt_update_status->execute([$current_user_id, $request_id]);
                    
                    $_SESSION['temp_message'] = ['text' => "Request ID {$request_id} approved. Student profile updated.", 'type' => 'success'];

                } else {
                    $_SESSION['temp_message'] = ['text' => "Error: Request {$request_id} not found or already processed.", 'type' => 'error'];
                }
            } elseif ($action === 'reject') {
                $stmt_update_status = $conn->prepare("UPDATE profile_change_requests SET status = 'Rejected', reviewed_by_sid = ?, review_date = NOW(), rejection_reason = 'Rejected by Registrar without further notes.' WHERE request_id = ?");
                $stmt_update_status->execute([$current_user_id, $request_id]);
                $_SESSION['temp_message'] = ['text' => "Request ID {$request_id} successfully rejected.", 'type' => 'warning'];
            }

            $conn->commit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $_SESSION['temp_message'] = ['text' => "Database Error during processing: " . $e->getMessage(), 'type' => 'error'];
        }
    }
    
    header("Location: registrar_academic_manager.php?view=change_requests");
    exit();
}

// --- 2.2. Batch Promotion Processing ---
if ($view === 'batch_promotion' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_promotion'])) {
    
    $from_year = $_POST['from_year'] ?? '';
    $to_year = $_POST['to_year'] ?? '';
    $new_academic_year = $_POST['new_academic_year'] ?? '';
    $new_semester = $_POST['new_semester'] ?? '';

    if (empty($from_year) || empty($to_year) || empty($new_academic_year) || empty($new_semester)) {
        $_SESSION['temp_message'] = ['text' => 'Promotion failed: All promotion fields are required.', 'type' => 'error'];
        header("Location: registrar_academic_manager.php?view=batch_promotion");
        exit();
    }
    
    try {
        $conn->beginTransaction();
        
        $stmt_update_profiles = $conn->prepare("
            UPDATE user_profiles up
            JOIN users u ON up.sid = u.sid
            SET up.academic_year = :to_year
            WHERE u.role = 'student' AND up.academic_year = :from_year
        ");
        $stmt_update_profiles->execute([':to_year' => $to_year, ':from_year' => $from_year]);
        $rows_updated = $stmt_update_profiles->rowCount();
        
        $stmt_delete_old_enrollment = $conn->prepare("
             DELETE e FROM enrollment e
             JOIN user_profiles up ON e.student_sid = up.sid
             WHERE up.academic_year = :to_year_check
        ");
        $stmt_delete_old_enrollment->execute([':to_year_check' => $to_year]); 
        
        $conn->commit();
        
        $_SESSION['temp_message'] = [
            'text' => "Success: {$rows_updated} students promoted from {$from_year} to {$to_year} for Academic Year {$new_academic_year} ({$new_semester}).",
            'type' => 'success'
        ];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['temp_message'] = ['text' => "Promotion failed due to a database error: " . $e->getMessage(), 'type' => 'error'];
        error_log("Batch Promotion Error: " . $e->getMessage());
    }
    header("Location: registrar_academic_manager.php?view=batch_promotion");
    exit();
}


// --- 2.3. Faculty CSV Upload Processing (MODIFIED FOR REVIEW STEP) ---
if ($view === 'staff_management' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_faculty'])) {

    if (isset($_FILES['faculty_file']) && $_FILES['faculty_file']['error'] == UPLOAD_ERR_OK) {

        $file_name = $_FILES['faculty_file']['name'];
        $handle = fopen($_FILES['faculty_file']['tmp_name'], "r");

        if ($handle !== FALSE) {
            $header = fgetcsv($handle);
            $expected_header = ['full_name', 'username_id', 'email', 'role'];

            if (count($header) !== count($expected_header) || array_diff($header, $expected_header)) {
                $_SESSION['temp_message'] = ['text' => "CSV upload failed. Invalid file format or missing columns.", 'type' => 'error'];
            } else {
                $review_data = [];
                $row_index = 0;
                $all_usernames = [];
                $all_emails = [];

                // Pre-check for existing usernames/emails in the database (OPTIMIZED FOR BATCH)
                try {
                    $stmt_db_check = $conn->query("SELECT username, email FROM users WHERE role != 'student'");
                    $existing_users = $stmt_db_check->fetchAll(PDO::FETCH_KEY_PAIR); // Example: ['username' => 'email']
                } catch (PDOException $e) {
                    $_SESSION['temp_message'] = ['text' => "Database error during pre-check: " . $e->getMessage(), 'type' => 'error'];
                    header("Location: registrar_academic_manager.php?view=staff_management");
                    exit();
                }

                while (($data = fgetcsv($handle)) !== FALSE) {
                    $row_index++;
                    $faculty_data = array_combine($header, $data);
                    
                    // Skip empty rows (usually just whitespace in the CSV)
                    if (count(array_filter($data)) === 0) continue; 
                    
                    $record = [
                        'row_num' => $row_index,
                        'original_data' => $faculty_data,
                        'errors' => [],
                        'status' => 'Pending'
                    ];

                    $full_name = trim($faculty_data['full_name'] ?? '');
                    $username = trim($faculty_data['username_id'] ?? '');
                    $email = trim($faculty_data['email'] ?? '');
                    $role = trim(strtolower($faculty_data['role'] ?? ''));

                    // --- 1. Basic Data Validation ---
                    if (empty($full_name) || empty($username) || empty($email)) {
                        $record['errors'][] = 'Missing essential field (Name, Username, or Email).';
                        $record['status'] = 'Error';
                    }
                    if (!in_array($role, ['professor', 'programhead', 'registrar'])) {
                        $record['errors'][] = "Invalid role: '{$role}'. Must be professor, programhead, or registrar.";
                        $record['status'] = 'Error';
                    }

                    // --- 2. Name Parsing (Simple split) ---
                    $name_parts = explode(' ', $full_name);
                    $last_name = array_pop($name_parts);
                    $first_name = implode(' ', $name_parts);

                    $record['parsed_name'] = ['first' => $first_name, 'last' => $last_name];
                    $record['role'] = $role;

                    // --- 3. Duplicate/Existing Check (Database) ---
                    if (isset($existing_users[$username])) {
                         $record['errors'][] = "Username '{$username}' already exists in database.";
                         $record['status'] = 'Conflict';
                    }
                    if (in_array($email, $existing_users)) {
                         $record['errors'][] = "Email '{$email}' already exists in database.";
                         $record['status'] = 'Conflict';
                    }

                    // --- 4. Duplicate Check (Within CSV) ---
                    if (in_array($username, $all_usernames)) {
                         $record['errors'][] = "Username '{$username}' is duplicated within this CSV file.";
                         if ($record['status'] !== 'Error') $record['status'] = 'Conflict'; 
                    }
                    if (in_array($email, $all_emails)) {
                         $record['errors'][] = "Email '{$email}' is duplicated within this CSV file.";
                         if ($record['status'] !== 'Error') $record['status'] = 'Conflict'; 
                    }

                    // Add to local checks
                    $all_usernames[] = $username;
                    $all_emails[] = $email;

                    $review_data[] = $record;
                }
                fclose($handle);
                
                // Store data in session and redirect to review page
                $_SESSION['csv_review_data'] = $review_data;
                header("Location: registrar_academic_manager.php?view=review_upload");
                exit();
            }
        } else {
            $_SESSION['temp_message'] = ['text' => "Error reading the uploaded file.", 'type' => 'error'];
        }
    } else {
        $_SESSION['temp_message'] = ['text' => "CSV upload failed or no file selected.", 'type' => 'error'];
    }

    header("Location: registrar_academic_manager.php?view=staff_management");
    exit();
}


// --- NEW POST HANDLER: Finalize Bulk Account Creation (MODIFIED to read edits) ---
if ($view === 'review_upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_upload'])) {

    if (!isset($_SESSION['csv_review_data'])) {
        $_SESSION['temp_message'] = ['text' => 'Error: Review data expired or missing.', 'type' => 'error'];
        header("Location: registrar_academic_manager.php?view=staff_management");
        exit();
    }
    
    $review_data = $_SESSION['csv_review_data'];
    $success_count = 0;
    $error_messages = [];
    $edited_data = $_POST['edited_data'] ?? []; // Capture edited data from JavaScript form

    foreach ($review_data as $index => $record) {
        
        // --- Apply client-side edits if present ---
        if (isset($edited_data[$index])) {
            $edits = $edited_data[$index];
            
            // Apply edits to the record (prioritize user input)
            $record['original_data']['full_name'] = $edits['full_name'] ?? $record['original_data']['full_name'];
            $record['original_data']['username_id'] = $edits['username'] ?? $record['original_data']['username_id'];
            $record['original_data']['email'] = $edits['email'] ?? $record['original_data']['email'];

            $record['parsed_name']['first'] = $edits['first_name'] ?? $record['parsed_name']['first'];
            $record['parsed_name']['last'] = $edits['last_name'] ?? $record['parsed_name']['last'];
            
            // Crucial: Assume user fixed the issue if they edited it, and reset status to Pending
            if ($record['status'] !== 'Pending' && !empty($edits)) {
                 $record['status'] = 'Pending';
                 $record['errors'] = []; // Clear old errors
            }
        }
        // --- END Apply client-side edits ---
        
        // Only process records marked as 'Pending'
        if ($record['status'] !== 'Pending') {
             continue; // Skip records still considered 'Error' or 'Conflict'
        }

        // Re-extract data after potential edit (use clean trim() values)
        $full_name = trim($record['original_data']['full_name']);
        $username = trim($record['original_data']['username_id']);
        $email = trim($record['original_data']['email']);
        $role = trim(strtolower($record['role'])); // Role is not editable via JS in this version

        $first_name = trim($record['parsed_name']['first']);
        $last_name = trim($record['parsed_name']['last']);
        $middle_name = null; // Middle name logic is still simple/absent in the name split

        // Final check before insertion (e.g., in case of concurrent DB change)
        try {
            $stmt_check = $conn->prepare("SELECT sid FROM users WHERE username = :username OR email = :email");
            $stmt_check->execute([':username' => $username, ':email' => $email]);
            
            if ($stmt_check->rowCount() > 0) {
                 $error_messages[] = "Row {$record['row_num']} (FINAL CONFLICT): User '{$username}' or email '{$email}' already exists. Insertion skipped.";
                 continue; // Skip insertion
            }
        } catch (PDOException $e) { /* ignore check error */ }

        // Generate temp password
        $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);

        try {
            $conn->beginTransaction();

            // 1. Create the user account
            $stmt_user = $conn->prepare("
                INSERT INTO users (username, email, password, role, is_verified, must_change_password)
                VALUES (:username, :email, :password, :role, 1, 1)
            ");
            $stmt_user->execute([
                ':username' => $username,
                ':email' => $email,
                ':password' => $hashed_password,
                ':role' => $role
            ]);
            $new_sid = $conn->lastInsertId();

            // 2. Create the user profile
            $stmt_profile = $conn->prepare("
                INSERT INTO user_profiles (sid, student_id, first_name, last_name, program)
                VALUES (:sid, :employee_id, :first_name, :last_name, NULL)
            ");
            $stmt_profile->execute([
                ':sid' => $new_sid,
                ':employee_id' => $username, // Employee ID/Student ID field
                ':first_name' => $first_name,
                ':last_name' => $last_name
            ]);

            // 3. Send email with temporary password
            // send_credentials_email($email, $full_name, $username, $temp_password); 

            $conn->commit();
            $success_count++;

        } catch (PDOException $e) {
            $conn->rollBack();
            $error_messages[] = "Row {$record['row_num']} (DB Error): Could not insert {$username}. Error: " . $e->getMessage();
        }
    }

    // Clean up session data
    unset($_SESSION['csv_review_data']);
    
    $final_msg = "Bulk Upload Finalized. **{$success_count}** accounts created.";
    if (!empty($error_messages)) {
        $final_msg .= " **" . count($error_messages) . "** records failed on final insert.";
        $_SESSION['temp_message'] = ['text' => $final_msg, 'type' => 'warning'];
    } elseif ($success_count == 0) {
        $final_msg = "Bulk Upload Finalized. **No** accounts were created.";
        $_SESSION['temp_message'] = ['text' => $final_msg, 'type' => 'error'];
    } else {
        $_SESSION['temp_message'] = ['text' => $final_msg, 'type' => 'success'];
    }

    header("Location: registrar_academic_manager.php?view=staff_management");
    exit();
}
// ---------------------------------------------------------------------------------


// =================================================================================
// 3. DATA FETCHING (Prepares variables for UI)
// =================================================================================

// --- 3.1. Staff Management Data ---
if ($view === 'staff_management') {
    require_once 'registrar_staff_management_logic.php'; // Assumes this file defines $staff_management_ui
}

// --- 3.2. Profile Change Requests Data ---
$pending_requests = [];
if ($view === 'change_requests') {
    try {
        $stmt = $conn->prepare("
             SELECT 
                 pcr.request_id, pcr.student_sid, 
                 CONCAT(up.last_name, ', ', up.first_name) as current_name, 
                 u.email as current_email,
                 pcr.new_first_name, pcr.new_middle_name, pcr.new_last_name, pcr.new_email,
                 pcr.submitted_at
             FROM profile_change_requests pcr
             JOIN users u ON pcr.student_sid = u.sid
             JOIN user_profiles up ON pcr.student_sid = up.sid
             WHERE pcr.status = 'Pending'
             ORDER BY pcr.submitted_at ASC
          ");
        $stmt->execute();
        $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $db_error = "Database error fetching profile change requests: " . $e->getMessage();
    }
}

// --- 3.3. Batch Promotion Data (Cohorts Overview) ---
if ($view === 'batch_promotion') {
    try {
        $stmt_eligible = $conn->query("
             SELECT up.academic_year, up.program, COUNT(up.sid) as total_students
             FROM user_profiles up
             JOIN users u ON up.sid = u.sid
             WHERE u.role = 'student' AND up.academic_year IN ('1st Year', '2nd Year', '3rd Year', '4th Year')
             GROUP BY up.academic_year, up.program
             ORDER BY up.academic_year, up.program
          ");
        $promotion_data = $stmt_eligible->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $db_error = "Error fetching promotion eligibility data: " . $e->getMessage();
    }
}


// =================================================================================
// 4. HTML RENDERING (Includes Header/Footer)
// =================================================================================

require_once 'registrar_header.php';
?>

            <?php // Tab Controls for Academic Management views ?>
            <?php $tab_views = ['change_requests', 'staff_management', 'batch_promotion']; ?>
            <?php if (in_array($view, $tab_views) || $view === 'review_upload'): // Include review_upload for proper header rendering ?>
            <div class="tab-controls">
                <a href="registrar_academic_manager.php?view=change_requests" class="tab-button <?= ($view === 'change_requests') ? 'active' : ''; ?>"><i class="fa-solid fa-user-check"></i> Profile Requests</a>
                <a href="registrar_academic_manager.php?view=batch_promotion" class="tab-button <?= ($view === 'batch_promotion') ? 'active' : ''; ?>"><i class="fa-solid fa-graduation-cap"></i> Batch Promotion</a>
                <a href="registrar_academic_manager.php?view=staff_management" class="tab-button <?= ($view === 'staff_management' || $view === 'review_upload') ? 'active' : ''; ?>"><i class="fa-solid fa-user-tie"></i> Faculty Management.</a>
            </div>
            <?php endif; ?>
            
            <section class="content-section">
                
                <?php if ($view === 'change_requests'): ?>
                    
                    <h2>
                        <i class="fa-solid fa-file-invoice"></i> Pending Profile Change Requests
                        <span class="info-tooltip">
                            <i class="fa-solid fa-circle-info"></i>
                            <span class="tooltip-text">
                                <p>Review student-submitted requests to officially update personal details (Names, Email).</p>
                                <p>Approve will commit the requested data immediately to the student's main profile.</p>
                            </span>
                        </span>
                    </h2>
                    <p class="description">Review student-submitted requests to officially update personal details (Names, Email).</p>

                    <?php if (!empty($pending_requests)): ?>
                        <div class="table-container">
                            <table class="req-table">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">Student ID</th>
                                        <th style="width: 40%;">Current Name / New Request</th>
                                        <th style="width: 20%;">Current Email / New Email</th>
                                        <th style="width: 10%;">Submitted</th>
                                        <th style="width: 20%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_requests as $request): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($request['student_sid']); ?></td>
                                            <td>
                                                <div class="badge-current-data">Current: <?= htmlspecialchars($request['current_name']); ?></div>
                                                <?php if ($request['new_first_name'] || $request['new_last_name'] || $request['new_middle_name']): ?>
                                                    <div class="badge-new-data">New: 
                                                        <?= htmlspecialchars($request['new_last_name'] ?? ''); ?>, 
                                                        <?= htmlspecialchars($request['new_first_name'] ?? ''); ?> 
                                                        <?= htmlspecialchars($request['new_middle_name'] ?? ''); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="badge-current-data"><?= htmlspecialchars($request['current_email']); ?></div>
                                                <?php if ($request['new_email']): ?>
                                                    <div class="badge-new-data">New: <?= htmlspecialchars($request['new_email']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('Y-m-d', strtotime($request['submitted_at'])); ?></td>
                                            <td class="action-buttons">
                                                <button class="approve-btn" onclick="confirmAction(<?= $request['request_id']; ?>, 'approve')"><i class="fa-solid fa-check"></i> Approve</button>
                                                <button class="reject-btn" onclick="confirmAction(<?= $request['request_id']; ?>, 'reject')"><i class="fa-solid fa-times"></i> Reject</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="no-data">No pending profile change requests.</p>
                    <?php endif; ?>

                <?php elseif ($view === 'staff_management'): ?>
                    
                    <h2>
                        <i class="fa-solid fa-user-tie"></i> Faculty Management
                        <span class="info-tooltip">
                            <i class="fa-solid fa-circle-info"></i>
                            <span class="tooltip-text">
                                <p>Manage accounts for Professors, Program Heads, and designated Registrar Staff.</p>
                                <p>New accounts should receive temporary credentials via email.</p>
                            </span>
                        </span>
                    </h2>
                    <p class="description">Interface for creating, viewing, and managing accounts and roles for all non-student users (Professors, Program Heads, Staff).</p>

                    <div class="group-header" onclick="toggleDirectory('faculty-bulk-upload-section')">
                        <span><i class="fa-solid fa-file-csv"></i> Bulk Upload Faculty/Program Heads</span>
                        <span class="info-tooltip" style="margin-left: 10px;">
                            <i class="fa-solid fa-circle-info"></i>
                            <span class="tooltip-text">
                                <p style="font-weight: 600; margin-bottom: 10px; color: var(--panel-blue);">CSV Upload Instructions:</p>
                                <p>Upload a CSV to create accounts for faculty and program heads.</p>
                                <p>Required CSV Columns: <code>full_name</code>, <code>username_id</code>, <code>email</code>, <code>role</code>.</p>
                                <p>The `role` must be one of: **professor**, **Programhead**, or **registrar**.</p>
                                <a href="registrar_academic_manager.php?download_template=faculty" style="color: var(--accent-light); font-weight: 600;">Download CSV Template</a>
                            </span>
                        </span>
                        <i id="faculty-bulk-upload-section-icon" class="fa-solid fa-chevron-right" style="float: right;"></i>
                    </div>
                    <div id="faculty-bulk-upload-section" class="group-content" style="display: none; padding: 20px; background: var(--bg-light);">
                        
                        <p class="description" style="margin-bottom: 15px;">
                            Upload a CSV file to batch-create non-student accounts. The system will review for conflicts and present a confirmation screen before creation.
                        </p>

                        <form
                            action="registrar_academic_manager.php?view=staff_management"
                            method="POST"
                            enctype="multipart/form-data"
                            style="display: flex; gap: 15px; align-items: flex-end;"
                        >
                            <div style="flex: 1;">
                                <label for="faculty_file" style="font-weight: 600; display: block; margin-bottom: 5px;">Select Faculty CSV:</label>
                                <input type="file" name="faculty_file" id="faculty_file" accept=".csv" required
                                    style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: 100%; background: var(--white);">
                            </div>
                            <button type="submit" name="upload_faculty" class="approve-btn" style="background-color: var(--info-blue); padding: 10px 20px;">
                                <i class="fa-solid fa-cloud-upload-alt"></i> Upload & Review
                            </button>
                        </form>
                    </div>

                    <?php if (!empty($staff_management_ui ?? '')): ?>
                        <?= $staff_management_ui; ?>
                    <?php endif; ?>
                
                <?php elseif ($view === 'review_upload'): ?>
                    
                    <?php if (empty($_SESSION['csv_review_data'])): ?>
                        <h2><i class="fa-solid fa-hand-paper"></i> Review Session Expired</h2>
                        <p class="no-data">No data found for review. Please go back to Staff Management and re-upload your CSV.</p>
                    <?php else: ?>

                        <?php $review_data = $_SESSION['csv_review_data']; ?>
                        <?php 
                            $total_records = count($review_data);
                            // Filter function to count records NOT 'Pending' (i.e., 'Error' or 'Conflict')
                            $error_count = count(array_filter($review_data, fn($r) => $r['status'] !== 'Pending'));
                            $ready_count = $total_records - $error_count;
                        ?>

                        <h2>
                            <i class="fa-solid fa-file-invoice"></i> Review Bulk Faculty Upload
                        </h2>
                        <p class="description">
                            Review **<?= $total_records; ?>** records before final creation. **Click any name, username, or email cell to manually correct data.** Records with errors/conflicts will be **skipped** unless manually corrected.
                        </p>

                        <div style="padding: 20px; margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: var(--success-green);">Ready to Process: <span id="ready-count"><?= $ready_count; ?></span></strong> |
                                <strong style="color: var(--critical);">Errors/Conflicts: <span id="error-count"><?= $error_count; ?></span></strong>
                            </div>

                            <form id="finalize-form" action="registrar_academic_manager.php?view=review_upload" method="POST" onsubmit="return confirm('CONFIRM: Are you sure you want to create the ' + document.getElementById('ready-count').textContent + ' valid accounts?');">
                                <input type="hidden" name="finalize_upload" value="1">
                                <button type="submit" class="approve-btn" <?= ($ready_count === 0) ? 'disabled' : ''; ?> style="padding: 12px 25px; font-size: 1rem;">
                                    <i class="fa-solid fa-check-double"></i> Finalize & Create Accounts
                                </button>
                                <a href="registrar_academic_manager.php?view=staff_management" class="reject-btn" style="text-decoration: none; padding: 12px 25px; margin-left: 10px;">
                                    <i class="fa-solid fa-times"></i> Cancel & Discard
                                </a>
                            </form>
                        </div>
                        
                        <div class="table-container">
                            <table class="req-table" id="review-table">
                                <thead>
                                    <tr>
                                        <th style="width: 5%;">#</th>
                                        <th style="width: 20%;">Full Name (CSV)</th>
                                        <th style="width: 20%;">Parsed Name (First / Last)</th>
                                        <th style="width: 15%;">Username/ID</th>
                                        <th style="width: 20%;">Email</th>
                                        <th style="width: 20%;">Status / Errors</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($review_data as $index => $record): ?>
                                        <?php 
                                            // Determine row class based on status
                                            $row_class = match($record['status']) {
                                                'Error' => 'critical-error-row',
                                                'Conflict' => 'warning-row',
                                                default => ''
                                            };
                                            // Store essential data in a single row attribute for easy JS access
                                            $row_data_json = json_encode([
                                                'row_num' => $record['row_num'],
                                                'full_name' => $record['original_data']['full_name'],
                                                'first_name' => $record['parsed_name']['first'],
                                                'last_name' => $record['parsed_name']['last'],
                                                'username' => $record['original_data']['username_id'],
                                                'email' => $record['original_data']['email'],
                                                'role' => $record['role']
                                            ]);
                                        ?>
                                        <tr 
                                            class="<?= $row_class; ?>" 
                                            data-index="<?= $index; ?>" 
                                            data-status="<?= $record['status']; ?>"
                                            data-original='<?= htmlspecialchars($row_data_json, ENT_QUOTES, 'UTF-8'); ?>'
                                            id="row-<?= $index; ?>"
                                        >
                                            <td><?= $record['row_num']; ?></td>
                                            <td class="editable-cell" data-field="full_name" onclick="makeEditable(this)">
                                                <?= htmlspecialchars($record['original_data']['full_name']); ?>
                                            </td>
                                            <td class="name-parts">
                                                <div class="editable-name" data-field="first_name" onclick="makeEditable(this)" style="font-weight: 600;">First: <?= htmlspecialchars($record['parsed_name']['first']); ?></div>
                                                <div class="editable-name" data-field="last_name" onclick="makeEditable(this)" style="color: var(--text-light); font-size: 0.9em;">Last: <?= htmlspecialchars($record['parsed_name']['last']); ?></div>
                                            </td>
                                            <td class="editable-cell <?= (str_contains(implode('', $record['errors']), 'Username')) ? 'highlight-error' : ''; ?>" data-field="username" onclick="makeEditable(this)">
                                                <?= htmlspecialchars($record['original_data']['username_id']); ?>
                                            </td>
                                            <td class="editable-cell <?= (str_contains(implode('', $record['errors']), 'Email')) ? 'highlight-error' : ''; ?>" data-field="email" onclick="makeEditable(this)">
                                                <?= htmlspecialchars($record['original_data']['email']); ?>
                                            </td>
                                            <td class="status-cell" id="status-<?= $index; ?>" data-initial-errors='<?= json_encode($record['errors']); ?>'>
                                                <?php if (!empty($record['errors'])): ?>
                                                    <span style="color: var(--critical);"><i class="fa-solid fa-exclamation-triangle"></i> <?= $record['status']; ?></span>
                                                    <ul class="error-list" style="list-style-type: none; padding-left: 0; font-weight: normal; font-size: 0.85em; margin-top: 5px;">
                                                        <?php foreach ($record['errors'] as $error): ?>
                                                            <li>- <?= htmlspecialchars($error); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <span style="color: var(--success-green);"><i class="fa-solid fa-check-circle"></i> Ready</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                <?php elseif ($view === 'batch_promotion'): ?>
                    
                    <div class="batch-promotion-dashboard">
                        <h2>
                            <i class="fa-solid fa-stairs"></i> Student Batch Promotion
                            <span class="info-tooltip">
                                <i class="fa-solid fa-circle-info"></i>
                                <span class="tooltip-text">
                                    <p>The Batch Promotion tool updates the Academic Year field for a mass group of students.</p>
                                    <p>It automatically attempts to re-enroll students into matching class sections for the new academic year and semester.</p>
                                    <p>Requires prior setup of all course offerings for the target year/semester.</p>
                                </span>
                            </span>
                        </h2>
                        <p class="description">Select a cohort to promote them to the next academic year. This process applies to all students in the selected academic year across all programs.</p>

                        <p id="critical-warning" class="message error critical-flash-message" style="font-weight: 600; cursor: pointer;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            CRITICAL: Before you run this tool, you must ensure the class shells (sections and subjects) for the new academic year and semester are fully set up in your Class Management tool. (Click to dismiss)
                        </p>
                        
                        <div style="margin-top: 30px; padding: 20px; border: 1px solid var(--border-color); border-radius: 8px;">
                            <h4 style="margin-bottom: 20px; color: var(--panel-blue);"><i class="fa-solid fa-person-digging"></i> Run Promotion Tool</h4>

                            <form action="registrar_academic_manager.php?view=batch_promotion" method="POST" onsubmit="return confirm('WARNING: Are you absolutely sure you want to promote this entire batch? This action is irreversible.');">
                                <input type="hidden" name="run_promotion" value="1">

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                                    
                                    <div>
                                        <label for="from_year" style="font-weight: 600; display: block; margin-bottom: 5px;">Promote Batch FROM Academic Year:</label>
                                        <select name="from_year" id="from_year" required class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                                            <option value="">Select current year...</option>
                                            <option value="1st Year">1st Year</option>
                                            <option value="2nd Year">2nd Year</option>
                                            <option value="3rd Year">3rd Year</option>
                                            <option value="4th Year">4th Year</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="to_year" style="font-weight: 600; display: block; margin-bottom: 5px;">TO Next Year Level:</label>
                                        <select name="to_year" id="to_year" required class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                                            <option value="">Select target year...</option>
                                            <option value="2nd Year">2nd Year</option>
                                            <option value="3rd Year">3rd Year</option>
                                            <option value="4th Year">4th Year</option>
                                            <option value="5th Year">5th Year (Graduating/Final Year)</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label for="new_academic_year" style="font-weight: 600; display: block; margin-bottom: 5px;">New Academic Year:</label>
                                        <input type="text" name="new_academic_year" id="new_academic_year" placeholder="e.g., 2026-2027" required
                                            style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                                    </div>

                                    <div>
                                        <label for="new_semester" style="font-weight: 600; display: block; margin-bottom: 5px;">New Semester:</label>
                                        <select name="new_semester" id="new_semester" required class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                                            <option value="">Select semester...</option>
                                            <option value="1st Semester">1st Semester</option>
                                            <option value="2nd Semester">2nd Semester</option>
                                            <option value="Summer">Summer</option>
                                        </select>
                                    </div>
                                </div>

                                <div style="text-align: right; margin-top: 30px;">
                                    <button type="submit" class="approve-btn" style="background-color: var(--critical); padding: 12px 25px; font-size: 1rem;">
                                        <i class="fa-solid fa-bolt"></i> Run Batch Promotion
                                    </button>
                                </div>
                            </form>
                        </div>

                        <h3 style="margin-top: 40px;"><i class="fa-solid fa-clipboard-list"></i> Current Student Cohorts Overview</h3>
                        
                        <div class="table-container">
                            <?php if (!empty($promotion_data)): ?>
                                <table class="review-table"> 
                                    <thead>
                                        <tr>
                                            <th style="width: 30%;">Program</th>
                                            <th style="width: 40%;">Current Academic Year</th>
                                            <th style="width: 30%;">Total Students</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $total_students_count = 0;
                                        foreach ($promotion_data as $data): 
                                            $total_students_count += (int)$data['total_students'];
                                        ?>
                                            <tr>
                                                <td><?= htmlspecialchars($data['program']); ?></td>
                                                <td><?= htmlspecialchars($data['academic_year']); ?></td>
                                                <td><?= htmlspecialchars($data['total_students']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="2" style="text-align: right; background: #e2e8f0;">Total Current Students:</th>
                                            <th style="background: #e2e8f0;"><?= $total_students_count; ?></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            <?php else: ?>
                                <p class="no-data">No student cohorts found to promote.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php endif; ?>
            </section>
            
            <form id="action-form" method="POST" action="registrar_academic_manager.php?view=change_requests">
                <input type="hidden" name="action" id="form-action">
                <input type="hidden" name="request_id" id="form-request-id">
            </form>
            
            <script>
                // Global staging area for edits to the CSV review data
                const stagedEdits = {};

                // Helper function to parse simple name splitting (First Last)
                function parseFullName(fullName) {
                    const parts = fullName.trim().split(/\s+/);
                    if (parts.length > 1) {
                        const lastName = parts.pop();
                        const firstName = parts.join(' ');
                        return { firstName, lastName };
                    }
                    return { firstName: fullName, lastName: '' };
                }

                // Helper function to re-evaluate the status of a single row after edit
                function recheckStatus(rowIndex) {
                    const row = document.getElementById(`row-${rowIndex}`);
                    const statusCell = document.getElementById(`status-${rowIndex}`);
                    let errors = [];

                    // --- 1. Get current values (may be staged) ---
                    const originalData = JSON.parse(row.dataset.original);
                    const rowData = stagedEdits[rowIndex] || originalData;
                    
                    // --- 2. Check for missing fields (Basic client-side check) ---
                    // The 'role' check is safe because it's non-editable via JS, defaulting to the original CSV value.
                    if (!rowData.first_name || !rowData.last_name || !rowData.username || !rowData.email || !rowData.role) {
                        errors.push("Missing required name/username/email field.");
                    }
                    
                    // --- 3. Render Status ---
                    if (errors.length > 0) {
                        row.classList.add('critical-error-row');
                        row.classList.remove('warning-row');
                        row.dataset.status = 'Error';
                    } else {
                        // If successfully edited and no obvious errors, set to Pending (Ready for server processing)
                        row.classList.remove('critical-error-row');
                        row.classList.remove('warning-row');
                        row.dataset.status = 'Pending';
                    }

                    statusCell.innerHTML = '';
                    if (errors.length > 0) {
                        statusCell.innerHTML = `<span style="color: var(--critical);"><i class="fa-solid fa-exclamation-triangle"></i> Error</span><ul class="error-list" style="list-style-type: none; padding-left: 0; font-weight: normal; font-size: 0.85em; margin-top: 5px;">`;
                        errors.forEach(err => {
                            statusCell.innerHTML += `<li>- ${err}</li>`;
                        });
                        statusCell.innerHTML += `</ul>`;
                    } else {
                        statusCell.innerHTML = `<span style="color: var(--success-green);"><i class="fa-solid fa-check-circle"></i> Ready</span>`;
                    }

                    updateOverallCounts();
                }

                // Function to update the global counters at the top of the page
                function updateOverallCounts() {
                    let readyCount = 0;
                    let errorCount = 0;
                    
                    document.querySelectorAll('#review-table tbody tr').forEach(row => {
                        const status = row.dataset.status;
                        if (status === 'Pending') {
                            readyCount++;
                        } else {
                            errorCount++;
                        }
                    });

                    document.getElementById('ready-count').textContent = readyCount;
                    document.getElementById('error-count').textContent = errorCount;
                    // Disable finalize button if no records are ready
                    const finalizeButton = document.querySelector('#finalize-form button[type="submit"]');
                    if (finalizeButton) {
                        finalizeButton.disabled = (readyCount === 0);
                    }
                }


                // Main function to make a cell editable
                function makeEditable(cell) {
                    // Prevent editing if an input is already present
                    if (cell.querySelector('input')) return; 

                    // Get values
                    const originalText = cell.textContent.replace(/First: |Last: /g, '').trim(); // Clean up display text
                    const field = cell.dataset.field;
                    const rowElement = cell.closest('tr');
                    const rowIndex = rowElement.dataset.index;

                    // 1. Create input field
                    const input = document.createElement('input');
                    input.type = 'text';
                    input.value = originalText;
                    input.style.width = '100%';
                    input.style.padding = '5px';
                    input.style.border = '1px solid var(--panel-blue)';
                    input.style.borderRadius = '4px';

                    // 2. Replace the cell content with the input field
                    // Handle "name-parts" div vs. "editable-cell" td
                    cell.innerHTML = '';
                    cell.appendChild(input);
                    input.focus();

                    // 3. Event listener for saving the change (on blur or Enter key)
                    const saveEdit = () => {
                        const newValue = input.value.trim();
                        
                        // 4. Stage the edit
                        if (!stagedEdits[rowIndex]) {
                            stagedEdits[rowIndex] = JSON.parse(rowElement.dataset.original);
                        }
                        
                        if (field === 'full_name') {
                            // If editing the full name field, we must re-parse the individual names
                            const parsed = parseFullName(newValue);
                            stagedEdits[rowIndex]['full_name'] = newValue;
                            stagedEdits[rowIndex]['first_name'] = parsed.firstName;
                            stagedEdits[rowIndex]['last_name'] = parsed.lastName;

                            // Update the display of the parsed names immediately
                            const namePartsCell = rowElement.querySelector('.name-parts');
                            namePartsCell.querySelector('[data-field="first_name"]').innerHTML = `First: ${parsed.firstName}`;
                            namePartsCell.querySelector('[data-field="last_name"]').innerHTML = `Last: ${parsed.lastName}`;
                            
                            // Also need to set hidden fields for first/last name to ensure they're passed to server
                            updateHiddenInput(rowIndex, 'first_name', parsed.firstName);
                            updateHiddenInput(rowIndex, 'last_name', parsed.lastName);

                        } else if (field === 'first_name' || field === 'last_name') {
                            // If editing a parsed name field, update the staged name parts
                            stagedEdits[rowIndex][field] = newValue;
                            cell.innerHTML = `${field === 'first_name' ? 'First' : 'Last'}: ${newValue}`;
                        } else {
                            // For Username/Email
                            stagedEdits[rowIndex][field] = newValue;
                        }

                        // Restore the original text content (if not name part)
                        if (cell.classList.contains('editable-cell')) {
                            cell.innerHTML = newValue;
                        }

                        // 5. Update the hidden field for this specific field
                        if (field !== 'first_name' && field !== 'last_name') {
                             updateHiddenInput(rowIndex, field, newValue);
                        } else {
                             // This is a name part, update its hidden input
                             updateHiddenInput(rowIndex, field, newValue);
                        }

                        // 6. Recheck the row status (validity)
                        recheckStatus(rowIndex);
                        
                        // Cleanup
                        input.removeEventListener('blur', saveEdit);
                        input.removeEventListener('keydown', handleKey);
                        input.remove();
                    };

                    const handleKey = (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            saveEdit();
                        } else if (e.key === 'Escape') {
                            // Revert and exit edit mode on escape
                            cell.innerHTML = originalText;
                            input.removeEventListener('blur', saveEdit);
                            input.removeEventListener('keydown', handleKey);
                            input.remove();
                        }
                    };

                    input.addEventListener('blur', saveEdit);
                    input.addEventListener('keydown', handleKey);
                }
                
                // Helper to manage hidden inputs for server post
                function updateHiddenInput(rowIndex, field, value) {
                    const form = document.getElementById('finalize-form');
                    const id = `edit-${rowIndex}-${field}`;
                    let hiddenInput = document.getElementById(id);

                    if (!hiddenInput) {
                         hiddenInput = document.createElement('input');
                         hiddenInput.type = 'hidden';
                         hiddenInput.name = `edited_data[${rowIndex}][${field}]`;
                         hiddenInput.id = id;
                         form.appendChild(hiddenInput);
                    }
                    hiddenInput.value = value;
                }

                // Local helper function for Profile Requests
                function confirmAction(id, action) {
                    let confirmation = confirm(`Are you sure you want to ${action} this profile change request (ID: ${id})? This action is final.`);
                    if (confirmation) {
                        document.getElementById('form-action').value = action;
                        document.getElementById('form-request-id').value = id;
                        document.getElementById('action-form').submit();
                    }
                }
                
                // Initial count calculation when the page loads
                document.addEventListener('DOMContentLoaded', updateOverallCounts);
            </script>

<?php
require_once 'registrar_footer.php';
ob_end_flush();
?>