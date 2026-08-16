<?php
/**
 * PHP 8.1+ Instructor Dashboard - Fully Integrated System
 * PAGE: teacher_manage_students.php
 * ---
 * MODIFICATION LOG:
 * 1. Bulk Selection (Checkboxes), Bulk Transfer, & Bulk Remove logic implemented.
 * 2. Unified single and bulk processing to ensure safety checks apply to all.
 * 3. FIX: Enrollment column changed from 'enrolled_at' to 'date_enrolled'.
 * 4. IMPROVEMENT: Added searchable list for UNENROLLED students in the "Add Student" modal.
 * 5. FEATURE: Roster includes student's 'program' for JS-side transfer eligibility checks.
 * 6. FEATURE: Transfer buttons disabled if selected students are NOT BSIS or BSCPE majors.
 * 7. FEATURE: ADD STUDENT modal now uses a reliable **Click-and-Add** method for multi-selection.
 * 8. ADDED: Placeholder for a CSV Bulk Upload feature with user instructions and CSV template.
 * 9. CRITICAL CHANGE: Enrollment logic and form modified to implement a **two-step process** * (Select Class first, then fetch students) to ensure students are only available if 
 * they are not yet enrolled in the subject associated with the selected class(es).
 */

session_start();
// NOTE: Assuming db.php contains the PDO connection $conn
include 'db.php'; 

// =========================================================================
// 1. GLOBAL SETUP & ACCESS CONTROL
// =========================================================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    header("Location: index.php");
    exit();
}

$current_user_id = $_SESSION['sid'] ?? $_SESSION['user_id'] ?? 0;
$teacher_name = $_SESSION['name'] ?? 'Professor';
$allowed_programs = ['BSIS', 'BSCPE'];

// Fetch total unread messages (initialized to 0, recalculated in try block)
$total_unread = 0;

// =========================================================================
// 2. EXPORT HANDLER
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export_single_student_grades') {
    $exp_sid = $_GET['student_sid'];
    $exp_class_id = $_GET['class_id'];

    // Security check: Ensure the class belongs to the current professor
    $stmt_check = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND professor_sid = ?");
    $stmt_check->execute([$exp_class_id, $current_user_id]);
    
    if ($stmt_check->rowCount() > 0) {
        $stmt_info = $conn->prepare("SELECT first_name, last_name FROM user_profiles WHERE sid = ?");
        $stmt_info->execute([$exp_sid]);
        $student_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
        
        if ($student_info) {
             $filename = "Grades_" . $student_info['last_name'] . "_" . $exp_sid . ".csv";

            $stmt_grades = $conn->prepare("
                SELECT gc.term, gc.component_name, gc.weight, rs.item_name, rs.score, rs.max_score, (rs.score / rs.max_score * 100) as percentage
                FROM raw_scores rs
                JOIN grade_components gc ON rs.component_id = gc.component_id
                JOIN enrollment e ON rs.enrollment_id = e.enrollment_id
                WHERE e.student_sid = ? AND e.class_id = ?
                ORDER BY gc.term, gc.component_name
            ");
            $stmt_grades->execute([$exp_sid, $exp_class_id]);
            $grades = $stmt_grades->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
            
            fputcsv($output, ['Student Name', $student_info['last_name'] . ', ' . $student_info['first_name']]);
            fputcsv($output, ['Student ID', $exp_sid]);
            fputcsv($output, []); 
            fputcsv($output, ['Term', 'Component', 'Weight', 'Item Name', 'Raw Score', 'Max Score', 'Percentage']);

            if (count($grades) > 0) {
                foreach ($grades as $row) {
                    fputcsv($output, [
                        $row['term'], 
                        $row['component_name'], 
                        ($row['weight'] * 100) . '%', 
                        $row['item_name'], 
                        $row['score'], 
                        $row['max_score'], 
                        number_format($row['percentage'], 2) . '%'
                    ]);
                }
            } else {
                fputcsv($output, ['No grades recorded for this student.']);
            }
            fclose($output);
            exit(); 
        }
    }
}

$db_error = "";
$success_message = "";
$error_message = "";
$search_query = $_GET['search'] ?? '';
$conversations = [];
$students = [];
$instructor_classes = [];
$available_students = []; // Holds list of un-enrolled students
$target_enroll_class_id = $_GET['target_enroll_class'] ?? null; // New GET parameter

try {
    if (!$conn) {
        throw new PDOException("Database connection object (\$conn) is not initialized.");
    }
    
    // --- Fetch conversations & Unread Count ---
    $stmt_conv_fetch = $conn->prepare("SELECT c.conversation_id, (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.conversation_id AND m.receiver_id = :current_user_id AND m.is_read = 0) AS unread_count FROM conversations c WHERE c.user_1_id = :current_user_id OR c.user_2_id = :current_user_id");
    $stmt_conv_fetch->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
    $stmt_conv_fetch->execute();
    $conversations = $stmt_conv_fetch->fetchAll(PDO::FETCH_ASSOC);
    $total_unread = array_sum(array_column($conversations, 'unread_count'));


    // --- Fetch Classes (For Enrollment and Transfer Modals) ---
    $stmt_classes = $conn->prepare("
        SELECT c.class_id, s.subject_code, s.subject_name, c.section, c.professor_sid, up.last_name AS prof_lastname
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        LEFT JOIN user_profiles up ON c.professor_sid = up.sid
        WHERE s.subject_id IN (SELECT DISTINCT subject_id FROM classes WHERE professor_sid = :professor_sid)
        ORDER BY s.subject_code, c.section
    ");
    $stmt_classes->execute([':professor_sid' => $current_user_id]);
    $instructor_classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

    // --- Fetch List of UNENROLLED Students (For Add Student Modal) ---
    // This runs ONLY if a target class ID is passed via the URL query string, 
    // ensuring the student list is correctly filtered by the subject.
    if ($target_enroll_class_id) {
        // First, get the subject_id of the target class
        $stmt_target_subj = $conn->prepare("SELECT subject_id FROM classes WHERE class_id = ?");
        $stmt_target_subj->execute([$target_enroll_class_id]);
        $target_subject_id = $stmt_target_subj->fetchColumn();

        if ($target_subject_id) {
            // Fetch students who are NOT currently enrolled in ANY class with the same subject_id
            $sql_available_students = "
                SELECT u.sid, up.first_name, up.last_name, up.program, up.academic_year
                FROM users u
                JOIN user_profiles up ON u.sid = up.sid
                WHERE u.role = 'student'
                AND u.sid NOT IN (
                    SELECT e.student_sid FROM enrollment e 
                    JOIN classes c ON e.class_id = c.class_id
                    WHERE c.subject_id = ?
                )
                ORDER BY up.last_name
            ";
            
            $stmt_available = $conn->prepare($sql_available_students);
            $stmt_available->execute([$target_subject_id]);
            $available_students = $stmt_available->fetchAll(PDO::FETCH_ASSOC);
        }
    }


    // =========================================================================
    // A. HANDLE POST REQUESTS (ADD, TRANSFER, REMOVE)
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // 1. ADD STUDENT (Handles array of IDs)
        if (isset($_POST['action']) && $_POST['action'] === 'add_student') {
            
            // Normalize student_sid to an array
            $target_sids = $_POST['student_sid'];
            if (!is_array($target_sids)) {
                $target_sids = [$target_sids];
            }
            // MODIFICATION: Normalize class_id to an array (because it is now multi-select)
            $target_class_ids = $_POST['class_id'];
            if (!is_array($target_class_ids)) {
                $target_class_ids = [$target_class_ids];
            }
            
            $success_count = 0;
            $fail_count = 0;

            try {
                $conn->beginTransaction();

                foreach ($target_sids as $target_sid) {
                    $target_sid = trim($target_sid);

                    // Basic validation: is it a student user?
                    $stmt_check_user = $conn->prepare("SELECT sid FROM users WHERE sid = ? AND role = 'student'");
                    $stmt_check_user->execute([$target_sid]);
                    
                    if ($stmt_check_user->rowCount() > 0) {
                        
                        // MODIFICATION: Loop through ALL selected classes
                        foreach ($target_class_ids as $target_class_id) {
                            
                            // Check if already enrolled in this class
                            $stmt_check_enroll = $conn->prepare("SELECT enrollment_id FROM enrollment WHERE student_sid = ? AND class_id = ?");
                            $stmt_check_enroll->execute([$target_sid, $target_class_id]);
                            
                            if ($stmt_check_enroll->rowCount() == 0) {
                                $stmt_add = $conn->prepare("INSERT INTO enrollment (student_sid, class_id, date_enrolled) VALUES (?, ?, NOW())");
                                $stmt_add->execute([$target_sid, $target_class_id]);
                                $success_count++;
                            } else {
                                $fail_count++; // Already enrolled
                            }
                        }
                    } else {
                        $fail_count++; // Not found or not a student
                    }
                }

                $conn->commit();
                // Update success message to reflect batch enrollment counts
                $num_students = count($target_sids);
                $num_classes = count($target_class_ids);
                $success_message = "Successfully enrolled **{$num_students}** student(s) into **{$num_classes}** class(es).";
                
                if ($fail_count > 0) {
                    $error_message = "Failed to enroll **$fail_count** record(s) (already enrolled or ID invalid).";
                }

                // Redirect after successful POST to clear GET parameters and prevent resubmission
                header("Location: teacher_manage_students.php");
                exit();

            } catch (Exception $e) {
                $conn->rollBack();
                $db_error = "Error during bulk enrollment: " . $e->getMessage();
            }
        }

        // 2. BULK / SINGLE TRANSFER (Unchanged Logic)
        if (isset($_POST['action']) && $_POST['action'] === 'transfer_student') {
            $student_sids = $_POST['student_sid']; 
            $new_class_id = $_POST['new_class_id'];
            
            if (!is_array($student_sids)) {
                $student_sids = [$student_sids];
            }

            $success_count = 0;
            $fail_count = 0;

            try {
                $conn->beginTransaction();

                // 1. Get Subject ID of New Class to determine the context of enrollment
                $stmt_subj = $conn->prepare("SELECT subject_id FROM classes WHERE class_id = ?");
                $stmt_subj->execute([$new_class_id]);
                $subject_id = $stmt_subj->fetchColumn();

                foreach ($student_sids as $sid) {
                    // 2. Find existing enrollment for this student in a class with the SAME Subject ID
                    $stmt_find_enroll = $conn->prepare("
                        SELECT e.enrollment_id, e.class_id 
                        FROM enrollment e 
                        JOIN classes c ON e.class_id = c.class_id 
                        WHERE e.student_sid = ? AND c.subject_id = ?
                    ");
                    $stmt_find_enroll->execute([$sid, $subject_id]);
                    $enrollment_data = $stmt_find_enroll->fetch(PDO::FETCH_ASSOC);

                    if ($enrollment_data) {
                        $enrollment_id = $enrollment_data['enrollment_id'];
                        $old_class_id = $enrollment_data['class_id'];

                        if ($old_class_id == $new_class_id) {
                            continue; // Skip if already in the target class
                        }

                        // Wipe Grades (Safety precaution for class transfer)
                        $stmt_clean = $conn->prepare("DELETE FROM raw_scores WHERE enrollment_id = ?");
                        $stmt_clean->execute([$enrollment_id]);

                        // Update Class
                        $stmt_update = $conn->prepare("UPDATE enrollment SET class_id = ? WHERE enrollment_id = ?");
                        $stmt_update->execute([$new_class_id, $enrollment_id]);
                        
                        $success_count++;
                    } else {
                        $fail_count++; // Student not found in a related class enrollment
                    }
                }

                $conn->commit();
                $success_message = "Successfully transferred $success_count student(s). Grades were reset for safety.";
                if ($fail_count > 0) $error_message = "Failed to transfer $fail_count student(s) (not enrolled in relevant subject).";

            } catch (Exception $e) {
                $conn->rollBack();
                $db_error = "Transfer Error: " . $e->getMessage();
            }
        }

        // 3. BULK / SINGLE REMOVE (Unchanged)
        if (isset($_POST['action']) && $_POST['action'] === 'remove_student') {
            $student_sids = $_POST['student_sid'];
            // Normalize to array
            if (!is_array($student_sids)) {
                $student_sids = [$student_sids];
            }
            
            $remove_count = 0;

            try {
                $conn->beginTransaction();

                foreach ($student_sids as $sid) {
                    // Find enrollment(s) for this student in classes taught by THIS professor
                    $stmt_find_enrolls = $conn->prepare("
                        SELECT e.enrollment_id 
                        FROM enrollment e 
                        JOIN classes c ON e.class_id = c.class_id 
                        WHERE e.student_sid = ? AND c.professor_sid = ?
                    ");
                    $stmt_find_enrolls->execute([$sid, $current_user_id]);
                    $enrollments = $stmt_find_enrolls->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($enrollments as $eid) {
                        // Delete Scores (CASCADE DELETE IS RECOMMENDED IN SCHEMA, but manually deleting here for safety)
                        $stmt_del_scores = $conn->prepare("DELETE FROM raw_scores WHERE enrollment_id = ?");
                        $stmt_del_scores->execute([$eid]);
                        // Delete Enrollment
                        $stmt_del_enroll = $conn->prepare("DELETE FROM enrollment WHERE enrollment_id = ?");
                        $stmt_del_enroll->execute([$eid]);
                        $remove_count++;
                    }
                }

                $conn->commit();
                $success_message = "Successfully un-enrolled $remove_count student record(s).";

            } catch (PDOException $e) {
                $conn->rollBack();
                $db_error = "Removal Error: " . $e->getMessage();
            }
        }
    }

    // =========================================================================
    // B. FETCH STUDENT DATA (Roster List)
    // =========================================================================
    $sql = "SELECT DISTINCT u.sid, p.first_name as fname, p.last_name as lname, p.program, 
                    s.subject_code, c.section, c.class_id
            FROM enrollment e
            JOIN classes c ON e.class_id = c.class_id
            JOIN subject s ON c.subject_id = s.subject_id
            JOIN users u ON e.student_sid = u.sid
            JOIN user_profiles p ON u.sid = p.sid
            WHERE c.professor_sid = :current_user_id AND u.role = 'student' ";

    $params = [':current_user_id' => $current_user_id];
    
    if ($search_query) {
        $sql .= " AND (u.sid LIKE :search OR p.first_name LIKE :search OR p.last_name LIKE :search OR c.section LIKE :search) ";
        $params[':search'] = '%' . $search_query . '%';
    }
    
    $sql .= " ORDER BY c.section, p.last_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instructor Portal - Manage Students</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* --- CSS Styles --- */
:root{
    --nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f4f7fa;
    --white:#fff; --text-dark:#102a43; --text-light:#627d98;
    --accent-light:#4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);
    --shadow-md: 0 6px 15px rgba(0,0,0,0.08); --critical:#b91c1c;
    --warning:#a16207; --muted:#888; --success-green: #166534;
    --border-color: #e5e7eb;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100vh; overflow-x: hidden; }
body{font-family: 'Inter', 'Segoe UI', sans-serif;margin:0;background:var(--bg-light);color:var(--text-dark); display: flex;}
.sidebar { width: 260px; background: var(--nav-dark); color: var(--white); display: flex; flex-direction: column; flex-shrink: 0; height: 100vh; transition: transform 0.3s ease; z-index: 2000; position: fixed; top: 0; left: 0; }
.sidebar-header { padding: 24px 20px; display: flex; align-items: center; gap: 15px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar-header img.sidebar-logo { width: 40px; height: 40px; border-radius: 4px; }
.sidebar-header h2 { font-size: 1.15rem; font-weight: 600; line-height: 1.3; }
.sidebar-header h2 span { font-size: 0.8rem; font-weight: 400; color: #9ac8ee; display: block; }
.sidebar-nav { flex-grow: 1; padding: 20px 0; overflow-y: auto; }
.sidebar-nav ul { list-style: none; }
.sidebar-nav li { margin: 0 10px; }
.sidebar-nav a { display: flex; align-items: center; gap: 15px; padding: 14px 20px; color: #e0f2fe; text-decoration: none; font-weight: 500; border-radius: 8px; transition: background 0.2s ease, color 0.2s ease; }
.sidebar-nav a:hover { background: rgba(255, 255, 255, 0.1); }
.sidebar-nav a.active { background: var(--accent-light); color: var(--nav-dark); font-weight: 700; }
.sidebar-footer { padding: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar-footer a { display: block; text-align: center; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; color: var(--white); text-decoration: none; font-weight: 500; transition: background 0.2s ease; }
.main-content { flex-grow: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; margin-left: 260px; width: calc(100% - 260px); transition: margin-left 0.3s ease; }
.header { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; background: var(--white); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
.menu-toggle { display: none; font-size: 1.5rem; background: none; border: none; cursor: pointer; color: var(--text-dark); }
.header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
.user-profile { display: flex; align-items: center; gap: 12px; }
.user-profile .icon-button { font-size: 1.2rem; color: var(--text-light); text-decoration: none; padding: 8px; border-radius: 50%; position: relative; }
.user-profile .icon-button:hover { background: var(--bg-light); }
.user-profile .unread-badge { background: var(--critical); color: var(--white); position: absolute; top: 0; right: 0; border-radius: 50%; padding: 2px 6px; font-size: 0.7rem; font-weight: 700; }
.user-profile-info { text-align: right; }
.user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
.user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
.page-view { flex-grow: 1; overflow-y: auto; padding: 24px; }
.content-section { background: var(--white); border-radius: 12px; padding: 24px; box-shadow: var(--shadow); margin-bottom: 24px; border: 1px solid var(--border-color); }
.content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
.message-box{padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold;}
.error-message{background: #fef2f2; color: var(--critical); border: 1px solid #fecaca;}
.success-message{background: #f0fdf4; color: var(--success-green); border: 1px solid #bbf7d0;}
input[type="text"], input[type="search"], select { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px; background: #f9fafb; }
#add_sid { height: 200px; } 
.filter-form { display: flex; gap: 10px; align-items: center; }
.action-button { background: var(--panel-blue); color: var(--white); border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;}
.action-button:hover { background: var(--nav-dark); }
.action-button.add-btn { background: var(--success-green); }
.action-button.add-btn:hover { background: #15803d; }
.btn-icon { padding: 6px 10px; font-size: 0.9rem; border-radius: 4px; margin-right: 5px; }
.btn-transfer { background: var(--warning); color: #fff; }
.btn-remove { background: var(--critical); color: #fff; }
.action-button:disabled { background: #ccc; cursor: not-allowed; opacity: 0.5 !important; } 
.table-container { width: 100%; overflow-x: auto; border: 1px solid var(--border-color); border-radius: 8px; }
.data-table{width:100%;border-collapse:collapse; background:var(--white); min-width: 700px; }
.data-table th{background: #f9fafb; color: var(--text-light); padding:12px 15px;text-align:left;font-weight:600;text-transform:uppercase;font-size:0.75rem; border-bottom: 1px solid var(--border-color);}
.data-table td{border-bottom:1px solid var(--border-color);padding:15px; vertical-align: middle; font-size: 0.9rem;}
.data-table tbody tr:last-child td { border-bottom: none; }
.no-data { text-align: center; padding: 30px; color: var(--text-light); }
.bulk-actions { 
    background: #eef2ff; padding: 10px 15px; border-radius: 8px; margin-bottom: 15px; 
    display: flex; gap: 10px; align-items: center; border: 1px solid #c7d2fe;
}
.bulk-actions span { font-weight: 600; color: #312e81; margin-right: auto; font-size: 0.9rem; }
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 3000; }
.modal-container { background: white; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden; }
.modal-header { padding: 15px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: #f9fafb; }
.modal-header h3 { margin: 0; font-size: 1.1rem; color: var(--text-dark); }
.modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-light); }
.modal-body { padding: 20px; }
.modal-footer { padding: 15px 20px; border-top: 1px solid var(--border-color); background: #f9fafb; text-align: right; }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 0.9rem; }
.sidebar-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1999; display: none; }
.sidebar-overlay.is-open { display: block; }
.lg-hidden { display: none; }
.selected-student-tag {
    display: inline-flex; align-items: center; background: #eef2ff; color: #312e81; 
    padding: 4px 8px; border-radius: 4px; margin-right: 5px; margin-bottom: 5px;
    font-size: 0.85rem; font-weight: 500;
}
.remove-tag-btn {
    background: none; border: none; color: #312e81; font-size: 0.9rem; margin-left: 5px;
    cursor: pointer; opacity: 0.7; transition: opacity 0.1s;
}
.remove-tag-btn:hover { opacity: 1; color: var(--critical); }
@media (max-width: 1024px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.is-open { transform: translateX(0); }
    .main-content { margin-left: 0; width: 100%; }
    .lg-hidden, .menu-toggle { display: block; }
    .filter-form { flex-direction: column; align-items: stretch; }
    .bulk-actions { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

<div id="add-student-modal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="teacher_manage_students.php" id="enroll-form">
            <div class="modal-header">
                <h3><i class="fa-solid fa-user-plus"></i> Enroll Student (Batch Add)</h3>
                <button type="button" class="modal-close" onclick="closeModal('add-student-modal')">&times;</button>
            </div>
            
            <div class="modal-body">
                <input type="hidden" name="action" value="add_student">
                
                <div id="bulk-enroll-inputs"></div>

                <div class="form-group">
                    <label for="add_class_select">Assign to Class First (Hold Ctrl/Cmd to select multiple)</label>
                    <select name="class_id[]" id="add_class_select" multiple size="4" required onchange="updateStudentListBasedOnSelection()">
                        <option value="" disabled>-- Select Section --</option>
                        <?php foreach ($instructor_classes as $cls): ?>
                            <?php if ($cls['professor_sid'] == $current_user_id): ?>
                            <option value="<?= $cls['class_id'] ?>" data-subject-code="<?= htmlspecialchars($cls['subject_code']) ?>">
                                <?= htmlspecialchars($cls['subject_code'] . " - " . $cls['section']) ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size: 0.8rem; color: var(--panel-blue); margin-top: 5px;">
                        <i class="fa-solid fa-lightbulb"></i> Students available below are **not yet enrolled** in the subject(s) you select above.
                    </p>
                </div>
                
                <hr style="margin: 15px 0; border-top: 1px solid var(--border-color);">

                <div class="form-group" id="student-select-area" style="<?= empty($available_students) ? 'opacity: 0.5; pointer-events: none;' : '' ?>">
                    <label for="search_student_input">Search & Select Student</label>
                    <input type="text" id="search_student_input" placeholder="Start typing ID or Name to filter the list below..." onkeyup="filterStudentOptions(this.value)" <?= empty($available_students) ? 'disabled' : '' ?>>
                    
                    <select id="add_sid" size="10" style="margin-top: 5px;" <?= empty($available_students) ? 'disabled' : '' ?>>
                        <option value="" disabled>-- Select Student to Add --</option>
                        <?php if (empty($available_students)): ?>
                             <option value="" disabled>-- Select a class above to see available students --</option>
                        <?php else: ?>
                            <?php foreach ($available_students as $avail_st): ?>
                            <option value="<?= $avail_st['sid'] ?>" data-display="<?= htmlspecialchars($avail_st['last_name'] . ', ' . $avail_st['first_name'] . " (" . $avail_st['sid'] . ")") ?>">
                                <?= htmlspecialchars($avail_st['sid'] . " | " . $avail_st['last_name'] . ", " . $avail_st['first_name'] . " | " . $avail_st['program'] . " - " . $avail_st['academic_year']) ?>
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <button type="button" id="add-to-enroll-list-btn" class="action-button" style="width: 100%; margin-top: 5px; background: #34d399; font-weight: bold; justify-content: center;" disabled>
                    <i class="fa-solid fa-arrow-down"></i> Add Selected Student to Enrollment List
                </button>
                
                <hr style="margin: 15px 0; border-top: 1px solid var(--border-color);">

                <div class="form-group">
                    <label>Selected Students for Enrollment (<span id="enroll-count">0</span>):</label>
                    <div id="selected-enroll-list" style="border: 1px solid var(--border-color); padding: 10px; border-radius: 6px; min-height: 40px; background: var(--bg-light);">
                        <p style="color: var(--text-light); font-size: 0.9em;">No students selected yet.</p>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="action-button" style="background:#ccc; color:#333;" onclick="closeModal('add-student-modal')">Cancel</button>
                <button type="submit" id="final-enroll-btn" class="action-button add-btn" disabled>Enroll (<span id="final-enroll-count">0</span>) Student(s)</button>
            </div>
        </form>
    </div>
</div>

<div id="bulk-upload-modal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="teacher_manage_students.php" enctype="multipart/form-data">
            <div class="modal-header">
                <h3><i class="fa-solid fa-file-csv"></i> Enroll via CSV Bulk Upload</h3>
                <button type="button" class="modal-close" onclick="closeModal('bulk-upload-modal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="bulk_upload_enrollment">
                
                <div class="form-group">
                    <p style="font-size: 0.9em; color: var(--text-light); margin-top: 10px;">
                        The form below is ready, but the system needs the backend code to successfully read, verify, and enroll students from the file.
                    </p>
                </div>

                <hr style="margin: 15px 0; border-top: 1px solid var(--border-color);">

                <div class="form-group">
                    <label>Required CSV Format:</label>
                    <pre style="background: #eee; padding: 10px; border-radius: 4px; font-size: 0.9em; margin-bottom: 10px;">student_sid,class_id</pre>
                    <p style="font-size: 0.85rem; color: var(--panel-blue);">
                        <i class="fa-solid fa-info-circle"></i> Download a template: 
                        <a href="data:text/csv;charset=utf-8,student_sid%2Cclass_id%0A202300123%2C5%0A202300124%2C5%0A202300125%2C6" download="Enrollment_Template.csv" style="color: #312e81; font-weight: 600;">
                            Enrollment_Template.csv
                        </a>
                    </p>
                </div>

                <div class="form-group">
                    <label for="csv_file">Upload CSV File (Max 1MB)</label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="action-button" style="background:#ccc; color:#333;" onclick="closeModal('bulk-upload-modal')">Cancel</button>
                <button type="submit" class="action-button add-btn" disabled title="Backend logic is required to enable this">Start Upload</button>
            </div>
        </form>
    </div>
</div>
<div id="transfer-student-modal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST" action="teacher_manage_students.php" id="bulk-transfer-form">
            <div class="modal-header">
                <h3><i class="fa-solid fa-right-left"></i> Transfer Students</h3>
                <button type="button" class="modal-close" onclick="closeModal('transfer-student-modal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="transfer_student">
                <div id="bulk-transfer-inputs"></div>
                
                <p id="transfer-modal-title">Transferring Selected Students</p>
                
                <div class="form-group">
                    <label for="new_class">Destination Class</label>
                    <select name="new_class_id" id="new_class" required>
                        <option value="">-- Select New Section --</option>
                        <?php foreach ($instructor_classes as $cls): ?>
                            <?php 
                                $is_other_prof = ($cls['professor_sid'] != $current_user_id);
                                $prof_label = $is_other_prof ? " (Prof. " . htmlspecialchars($cls['prof_lastname']) . ")" : " (My Class)";
                                $style = $is_other_prof ? "color: #d97706; font-weight:bold;" : ""; 
                            ?>
                            <option value="<?= $cls['class_id'] ?>" style="<?= $style ?>">
                                <?= htmlspecialchars($cls['subject_code'] . " - " . $cls['section']) . $prof_label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size: 0.8rem; color: #d97706; margin-top: 10px;">
                        <i class="fa-solid fa-triangle-exclamation"></i> <strong>Warning:</strong> Transferring will <u>reset</u> current grades to avoid data conflicts.
                    </p>
                </div>
                
                <a href="#" id="download-grades-btn" class="action-button" style="display:none; background: #fff; color: #856404; border: 1px solid #856404; width: auto; font-size: 0.85rem;">
                    <i class="fa-solid fa-download"></i> Download Grades (.csv)
                </a>
            </div>
            <div class="modal-footer">
                <button type="button" class="action-button" style="background:#ccc; color:#333;" onclick="closeModal('transfer-student-modal')">Cancel</button>
                <button type="submit" class="action-button btn-transfer">Confirm Transfer</button>
            </div>
        </form>
    </div>
</div>

<form id="bulk-remove-form" method="POST" style="display:none;">
    <input type="hidden" name="action" value="remove_student">
    <div id="bulk-remove-inputs"></div>
</form>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="dfcam-logo.png" alt="DFCAM Logo" class="sidebar-logo">
        <h2>DFCAMCLP<span>Instructor Portal</span></h2>
    </div>
    <nav class="sidebar-nav custom-scrollbar">
        <ul>
            <li><a href="teacher_dashboard.php" class="nav-link" data-view="dashboard"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="input_grades.php" class="nav-link" data-view="grade_input"><i class="fa-solid fa-file-upload"></i><span>Grade Input</span></a></li>
            <li><a href="grade_management.php" class="nav-link" data-view="grade_management"><i class="fa-solid fa-archive"></i><span>Grade Management</span></a></li>
            <li><a href="teacher_message.php" class="nav-link" data-view="messages"><i class="fa-solid fa-comments"></i><span>Messages</span></a></li>
            <li><a href="teacher_manage_students.php" class="nav-link active" data-view="manage_students"><i class="fa-solid fa-users-cog"></i><span>Manage Students</span></a></li>
            <li><a href="attendance.php" class="nav-link" data-view="attendance"><i class="fa-solid fa-clipboard-user"></i><span>Attendance</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="main-content">
    <header class="header">
        <button class="menu-toggle" id="menu-toggle">
            <i class="fa-solid fa-bars"></i>
        </button>
        <h1 class="header-title">Manage Students</h1>
        <div class="user-profile">
            <a href="teacher_message.php" class="icon-button">
                <i class="fa-solid fa-envelope"></i>
                <?php if ($total_unread > 0): ?>
                    <span class="unread-badge"><?= $total_unread ?></span>
                <?php endif; ?>
            </a>
            <div class="user-profile-info">
                <strong><?php echo htmlspecialchars($teacher_name); ?></strong>
                <span><?php echo htmlspecialchars($_SESSION['role']); ?></span>
            </div>
        </div>
    </header>

    <main class="page-view custom-scrollbar">
        <?php if ($success_message): ?><div class="message-box success-message">✅ <?= htmlspecialchars($success_message) ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="message-box error-message">❌ <?= htmlspecialchars($error_message) ?></div><?php endif; ?>
        <?php if ($db_error): ?><div class="message-box error-message">⚠️ <?= $db_error ?></div><?php endif; ?>

        <section class="content-section">
            <div class="content-header">
                <h2><i class="fa-solid fa-users"></i> Student Roster & Enrollment</h2>
                <div>
                    <button class="action-button add-btn" onclick="openModal('add-student-modal')">
                        <i class="fa-solid fa-plus"></i> Add/Enroll Student
                    </button>
                    <button class="action-button" onclick="openModal('bulk-upload-modal')" style="background: var(--muted);">
                        <i class="fa-solid fa-upload"></i> Bulk CSV
                    </button>
                </div>
            </div>

            <form method="GET" action="teacher_manage_students.php" class="filter-form">
                <input type="search" name="search" placeholder="Search Name, ID, or Section..." value="<?= htmlspecialchars($search_query) ?>">
                <button type="submit" class="action-button"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <a href="teacher_manage_students.php" class="action-button" style="background:#ccc; color:#333;"><i class="fa-solid fa-xmark"></i> Clear</a>
            </form>

            <div class="bulk-actions">
                <span><i class="fa-solid fa-check-square"></i> Selected: <strong id="selected-count">0</strong></span>
                <button type="button" class="action-button btn-transfer" id="bulk-transfer-btn" disabled onclick="openBulkTransfer()">
                    <i class="fa-solid fa-right-left"></i> Transfer Selected
                </button>
                <button type="button" class="action-button btn-remove" id="bulk-remove-btn" disabled onclick="submitBulkRemove()">
                    <i class="fa-solid fa-user-minus"></i> Remove Selected
                </button>
            </div>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;"><input type="checkbox" id="select-all"></th>
                            <th>Student ID</th>
                            <th>Full Name</th>
                            <th>Section</th>
                            <th>Subject</th>
                            <th>Program</th> 
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $st): ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" class="student-checkbox" 
                                        value="<?= $st['sid'] ?>" 
                                        data-name="<?= htmlspecialchars($st['lname'] . ', ' . $st['fname']) ?>" 
                                        data-class="<?= $st['class_id'] ?>"
                                        data-program="<?= htmlspecialchars($st['program']) ?>">
                                </td>
                                <td><?= htmlspecialchars($st['sid']) ?></td>
                                <td><strong><?= htmlspecialchars($st['lname'] . ', ' . $st['fname']) ?></strong></td>
                                
                                <td><?= htmlspecialchars($st['section']) ?></td>
                                <td><?= htmlspecialchars($st['subject_code']) ?></td>
                                <td><span style="font-weight: 600; color: <?= in_array($st['program'], $allowed_programs) ? '#1f6ea3' : '#888' ?>;"><?= htmlspecialchars($st['program']) ?></span></td> 
                                <td>
                                    <button type="button" class="action-button btn-icon btn-transfer" title="Transfer"
                                        onclick="openSingleTransfer('<?= $st['sid'] ?>', '<?= addslashes($st['lname'] . ', ' . $st['fname']) ?>', '<?= $st['class_id'] ?>', '<?= addslashes($st['program']) ?>')">
                                        <i class="fa-solid fa-right-left"></i>
                                    </button>
                                    <button type="button" class="action-button btn-icon btn-remove" title="Remove"
                                        onclick="submitSingleRemove('<?= $st['sid'] ?>', '<?= addslashes($st['lname'] . ', ' . $st['fname']) ?>')">
                                        <i class="fa-solid fa-user-minus"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="no-data">No students found.</td></tr> 
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="footer">© 2025 Smart Grade Monitoring | Integrated Teacher Module</div>
    </main>
</div>

<script>
// PHP array of allowed programs passed to JS
const ALLOWED_PROGRAMS = <?= json_encode($allowed_programs) ?>;

// List of currently selected SIDs for enrollment
let selectedSIDs = new Set(); 

// Get static references for DOM elements
const availableStudentsSelect = document.getElementById('add_sid');
const addToEnrollBtn = document.getElementById('add-to-enroll-list-btn');
const selectedEnrollListDiv = document.getElementById('selected-enroll-list');
const enrollCountSpan = document.getElementById('enroll-count');
const finalEnrollCountSpan = document.getElementById('final-enroll-count');
const finalEnrollBtn = document.getElementById('final-enroll-btn');
const bulkEnrollInputsDiv = document.getElementById('bulk-enroll-inputs');
const classSelect = document.getElementById('add_class_select');


// --- Modal Utils ---
function openModal(id) { 
    if (id === 'add-student-modal') {
        
        // Find the class ID of the *first* selected class to use for filtering the student list on the backend.
        let firstSelectedClassId = null;
        for (let i = 0; i < classSelect.options.length; i++) {
            if (classSelect.options[i].selected && classSelect.options[i].value) {
                firstSelectedClassId = classSelect.options[i].value;
                break;
            }
        }
        
        // If a class is selected, check if we need to reload to fetch the student list (Step 2)
        if (firstSelectedClassId) {
            const currentUrl = new URL(window.location.href);
            const targetClassParam = currentUrl.searchParams.get('target_enroll_class');
            
            // Reload if the URL parameter doesn't match the current selection
            if (targetClassParam !== firstSelectedClassId.toString()) {
                currentUrl.searchParams.set('target_enroll_class', firstSelectedClassId);
                window.location.replace(currentUrl.toString()); 
                return; // Stop execution, page is reloading
            }
        } else if (window.location.search.includes('target_enroll_class')) {
            // If the modal is opened but no class is selected (i.e. first time open), 
            // clear the URL parameter to show the initial state.
            window.location.replace("teacher_manage_students.php");
            return;
        }
    }
    
    // Default modal open behavior
    document.getElementById(id).style.display = 'flex'; 
    
    // Enable/disable the 'Add Selected Student' button based on student selection
    if (id === 'add-student-modal' && !availableStudentsSelect.disabled) {
        
        // Initial check for button state after modal opens
        addToEnrollBtn.disabled = availableStudentsSelect.selectedIndex <= 0;
        
        // Add listener that fires when user clicks an option in the dropdown
        availableStudentsSelect.onchange = function() {
            addToEnrollBtn.disabled = availableStudentsSelect.selectedIndex <= 0;
        };
    }
}


function closeModal(id) { 
    document.getElementById(id).style.display = 'none'; 
    // Reset transfer modal filters and selections when closed
    if (id === 'transfer-student-modal') {
        const select = document.getElementById('new_class');
        for (let i = 0; i < select.options.length; i++) {
            select.options[i].style.display = 'block';
        }
    }
    // Reset enrollment modal selections when closed
    if (id === 'add-student-modal') {
        // Clear enrollment list
        selectedSIDs.clear();
        updateEnrollmentList();
        
        // Clear multi-select dropdown 
        for (let i = 0; i < classSelect.options.length; i++) {
            classSelect.options[i].selected = false;
        }

        // Redirect back to the clear URL state if the modal is closed
        if (window.location.search.includes('target_enroll_class')) {
             window.location.replace("teacher_manage_students.php");
        }
    }
}

// Function to handle the onchange event of the class select box
function updateStudentListBasedOnSelection() {
    
    // Find the class ID of the *first* selected class to use for filtering the student list on the backend.
    let firstSelectedClassId = null;
    for (let i = 0; i < classSelect.options.length; i++) {
        if (classSelect.options[i].selected && classSelect.options[i].value) {
            firstSelectedClassId = classSelect.options[i].value;
            break;
        }
    }

    if (firstSelectedClassId) {
        // Force reload the page with the target_enroll_class parameter
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('target_enroll_class', firstSelectedClassId);
        window.location.replace(currentUrl.toString()); 
    }
}

// Event Listener for the "Add Selected Student to Enrollment List" button
if(addToEnrollBtn) {
    addToEnrollBtn.addEventListener('click', addToEnrollList);
}

// Handles the movement of a selected student from the dropdown to the list
function addToEnrollList() {
    const selectedOption = availableStudentsSelect.options[availableStudentsSelect.selectedIndex];
    
    if (selectedOption && selectedOption.value) {
        const sid = selectedOption.value;
        
        if (!selectedSIDs.has(sid)) {
            selectedSIDs.add(sid);
            
            // Remove the option from the source list (hide it)
            selectedOption.style.display = 'none';
            availableStudentsSelect.selectedIndex = 0; // Reset selection
            
            updateEnrollmentList();
        }
    }
}

// Handles removal of a student from the enrollment list
function removeEnrollment(sid) {
    if (selectedSIDs.has(sid)) {
        selectedSIDs.delete(sid);
        
        // Return the option to the source list (make visible again)
        for (let i = 0; i < availableStudentsSelect.options.length; i++) {
            if (availableStudentsSelect.options[i].value === sid) {
                availableStudentsSelect.options[i].style.display = '';
                // Re-apply filter in case it was open
                filterStudentOptions(document.getElementById('search_student_input').value); 
                break;
            }
        }
        
        updateEnrollmentList();
    }
}

// Updates the visible list and the hidden form inputs
function updateEnrollmentList() {
    const count = selectedSIDs.size;
    enrollCountSpan.textContent = count;
    finalEnrollCountSpan.textContent = count;
    
    // Final enroll button is disabled if no students selected OR no classes selected (HTML 'required' handles class check on submit)
    finalEnrollBtn.disabled = count === 0; 
    
    selectedEnrollListDiv.innerHTML = '';
    bulkEnrollInputsDiv.innerHTML = '';

    if (count === 0) {
        selectedEnrollListDiv.innerHTML = '<p style="color: var(--text-light); font-size: 0.9em;">No students selected yet.</p>';
    } else {
        selectedSIDs.forEach(sid => {
            // Find the display name using the select options data
            const option = Array.from(availableStudentsSelect.options).find(opt => opt.value === sid);
            // Fallback for display name if data-display is missing
            const displayName = option && option.dataset.display ? option.dataset.display : sid;

            // 1. Visible Tag
            const tag = document.createElement('span');
            tag.className = 'selected-student-tag';
            tag.innerHTML = `${displayName} <button type="button" class="remove-tag-btn" onclick="removeEnrollment('${sid}')">&times;</button>`;
            selectedEnrollListDiv.appendChild(tag);
            
            // 2. Hidden Form Input (Crucial for submission)
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'student_sid[]';
            input.value = sid;
            bulkEnrollInputsDiv.appendChild(input);
        });
    }
}

// --- Student Search/Filter Logic for Add Modal ---
function filterStudentOptions(searchText) {
    const filter = searchText.toUpperCase();
    
    for (let i = 0; i < availableStudentsSelect.options.length; i++) {
        const option = availableStudentsSelect.options[i];
        const text = option.textContent || option.innerText;
        
        if (option.value === "" || selectedSIDs.has(option.value)) {
            // Keep header visible, skip selected students (they are already hidden)
            continue;
        }

        if (text.toUpperCase().indexOf(filter) > -1) {
            option.style.display = "";
        } else {
            option.style.display = "none";
        }
    }
}


// --- Selection Logic (Unchanged) ---
const selectAll = document.getElementById('select-all');
const checkboxes = document.querySelectorAll('.student-checkbox');
const bulkTransferBtn = document.getElementById('bulk-transfer-btn');
const bulkRemoveBtn = document.getElementById('bulk-remove-btn');
const selectedCountSpan = document.getElementById('selected-count');

/**
 * Checks if all selected students belong to one of the allowed programs (BSIS or BSCPE).
 * @returns {object} { isEligible: boolean, programs: Set<string> }
 */
function checkTransferEligibility() {
    const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
    if (checkedBoxes.length === 0) {
        return { isEligible: false, programs: new Set() };
    }

    const programs = new Set();
    let isEligible = true;

    checkedBoxes.forEach(cb => {
        const program = cb.dataset.program;
        programs.add(program);
        if (!ALLOWED_PROGRAMS.includes(program)) {
            isEligible = false;
        }
    });

    return { isEligible, programs };
}

function updateBulkButtons() {
    const checkedCount = document.querySelectorAll('.student-checkbox:checked').length;
    const eligibility = checkTransferEligibility();

    selectedCountSpan.textContent = checkedCount;
    
    // Check eligibility: must have selections AND must be BSIS/BSCPE
    const isTransferEligible = checkedCount > 0 && eligibility.isEligible;

    bulkTransferBtn.disabled = !isTransferEligible;
    bulkRemoveBtn.disabled = checkedCount === 0; // Removal is always allowed
}

if (selectAll) {
    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = this.checked);
        updateBulkButtons();
    });
}

checkboxes.forEach(cb => {
    cb.addEventListener('change', updateBulkButtons);
});

// Initialize button states on load
document.addEventListener('DOMContentLoaded', updateBulkButtons);


// --- Transfer Logic (Bulk) (Unchanged) ---
function openBulkTransfer() {
    const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
    const eligibility = checkTransferEligibility();

    if (!eligibility.isEligible) {
        alert("Transfer is restricted. Please select only students who are BSIS or BSCPE majors.");
        return;
    }

    const container = document.getElementById('bulk-transfer-inputs');
    const title = document.getElementById('transfer-modal-title');
    const dlBtn = document.getElementById('download-grades-btn');
    
    container.innerHTML = ''; // Clear previous
    dlBtn.style.display = 'none'; // Hide single download
    
    checkedBoxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'student_sid[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    
    const programList = Array.from(eligibility.programs).join(', ');
    title.textContent = `Transferring ${checkedBoxes.length} Selected Students (${programList})`;
    
    // Reset dropdown filter visibility 
    const select = document.getElementById('new_class');
    for (let i = 0; i < select.options.length; i++) {
        select.options[i].style.display = 'block';
    }

    openModal('transfer-student-modal');
}

// --- Transfer Logic (Single) (Unchanged) ---
function openSingleTransfer(sid, name, classId, program) {
    if (!ALLOWED_PROGRAMS.includes(program)) {
        alert(`Transfer is restricted for ${program} majors. This option is only available for BSIS or BSCPE students.`);
        return;
    }

    const container = document.getElementById('bulk-transfer-inputs');
    const title = document.getElementById('transfer-modal-title');
    const dlBtn = document.getElementById('download-grades-btn');
    
    container.innerHTML = '';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'student_sid[]';
    input.value = sid;
    container.appendChild(input);
    
    title.innerHTML = `Transferring <strong>${name} (${program})</strong>`;
    
    // Setup Download Link for Single User
    dlBtn.style.display = 'inline-flex';
    dlBtn.href = `teacher_manage_students.php?action=export_single_student_grades&student_sid=${sid}&class_id=${classId}`;
    
    // Filter dropdown to hide current class for selection
    const select = document.getElementById('new_class');
    for (let i = 0; i < select.options.length; i++) {
        const option = select.options[i];
        if(option.value == classId) {
            option.style.display = 'none';
            if(select.value == classId) select.value = ""; // Clear selection if current class was selected
        } else {
            option.style.display = 'block';
        }
    }

    openModal('transfer-student-modal');
}

// --- Remove Logic (Bulk/Single) (Unchanged) ---
function submitBulkRemove() {
    const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
    if (!confirm(`Are you sure you want to un-enroll ${checkedBoxes.length} students? This will delete their grades.`)) return;
    
    const form = document.getElementById('bulk-remove-form');
    const container = document.getElementById('bulk-remove-inputs');
    container.innerHTML = '';
    
    checkedBoxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'student_sid[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    
    form.submit();
}

function submitSingleRemove(sid, name) {
    if (!confirm(`Are you sure you want to un-enroll student ${name} (${sid})? This will delete their grades.`)) return;

    const form = document.getElementById('bulk-remove-form');
    const container = document.getElementById('bulk-remove-inputs');
    container.innerHTML = '';

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'student_sid[]';
    input.value = sid;
    container.appendChild(input);

    form.submit();
}


// Sidebar Toggle for Mobile
const sidebar = document.getElementById('sidebar');
const menuToggle = document.getElementById('menu-toggle');
const overlay = document.getElementById('sidebar-overlay');

if (menuToggle) {
    menuToggle.addEventListener('click', () => {
        sidebar.classList.toggle('is-open');
        overlay.classList.toggle('is-open');
    });
}
if (overlay) {
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('is-open');
        overlay.classList.remove('is-open');
    });
}

// --- Initialization for Two-Step Enrollment ---
document.addEventListener('DOMContentLoaded', function() {
    // Check if a class was selected and the page reloaded (Step 2 is ready)
    if (window.location.search.includes('target_enroll_class')) {
        // Ensure the correct class is highlighted in the multi-select
        const currentUrl = new URL(window.location.href);
        const targetClassParam = currentUrl.searchParams.get('target_enroll_class');
        
        for (let i = 0; i < classSelect.options.length; i++) {
            // Select the option that matches the class ID from the URL
            if (classSelect.options[i].value === targetClassParam) {
                classSelect.options[i].selected = true;
            }
        }
        
        // Automatically re-open the modal
        openModal('add-student-modal');
    }
});
</script>
</body>
</html>