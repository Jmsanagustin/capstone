<?php
// FILE: input_grades.php 
// UPDATED: Integrated with Dashboard Navigation, Sidebar, and Notification System.

session_start();
include 'db.php'; 

// =========================================================================
// 1. GLOBAL SETUP & ACCESS CONTROL
// =========================================================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    header("Location: index.php");
    exit();
}

$current_user_id = $_SESSION['sid'] ?? $_SESSION['user_id'] ?? 0;
$current_user_name = $_SESSION['username'] ?? 'Professor';
$teacher_name = $_SESSION['name'] ?? $current_user_name; 
$current_user_role = $_SESSION['role'] ?? 'Professor';

$error_message = "";
$success_message = "";
$db_error = "";
$PASSING_GRADE = 75.00;

// Initialize data containers
$conversations = [];
$instructor_classes = [];

// Containers for Notifications
$failing_exam_students = []; 
$missing_grade_students = []; 
$failing_item_students = []; 

try {
    if (!$conn) {
        throw new PDOException("Database connection object (\$conn) is not initialized.");
    }

    // --- Fetch Professor's First Name for greeting (Consistency Check) ---
    if ($teacher_name === 'Professor' || $teacher_name === $current_user_name) {
        $stmt_prof_name = $conn->prepare("SELECT first_name FROM user_profiles WHERE sid = ?");
        $stmt_prof_name->execute([$current_user_id]);
        $teacher_name_from_db = $stmt_prof_name->fetchColumn();
        if ($teacher_name_from_db) {
            $teacher_name = $teacher_name_from_db;
            $_SESSION['name'] = $teacher_name; 
        }
    }
    
    // --- POST HANDLER FOR "Mark as Settled" (Notification System) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'mark_settled') {
        $student_sid = filter_input(INPUT_POST, 'student_sid', FILTER_VALIDATE_INT);
        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
        $alert_type = $_POST['alert_type'] ?? '';

        if ($student_sid && $class_id && in_array($alert_type, ['failing', 'missing', 'failing_item'])) {
            $stmt_dismiss = $conn->prepare("
                INSERT IGNORE INTO intervention_dismissals (professor_sid, student_sid, class_id, alert_type)
                VALUES (:prof_sid, :stud_sid, :class_id, :alert_type)
            ");
            $stmt_dismiss->execute([
                ':prof_sid' => $current_user_id,
                ':stud_sid' => $student_sid,
                ':class_id' => $class_id,
                ':alert_type' => $alert_type
            ]);
            // Refresh to clear post data
            header("Location: input_grades.php"); 
            exit();
        }
    }

    // --- Always fetch conversations for sidebar/header messages ---
    $stmt_conv_fetch = $conn->prepare("
        SELECT c.conversation_id, c.updated_at,
               CASE WHEN c.user_1_id = :current_user_id THEN u2.username ELSE u1.username END AS other_username,
               (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.conversation_id AND m.receiver_id = :current_user_id AND m.is_read = 0) AS unread_count
        FROM conversations c
        JOIN users u1 ON c.user_1_id = u1.sid JOIN users u2 ON c.user_2_id = u2.sid
        WHERE c.user_1_id = :current_user_id OR c.user_2_id = :current_user_id
        ORDER BY c.updated_at DESC
    ");
    $stmt_conv_fetch->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
    $stmt_conv_fetch->execute();
    $conversations = $stmt_conv_fetch->fetchAll(PDO::FETCH_ASSOC);

    // --- Session Message Handling (for redirects from confirm_upload.php) ---
    if (isset($_SESSION['upload_message'])) {
        $session_message = $_SESSION['upload_message'];
        unset($_SESSION['upload_message']);
        if ($session_message['type'] === 'success') { $success_message = $session_message['body']; } 
        else { $error_message = $session_message['body']; }
    }
    
    // =========================================================================
    // --- FETCH INTERVENTION DATA (ALERTS) FOR BELL ICON ---
    // =========================================================================
    
    // 1. Failing Major Exam
    $stmt_failing = $conn->prepare("
        SELECT p.first_name, p.last_name, s.subject_code, c.section, u.sid, c.class_id,
               r.item_name, ((r.score / r.max_score) * 50 + 50) AS transmuted_grade
        FROM classes c
        JOIN grade_components gc ON c.class_id = gc.class_id
        JOIN raw_scores r ON gc.component_id = r.component_id
        JOIN enrollment e ON r.enrollment_id = e.enrollment_id
        JOIN users u ON e.student_sid = u.sid
        JOIN user_profiles p ON u.sid = p.sid
        JOIN subject s ON c.subject_id = s.subject_id
        LEFT JOIN intervention_dismissals d ON d.professor_sid = c.professor_sid
            AND d.student_sid = u.sid AND d.class_id = c.class_id AND d.alert_type = 'failing'
        WHERE c.professor_sid = :current_user_id
          AND gc.component_name LIKE '%MAJOR EXAM%'
          AND r.max_score > 0
          AND ((r.score / r.max_score) * 50 + 50) < :passing_grade
          AND c.midterm_finalized_at IS NULL AND c.final_finalized_at IS NULL
          AND d.dismissal_id IS NULL
        GROUP BY u.sid, c.class_id, r.item_name, p.first_name, p.last_name, s.subject_code, c.section
    ");
    $stmt_failing->execute([':current_user_id' => $current_user_id, ':passing_grade' => $PASSING_GRADE]);
    $failing_exam_students = $stmt_failing->fetchAll(PDO::FETCH_ASSOC);

    // 2. Failing Items
    $stmt_failing_items = $conn->prepare("
        SELECT p.first_name, p.last_name, s.subject_code, c.section, u.sid, c.class_id,
               r.item_name, gc.component_name, ((r.score / r.max_score) * 50 + 50) AS transmuted_grade
        FROM classes c
        JOIN grade_components gc ON c.class_id = gc.class_id
        JOIN raw_scores r ON gc.component_id = r.component_id
        JOIN enrollment e ON r.enrollment_id = e.enrollment_id
        JOIN users u ON e.student_sid = u.sid
        JOIN user_profiles p ON u.sid = p.sid
        JOIN subject s ON c.subject_id = s.subject_id
        LEFT JOIN intervention_dismissals d ON d.professor_sid = c.professor_sid
            AND d.student_sid = u.sid AND d.class_id = c.class_id AND d.alert_type = 'failing_item'
        WHERE c.professor_sid = :current_user_id
          AND gc.component_name NOT LIKE '%MAJOR EXAM%' 
          AND r.max_score > 0
          AND ((r.score / r.max_score) * 50 + 50) < :passing_grade 
          AND c.midterm_finalized_at IS NULL AND c.final_finalized_at IS NULL
          AND d.dismissal_id IS NULL
        GROUP BY u.sid, c.class_id, r.item_name, p.first_name, p.last_name, s.subject_code, c.section
    ");
    $stmt_failing_items->execute([':current_user_id' => $current_user_id, ':passing_grade' => $PASSING_GRADE]);
    $failing_item_students = $stmt_failing_items->fetchAll(PDO::FETCH_ASSOC);

    // 3. Missing Grades
    $stmt_missing = $conn->prepare("
        SELECT p.first_name, p.last_name, s.subject_code, c.section, u.sid, c.class_id
        FROM classes c
        JOIN enrollment e ON c.class_id = e.class_id
        JOIN users u ON e.student_sid = u.sid
        JOIN user_profiles p ON u.sid = p.sid
        JOIN subject s ON c.subject_id = s.subject_id
        LEFT JOIN raw_scores rs ON e.enrollment_id = rs.enrollment_id
        LEFT JOIN intervention_dismissals d ON d.professor_sid = c.professor_sid
            AND d.student_sid = u.sid AND d.class_id = c.class_id AND d.alert_type = 'missing'
        WHERE c.professor_sid = :current_user_id
          AND c.midterm_finalized_at IS NULL AND c.final_finalized_at IS NULL
          AND d.dismissal_id IS NULL 
        GROUP BY u.sid, c.class_id, p.first_name, p.last_name, s.subject_code, c.section
        HAVING COUNT(rs.score_id) = 0
    ");
    $stmt_missing->execute([':current_user_id' => $current_user_id]);
    $missing_grade_students = $stmt_missing->fetchAll(PDO::FETCH_ASSOC);

    $total_alerts = count($failing_exam_students) + count($missing_grade_students) + count($failing_item_students);

    // =========================================================================
    // --- FETCH INSTRUCTOR CLASSES FOR MAIN CONTENT ---
    // =========================================================================
    $stmt_classes = $conn->prepare("
        SELECT 
            c.class_id, s.subject_code, s.subject_name, c.section,
            c.midterm_submission_start, c.midterm_finalized_at,
            c.final_submission_start, c.final_finalized_at
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        WHERE c.professor_sid = :professor_sid
        ORDER BY s.subject_code, c.section
    ");
    $stmt_classes->execute([':professor_sid' => $current_user_id]);
    $instructor_classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

    // Helper function to get submission status
    function getSubmissionStatus($start_time, $finalized_time) {
        if ($finalized_time) {
            return ['status' => 'Locked', 'class' => 'locked', 'message' => 'Finalized on ' . date('M j, Y', strtotime($finalized_time))];
        }
        if ($start_time) {
            $deadline = strtotime($start_time . ' + 7 days');
            $time_left = $deadline - time();
            
            if ($time_left > 0) {
                $days = floor($time_left / (60 * 60 * 24));
                $hours = floor(($time_left % (60 * 60 * 24)) / (60 * 60));
                return ['status' => 'Pending', 'class' => 'pending', 'message' => "Locks in approx. $days days, $hours hours."];
            } else {
                return ['status' => 'Locking', 'class' => 'locked', 'message' => 'Deadline passed. Will lock on next auto-submit.'];
            }
        }
        return ['status' => 'Open', 'class' => 'open', 'message' => 'Not yet started. Timer begins when 50% of Major Exam grades are entered.'];
    }

} catch (PDOException $e) {
    $db_error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instructor Portal - Grade Input</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
    --nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f2f5f8;
    --white:#fff; --text-dark:#102a43; --text-light:#627d98;
    --accent-light:#4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);
    --shadow-md: 0 6px 15px rgba(0,0,0,0.08); --critical:#cc3333;
    --warning:#ff9900; --muted:#888; --success-green: #28a745;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100vh; overflow: hidden; }
body{font-family: 'Inter', 'Segoe UI', sans-serif;margin:0;background:var(--bg-light);color:var(--text-dark); display: flex;}

/* --- Sidebar (Matches Dashboard) --- */
.sidebar { 
    width: 260px; background: var(--nav-dark); color: var(--white); 
    display: flex; flex-direction: column; flex-shrink: 0; height: 100vh;
    position: fixed; top: 0; left: 0; z-index: 40;
    transition: transform 0.3s ease-in-out;
    transform: translateX(-100%); 
}
.sidebar-visible { transform: translateX(0); }
.sidebar-header { padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar-header-logo { display: flex; align-items: center; gap: 10px; text-decoration: none; color: white; }
.sidebar-header-logo img { height: 32px; width: 32px; }
.sidebar-header-logo h2 { font-size: 1.15rem; font-weight: 600; line-height: 1.3; }
.sidebar-header-logo h2 span { font-size: 0.8rem; font-weight: 400; color: #9ac8ee; display: block; }
.sidebar-nav { flex-grow: 1; padding: 20px 0; overflow-y: auto; }
.sidebar-nav ul { list-style: none; }
.sidebar-nav li { margin: 0 10px; }
.sidebar-nav a { display: flex; align-items: center; gap: 15px; padding: 14px 20px; color: #e0f2fe; text-decoration: none; font-weight: 500; border-radius: 8px; transition: background 0.2s ease, color 0.2s ease; }
.sidebar-nav a i { font-size: 1.1rem; width: 20px; text-align: center; color: #9ac8ee; }
.sidebar-nav a:hover { background: rgba(255, 255, 255, 0.1); }
.sidebar-nav a.active { background: var(--accent-light); color: var(--nav-dark); font-weight: 700; }
.sidebar-nav a.active i { color: var(--nav-dark); }
.sidebar-footer { padding: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar-footer a { display: block; text-align: center; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; color: var(--white); text-decoration: none; font-weight: 500; transition: background 0.2s ease; }
.sidebar-footer a:hover { background: rgba(0,0,0,0.4); }

#sidebar-overlay { 
    position: fixed; inset: 0; background: #000; opacity: 0.5; 
    z-index: 30; transition: opacity 0.3s ease-in-out; 
    display: none; 
}

/* --- Main Content Layout --- */
.main-content { 
    flex-grow: 1; display: flex; flex-direction: column; 
    height: 100vh; overflow: hidden; 
    transition: margin-left 0.3s ease-in-out;
    width: 100%; 
}

/* --- Header & Profile --- */
.header { display: flex; justify-content: space-between; align-items: center; padding: 16px 32px; background: var(--white); border-bottom: 1px solid #e0e6ed; flex-shrink: 0; }
.header-left { display: flex; align-items: center; gap: 16px; } 
.header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
.user-profile { display: flex; align-items: center; gap: 12px; }
.user-profile .icon-button { 
    font-size: 1.2rem; color: var(--text-light); text-decoration: none; 
    padding: 8px; border-radius: 50%; transition: background 0.2s ease;
    position: relative; background: none; border: none; cursor: pointer; 
}
.user-profile .icon-button:hover { background: var(--bg-light); }
.user-profile-info { text-align: right; }
.user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
.user-profile-info span { font-size: 0.85rem; color: var(--text-light); }

.notification-badge {
    position: absolute; top: 2px; right: 2px;
    background: var(--critical); color: var(--white);
    font-size: 0.7rem; font-weight: 700; padding: 2px 6px;
    border-radius: 50%; border: 2px solid var(--white);
}

/* --- Notification Popover --- */
.notification-popover {
    display: none; position: absolute; top: 65px; right: 150px;
    width: 380px; max-height: 400px; background: var(--white);
    border-radius: 12px; box-shadow: var(--shadow-md); z-index: 50;
    border: 1px solid #e0e6ed; overflow: hidden; flex-direction: column;
}
.notification-popover.visible { display: flex; }
.popover-header { padding: 16px 20px; border-bottom: 1px solid #e0e6ed; flex-shrink: 0; }
.popover-header h3 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin: 0; }
.popover-list { list-style-type: none; flex-grow: 1; overflow-y: auto; padding: 0; }
.popover-list li { border-bottom: 1px solid #f2f5f8; }
.popover-item-content { display: flex; align-items: center; gap: 15px; padding: 12px 20px; color: var(--text-dark); }
.popover-list .alert-icon { font-size: 1.2rem; flex-shrink: 0; width: 25px; text-align: center; }
.popover-list .alert-icon.critical { color: var(--critical); }
.popover-list .alert-icon.warning { color: var(--warning); }
.popover-list .alert-icon.missing { color: var(--text-light); }
.popover-list .alert-text { display: flex; flex-direction: column; flex-grow: 1; }
.popover-list .alert-text strong { font-size: 0.9rem; font-weight: 600; }
.popover-list .alert-text p { font-size: 0.85rem; color: var(--text-light); margin: 0; line-height: 1.4; }
.popover-list .settle-form button { background: none; border: none; font-size: 0.75rem; font-weight: 600; color: var(--text-light); cursor: pointer; padding: 2px 0; margin-top: 5px; }
.popover-list .settle-form button:hover { color: var(--success-green); text-decoration: underline; }
.popover-list li.no-alerts { text-align: center; padding: 40px 20px; color: var(--text-light); }

/* --- Grading Page Specific Styles --- */
.page-view { flex-grow: 1; overflow-y: auto; padding: 32px; }
.content-section { background: var(--white); border-radius: 12px; padding: 24px; box-shadow: var(--shadow); margin-bottom: 32px; }
.content-section h2 { font-size: 1.25rem; font-weight: 700; color: var(--panel-blue); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.message-box{padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold;}
.error-message{background: #fde8e8; color: var(--critical); border: 1px solid var(--critical);}
.success-message{background: #e6f7e6; color: var(--success-green); border: 1px solid var(--success-green);}
.warning-message{background: #fff8e1; color: var(--warning); border: 1px solid var(--warning);}
.no-data { text-align: center; padding: 30px; color: var(--text-light); }
input[type="text"], input[type="file"], input[type="date"], select, textarea {
    padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px;
    width: 100%;
}
.form-grid { 
    display: grid; grid-template-columns: 2fr 1fr auto; gap: 15px; 
    align-items: end; padding-top: 20px;
}
.form-grid label {display:block; font-weight:bold; margin-bottom: 5px; font-size: 0.8rem; color: var(--text-light);}
.action-button { background: var(--panel-blue); color: var(--white); font-weight: bold; border: none; cursor: pointer; transition: background 0.2s; padding: 10px; border-radius: 8px; text-decoration: none; display: inline-block; text-align: center;}
.action-button:hover{background: var(--nav-dark);}
.action-button:disabled, .action-button.disabled { background: #adb5bd; cursor: not-allowed; opacity: 0.7; pointer-events: none; }
.custom-scrollbar::-webkit-scrollbar { width: 8px; }
.custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.footer{text-align:center;font-size:12px;color:var(--muted);margin-top:30px; padding: 10px 0;}

/* Workflow & Status Boxes */
.status-box { padding: 10px; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-align: center; }
.status-box.open { background: #e6f7e6; color: var(--success-green); border: 1px solid var(--success-green); }
.status-box.pending { background: #fff8e1; color: var(--warning); border: 1px solid var(--warning); }
.status-box.locked { background: #fde8e8; color: var(--critical); border: 1px solid var(--critical); }
.class-title { font-size: 1.2rem; font-weight: 700; color: var(--text-dark); margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid var(--bg-light); }
.workflow-section { padding: 20px; background: #f9fafb; border-radius: 8px; border: 1px solid #e0e6ed; margin-top: 20px; }
.workflow-section.locked { opacity: 0.6; pointer-events: none; background: #f8f8f8; }
.workflow-buttons { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; max-width: 600px; margin-top: 15px; }
.input-options { display: flex; gap: 15px; align-items: center; padding-bottom: 20px; border-bottom: 1px solid #eee; }
.input-options .or-divider { font-weight: bold; color: var(--text-light); }
.grade-preview-area { margin-top: 25px; border-top: 1px solid #eee; padding-top: 20px; display: none; }
.final-submission-section { border-top: 2px solid var(--panel-blue); margin-top: 25px; padding-top: 20px; }
.final-submission-section h4 { font-size: 1.1rem; font-weight: 700; color: var(--text-dark); margin-bottom: 5px; }
.final-submission-section p { font-size: 0.9rem; color: var(--text-light); margin-bottom: 15px; }

/* Tables */
.table-container { width: 100%; overflow-x: auto; }
.data-table{width:100%;border-collapse:collapse;margin-top:15px; background:var(--white); border-radius: 8px; overflow: hidden; box-shadow: var(--shadow); }
.data-table th{background:var(--bg-light);color:var(--text-light);padding:12px 15px;text-align:left;font-weight:600;text-transform:uppercase;font-size:0.75rem;}
.data-table td{border-bottom:1px solid #e0e6ed;padding:15px; vertical-align: middle; text-align: left;}
.data-table .risk-critical{background:rgba(204, 51, 51, 0.08);}
.critical-text { color: var(--critical); font-weight: bold; }
.success-text { color: var(--success-green); font-weight: bold; }

/* Review Modal */
.review-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); display: flex; align-items: center; justify-content: center; z-index: 10000; }
.review-modal-content { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3); max-width: 900px; width: 95%; max-height: 90vh; display: flex; flex-direction: column; }
.review-modal-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.review-modal-table th { font-size: 0.75rem; font-weight: 600; color: var(--text-dark); padding: 10px; text-align:left; }
.review-modal-table td { font-size: 0.85rem; padding: 8px 10px; border-bottom: 1px solid #eee; text-align: center; }
.review-modal-table td:first-child, .review-modal-table td:nth-child(2) { text-align: left; }
.action-update { color: #d97706; } 
.action-new { color: #155724; } 

/* Responsive Utilities */
.toggle-button { background: none; border: none; cursor: pointer; color: var(--text-dark); padding: 4px; }
.toggle-button i { font-size: 1.25rem; }
.lg-hidden { display: none; }

@media (max-width: 1023px) { 
    .lg-hidden { display: inline-block; } 
    .header { padding-left: 16px; padding-right: 16px; }
    .page-view { padding: 16px; }
    .header-title { font-size: 1.2rem; }
    .form-grid { grid-template-columns: 1fr; }
    .notification-popover { right: 16px; width: 320px; }
}
@media (min-width: 1024px) { 
    .sidebar { transform: translateX(0); }
    .main-content { margin-left: 260px; }
    .lg-hidden { display: none; }
    #sidebar-overlay { display: none !important; }
}
</style>
</head>
<body>

<div id="review-modal" class="review-modal-overlay" style="display: none;">
    <div class="review-modal-content">
        <h3 style="font-size:1.5rem; font-weight:700; margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:10px;">🚨 Review Grade Overwrites</h3>
        <p style="color:#4a5568; margin-bottom:15px;">You are about to **OVERWRITE** scores that already exist in the system. Review the changes below before confirming.</p>
        <div style="flex-grow:1; overflow-y:auto; border:1px solid #eee; border-radius:8px; margin-bottom:15px;">
            <table class="review-modal-table">
                <thead>
                    <tr style="background:#f7fafc;">
                        <th>Student Name</th>
                        <th>Item</th>
                        <th>Previous Score / Max</th>
                        <th>New Score / Max</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="review-modal-body"></tbody>
            </table>
        </div>
        <div style="text-align:right; gap:10px; display:flex; justify-content:flex-end;">
            <button type="button" id="modal-cancel-review-btn" class="action-button" style="background:#e2e8f0; color:#2d3748;">Cancel Upload</button>
            <button type="button" id="modal-confirm-update-btn" class="action-button" style="background:#e53e3e;">Confirm & Overwrite Grades</button>
        </div>
    </div>
</div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="teacher_dashboard.php" class="sidebar-header-logo">
            <img src="dfcam-logo.png" alt="DFCAMCLP Logo"> 
            <h2>DFCAMCLP<span>Instructor Portal</span></h2>
        </a>
        <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
            <i class="fa-solid fa-times" style="color: var(--white);"></i>
        </button>
    </div>
    <nav class="sidebar-nav custom-scrollbar">
        <ul>
            <li><a href="teacher_dashboard.php" class="nav-link" data-view="dashboard"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="input_grades.php" class="nav-link active" data-view="grade_input"><i class="fa-solid fa-file-upload"></i><span>Grade Input</span></a></li>
            <li><a href="grade_management.php" class="nav-link" data-view="grade_management"><i class="fa-solid fa-archive"></i><span>Grade Management</span></a></li>
            <li><a href="teacher_message.php" class="nav-link" data-view="messages"><i class="fa-solid fa-comments"></i><span>Messages</span></a></li>
            <li><a href="teacher_manage_students.php" class="nav-link" data-view="manage_students"><i class="fa-solid fa-users-cog"></i><span>Manage Students</span></a></li>
            <li><a href="attendance.php" class="nav-link" data-view="attendance"><i class="fa-solid fa-clipboard-user"></i><span>Attendance</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div id="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="main-content">
    
    <header class="header">
        <div class="header-left">
            <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h1 class="header-title">Grade Input</h1>
        </div>
        <div class="user-profile">
            <button class="icon-button" id="notification-bell-btn" title="Alerts">
                <i class="fa-solid fa-bell"></i>
                <?php if ($total_alerts > 0): ?>
                    <span class="notification-badge"><?= $total_alerts ?></span>
                <?php endif; ?>
            </button>

            <a href="teacher_message.php" class="icon-button" title="Messages">
                <i class="fa-solid fa-envelope"></i>
                <?php 
                    $total_unread = array_sum(array_column($conversations, 'unread_count'));
                    if ($total_unread > 0): 
                ?>
                    <span class="notification-badge" style="background: var(--panel-blue);"><?= $total_unread ?></span>
                <?php endif; ?>
            </a>
            
            <div class="user-profile-info">
                <strong><?php echo htmlspecialchars($teacher_name); ?></strong>
                <span><?php echo htmlspecialchars($current_user_role); ?></span>
            </div>
        </div>
    </header>

    <div class="notification-popover" id="notification-popover">
        <div class="popover-header">
            <h3>Intervention Alerts (<?= $total_alerts ?>)</h3>
        </div>
        <ul class="popover-list custom-scrollbar">
            <?php if ($total_alerts == 0): ?>
                <li class="no-alerts">
                    <i class="fa-solid fa-check-circle" style="font-size: 2rem; color: var(--success-green); margin-bottom: 10px; display:block;"></i>
                    <span>No alerts. All students are on track.</span>
                </li>
            <?php endif; ?>
            
            <?php foreach ($failing_exam_students as $student): ?>
                <li>
                    <div class="popover-item-content">
                        <span class="alert-icon critical"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <div class="alert-text">
                            <strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></strong>
                            <p>Failing Major Exam (<?= number_format($student['transmuted_grade'], 2) ?>) in <?= htmlspecialchars($student['subject_code']) ?></p>
                            <form method="POST" action="input_grades.php" class="settle-form">
                                <input type="hidden" name="action" value="mark_settled">
                                <input type="hidden" name="student_sid" value="<?= $student['sid'] ?>">
                                <input type="hidden" name="class_id" value="<?= $student['class_id'] ?>">
                                <input type="hidden" name="alert_type" value="failing">
                                <button type="submit">Mark as Settled</button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>

            <?php foreach ($failing_item_students as $student): ?>
                <li>
                    <div class="popover-item-content">
                        <span class="alert-icon warning"><i class="fa-solid fa-exclamation-circle"></i></span>
                        <div class="alert-text">
                            <strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></strong>
                            <p>Failing Item (<?= number_format($student['transmuted_grade'], 2) ?>) in <?= htmlspecialchars($student['subject_code']) ?></p>
                            <form method="POST" action="input_grades.php" class="settle-form">
                                <input type="hidden" name="action" value="mark_settled">
                                <input type="hidden" name="student_sid" value="<?= $student['sid'] ?>">
                                <input type="hidden" name="class_id" value="<?= $student['class_id'] ?>">
                                <input type="hidden" name="alert_type" value="failing_item">
                                <button type="submit">Mark as Settled</button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>

            <?php foreach ($missing_grade_students as $student): ?>
                 <li>
                    <div class="popover-item-content">
                        <span class="alert-icon missing"><i class="fa-solid fa-question-circle"></i></span>
                        <div class="alert-text">
                            <strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></strong>
                            <p>Missing all grades in <?= htmlspecialchars($student['subject_code']) ?></p>
                            <form method="POST" action="input_grades.php" class="settle-form">
                                <input type="hidden" name="action" value="mark_settled">
                                <input type="hidden" name="student_sid" value="<?= $student['sid'] ?>">
                                <input type="hidden" name="class_id" value="<?= $student['class_id'] ?>">
                                <input type="hidden" name="alert_type" value="missing">
                                <button type="submit">Mark as Settled</button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <main class="page-view custom-scrollbar">

        <?php if ($db_error): ?>
            <div class="message-box error-message"><?php echo $db_error; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="message-box error-message">❌ <?php echo htmlspecialchars($error_message); ?></div>
        <?php elseif ($success_message): ?>
            <div class="message-box success-message">✅ <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <section class="content-section">
            <h2>Grade Input Workflow</h2>
            <p>Use this page to input raw scores (e.g., quizzes, activities, exams) as you record them. The 7-day finalization timer begins once you save the first "Major Exam" scores.</p>
        </section>
        
        <?php if (empty($instructor_classes)): ?>
            <p class="no-data">You are not currently assigned to any classes.</p>
        <?php else: ?>
            <?php foreach ($instructor_classes as $class): ?>
                <?php 
                    // Get status for Midterm and Final
                    $midterm_status = getSubmissionStatus($class['midterm_submission_start'], $class['midterm_finalized_at']);
                    $final_status = getSubmissionStatus($class['final_submission_start'], $class['final_finalized_at']);
                    
                    $is_midterm_locked = $midterm_status['class'] === 'locked';
                    $is_final_locked = $final_status['class'] === 'locked';
                ?>
                <section class="content-section">
                    <div class="class-title">
                        <i class="fa-solid fa-book"></i>
                        <?= htmlspecialchars($class['subject_code'] . " - " . $class['subject_name'] . " (" . $class['section'] . ")") ?>
                    </div>
                    
                    <div class="workflow-section <?= $is_midterm_locked ? 'locked' : '' ?>">
                        <h3>Midterm Grades</h3>
                        <div class="status-box <?= $midterm_status['class'] ?>" style="margin-bottom: 20px;">
                            <strong><?= $midterm_status['status'] ?>:</strong> <?= $midterm_status['message'] ?>
                        </div>

                        <div class="input-options">
                             <a href="manual_entry.php?class_id=<?= $class['class_id'] ?>&term=Midterm" 
                                class="action-button <?= $is_midterm_locked ? 'disabled' : '' ?>" 
                                style="background: var(--success-green); text-decoration: none;">
                                <i class="fa-solid fa-keyboard"></i> Enter Grades Manually
                             </a>
                             <span class="or-divider">OR</span>
                             <span>Upload a CSV file of raw scores (e.g., Quiz 1):</span>
                        </div>

                        <form class="grade-upload-form" data-class-id="<?= $class['class_id'] ?>" data-term="Midterm">
                            <div class="form-grid">
                                <div>
                                    <label for="midterm_file_<?= $class['class_id'] ?>">Upload Raw Score Sheet (.csv)</label>
                                    <input type="file" name="grades_excel" class="excel-file-input" accept=".csv" <?= $is_midterm_locked ? 'disabled' : '' ?> required>
                                </div>
                                <div style="visibility: hidden;"></div> 
                                <button type="submit" class="action-button compute-btn" <?= $is_midterm_locked ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-upload"></i> Upload & Process File
                                </button>
                            </div>
                        </form>
                        <div class="message-box upload-status" style="display:none;"></div>
                        
                        <?php if ($midterm_status['class'] === 'pending'): ?>
                            <div class="final-submission-section">
                                <h4><i class="fa-solid fa-check-double"></i> Manual Final Grade Submission</h4>
                                <p>The 7-day timer is active. You can now manually validate and submit your final grades to the Program Head.</p>
                                <div class="workflow-buttons">
                                    <button class="action-button validate-btn" style="background: var(--warning);">
                                        <i class="fa-solid fa-flask"></i> 1. Run Validation
                                    </button>
                                    <button class="action-button submit-btn" style="background: var(--success-green);" disabled>
                                        <i class="fa-solid fa-check-double"></i> 2. Submit to Program Head
                                    </button>
                                </div>
                                <div class="grade-preview-area" style="display:none;"></div>
                            </div>
                        <?php endif; ?>
                        
                    </div>

                    <div class="workflow-section <?= $is_final_locked ? 'locked' : '' ?>" style="margin-top: 20px;">
                        <h3>Final Term Grades</h3>
                        <div class="status-box <?= $final_status['class'] ?>" style="margin-bottom: 20px;">
                            <strong><?= $final_status['status'] ?>:</strong> <?= $final_status['message'] ?>
                        </div>

                        <div class="input-options">
                             <a href="manual_entry.php?class_id=<?= $class['class_id'] ?>&term=Final" 
                                class="action-button <?= $is_final_locked ? 'disabled' : '' ?>" 
                                style="background: var(--success-green); text-decoration: none;">
                                <i class="fa-solid fa-keyboard"></i> Enter Grades Manually
                             </a>
                             <span class="or-divider">OR</span>
                             <span>Upload a CSV file of raw scores (e.g., Quiz 1):</span>
                        </div>

                        <form class="grade-upload-form" data-class-id="<?= $class['class_id'] ?>" data-term="Final">
                            <div class="form-grid">
                                <div>
                                    <label for="final_file_<?= $class['class_id'] ?>">Upload Raw Score Sheet (.csv)</label>
                                    <input type="file" name="grades_excel" class="excel-file-input" accept=".csv" <?= $is_final_locked ? 'disabled' : '' ?> required>
                                </div>
                                <div style="visibility: hidden;"></div> 
                                <button type="submit" class="action-button compute-btn" <?= $is_final_locked ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-upload"></i> Upload & Process File
                                </button>
                            </div>
                        </form>
                        <div class="message-box upload-status" style="display:none;"></div>

                        <?php if ($final_status['class'] === 'pending'): ?>
                            <div class="final-submission-section">
                                <h4><i class="fa-solid fa-check-double"></i> Manual Final Grade Submission</h4>
                                <p>The 7-day timer is active. You can now manually validate and submit your final grades to the Program Head.</p>
                                <div class="workflow-buttons">
                                    <button class="action-button validate-btn" style="background: var(--warning);">
                                        <i class="fa-solid fa-flask"></i> 1. Run Validation
                                    </button>
                                    <button class="action-button submit-btn" style="background: var(--success-green);" disabled>
                                        <i class="fa-solid fa-check-double"></i> 2. Submit to Program Head
                                    </button>
                                </div>
                                <div class="grade-preview-area" style="display:none;"></div>
                            </div>
                        <?php endif; ?>
                        
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="footer">© 2025 Smart Grade Monitoring | Integrated Teacher Module</div>
    </main>
</div>

<script>
// --- GLOBAL UI SCRIPTS (Sidebar, Overlay, Notifications) ---
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    sidebar.classList.toggle('sidebar-visible');
    overlay.style.display = sidebar.classList.contains('sidebar-visible') ? 'block' : 'none';
}

function handleResize() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    if (window.innerWidth >= 1024) {
        sidebar.classList.add('sidebar-visible');
        overlay.style.display = 'none';
    } else {
        if (!sidebar.classList.contains('sidebar-visible')) {
             overlay.style.display = 'none';
        }
    }
}

// --- ACTIVE LINK & NOTIFICATION LOGIC ---
const currentView = 'grade_input'; 

function setActiveSidebarLink() {
    const navLinks = document.querySelectorAll('.sidebar-nav a.nav-link');
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('data-view') === currentView) {
            link.classList.add('active');
        }
    });
}

document.addEventListener("DOMContentLoaded", function() {
    // 1. Initial UI Setup
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.add('sidebar-visible');
    }
    window.addEventListener('resize', handleResize);
    setActiveSidebarLink();

    // 2. Notification Popover Logic
    const bellBtn = document.getElementById('notification-bell-btn');
    const popover = document.getElementById('notification-popover');
    
    if (bellBtn && popover) {
        bellBtn.addEventListener('click', function(event) {
            event.stopPropagation(); 
            popover.classList.toggle('visible'); 
        });
    }
    window.addEventListener('click', function(event) {
        if (popover && popover.classList.contains('visible')) {
            if (!popover.contains(event.target) && !bellBtn.contains(event.target)) {
                popover.classList.remove('visible');
            }
        }
    });

    // --- GRADING WORKFLOW LOGIC (MODALS, UPLOADS) ---
    
    // Review Modal Elements
    const reviewModal = document.getElementById('review-modal');
    const modalBody = document.getElementById('review-modal-body');
    const modalConfirmBtn = document.getElementById('modal-confirm-update-btn');
    const modalCancelBtn = document.getElementById('modal-cancel-review-btn');
    
    let currentFormData = null; 
    let currentUploadStatusBox = null; 

    // Helper to show review modal
    function showReviewModal(reviewData) {
        modalBody.innerHTML = ''; 
        reviewData.forEach(item => {
            const row = document.createElement('tr');
            const newScore = item.new_score !== undefined ? item.new_score : 0;
            const oldScoreDisplay = item.old_score !== 'N/A' ? `<span style="color:#718096">${item.old_score} / ${item.old_max}</span>` : '—';
            const newScoreDisplay = `<span style="color:#e53e3e; font-weight:bold">${newScore} / ${item.new_max}</span>`;
            
            const actionClass = item.action === 'UPDATE' ? 'action-update' : 'action-new';
            const actionText = item.action === 'UPDATE' ? 'Overwrite' : 'New Item';

            row.innerHTML = `
                <td style="text-align:left; font-weight:500;">${item.student_name}</td>
                <td style="text-align:left;">${item.item_name}</td>
                <td>${oldScoreDisplay}</td>
                <td>${newScoreDisplay}</td>
                <td class="${actionClass}" style="font-weight:bold;">${actionText}</td>
            `;
            modalBody.appendChild(row);
        });
        reviewModal.style.display = 'flex';
    }

    // Modal Confirmation Logic
    modalConfirmBtn.addEventListener('click', async () => {
        if (!currentFormData || !currentUploadStatusBox) return; 

        reviewModal.style.display = 'none';
        
        const section = currentUploadStatusBox.closest('.workflow-section');
        const computeBtn = section.querySelector('.compute-btn');
        
        computeBtn.disabled = true;
        currentUploadStatusBox.className = 'message-box upload-status warning-message';
        currentUploadStatusBox.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Confirmation received. Finalizing grades...';
        currentUploadStatusBox.style.display = 'block';

        currentFormData.append('confirmation', 'true');

        try {
            const response = await fetch('confirm_upload.php', {
                method: 'POST',
                body: currentFormData
            });
            
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) {
                 const textError = await response.text();
                 throw new Error("Final Save Failed. Server response was not JSON: " + textError);
            }
            
            const result = await response.json();

            if (result.status === 'success') {
                window.location.href = `input_grades.php?success=1&msg=${encodeURIComponent(result.message)}`;
            } else {
                window.alert('Final Save Failed:\n\n' + result.message);
                currentUploadStatusBox.style.display = 'none'; 
                computeBtn.disabled = false;
            }

        } catch (error) {
            window.alert('Connection Error during final save:\n\n' + error.message);
            currentUploadStatusBox.style.display = 'none'; 
            computeBtn.disabled = false;
        } finally {
            currentFormData = null;
            currentUploadStatusBox = null;
        }
    });

    modalCancelBtn.addEventListener('click', () => {
        reviewModal.style.display = 'none';
        if (!currentUploadStatusBox) return; 
        
        const section = currentUploadStatusBox.closest('.workflow-section');
        if (section) {
            const computeBtn = section.querySelector('.compute-btn');
            computeBtn.disabled = false;
            computeBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload & Process File';
            currentUploadStatusBox.style.display = 'none';
            currentUploadStatusBox.className = 'message-box upload-status'; 
        }
        currentFormData = null;
        currentUploadStatusBox = null;
    });


    // --- UPLOAD HANDLER ---
    document.querySelectorAll('.grade-upload-form').forEach(form => {
        const classId = form.dataset.classId;
        const term = form.dataset.term;
        
        const section = form.closest('.workflow-section');
        const uploadStatus = section.querySelector('.upload-status');
        const computeBtn = form.querySelector('.compute-btn');
        const fileInput = form.querySelector('.excel-file-input');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            currentFormData = new FormData(form); 
            currentUploadStatusBox = uploadStatus;
            
            if (!fileInput.value) {
                window.alert('Please select a file to upload.');
                uploadStatus.style.display = 'none'; 
                return;
            }

            computeBtn.disabled = true;
            computeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            uploadStatus.innerHTML = '⏳ Processing file and checking for duplicates...';
            uploadStatus.className = 'message-box upload-status warning-message';
            uploadStatus.style.display = 'block';
            
            currentFormData.append('class_id', classId);
            currentFormData.append('term', term);
            currentFormData.append('professor_sid', '<?= $current_user_id ?>');

            try {
                const response = await fetch('upload_grades.php', {
                    method: 'POST',
                    body: currentFormData 
                });

                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    const textError = await response.text();
                    throw new Error("Server did not return JSON. Response: " + textError);
                }

                const result = await response.json();
                
                if (result.status === 'review') {
                    showReviewModal(result.review_list); 
                } else if (result.status === 'success') {
                    uploadStatus.className = 'message-box upload-status success-message';
                    uploadStatus.innerHTML = `✅ ${result.message || 'Scores saved successfully.'}`;
                    fileInput.value = ''; 
                    computeBtn.disabled = false;
                    computeBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload & Process File';
                    
                    currentFormData = null;
                    currentUploadStatusBox = null;

                    setTimeout(() => { uploadStatus.style.display = 'none'; }, 5000);

                } else {
                    throw new Error(result.error || result.message || 'Unknown server error.');
                }
            } catch (error) {
                window.alert('Upload Error:\n\n' + error.message);
                uploadStatus.style.display = 'none'; 
                computeBtn.disabled = false;
                computeBtn.innerHTML = '<i class="fa-solid fa-upload"></i> Upload & Process File';
                currentFormData = null;
                currentUploadStatusBox = null;
            }
        });
    });

    // --- FINAL SUBMISSION HANDLERS ---
    document.querySelectorAll('.validate-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const section = e.currentTarget.closest('.final-submission-section');
            const workflowSection = e.currentTarget.closest('.workflow-section');
            const classId = workflowSection.querySelector('.grade-upload-form').dataset.classId;
            const term = workflowSection.querySelector('.grade-upload-form').dataset.term;

            const validateBtn = section.querySelector('.validate-btn');
            const submitBtn = section.querySelector('.submit-btn');
            const gradePreviewArea = section.querySelector('.grade-preview-area');
            
            let finalStatusBox = section.querySelector('.final-upload-status');
            if (!finalStatusBox) {
                finalStatusBox = document.createElement('div');
                finalStatusBox.className = 'message-box final-upload-status';
                finalStatusBox.style.display = 'none';
                section.querySelector('p').insertAdjacentElement('afterend', finalStatusBox);
            }
            
            if (!classId || !term) {
                window.alert('Error: No Class ID or Term.');
                finalStatusBox.style.display = 'none'; 
                return;
            }
            
            validateBtn.disabled = true;
            validateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Validating...';
            finalStatusBox.innerHTML = '⏳ Running validation and risk forecast...';
            finalStatusBox.className = 'message-box final-upload-status warning-message';
            finalStatusBox.style.display = 'block';
            
            try {
                const response = await fetch(`validate_grade.php?class_id=${classId}&term=${term}`, { method: 'POST' });

                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    const textError = await response.text();
                    throw new Error("Server did not return JSON. Response: " + textError);
                }
                
                const result = await response.json();

                if (response.ok && result.status === 'success') {
                    const riskMsg = result.risk_count > 0 ? `**${result.risk_count} student(s) flagged AT RISK.** Review below!` : 'No students flagged at risk.';
                    finalStatusBox.className = 'message-box final-upload-status success-message';
                    finalStatusBox.innerHTML = `✅ **Validation Complete.** ${riskMsg}`;
                    submitBtn.disabled = false; 
                    
                    renderGradePreview(result.validated_grades_preview, gradePreviewArea);
                } else {
                    throw new Error(result.message || 'Server error.');
                }
            } catch (error) {
                    window.alert('Validation Error:\n\n' + error.message);
                    finalStatusBox.style.display = 'none'; 
            } finally {
                validateBtn.disabled = false;
                validateBtn.innerHTML = '<i class="fa-solid fa-flask"></i> 1. Run Validation';
            }
        });
    });

    document.querySelectorAll('.submit-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const section = e.currentTarget.closest('.final-submission-section');
            const workflowSection = e.currentTarget.closest('.workflow-section');
            const classId = workflowSection.querySelector('.grade-upload-form').dataset.classId;
            const term = workflowSection.querySelector('.grade-upload-form').dataset.term;
            const validateBtn = section.querySelector('.validate-btn');
            const finalStatusBox = section.querySelector('.final-upload-status'); 
            
            if (!classId || !term || !finalStatusBox) return;
            
            if (!confirm(`WARNING: Are you sure you want to submit ${term} grades for this class? This will lock the grades and send them for official Program Head Approval.`)) {
                return;
            }
            
            btn.disabled = true;
            validateBtn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
            finalStatusBox.innerHTML = '⏳ Submitting grades for Program Head Approval...';
            finalStatusBox.className = 'message-box final-upload-status warning-message';
            finalStatusBox.style.display = 'block';

            try {
                const response = await fetch(`submit_for_approval.php?class_id=${classId}&term=${term}`, { method: 'POST' });
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    const textError = await response.text();
                    throw new Error("Server did not return JSON. Response: " + textError);
                }
                const result = await response.json();
                if (response.ok && result.status === 'success') {
                    window.location.href = `input_grades.php?success=1&msg=${encodeURIComponent(result.message)}`;
                } else {
                    throw new Error(result.message || 'Unknown server error.');
                }
            } catch (error) {
                    window.alert('Submission Failed:\n\n' + error.message);
                    finalStatusBox.style.display = 'none'; 
                    btn.disabled = false;
                    validateBtn.disabled = false;
            }
        });
    });

    function renderGradePreview(grades, previewArea) {
        if (!grades || grades.length === 0) {
            previewArea.innerHTML = '<p class="no-data">No grade preview available.</p>';
            previewArea.style.display = 'block';
            return;
        }
        let tableHTML = `
            <h4>Grade Preview & Validation</h4>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Computed Grade</th>
                            <th>Validation Error</th>
                            <th>AI Risk Status</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        grades.forEach(g => {
            const isAtRisk = g.risk_status === 'AT_RISK';
            const rowClass = isAtRisk ? 'risk-critical' : '';
            const studentId = g.student_id || 'N/A';
            const computedGrade = g.computed_grade_display || 'N/A'; 
            const validationError = g.validation_error || '—';
            const riskStatus = (g.risk_status || 'N/A').replace('_', ' ');

            tableHTML += `
                <tr class="${rowClass}">
                    <td>${studentId}</td>
                    <td><strong>${computedGrade}</strong></td>
                    <td class="${g.validation_error ? 'critical-text' : ''}">${validationError}</td>
                    <td><span class="${isAtRisk ? 'critical-text' : 'success-text'}">${riskStatus}</span></td>
                </tr>
            `;
        });
        tableHTML += `</tbody></table></div>`;
        previewArea.innerHTML = tableHTML;
        previewArea.style.display = 'block';
    }
});
</script>
</body>
</html>