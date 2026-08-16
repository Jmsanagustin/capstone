<?php
// PHP 8.1+ Instructor Dashboard - Fully Integrated System
// PAGE: teacher_dashboard.php
//
// --- CHANGELOG ---
// ... (previous changelogs)
// 13. [REPLACE] Removed notification bar and hidden alert section.
// 14. [ADD] Added notification bell icon to header with a popover for all alerts.
// 15. [FIX] Updated student "Contact" link to use `sid` for messaging.
// 16. [ENHANCE] Integrated responsive sidebar and logo from student_dashboard.
// 17. [ENHANCE] Grouped subjects by Academic Year and Semester.

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

$PASSING_GRADE = 75.00; // Passing grade

// Initialize data containers
$conversations = [];
$professor_classes = []; 
$failing_exam_students = []; 
$missing_grade_students = []; 
$failing_item_students = []; 

try {
    if (!$conn) {
        throw new PDOException("Database connection object (\$conn) is not initialized.");
    }

    // --- Fetch Professor's First Name for greeting ---
    if ($teacher_name === 'Professor' || $teacher_name === $current_user_name) {
        $stmt_prof_name = $conn->prepare("SELECT first_name FROM user_profiles WHERE sid = ?");
        $stmt_prof_name->execute([$current_user_id]);
        $teacher_name_from_db = $stmt_prof_name->fetchColumn();
        if ($teacher_name_from_db) {
            $teacher_name = $teacher_name_from_db;
            $_SESSION['name'] = $teacher_name; 
        }
    }

    // --- POST HANDLER FOR "Mark as Settled" ---
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
            
            $_SESSION['dashboard_message'] = ['type' => 'success', 'body' => 'Alert marked as settled.'];
        } else {
            $_SESSION['dashboard_message'] = ['type' => 'error', 'body' => 'Failed to mark alert as settled. Invalid data.'];
        }
        
        // (MODIFIED) Redirect back to main dashboard (popover will be updated)
        header("Location: teacher_dashboard.php"); 
        exit();
    }
    // --- END POST HANDLER ---
    
    // --- Fetch conversations for sidebar (using full names) ---
    $stmt_conv_fetch = $conn->prepare("
        SELECT c.conversation_id, c.updated_at,
               CASE 
                   WHEN c.user_1_id = :current_user_id 
                   THEN CONCAT(up2.first_name, ' ', up2.last_name) 
                   ELSE CONCAT(up1.first_name, ' ', up1.last_name) 
               END AS other_full_name,
               (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.conversation_id AND m.receiver_id = :current_user_id AND m.is_read = 0) AS unread_count
        FROM conversations c
        JOIN users u1 ON c.user_1_id = u1.sid
        JOIN users u2 ON c.user_2_id = u2.sid
        LEFT JOIN user_profiles up1 ON u1.sid = up1.sid
        LEFT JOIN user_profiles up2 ON u2.sid = up2.sid
        WHERE (c.user_1_id = :current_user_id OR c.user_2_id = :current_user_id)
        ORDER BY c.updated_at DESC
    ");
    $stmt_conv_fetch->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
    $stmt_conv_fetch->execute();
    $conversations = $stmt_conv_fetch->fetchAll(PDO::FETCH_ASSOC);

    // --- Session Message Handling ---
    if (isset($_SESSION['dashboard_message'])) {
        $session_message = $_SESSION['dashboard_message'];
        unset($_SESSION['dashboard_message']);
        
        if ($session_message['type'] === 'success') {
            $success_message = $session_message['body'];
        } else {
            $error_message = $session_message['body'];
        }
    }
    
    // =========================================================================
    // --- FETCH INTERVENTION DATA (ALERTS) ---
    // =========================================================================
    
    // 1. Find students FAILING a Major Exam
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
        ORDER BY p.last_name, s.subject_code
    ");
    $stmt_failing->execute([
        ':current_user_id' => $current_user_id,
        ':passing_grade' => $PASSING_GRADE
    ]);
    $failing_exam_students = $stmt_failing->fetchAll(PDO::FETCH_ASSOC);

    // 2. Find students FAILING any OTHER item (Quiz, PT, etc.)
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
        ORDER BY p.last_name, s.subject_code
    ");
    $stmt_failing_items->execute([
        ':current_user_id' => $current_user_id,
        ':passing_grade' => $PASSING_GRADE
    ]);
    $failing_item_students = $stmt_failing_items->fetchAll(PDO::FETCH_ASSOC);


    // 3. Find students with ZERO raw scores in any active class.
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
        ORDER BY p.last_name, s.subject_code
    ");
    $stmt_missing->execute([':current_user_id' => $current_user_id]);
    $missing_grade_students = $stmt_missing->fetchAll(PDO::FETCH_ASSOC);
    
    // --- (NEW) Calculate Total Alerts ---
    $total_alerts = count($failing_exam_students) + count($missing_grade_students) + count($failing_item_students);

    // =========================================================================
    // B. FETCH DATA FOR HIERARCHICAL VIEW
    // =========================================================================
    
    $stmt_classes = $conn->prepare("
        SELECT 
            c.class_id, c.section, c.subject_id, s.subject_name, s.subject_code,
            c.academic_year, c.semester
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        WHERE c.professor_sid = :current_user_id
        ORDER BY c.academic_year DESC, c.semester DESC, s.subject_code, c.section
    ");
    $stmt_classes->execute([':current_user_id' => $current_user_id]);
    $all_professor_classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

    // Manually build the hierarchical array
    $professor_classes = [];
    foreach ($all_professor_classes as $class) {
        $year = $class['academic_year'];
        $sem = $class['semester'];
        $code = $class['subject_code'];
        
        if (!isset($professor_classes[$year])) $professor_classes[$year] = [];
        if (!isset($professor_classes[$year][$sem])) $professor_classes[$year][$sem] = [];
        if (!isset($professor_classes[$year][$sem][$code])) {
            $professor_classes[$year][$sem][$code] = [
                'subject_name' => $class['subject_name'],
                'sections' => []
            ];
        }
        $professor_classes[$year][$sem][$code]['sections'][] = $class;
    }


    // Fetches all student grade info
    $stmt_students = $conn->prepare("
        SELECT
            u.sid, u.username, p.first_name, p.last_name,
            g_mid.final_grade AS midterm_grade_approved,  
            g_final.term_grade AS final_term_grade,      
            g_final.final_grade AS final_semester_grade, 
            g_final.status AS final_status,
            g_mid.status AS midterm_status
        FROM enrollment e
        JOIN users u ON e.student_sid = u.sid
        JOIN user_profiles p ON u.sid = p.sid
        LEFT JOIN grades g_mid ON u.username = g_mid.student_id_string 
            AND g_mid.subject_id = :subject_id AND g_mid.term = 'Midterm'
        LEFT JOIN grades g_final ON u.username = g_final.student_id_string 
            AND g_final.subject_id = :subject_id AND g_final.term = 'Final'
        WHERE e.class_id = :class_id
        ORDER BY p.last_name, p.first_name
    ");
    
    // Loop through new 3-level array to fetch students
    foreach ($professor_classes as $year => &$semesters) {
        foreach ($semesters as $sem => &$subjects) {
            foreach ($subjects as $code => &$subject_data) {
                foreach ($subject_data['sections'] as $key => &$section) {
                    
                    $stmt_students->execute([
                        ':subject_id' => $section['subject_id'],
                        ':class_id' => $section['class_id']
                    ]);
                    $students_in_section = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
                    
                    $section['students'] = $students_in_section;
                    $section['student_count'] = count($students_in_section);
                }
            }
        }
    }
    unset($semesters, $subjects, $subject_data, $section);


} catch (PDOException $e) {
    $db_error = "Database error: " . $e->getMessage();
}

// Helper function (Unchanged)
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instructor Portal - Dashboard</title>
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

/* --- (MODIFIED) Sidebar --- */
.sidebar { 
    width: 260px; background: var(--nav-dark); color: var(--white); 
    display: flex; flex-direction: column; flex-shrink: 0; height: 100vh;
    /* For Toggle */
    position: fixed; top: 0; left: 0; z-index: 40;
    transition: transform 0.3s ease-in-out;
    transform: translateX(-100%); 
}
.sidebar-visible {
    transform: translateX(0);
}
.sidebar-header { padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar-header-logo { display: flex; align-items: center; gap: 10px; text-decoration: none; color: white; }
.sidebar-header-logo img {
    height: 32px; 
    width: 32px;
}
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

/* --- (MODIFIED) Main Content --- */
.main-content { 
    flex-grow: 1; display: flex; flex-direction: column; 
    height: 100vh; overflow: hidden; 
    transition: margin-left 0.3s ease-in-out;
    width: 100%; 
}
.header { display: flex; justify-content: space-between; align-items: center; padding: 16px 32px; background: var(--white); border-bottom: 1px solid #e0e6ed; flex-shrink: 0; }
.header-left { display: flex; align-items: center; gap: 16px; } 
.header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
.user-profile { display: flex; align-items: center; gap: 12px; }
.user-profile .icon-button { 
    font-size: 1.2rem; color: var(--text-light); text-decoration: none; 
    padding: 8px; border-radius: 50%; transition: background 0.2s ease;
    position: relative; /* (NEW) For badge */
    background: none; /* (NEW) Ensure button style */
    border: none; /* (NEW) */
    cursor: pointer; /* (NEW) */
}
.user-profile .icon-button:hover { background: var(--bg-light); }
.user-profile-info { text-align: right; }
.user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
.user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
.page-view { flex-grow: 1; overflow-y: auto; padding: 32px; }

/* (NEW) Notification Badge */
.notification-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    background: var(--critical);
    color: var(--white);
    font-size: 0.7rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 50%;
    border: 2px solid var(--white);
}

/* (NEW) Notification Popover */
.notification-popover {
    display: none; /* Hidden by default */
    position: absolute;
    top: 65px; /* Below header */
    right: 150px; /* Adjust as needed */
    width: 380px;
    max-height: 400px;
    background: var(--white);
    border-radius: 12px;
    box-shadow: var(--shadow-md);
    z-index: 50;
    border: 1px solid #e0e6ed;
    overflow: hidden;
    flex-direction: column;
}
.notification-popover.visible {
    display: flex;
}
.popover-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e0e6ed;
    flex-shrink: 0;
}
.popover-header h3 {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
    padding: 0;
    border: none;
}
.popover-list {
    list-style-type: none;
    flex-grow: 1;
    overflow-y: auto;
    padding: 0;
}
.popover-list li {
    border-bottom: 1px solid #f2f5f8;
}
.popover-list li:last-child {
    border-bottom: none;
}
/* (MODIFIED) Popover list item content */
.popover-item-content {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 12px 20px;
    text-decoration: none;
    color: var(--text-dark);
    transition: background 0.2s ease;
}
.popover-item-content:hover {
     background: var(--bg-light);
}
.popover-list .alert-icon {
    font-size: 1.2rem;
    flex-shrink: 0;
    width: 25px;
    text-align: center;
}
.popover-list .alert-icon.critical { color: var(--critical); }
.popover-list .alert-icon.warning { color: var(--warning); }
.popover-list .alert-icon.missing { color: var(--text-light); }

.popover-list .alert-text {
    display: flex;
    flex-direction: column;
    flex-grow: 1; /* Allow text to grow */
}
.popover-list .alert-text a {
    text-decoration: none;
    color: inherit;
}
.popover-list .alert-text a:hover {
    text-decoration: underline;
}

.popover-list .alert-text strong {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-dark);
}
.popover-list .alert-text p {
    font-size: 0.85rem;
    color: var(--text-light);
    margin: 0;
    line-height: 1.4;
}
/* (NEW) Settle form inside popover */
.popover-list .settle-form {
    align-self: flex-start;
    margin-top: 5px;
}
.popover-list .settle-form button {
    background: none; border: none;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-light);
    cursor: pointer;
    padding: 2px 5px;
}
.popover-list .settle-form button:hover {
    color: var(--success-green);
    text-decoration: underline;
}

.popover-list li.no-alerts {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    text-align: center;
    color: var(--text-light);
}
.popover-list li.no-alerts i {
    font-size: 2rem;
    color: var(--success-green);
    margin-bottom: 10px;
}
/* (END NEW) */

.toggle-button {
    background: none; border: none; cursor: pointer; color: var(--text-dark);
    padding: 4px;
}
.toggle-button i { font-size: 1.25rem; }
.lg-hidden { display: none; } 

/* --- Content & Table Styles --- */
.content-section { background: var(--white); border-radius: 12px; padding: 24px; box-shadow: var(--shadow); margin-bottom: 32px; }
.content-section h2 { 
    font-size: 1.5rem; 
    font-weight: 700; 
    color: var(--nav-dark); 
    margin-bottom: 10px; 
    display: flex; 
    align-items: center; 
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--panel-blue);
}
.content-section h3 { 
    font-size: 1.25rem; 
    font-weight: 600; 
    color: var(--panel-blue); 
    margin: 20px 0 15px 0; 
    padding-bottom: 10px; 
    border-bottom: 1px solid #e0e6ed;
}
.content-section h4 { 
    font-size: 1.1rem; 
    font-weight: 700; 
    color: var(--text-dark); 
    margin: 20px 0 15px 0; 
    padding-bottom: 10px; 
    border-bottom: 2px solid var(--bg-light);
}
.section-box {
    margin-left: 20px; 
    margin-top: 15px; 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    background: #f9fafb; 
    padding: 12px 15px; 
    border-radius: 8px;
    border: 1px solid #e0e6ed;
}

.dashboard-banner{ background: linear-gradient(135deg, var(--panel-blue), #2b77a3); color: white; padding: 25px; border-radius: 12px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center; box-shadow: var(--shadow); }
.dashboard-banner h2{margin:0; font-size: 1.8em;}
.dashboard-banner p{margin: 5px 0 0 0; opacity: 0.9;}
.timer{background:rgba(255,255,255,0.2);padding:8px 16px;border-radius:20px;font-weight:bold;font-size: 1.1em;}
.message-box{padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold;}
.error-message{background: #fde8e8; color: var(--critical); border: 1px solid var(--critical);}
.success-message{background: #e6f7e6; color: var(--success-green); border: 1px solid var(--success-green);}
.warning-message{background: #fff8e1; color: var(--warning); border: 1px solid var(--warning);}
.no-data { text-align: center; padding: 30px; color: var(--text-light); }
.action-button { background: var(--panel-blue); color: var(--white); font-weight: bold; border: none; cursor: pointer; transition: background 0.2s; padding: 6px 12px; border-radius: 8px; font-size: 0.85rem; text-decoration: none;}
.action-button:hover{background: var(--nav-dark);}
.action-button.contact-btn { background: var(--success-green); }
.action-button.breakdown-btn { background: var(--warning); color: var(--text-dark); }
.table-container { width: 100%; overflow-x: auto; }
.data-table{width:100%;border-collapse:collapse;margin-top:15px; background:var(--white); border-radius: 8px; overflow: hidden; box-shadow: var(--shadow); }
.data-table th{background:var(--bg-light);color:var(--text-light);padding:12px 15px;text-align:left;font-weight:600;text-transform:uppercase;font-size:0.75rem;}
.data-table td{border-bottom:1px solid #e0e6ed;padding:15px; vertical-align: middle; text-align: left;}
.data-table th.center, .data-table td.center { text-align: center; }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table .risk-critical{background:rgba(204, 51, 51, 0.08);}
.data-table .risk-warning{background:rgba(255, 153, 0, 0.08);}
.performance-flag{font-size:11px; font-weight:bold; margin-top:3px; line-height: 1.2;}
.critical{color:var(--critical);}
.warning{color:var(--warning);}
.missing{color:var(--muted); font-style: italic;}
.success{color:var(--success-green);} 
.custom-scrollbar::-webkit-scrollbar { width: 8px; }
.custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.footer{text-align:center;font-size:12px;color:var(--muted);margin-top:30px; padding: 10px 0;}

/* (REMOVED) Old Alert Box Styles */
/* (REMOVED) Notification Bar */

/* Student List Toggle */
.student-list-toggle {
    background: none;
    border: 1px solid var(--panel-blue);
    color: var(--panel-blue);
    font-weight: 600;
    font-size: 0.85rem;
    padding: 4px 10px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.student-list-toggle:hover {
    background: var(--panel-blue);
    color: var(--white);
}
.student-list-toggle .fa-plus,
.student-list-toggle.expanded .fa-minus {
    display: inline-block;
}
.student-list-toggle .fa-minus,
.student-list-toggle.expanded .fa-plus {
    display: none;
}
.student-list-collapsible {
    display: none; 
    margin-left: 20px; 
    margin-right: 20px; 
    width: auto;
    border-top: 1px solid #e0e6ed;
    padding-top: 15px;
    margin-top: 15px;
}

/* --- (NEW) Responsive Media Queries --- */
@media (max-width: 1023px) { /* lg breakpoint */
    .lg-hidden { display: inline-block; } 
    .header { padding-left: 16px; padding-right: 16px; }
    .page-view { padding: 16px; }
    .header-title { font-size: 1.2rem; }
    .section-box { flex-direction: column; align-items: flex-start; gap: 10px; }
    .data-table { font-size: 0.9rem; }
    /* (NEW) Reposition popover on mobile */
    .notification-popover {
        right: 16px;
        width: 320px;
    }
}

/* FIX: Desktop & Sidebar Toggle Behavior */
@media (min-width: 1024px) { /* lg breakpoint */
    .sidebar {
        transform: translateX(0); 
    }
    .main-content {
        margin-left: 260px; 
    }
    .lg-hidden {
        display: none; 
    }
    #sidebar-overlay {
        display: none !important; 
    }
}
</style>
</head>
<body>

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
            <li><a href="teacher_dashboard.php" class="nav-link active" data-view="dashboard"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="input_grades.php" class="nav-link" data-view="grade_input"><i class="fa-solid fa-file-upload"></i><span>Grade Input</span></a></li>
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

<div class="main-content" id="main-content">

    <header class="header">
        <div class="header-left">
            <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h1 class="header-title">Dashboard</h1>
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
                <span><?php echo htmlspecialchars(ucfirst($current_user_role)); ?></span>
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
                    <i class="fa-solid fa-check-circle"></i>
                    <span>No alerts. All students are on track.</span>
                </li>
            <?php endif; ?>
            
            <?php foreach ($failing_exam_students as $student): ?>
                <li>
                    <div class="popover-item-content">
                        <span class="alert-icon critical"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <div class="alert-text">
                            <a href="professor_student_breakdown.php?student_sid=<?= $student['sid'] ?>&class_id=<?= $student['class_id'] ?>">
                                <strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></strong>
                            </a>
                            <p>Failing Major Exam (<?= number_format($student['transmuted_grade'], 2) ?>) in <?= htmlspecialchars($student['subject_code']) ?></p>
                            
                            <form method="POST" action="teacher_dashboard.php" class="settle-form">
                                <input type="hidden" name="action" value="mark_settled">
                                <input type="hidden" name="student_sid" value="<?= $student['sid'] ?>">
                                <input type="hidden" name="class_id" value="<?= $student['class_id'] ?>">
                                <input type="hidden" name="alert_type" value="failing">
                                <button type="submit" title="Mark as Settled">Mark as Settled</button>
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
                            <a href="professor_student_breakdown.php?student_sid=<?= $student['sid'] ?>&class_id=<?= $student['class_id'] ?>">
                                <strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></strong>
                            </a>
                            <p>Failing Item (<?= number_format($student['transmuted_grade'], 2) ?>) in <?= htmlspecialchars($student['subject_code']) ?></p>
                            
                            <form method="POST" action="teacher_dashboard.php" class="settle-form">
                                <input type="hidden" name="action" value="mark_settled">
                                <input type="hidden" name="student_sid" value="<?= $student['sid'] ?>">
                                <input type="hidden" name="class_id" value="<?= $student['class_id'] ?>">
                                <input type="hidden" name="alert_type" value="failing_item">
                                <button type="submit" title="Mark as Settled">Mark as Settled</button>
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
                            <a href="input_grades.php?class_id=<?= $student['class_id'] ?>">
                                <strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></strong>
                            </a>
                            <p>Missing all grades in <?= htmlspecialchars($student['subject_code']) ?></p>
                            
                            <form method="POST" action="teacher_dashboard.php" class="settle-form">
                                <input type="hidden" name="action" value="mark_settled">
                                <input type="hidden" name="student_sid" value="<?= $student['sid'] ?>">
                                <input type="hidden" name="class_id" value="<?= $student['class_id'] ?>">
                                <input type="hidden" name="alert_type" value="missing">
                                <button type="submit" title="Mark as Settled">Mark as Settled</button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <main class="page-view custom-scrollbar">

        <?php if ($db_error): // Show general DB errors ?>
            <div class="message-box error-message"><?php echo $db_error; ?></div>
        <?php endif; ?>
        <?php if ($error_message): // Show main page errors ?>
            <div class="message-box error-message">❌ <?php echo htmlspecialchars($error_message); ?></div>
        <?php elseif ($success_message): // Show success message (if any) ?>
            <div class="message-box success-message">✅ <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        
        <div class="dashboard-banner">
            <div>
                <h2>Hello <?php echo htmlspecialchars($teacher_name); ?> 👋</h2>
                <p>Welcome to your instructor dashboard.</p>
            </div>
            <div class="timer" id="clock">00:00:00</div>
        </div>

        <section class="content-section">
            
            <?php if (empty($professor_classes)): ?>
                <h2><i class="fa-solid fa-book"></i> Subjects Handled</h2>
                <p class="no-data">You are not currently assigned to any subjects or sections.</p>
            <?php else: ?>
                
                <?php foreach ($professor_classes as $year => $semesters): ?>
                    <h2>
                        <i class="fa-solid fa-calendar-alt"></i> 
                        Academic Year: <?= htmlspecialchars($year) ?>
                    </h2>
                    
                    <?php foreach ($semesters as $sem => $subjects): ?>
                        <h3><?= htmlspecialchars($sem) ?></h3>
                        
                        <?php foreach ($subjects as $subject_code => $subject_data): ?>
                            <h4 style="margin-left: 10px;">
                                <i class="fa-solid fa-book"></i>
                                <?= htmlspecialchars($subject_code) ?> - <?= htmlspecialchars($subject_data['subject_name']) ?>
                            </h4>

                            <?php foreach ($subject_data['sections'] as $section): ?>
                                
                                <div class="section-box">
                                    <span>
                                        <i class="fa-solid fa-users"></i>
                                        Section: <strong><?= htmlspecialchars($section['section']) ?></strong>
                                        (<?= $section['student_count'] ?> Students)
                                    </span>
                                    
                                    <span style="font-size: 0.9rem; color: var(--text-dark); display: flex; align-items: center; gap: 15px;">
                                        <button class="student-list-toggle" data-target="student-list-<?= $section['class_id'] ?>">
                                            <i class="fa-solid fa-plus"></i><i class="fa-solid fa-minus"></i>
                                            Show Students
                                        </button>
                                        
                                        <a href="input_grades.php?class_id=<?= $section['class_id'] ?>" class="action-button" style="background: var(--panel-blue);" title="Manage this class (components, items)">
                                            <i class="fa-solid fa-cog"></i> Manage Class
                                        </a>
                                    </span>
                                </div>

                                <div class="student-list-collapsible" id="student-list-<?= $section['class_id'] ?>">
                                    <?php if (empty($section['students'])): ?>
                                        <p class="no-data" style="padding: 15px;">No students enrolled in this section.</p>
                                    <?php else: ?>
                                        <div class="table-container" style="margin: 0; width: 100%;">
                                            <table class="data-table">
                                                <thead>
                                                    <tr>
                                                        <th>Student Name</th>
                                                        <th class="center">Overall Grade</th>
                                                        <th class="center">Status / Warning</th>
                                                        <th class="center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($section['students'] as $student): ?>
                                                        
                                                        <?php
                                                            $grade = null;
                                                            $status = 'Draft'; // Default
                                                            $status_class = 'missing';
                                                            $status_icon = '✏️';
                                                            $status_text = 'Draft (Not Submitted)';

                                                            if ($student['final_semester_grade'] !== null) {
                                                                $grade = $student['final_semester_grade'];
                                                                $status = $student['final_status'];
                                                            } elseif ($student['midterm_grade_approved'] !== null) {
                                                                $grade = $student['midterm_grade_approved'];
                                                                $status = $student['midterm_status'];
                                                            }

                                                            if ($status == 'Pending PH Approval') {
                                                                $status_class = 'warning';
                                                                $status_icon = '⏳';
                                                                $status_text = 'Pending Approval';
                                                            } elseif ($status == 'Approved by PH' || $status == 'Acknowledged by Registrar') {
                                                                if ($grade < $PASSING_GRADE) {
                                                                    $status_class = 'critical';
                                                                    $status_icon = '🚨';
                                                                    $status_text = 'Failing';
                                                                } else {
                                                                    $status_class = 'success';
                                                                    $status_icon = '✅';
                                                                    $status_text = 'Approved';
                                                                }
                                                            } elseif ($status == 'Rejected') {
                                                                $status_class = 'critical';
                                                                $status_icon = '❌';
                                                                $status_text = 'Rejected';
                                                            }
                                                        ?>

                                                        <tr class="<?= $status_class === 'critical' ? 'risk-critical' : ($status_class === 'warning' ? 'risk-warning' : '') ?>">
                                                            <td>
                                                                <strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></strong>
                                                            </td>
                                                            <td class="center">
                                                                <strong><?= $grade !== null ? htmlspecialchars(round($grade, 2)) : 'N/A' ?></strong>
                                                            </td>
                                                            <td class="center">
                                                                <span class="performance-flag <?= $status_class ?>">
                                                                    <?= $status_icon ?> <?= $status_text ?>
                                                                </span>
                                                            </td>
                                                            <td class="center">
                                                                <a href="professor_student_breakdown.php?student_sid=<?= $student['sid'] ?>&class_id=<?= $section['class_id'] ?>" class="action-button breakdown-btn" title="View Grade Breakdown" style="margin-right: 5px;">
                                                                    <i class="fa-solid fa-calculator"></i> Breakdown
                                                                </a>
                                                                <a href="teacher_message.php?start_with_sid=<?= $student['sid'] ?>" class="action-button contact-btn" title="Contact Student">
                                                                    <i class="fa-solid fa-comment"></i> Contact
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div> 
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            
        </section>
        
        <div class="footer">© 2025 Smart Grade Monitoring | Integrated Teacher Module</div>
    </main>
</div>

<script>
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
</script>

<script>
// --- START: Full JavaScript Logic ---

// Utility function to set the active state on sidebar links
function setActiveSidebarLink() {
    const navLinks = document.querySelectorAll('.sidebar-nav a.nav-link');
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('data-view') === 'dashboard') {
            link.classList.add('active');
        }
    });
}
// Utility function for the clock
function updateClock() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const timeString = `${hours}:${minutes}:${seconds}`;
    const clockElement = document.getElementById('clock');
    if (clockElement) {
        clockElement.textContent = timeString;
    }
}


document.addEventListener("DOMContentLoaded", function() {
    // (NEW) Sidebar toggle init
    if (window.innerWidth >= 1024) {
        document.getElementById('sidebar').classList.add('sidebar-visible');
    }
    window.addEventListener('resize', handleResize);
    // (END NEW)

    setActiveSidebarLink();
    setInterval(updateClock, 1000);
    updateClock(); // Initial call

    // --- (NEW) Notification Popover Logic ---
    const bellBtn = document.getElementById('notification-bell-btn');
    const popover = document.getElementById('notification-popover');
    
    if (bellBtn && popover) {
        bellBtn.addEventListener('click', function(event) {
            event.stopPropagation(); // Stop click from bubbling to window
            
            // This 'toggle' command makes it appear and disappear on click
            popover.classList.toggle('visible'); 
        });
    }
    
    // (NEW) Close popover if clicking outside
    window.addEventListener('click', function(event) {
        if (popover && popover.classList.contains('visible')) {
            // Check if the click is outside the popover AND outside the bell button
            if (!popover.contains(event.target) && !bellBtn.contains(event.target)) {
                popover.classList.remove('visible');
            }
        }
    });
    // --- (END NEW) ---

    // --- (REMOVED) Old Alert Bar Toggle Logic ---

    // --- Student List Toggle Logic ---
    const toggleButtons = document.querySelectorAll('.student-list-toggle');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const targetElement = document.getElementById(targetId);
            
            if (targetElement) {
                const isExpanded = targetElement.style.display === 'block';
                if (isExpanded) {
                    targetElement.style.display = 'none';
                    this.classList.remove('expanded');
                    this.innerHTML = '<i class="fa-solid fa-plus"></i><i class="fa-solid fa-minus"></i> Show Students';
                } else {
                    targetElement.style.display = 'block';
                    this.classList.add('expanded');
                    this.innerHTML = '<i class="fa-solid fa-plus"></i><i class="fa-solid fa-minus"></i> Hide Students';
                }
            }
        });
    });
    // --- END: Student List Toggle Logic ---
});
</script>
</body>
</html>