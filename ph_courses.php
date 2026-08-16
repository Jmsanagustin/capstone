<?php
// FILE: ph_courses.php (Course Management View)
// VERSION 7.5: Collapsible Sections

// 1. START ALL PHP PROCESSING FIRST
session_start();
include 'db.php';

// --- GET HANDLER: Download CSV Template ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download_template') {
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bulk_upload_template.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header Row (The required columns)
    fputcsv($output, [
        'subject_code', 
        'professor_username', 
        'academic_year', 
        'semester', 
        'section_suffix'
    ]);
    
    // Example Row (to guide the user)
    fputcsv($output, [
        'IT-101', 
        'jdelacruz', 
        '2025-2026', 
        '1st Semester', 
        '11'
    ]);
    
    fclose($output);
    exit();
}
// --- END OF DOWNLOAD HANDLER ---


// --- Authorization Check (Run before any header output) ---
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
    header("Location: index.php");
    exit();
}

$program_head_sid = $_SESSION['sid'] ?? 0;
$program_head_program = $_SESSION['program'] ?? '';

$temp_message = ['type' => 'error', 'text' => 'An unknown error occurred.'];


// --- POST HANDLER: Create New Class (SINGLE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_class'])) {
    
    // 1. Get and sanitize form data
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $professor_sid = filter_input(INPUT_POST, 'professor_sid', FILTER_VALIDATE_INT);
    $academic_year = trim($_POST['academic_year']);
    $semester = trim($_POST['semester']);
    
    // **MODIFIED** Get the suffix and auto-prepend the program
    $section_suffix = trim($_POST['section_suffix']);
    $section = $program_head_program . '-' . $section_suffix;

    // 2. Validate data (check suffix)
    if (empty($subject_id) || empty($professor_sid) || empty($academic_year) || empty($semester) || empty($section_suffix)) {
        $temp_message['text'] = 'All fields are required.';
    } else {
        try {
            $conn->beginTransaction();

            // 3. Check for duplicates (uses the full $section key)
            $stmt_check = $conn->prepare("
                SELECT class_id FROM classes 
                WHERE subject_id = :subject_id 
                  AND academic_year = :academic_year 
                  AND semester = :semester 
                  AND section = :section
            ");
            $stmt_check->execute([
                ':subject_id' => $subject_id,
                ':academic_year' => $academic_year,
                ':semester' => $semester,
                ':section' => $section
            ]);
            
            if ($stmt_check->fetch()) {
                // *** NEW FRIENDLY ERROR ***
                throw new Exception('<strong>Duplicate Class</strong><br>A class with this exact Subject, Year, Semester, and Section already exists.');
            }
            
            // 4. No duplicate, proceed with insert
            $stmt_insert = $conn->prepare("
                INSERT INTO classes (subject_id, professor_sid, academic_year, semester, section, is_finalized)
                VALUES (:subject_id, :professor_sid, :academic_year, :semester, :section, 0)
            ");
            
            $stmt_insert->execute([
                ':subject_id' => $subject_id,
                ':professor_sid' => $professor_sid,
                ':academic_year' => $academic_year,
                ':semester' => $semester,
                ':section' => $section
            ]);
            
            $new_class_id = $conn->lastInsertId();

            // 5. Check if the subject has a lab
            $stmt_check_lab = $conn->prepare("SELECT has_lab FROM subject WHERE subject_id = ?");
            $stmt_check_lab->execute([$subject_id]);
            $has_lab = $stmt_check_lab->fetchColumn();

            // 6. Define default components
            $default_components = [
                ['name' => 'QUIZ', 'weight' => 0.15],
                ['name' => 'CLASS PARTICIPATION', 'weight' => 0.15],
                ['name' => 'PERFORMANCE TASK', 'weight' => 0.30],
                ['name' => 'MAJOR EXAM', 'weight' => 0.40]
            ];
            $terms_to_create = ['Midterm', 'Final'];
            $sql_insert_comp = "INSERT INTO grade_components (class_id, term, component_type, component_name, weight) 
                                VALUES (:cid, :term, :type, :name, :weight)";
            $stmt_insert_comp = $conn->prepare($sql_insert_comp);

            foreach ($terms_to_create as $term) {
                foreach ($default_components as $comp) {
                    $stmt_insert_comp->execute([
                        ':cid' => $new_class_id, ':term' => $term, ':type' => 'Lecture', ':name' => $comp['name'], ':weight' => $comp['weight']
                    ]);
                    if ($has_lab) {
                        $stmt_insert_comp->execute([
                            ':cid' => $new_class_id, ':term' => $term, ':type' => 'Laboratory', ':name' => $comp['name'], ':weight' => $comp['weight']
                        ]);
                    }
                }
            }
            
            $conn->commit();
            $temp_message['type'] = 'success';
            $temp_message['text'] = 'Class successfully created! Default components have been added.';
            
        // *** NEW CATCH LOGIC ***
        } catch (PDOException $e) { // Catch database errors
            $conn->rollBack();
            $temp_message['text'] = 'Database Error: ' . $e->getMessage();
        } catch (Exception $e) { // Catch our custom validation errors
            $conn->rollBack();
            $temp_message['text'] = $e->getMessage(); // This will be our "Duplicate Class" message
        }
    }
    
    // --- AJAX/MODERN RESPONSE ---
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
    if ($is_ajax) {
        if ($temp_message['type'] === 'error') {
            http_response_code(400); 
        } else {
            http_response_code(200); 
        }
        header('Content-Type: application/json');
        echo json_encode($temp_message);
        exit();
    } else {
        $_SESSION['temp_message'] = $temp_message;
        header("Location: ph_courses.php");
        exit();
    }
}
// --- END OF CREATE HANDLER ---


// --- POST HANDLER: Update Existing Class ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_class'])) {
    
    // 1. Get and sanitize form data
    $class_id_to_update = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
    $professor_sid = filter_input(INPUT_POST, 'professor_sid', FILTER_VALIDATE_INT);
    $academic_year = trim($_POST['academic_year']);
    $semester = trim($_POST['semester']);
    $section_suffix = trim($_POST['section_suffix']);
    $section = $program_head_program . '-' . $section_suffix;

    // 2. Validate data
    if (empty($class_id_to_update) || empty($subject_id) || empty($professor_sid) || empty($academic_year) || empty($semester) || empty($section_suffix)) {
        $temp_message['text'] = 'All fields are required.';
    } else {
        try {
            // 3. Check for duplicates (excluding the class we are editing)
            $stmt_check = $conn->prepare("
                SELECT class_id FROM classes 
                WHERE subject_id = :subject_id 
                  AND academic_year = :academic_year 
                  AND semester = :semester 
                  AND section = :section
                  AND class_id != :class_id_to_update
            ");
            $stmt_check->execute([
                ':subject_id' => $subject_id,
                ':academic_year' => $academic_year,
                ':semester' => $semester,
                ':section' => $section,
                ':class_id_to_update' => $class_id_to_update
            ]);
            
            if ($stmt_check->fetch()) {
                 // *** NEW FRIENDLY ERROR ***
                throw new Exception('<strong>Duplicate Class</strong><br>Another class with this exact Subject, Year, Semester, and Section already exists.');
            }
            
            // 4. No duplicate, proceed with update
            $stmt_update = $conn->prepare("
                UPDATE classes SET
                    subject_id = :subject_id,
                    professor_sid = :professor_sid,
                    academic_year = :academic_year,
                    semester = :semester,
                    section = :section
                WHERE class_id = :class_id_to_update
            ");
            
            $stmt_update->execute([
                ':subject_id' => $subject_id,
                ':professor_sid' => $professor_sid,
                ':academic_year' => $academic_year,
                ':semester' => $semester,
                ':section' => $section,
                ':class_id_to_update' => $class_id_to_update
            ]);
            
            $temp_message['type'] = 'success';
            $temp_message['text'] = 'Class successfully updated!';
            
        // *** NEW CATCH LOGIC ***
        } catch (PDOException $e) { // Catch database errors
            $temp_message['text'] = 'Database error updating class: ' . $e->getMessage();
        } catch (Exception $e) { // Catch our validation error
            $temp_message['text'] = $e->getMessage();
        }
    }
    
    // --- AJAX/MODERN RESPONSE ---
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
    if ($is_ajax) {
        if ($temp_message['type'] === 'error') {
            http_response_code(400); 
        } else {
            http_response_code(200); 
        }
        header('Content-Type: application/json');
        echo json_encode($temp_message);
        exit();
    } else {
        $_SESSION['temp_message'] = $temp_message;
        header("Location: ph_courses.php");
        exit();
    }
}
// --- END OF UPDATE HANDLER ---


// --- POST HANDLER: Bulk Upload Classes ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_upload'])) {
    
    $temp_message = ['type' => 'error', 'text' => 'Unknown upload error.'];

    if (isset($_FILES['class_csv']) && $_FILES['class_csv']['error'] == UPLOAD_ERR_OK) {
        
        $file_tmp_path = $_FILES['class_csv']['tmp_name'];
        $file_mime_type = mime_content_type($file_tmp_path);

        if ($file_mime_type != 'text/csv' && $file_mime_type != 'application/csv' && $file_mime_type != 'text/plain') {
             $temp_message['text'] = 'Invalid file type. Please upload a .csv file.';
        } else {
            $file_handle = fopen($file_tmp_path, 'r');
            if ($file_handle === FALSE) {
                $temp_message['text'] = 'Error opening the uploaded file.';
            } else {
                
                fgetcsv($file_handle); // Skip header row
                
                $row_num = 1;
                $success_count = 0;
                $error_list = [];

                $stmt_find_subj = $conn->prepare("SELECT subject_id, has_lab FROM subject WHERE subject_code = ? AND program_head_id = ?");
                $stmt_find_prof = $conn->prepare("SELECT sid FROM users WHERE username = ? AND role = 'professor'");
                $stmt_check_dupe = $conn->prepare("SELECT class_id FROM classes WHERE subject_id = ? AND academic_year = ? AND semester = ? AND section = ?");
                $stmt_insert_class = $conn->prepare("INSERT INTO classes (subject_id, professor_sid, academic_year, semester, section, is_finalized) VALUES (?, ?, ?, ?, ?, 0)");
                $stmt_insert_comp = $conn->prepare("INSERT INTO grade_components (class_id, term, component_type, component_name, weight) VALUES (?, ?, ?, ?, ?)");

                $default_components = [
                    ['name' => 'QUIZ', 'weight' => 0.15],
                    ['name' => 'CLASS PARTICIPATION', 'weight' => 0.15],
                    ['name' => 'PERFORMANCE TASK', 'weight' => 0.30],
                    ['name' => 'MAJOR EXAM', 'weight' => 0.40]
                ];
                $terms_to_create = ['Midterm', 'Final'];

                while (($data = fgetcsv($file_handle)) !== FALSE) {
                    $row_num++;
                    if (count($data) < 5) {
                        // *** NEW FRIENDLY ERROR ***
                        $error_list[] = "Row $row_num: <strong>Missing Data</strong><br>This row only has " . count($data) . " columns. It needs 5 (Subject Code, Professor, Year, Semester, Section Suffix).";
                        continue;
                    }

                    $subject_code = trim($data[0]);
                    $prof_username = trim($data[1]);
                    $acad_year = trim($data[2]);
                    $semester = trim($data[3]);
                    $section_suffix = trim($data[4]);
                    $section = $program_head_program . '-' . $section_suffix;

                    $conn->beginTransaction();
                    try {
                        // 1. Find Subject ID
                        $stmt_find_subj->execute([$subject_code, $program_head_sid]);
                        $subject = $stmt_find_subj->fetch(PDO::FETCH_ASSOC);
                        if (!$subject) {
                            // *** NEW FRIENDLY ERROR ***
                            throw new Exception("<strong>Subject Not Found</strong><br>The subject code '$subject_code' does not exist or is not part of your program.");
                        }
                        $subject_id = $subject['subject_id'];
                        $has_lab = $subject['has_lab'];

                        // 2. Find Professor SID
                        $stmt_find_prof->execute([$prof_username]);
                        $professor_sid = $stmt_find_prof->fetchColumn();
                        if (!$professor_sid) {
                            // *** NEW FRIENDLY ERROR ***
                            throw new Exception("<strong>Professor Not Found</strong><br>The professor with username '$prof_username' could not be found.");
                        }

                        // 3. Check for duplicates
                        $stmt_check_dupe->execute([$subject_id, $acad_year, $semester, $section]);
                        if ($stmt_check_dupe->fetch()) {
                            // *** NEW FRIENDLY ERROR ***
                            throw new Exception("<strong>Duplicate Class</strong><br>A class with this exact Subject, Year, Semester, and Section already exists.");
                        }

                        // 4. Insert class
                        $stmt_insert_class->execute([$subject_id, $professor_sid, $acad_year, $semester, $section]);
                        $new_class_id = $conn->lastInsertId();

                        // 5. Add components
                        foreach ($terms_to_create as $term) {
                            foreach ($default_components as $comp) {
                                $stmt_insert_comp->execute([$new_class_id, $term, 'Lecture', $comp['name'], $comp['weight']]);
                                if ($has_lab) {
                                    $stmt_insert_comp->execute([$new_class_id, $term, 'Laboratory', $comp['name'], $comp['weight']]);
                                }
                            }
                        }
                        
                        $conn->commit();
                        $success_count++;

                    } catch (Exception $e) {
                        $conn->rollBack();
                        $error_list[] = "Row $row_num: " . $e->getMessage();
                    }
                }
                fclose($file_handle);

                // Format the final message
                $temp_message['type'] = 'success';
                $temp_message['text'] = "✅ Bulk upload complete: <strong>$success_count class(es) created.</strong>";
                if (!empty($error_list)) {
                    $temp_message['type'] = 'error'; // If any error, make the whole message an error
                    $temp_message['text'] = "⚠️ Bulk upload finished with errors. <strong>$success_count class(es) created.</strong><br>The following " . count($error_list) . " rows failed:";
                    $temp_message['text'] .= '<ul style="margin-top: 10px; max-height: 150px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
                    foreach ($error_list as $error) {
                        // Use html_entity_decode to render the <strong> tag
                        $temp_message['text'] .= '<li>' . html_entity_decode($error) . '</li>';
                    }
                    $temp_message['text'] .= '</ul>';
                }
            }
        }
    } else {
        // *** NEW FRIENDLY FILE ERRORS ***
        $error_code = $_FILES['class_csv']['error'] ?? UPLOAD_ERR_NO_FILE;
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $temp_message['text'] = '<strong>Upload Failed</strong><br>The selected file is too large.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $temp_message['text'] = '<strong>Upload Failed</strong><br>The file was only partially uploaded. Please try again.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $temp_message['text'] = '<strong>No File Selected</strong><br>You did not select a file to upload.';
                break;
            default:
                $temp_message['text'] = '<strong>Upload Failed</strong><br>An unknown error occurred during file upload.';
                break;
        }
    }

    // --- AJAX/MODERN RESPONSE ---
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
    if ($is_ajax) {
        if ($temp_message['type'] === 'error') {
            http_response_code(400); 
        } else {
            http_response_code(200);
        }
        header('Content-Type: application/json');
        echo json_encode($temp_message);
        exit();
    } else {
        $_SESSION['temp_message'] = $temp_message;
        header("Location: ph_courses.php");
        exit();
    }
}
// --- END OF BULK UPLOAD HANDLER ---


// 2. NOW, INCLUDE THE HEADER AND RENDER THE PAGE
$current_view = 'courses';
$page_title = 'Class Management';
include_once 'ph_header.php'; // Prints HTML

// --- Initialize Page-Specific Variables ---
$all_subjects = [];
$all_professors = [];
$course_list = [];
$edit_data = null; 

$message = '';
$message_type = '';
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}

if (empty($program_head_program)) {
     $message = "Your user account is not assigned to a program (e.g., BSIS or BSCPE). Please contact the administrator.";
     $message_type = 'error';
     $db_error = $message; 
}

// --- GET HANDLER: Populate Edit Form ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $class_id_to_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($class_id_to_edit) {
        $stmt_edit = $conn->prepare("SELECT * FROM classes WHERE class_id = ?");
        $stmt_edit->execute([$class_id_to_edit]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_data) {
            $message = 'Error: Class to edit not found.';
            $message_type = 'error';
        }
    }
}
// --- END: GET HANDLER ---


// --- (NEW) Generate Dynamic Year/Semester Options ---
$current_year = (int)date('Y');
$academic_years = [];
// Generate a range of years (e.g., 2 years past, 5 years future)
for ($i = -2; $i <= 5; $i++) {
    $start_year = $current_year + $i;
    $academic_years[] = $start_year . '-' . ($start_year + 1);
}
// Set the default AY to the current one (e.g., 2025-2026)
$default_ay = $current_year . '-' . ($current_year + 1);

// (NEW) Add 'Summer' option
$semesters = ['1st Semester', '2nd Semester', 'Summer'];


try {
    if (empty($db_error)) {
        // --- Fetch data for dropdowns ---
        $stmt_subjects = $conn->prepare("
            SELECT subject_id, subject_code, subject_name, has_lab 
            FROM subject 
            WHERE program_head_id = :ph_id 
            ORDER BY subject_code ASC
        ");
        $stmt_subjects->execute([':ph_id' => $program_head_sid]);
        $all_subjects = $stmt_subjects->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt_profs = $conn->prepare("SELECT u.sid, u.username, CONCAT(up.first_name, ' ', up.last_name) as full_name FROM users u JOIN user_profiles up ON u.sid = up.sid WHERE u.role = 'professor' ORDER BY up.last_name ASC");
        $stmt_profs->execute();
        $all_professors = $stmt_profs->fetchAll(PDO::FETCH_ASSOC);

        // --- Fetch existing classes for this PH ---
        $stmt_courses = $conn->prepare("
            SELECT 
                c.class_id,
                s.subject_code,
                s.subject_name,
                s.has_lab,
                CONCAT(up.first_name, ' ', up.last_name) AS professor_name,
                c.academic_year,
                c.semester,
                c.section,
                (SELECT COUNT(e.enrollment_id) FROM enrollment e WHERE e.class_id = c.class_id) AS student_count
            FROM classes c
            JOIN subject s ON c.subject_id = s.subject_id
            JOIN users u ON c.professor_sid = u.sid
            JOIN user_profiles up ON u.sid = up.sid
            WHERE s.program_head_id = :ph_id
            ORDER BY c.academic_year DESC, c.semester, c.section, s.subject_code
        ");
        $stmt_courses->bindParam(':ph_id', $program_head_sid, PDO::PARAM_INT);
        $stmt_courses->execute();
        $course_list = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $db_error = "Database error fetching course data: " . $e->getMessage();
}

if (!empty($db_error) && empty($message)) {
    $message = $db_error;
    $message_type = 'error';
}
?>

<div id="messageModal" class="modal-overlay">
    <div class="modal-content">
        <span class="modal-close">&times;</span>
        <div id="modalMessage" class="message">
        </div>
    </div>
</div>

<div id="confirmationModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 400px;">
        <h3 id="confirmModalTitle">Please Confirm</h3>
        <p id="confirmModalMessage" style="margin: 15px 0;">Are you sure?</p>
        <div class="confirm-button-container">
            <button id="confirmBtn" class="approve-btn">
                <i class="fa-solid fa-check"></i> Confirm
            </button>
            <button id="cancelBtn" class="reject-btn">
                <i class="fa-solid fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<section class="content-section">
    <button type="button" class="collapsible-header">
        <?php if ($edit_data): ?>
            <h2><i class="fa-solid fa-pen-to-square"></i> Edit Class</h2>
        <?php else: ?>
            <h2><i class="fa-solid fa-plus"></i> Create New Class</h2>
        <?php endif; ?>
        <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>
    
    <div class="collapsible-content">
        <p class="description">
            <?php if ($edit_data): ?>
                Update the details for this class.
            <?php else: ?>
                Create a new class offering for student enrollment.
            <?php endif; ?>
        </p>

        <?php if (!$edit_data && empty($db_error)): ?>
        <button type="button" class="info-toggle-btn" onclick="toggleInfo('create-info-box')">
            <i class="fa-solid fa-circle-info"></i> Show Form Instructions
        </button>
        <div class="message info" id="create-info-box" style="display: none; margin-bottom: 20px; font-size: 0.9em;">
            <strong>Note:</strong> The <strong>Section Suffix</strong> is the number *after* your program code (e.g., for 'BSIS-11', you would enter '11').
        </div>
        <?php endif; ?>

        <form 
            action="ph_courses.php" 
            method="POST"
            class="ph-form"
            <?php if ($edit_data): ?>
            id="update-class-form"
            data-confirm-message="Are you sure you want to save these changes? This may affect all students currently enrolled in this class."
            <?php else: ?>
            id="create-class-form"
            data-confirm-message="Are you sure you want to create this new class?"
            <?php endif; ?>
            <?php if (!empty($db_error) || empty($program_head_program)) echo 'hidden'; ?>
        >

            <?php if ($edit_data): ?>
                <input type="hidden" name="update_class" value="1">
                <input type="hidden" name="class_id" value="<?= $edit_data['class_id']; ?>">
            <?php else: ?>
                <input type="hidden" name="create_class" value="1">
            <?php endif; ?>

            <div>
                <label for="subject_id">Subject:</label>
                <select name="subject_id" id="subject_id" required>
                    <option value="">Select a Subject...</option>
                    <?php foreach ($all_subjects as $subject): ?>
                        <option value="<?= $subject['subject_id']; ?>" 
                                data-has-lab="<?= $subject['has_lab'] ?>"
                                <?php echo ($edit_data && $edit_data['subject_id'] == $subject['subject_id']) ? 'selected' : ''; ?>>
                            
                            <?= htmlspecialchars($subject['subject_code'] . ' - ' . $subject['subject_name']); ?>
                            <?= $subject['has_lab'] ? ' (w/ Lab)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if(empty($all_subjects)): ?>
                        <option value="" disabled>No subjects found.</option>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label for="professor_sid">Professor:</label>
                <select name="professor_sid" id="professor_sid" required>
                    <option value="">Select a Professor...</option>
                    <?php foreach ($all_professors as $prof): ?>
                        <option value="<?= $prof['sid']; ?>"
                                <?php echo ($edit_data && $edit_data['professor_sid'] == $prof['sid']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($prof['full_name']); ?> (<?= htmlspecialchars($prof['username']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="academic_year">Academic Year:</label>
                <select name="academic_year" id="academic_year" required>
                    <?php
                    // Use the $edit_data value if available, otherwise use the dynamic default
                    $selected_ay = $edit_data['academic_year'] ?? $default_ay; 
                    foreach ($academic_years as $ay): 
                    ?>
                        <option value="<?= $ay; ?>" <?php echo ($selected_ay == $ay) ? 'selected' : ''; ?>>
                            <?= $ay; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="semester">Semester:</label>
                <select name="semester" id="semester" required>
                    <?php
                    // Use $edit_data value if available, otherwise default to '1st Semester'
                    $selected_sem = $edit_data['semester'] ?? '1st Semester';
                    foreach ($semesters as $sem): 
                    ?>
                        <option value="<?= $sem; ?>" <?php echo ($selected_sem == $sem) ? 'selected' : ''; ?>>
                            <?= $sem; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-full-width">
                <label for="section_suffix">Section Suffix (e.g., 11, 12, 21):</label>
                <?php
                    // Pre-fill edit form by splitting "BSIS-11" into just "11"
                    $section_suffix_val = '';
                    if ($edit_data && !empty($edit_data['section'])) {
                        $parts = explode('-', $edit_data['section']);
                        $section_suffix_val = end($parts);
                    }
                ?>
                <input type="text" name="section_suffix" id="section_suffix" placeholder="e.g., 11" 
                       value="<?php echo htmlspecialchars($section_suffix_val); ?>" required>
            </div>

            <div class="form-button-container">
                <?php if ($edit_data): ?>
                    <a href="ph_courses.php" class="reject-btn">Cancel</a>
                    <button type="submit" name="update_class_btn" class="approve-btn" style="background-color: var(--success-green);">
                        <i class="fa-solid fa-save"></i> Save Changes
                    </button>
                <?php else: ?>
                    <button type="submit" name="create_class_btn" class="approve-btn" style="background-color: var(--panel-blue);">
                        <i class="fa-solid fa-plus"></i> Create Class & Components
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<section class="content-section">
    <button type="button" class="collapsible-header">
        <h2><i class="fa-solid fa-file-upload"></i> Bulk Upload Classes</h2>
        <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>
    
    <div class="collapsible-content">
        <p class="description">Upload a .csv file to create multiple classes at once.</p>
        
        <button type="button" class="info-toggle-btn" onclick="toggleInfo('bulk-info-box')">
            <i class="fa-solid fa-circle-info"></i> Show Bulk Upload Instructions
        </button>
        
        <form action="ph_courses.php" method="POST" enctype="multipart/form-data" 
              class="bulk-upload-form"
              <?php if (!empty($db_error) || empty($program_head_program)) echo 'hidden'; ?>>
            
            <div class="bulk-upload-info" id="bulk-info-box" style="display: none;">
                <strong>Required CSV Format:</strong>
                <p>The file must be a .csv with 5 columns in this exact order (with a header row):<br>
                    <code>subject_code, professor_username, academic_year, semester, section_suffix</code>
                </p>
                <p>
                    Example Row:<br>
                    <code>IT-101, jdelacruz, 2025-2026, 1st Semester, 11</code>
                    (The system will automatically create the key 'BSIS-11' if your program is BSIS)
                </p>
                
                <p style="margin-top: 15px;">
                    <a href="ph_courses.php?action=download_template" class="download-template-link">
                        <i class="fa-solid fa-file-csv"></i> Download CSV Template (with example)
                    </a>
                </p>
                
            </div>
            
            <div class="bulk-upload-input-row">
                <input type="file" name="class_csv" id="class_csv" accept=".csv" required>
                <button type="submit" name="bulk_upload" class="approve-btn">
                    <i class="fa-solid fa-upload"></i> Upload and Process File
                </button>
            </div>
        </form>
    </div>
</section>

<section class="content-section">
    <button type="button" class="collapsible-header">
        <h2><i class="fa-solid fa-book-open"></i> Existing Classes (<?php echo htmlspecialchars($program_head_program); ?>)</h2>
        <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>

    <div class="collapsible-content">
        <p class="description">View all classes already created and offered by your department.</p>
        <div class="table-container">
            <?php if (!empty($course_list)): ?>
            <table class="review-table">
                <thead>
                    <tr>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Professor</th>
                        <th>Acad. Year</th>
                        <th>Semester</th>
                        <th>Section Key</th>
                        <th>Students</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($course_list as $class_item): ?>
                <tr <?php echo ($edit_data && $edit_data['class_id'] == $class_item['class_id']) ? 'style="background-color: #e0f2fe;"' : ''; ?>>
                    <td><strong><?= htmlspecialchars($class_item['subject_code']); ?></strong></td>
                    <td>
                        <?= htmlspecialchars($class_item['subject_name']); ?>
                        <?php if ($class_item['has_lab']): ?>
                            <span class="lab-tag">(w/ Lab)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($class_item['professor_name']); ?></td>
                    <td><?= htmlspecialchars($class_item['academic_year']); ?></td>
                    <td><?= htmlspecialchars($class_item['semester']); ?></td>
                    <td><?= htmlspecialchars($class_item['section']); ?></td>
                    <td><?= htmlspecialchars($class_item['student_count']); ?></td>
                    <td class="action-buttons-flex">
                        <a href="class_details.php?id=<?= $class_item['class_id']; ?>" class="action-btn-view" title="View Roster">
                            <i class="fa-solid fa-users"></i> View
                        </a>
                        <a href="ph_courses.php?action=edit&id=<?= $class_item['class_id']; ?>" class="action-btn-edit" title="Edit Class Details">
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php elseif(empty($db_error)): ?>
                <p class="no-data">No classes created yet. Use the form above to add one.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<style>
    /* --- (NEW) Collapsible Sections --- */
    .collapsible-header {
        background: none;
        border: none;
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0;
        cursor: pointer;
        text-align: left;
        margin-bottom: 1.5rem; /* Space below header */
    }
    .collapsible-header h2 {
        margin: 0;
        font-size: 1.4rem; 
        color: var(--text-dark);
    }
    .collapsible-header .collapse-icon {
        font-size: 1.2rem;
        color: var(--panel-blue);
        transition: transform 0.3s ease;
        margin-left: 15px; /* Add some space */
    }
    
    /* When collapsed */
    .collapsible-content.collapsed {
        display: none;
    }
    .collapsible-header:not(.active) .collapse-icon {
        transform: rotate(-90deg);
    }
    
    /* Fade-in animation for content */
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .collapsible-content {
        animation: fadeIn 0.3s ease;
    }
    
    /* Add bottom margin back to all sections */
    section.content-section {
        margin-bottom: 2rem;
    }


    /* Form Grid */
    .ph-form {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        background: var(--bg-light);
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e0e6ed;
    }
    .ph-form label {
        font-weight: 600; 
        display: block; 
        margin-bottom: 5px;
        font-size: 0.9rem;
    }
    .ph-form input[type="text"],
    .ph-form select {
        width: 100%; 
        padding: 10px; 
        border: 1px solid #ccc; 
        border-radius: 6px;
        font-size: 0.95rem;
    }
    .form-full-width {
        grid-column: 1 / -1;
    }
    .form-button-container {
        grid-column: 1 / -1;
        text-align: right;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
    }
    .form-button-container .reject-btn {
        text-decoration: none;
        padding: 10px 15px;
    }

    /* Info Toggle Button */
    .info-toggle-btn {
        background: none;
        border: none;
        color: var(--panel-blue);
        cursor: pointer;
        font-size: 0.9rem;
        font-weight: 600;
        padding: 5px 0;
        margin-bottom: 15px;
        display: block;
    }
    .info-toggle-btn:hover {
        text-decoration: underline;
    }
    .info-toggle-btn i {
        margin-right: 5px;
    }

    /* Bulk Upload */
    .bulk-upload-form {
        background: var(--bg-light);
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e0e6ed;
    }
    .bulk-upload-info {
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 20px;
    }
    .bulk-upload-info p { margin: 5px 0 0 0; font-size: 0.9rem; line-height: 1.5; }
    .bulk-upload-info code {
        background: #e9ecef;
        padding: 2px 5px;
        border-radius: 3px;
        font-family: monospace;
    }
    .bulk-upload-input-row {
        display: flex;
        gap: 15px;
        align-items: center;
    }
    .bulk-upload-input-row input[type="file"] {
        flex-grow: 1;
        border: 1px solid #ccc;
        border-radius: 6px;
        padding: 8px;
        background: #fff;
    }

    /* --- (NEW) CSV Download Link Style --- */
    .download-template-link {
        text-decoration: none;
        font-weight: 600;
        color: var(--panel-blue);
        font-size: 0.9rem;
    }
    .download-template-link:hover {
        text-decoration: underline;
    }
    .download-template-link i {
        margin-right: 5px;
    }

    /* Table Actions */
    .lab-tag {
        color: var(--panel-blue); 
        font-size: 0.8rem; 
        font-weight: bold;
    }
    .action-buttons-flex {
        display: flex;
        gap: 5px;
        justify-content: center;
    }
    .action-buttons-flex a {
        padding: 6px 10px;
        text-decoration: none;
        color: white;
        border-radius: 5px;
        font-size: 0.85rem;
    }
    .action-btn-view {
        background-color: var(--panel-blue);
    }
    .action-btn-edit {
        background-color: #f59e0b; /* Amber */
    }

    /* Modal Styles */
    .modal-overlay {
        display: none; /* Hidden by default */
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        justify-content: center;
        align-items: center;
    }
    .modal-content {
        background-color: #fff;
        padding: 25px;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        min-width: 300px;
        max-width: 500px;
        position: relative;
    }
    .modal-close {
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 1.5rem;
        font-weight: bold;
        color: #aaa;
        cursor: pointer;
    }
    .modal-close:hover {
        color: #333;
    }
    #modalMessage.message {
        margin: 0;
        padding: 15px;
        border-left-width: 5px;
    }
    #modalMessage.success {
        border-left-color: var(--success-green, #28a745);
        background-color: #f0fff4;
    }
    #modalMessage.error {
        border-left-color: var(--error-red, #dc3545);
        background-color: #fff8f8;
    }
    /* Make bold tags work in error message */
    #modalMessage.error strong, #modalMessage.error b {
        font-weight: 700;
        display: block;
        font-size: 1.1em;
        margin-bottom: 5px;
    }
    /* Make bold tags work in bulk upload list */
    .message ul li strong {
        font-weight: 700;
        display: block;
        font-size: 1.05em;
        margin-bottom: 3px;
    }


    /* New Confirmation Modal Buttons */
    .confirm-button-container {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 20px;
    }
    .confirm-button-container .approve-btn {
        background-color: var(--panel-blue, #007bff);
    }
    .confirm-button-container .reject-btn {
        background-color: #6c757d;
    }
    .confirm-button-container button {
        font-size: 0.9rem;
        padding: 10px 15px;
    }

    /* Spinner for loading buttons */
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    .fa-spinner {
        animation: spin 1s linear infinite;
    }
</style>

<script>
    // --- Pass PHP messages to JavaScript (from session) ---
    const phpMessage = <?php echo json_encode($message); ?>;
    const phpMessageType = <?php echo json_encode($message_type); ?>;

    // --- State Variable ---
    let formToSubmit = null;
    let originalButtonHtml = '';

    // --- Get All Modal Elements ---
    const resultModal = document.getElementById('messageModal');
    const resultMsgDiv = document.getElementById('modalMessage');
    const resultModalCloseBtn = resultModal.querySelector('.modal-close');

    const confirmModal = document.getElementById('confirmationModal');
    const confirmMsgDiv = document.getElementById('confirmModalMessage');
    const confirmBtn = document.getElementById('confirmBtn');
    const cancelBtn = document.getElementById('cancelBtn');

    // --- Function to toggle info boxes (Your original function) ---
    function toggleInfo(boxId) {
        var infoBox = document.getElementById(boxId);
        if (infoBox.style.display === 'none') {
            infoBox.style.display = 'block';
        } else {
            infoBox.style.display = 'none';
        }
    }

    // --- Function to show the RESULT modal (success/error) ---
    function showModalMessage(message, type) {
        resultMsgDiv.innerHTML = message;
        resultMsgDiv.className = 'message ' + type;
        resultModal.style.display = 'flex';
    }
    
    // --- Function to close the RESULT modal ---
    function closeModal() {
        resultModal.style.display = 'none';
    }
    
    // --- Function to show the CONFIRMATION modal ---
    function showConfirmationModal(form) {
        formToSubmit = form; // Store the form that needs submitting
        const message = form.dataset.confirmMessage || 'Are you sure?';
        confirmMsgDiv.textContent = message;
        confirmModal.style.display = 'flex';
    }

    // --- Function to close the CONFIRMATION modal ---
    function closeConfirmationModal() {
        confirmModal.style.display = 'none';
        formToSubmit = null;
    }

    // --- Asynchronous AJAX Form Submission Handler ---
    async function handleFormSubmit(form) {
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        originalButtonHtml = submitButton.innerHTML; // Save original button content

        // 1. Show loading state
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

        try {
            // --- (NEW) Use form's action attribute ---
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest' // Tells PHP this is AJAX
                }
            });

            const result = await response.json();

            if (!response.ok) {
                // Server returned an error (400, 500, etc.)
                throw new Error(result.text || 'An unknown error occurred.');
            }

            // 2. SUCCESS: Show success message
            showModalMessage(result.text, result.type);
            
            // Reload the page after 1.5s to show the new data in the table
            setTimeout(() => {
                location.reload();
            }, 1500);

        } catch (error) {
            // 3. FAILURE: Show error message
            showModalMessage(error.message, 'error');
            // Restore button immediately on error
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonHtml;
        }
    }


    // --- Logic to run on page load ---
    document.addEventListener('DOMContentLoaded', () => {

        // --- 1. Check for session messages from PHP (Your original logic) ---
        if (phpMessage) {
            showModalMessage(phpMessage, phpMessageType);
        }
        
        // --- (NEW) Check if close button exists before adding listener ---
        if (resultModalCloseBtn) {
            resultModalCloseBtn.addEventListener('click', closeModal);
        }

        
        // --- 2. NEW: Attach listeners for confirmation modal buttons ---
        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeConfirmationModal);
        }
        
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                if (formToSubmit) {
                    handleFormSubmit(formToSubmit);
                }
                closeConfirmationModal();
            });
        }


        // --- 3. NEW: Intercept form submissions ---
        const createForm = document.getElementById('create-class-form');
        const updateForm = document.getElementById('update-class-form');
        
        if (createForm) {
            createForm.addEventListener('submit', (e) => {
                e.preventDefault(); // Stop the default page reload
                showConfirmationModal(createForm); // Show our new modal instead
            });
        }
        
        if (updateForm) {
            updateForm.addEventListener('submit', (e) => {
                e.preventDefault(); // Stop the default page reload
                showConfirmationModal(updateForm); // Show our new modal instead
            });
        }
        
        // (Note: The bulk upload form is not intercepted here, as file uploads
        // with AJAX are more complex. It will still work with the PHP fallback.)

        // --- 4. NEW: Collapsible Section Handler ---
        document.querySelectorAll('.collapsible-header').forEach(header => {
            const content = header.nextElementSibling;
            if (!content) return;

            // Check if this is the "Create" or "Edit" form section
            const isCreateOrEdit = header.querySelector('i.fa-plus, i.fa-pen-to-square');

            if (isCreateOrEdit) {
                // Start open
                header.classList.add('active');
                content.classList.remove('collapsed');
            } else {
                // Start collapsed
                header.classList.remove('active');
                content.classList.add('collapsed');
            }

            header.addEventListener('click', () => {
                content.classList.toggle('collapsed');
                header.classList.toggle('active');
            });
        });
    });
</script>

<?php include 'ph_footer.php'; // Include the footer template ?>