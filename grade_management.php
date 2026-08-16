<?php
// FILE: grade_management.php
// MODIFICATIONS:
// 1. Sidebar CSS & Toggle fixed.
// 2. Mobile responsiveness added.
// 3. "Manage Students" link RESTORED.

session_start();
// Check if db.php is available before including
if (!file_exists('db.php')) {
    die("Configuration Error: Database connection file 'db.php' is missing.");
}
include 'db.php'; // Include database connection

// =========================================================================
// 1. GLOBAL SETUP & ACCESS CONTROL
// =========================================================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    header("Location: index.php");
    exit();
}

// Sanitize and default session variables
$current_user_id = filter_var($_SESSION['sid'] ?? $_SESSION['user_id'] ?? 0, FILTER_SANITIZE_NUMBER_INT);
$teacher_name = htmlspecialchars($_SESSION['name'] ?? 'Professor');
$current_user_name = htmlspecialchars($_SESSION['username'] ?? 'Professor'); 

$db_error = "";
$current_view = filter_var($_GET['view'] ?? 'status', FILTER_SANITIZE_STRING); 
if (!in_array($current_view, ['status', 'history'])) {
    $current_view = 'status'; // Enforce valid view
}

// Data containers
$class_statuses = []; // For Approval Status view
$archived_grades = []; 
$grouped_history = []; 

// Filters and static data for History view
$search_term = filter_var($_GET['search'] ?? '', FILTER_SANITIZE_STRING);
$filter_term = filter_var($_GET['term'] ?? '', FILTER_SANITIZE_STRING);
$filter_subject = filter_var($_GET['subject'] ?? '', FILTER_SANITIZE_STRING);
$unique_terms = [];
$unique_subjects = [];
$PASSING_GRADE = 75.00;

$total_unread = 0; 

// =========================================================================
// 2. HELPER FUNCTIONS (Timer and Approval Status)
// =========================================================================

/**
 * Helper function to get submission status based on class table fields (The 7-Day Timer).
 */
function getSubmissionStatus($start_time, $finalized_time) {
    if ($finalized_time) {
        return ['status' => 'Locked', 'class' => 'locked', 'message' => 'FINALIZED by Registrar/System on ' . date('M j, Y', strtotime($finalized_time))];
    }
    if ($start_time) {
        $deadline = strtotime($start_time . ' + 7 days');
        $time_left = $deadline - time();
        
        if ($time_left > 0) {
            $days = floor($time_left / (60 * 60 * 24));
            $hours = floor(($time_left % (60 * 60 * 24)) / (60 * 60));
            $countdown_text = "Locks in approx. $days days, $hours hours."; 
            return ['status' => 'Pending', 'class' => 'pending', 'message' => $countdown_text];
        } else {
            return ['status' => 'Auto-Lock', 'class' => 'critical', 'message' => 'Deadline passed. Report has been auto-submitted to Program Head.'];
        }
    }
    return ['status' => 'Open', 'class' => 'open', 'message' => 'Not yet started.'];
}

/**
 * Gets the Program Head Approval Status specifically for a given term ('Midterm' or 'Final').
 */
function getTermApprovalStatus($conn, $class_id, $term) {
    $stmt_subj = $conn->prepare("SELECT subject_id FROM classes WHERE class_id = ?");
    $stmt_subj->execute([$class_id]);
    $subject_id = $stmt_subj->fetchColumn();

    if (!$subject_id) {
        return ['status' => 'Class Error', 'class' => 'critical', 'message' => 'Subject not found for class.'];
    }

    $stmt_grade_status = $conn->prepare("
        SELECT g.status
        FROM grades g
        JOIN users u ON g.student_id_string = u.username
        JOIN enrollment e ON u.sid = e.student_sid
        WHERE e.class_id = :class_id AND g.subject_id = :subject_id AND g.term = :term
        ORDER BY FIELD(g.status, 'Rejected', 'Pending Approval', 'Approved by PH', 'Approved by Registrar') DESC
        LIMIT 1 
    ");
    $stmt_grade_status->execute([
        ':class_id' => $class_id,
        ':subject_id' => $subject_id,
        ':term' => $term
    ]);
    $status = $stmt_grade_status->fetchColumn();

    if (!$status) {
        $stmt_check_submission = $conn->prepare("SELECT " . strtolower($term) . "_submission_start FROM classes WHERE class_id = ?");
        $stmt_check_submission->execute([$class_id]);
        $submission_start = $stmt_check_submission->fetchColumn();

        if ($submission_start) {
            return ['status' => 'Draft/Pending', 'class' => 'missing', 'message' => 'Awaiting final submission to Program Head.'];
        } else {
            return ['status' => 'Not Started', 'class' => 'open', 'message' => 'Awaiting initial submission by instructor.'];
        }
    }

    switch ($status) {
        case 'Pending PH Approval':
            return ['status' => 'PH Review', 'class' => 'warning', 'message' => 'Pending review by the Program Head.'];
        case 'Approved by PH':
            return ['status' => 'Approved by PH', 'class' => 'success', 'message' => 'Approved. Sent to Registrar for archival.'];
        case 'Approved by Registrar':
            return ['status' => 'Archived/Finalized', 'class' => 'success', 'message' => 'Finalized and officially recorded.'];
        case 'Rejected':
        case 'Rejected by PH':
            return ['status' => 'Rejected', 'class' => 'critical', 'message' => 'Rejected by PH. Needs review/correction.'];
        default:
            return ['status' => 'Unknown', 'class' => 'muted', 'message' => 'Current status: ' . htmlspecialchars($status)];
    }
}


// =========================================================================
// 3. MAIN DATA FETCH LOGIC
// =========================================================================

try {
    // Fetch unread messages count for the header
    $stmt_conv_fetch = $conn->prepare("
        SELECT COUNT(m.message_id) AS total_unread 
        FROM messages m 
        WHERE m.receiver_id = :current_user_id AND m.is_read = 0
    ");
    $stmt_conv_fetch->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
    $stmt_conv_fetch->execute();
    $total_unread = $stmt_conv_fetch->fetchColumn();

    
    if ($current_view === 'status') {
        // --- FETCH LOGIC FOR GRADE APPROVAL STATUS ---
        $stmt_classes = $conn->prepare("
            SELECT
                c.class_id, c.section, c.midterm_submission_start, c.midterm_finalized_at,
                c.final_submission_start, c.final_finalized_at,
                s.subject_code, s.subject_name
            FROM classes c
            JOIN subject s ON c.subject_id = s.subject_id
            WHERE c.professor_sid = :current_user_id
            ORDER BY s.subject_code, c.section
        ");
        $stmt_classes->execute([':current_user_id' => $current_user_id]);
        $classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

        foreach ($classes as $class) {
            $midterm_timer_status = getSubmissionStatus($class['midterm_submission_start'], $class['midterm_finalized_at']);
            $final_timer_status = getSubmissionStatus($class['final_submission_start'], $class['final_finalized_at']);
            
            $midterm_ph_approval = getTermApprovalStatus($conn, $class['class_id'], 'Midterm');
            $final_ph_approval = getTermApprovalStatus($conn, $class['class_id'], 'Final');

            $class_statuses[] = [
                'subject_code' => $class['subject_code'],
                'subject_name' => $class['subject_name'],
                'section' => $class['section'],
                'midterm_timer' => $midterm_timer_status,
                'final_timer' => $final_timer_status, 
                'midterm_ph_approval' => $midterm_ph_approval,
                'final_ph_approval' => $final_ph_approval,
            ];
        }
        
    } elseif ($current_view === 'history') {
        // --- FETCH LOGIC FOR GRADE HISTORY ---
        $stmt_classes = $conn->prepare("SELECT class_id, section FROM classes WHERE professor_sid = ?");
        $stmt_classes->execute([$current_user_id]);
        $professor_classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);
        $class_ids = array_column($professor_classes, 'class_id');
        $class_map_full = array_column($professor_classes, 'section', 'class_id'); 

        if (empty($class_ids)) {
            $db_error = "No classes assigned to this professor. Grade history cannot be loaded."; 
        } else {
            
            $params = [];
            $in_placeholders = implode(',', array_fill(0, count($class_ids), '?'));
            $sql = "
                SELECT 
                    g.archive_id, g.class_id, g.subject_code, g.subject_name, g.academic_term,
                    g.final_grade, g.gwa, g.student_sid,
                    up.first_name, up.last_name
                FROM grade_archives g
                JOIN user_profiles up ON g.student_sid = up.sid
                WHERE g.class_id IN ($in_placeholders)
            ";
            $params = $class_ids;
            $where_clauses = [];
            
            if (!empty($search_term)) {
                $where_clauses[] = "(up.last_name LIKE ? OR up.first_name LIKE ? OR g.student_sid LIKE ?)";
                $search_param = '%' . $search_term . '%';
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
            }

            if (!empty($filter_term)) {
                $where_clauses[] = "g.academic_term = ?";
                $params[] = $filter_term;
            }
            
            if (!empty($filter_subject)) {
                $where_clauses[] = "g.subject_code = ?";
                $params[] = $filter_subject;
            }

            if (!empty($where_clauses)) {
                $sql .= " AND " . implode(" AND ", $where_clauses);
            }

            $sql .= " ORDER BY g.academic_term DESC, g.subject_code, g.class_id, up.last_name";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params); 
            $archived_grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($archived_grades as $record) {
                $class_id = $record['class_id'];
                $section = $class_map_full[$class_id] ?? 'N/A';
                $key = $record['academic_term'] . '|' . $record['subject_code'] . '|' . $section; 

                if (!isset($grouped_history[$key])) {
                    $grouped_history[$key] = [
                        'term' => $record['academic_term'],
                        'subject_code' => $record['subject_code'],
                        'subject_name' => $record['subject_name'],
                        'section' => $section,
                        'students' => []
                    ];
                }
                $grouped_history[$key]['students'][] = $record;
            }
            
            $stmt_filters_term = $conn->prepare("
                SELECT DISTINCT academic_term FROM grade_archives 
                WHERE class_id IN ($in_placeholders) ORDER BY academic_term DESC
            ");
            $stmt_filters_term->execute($class_ids);
            $unique_terms = $stmt_filters_term->fetchAll(PDO::FETCH_COLUMN);

            $stmt_filters_subject = $conn->prepare("
                SELECT DISTINCT subject_code FROM grade_archives 
                WHERE class_id IN ($in_placeholders) ORDER BY subject_code
            ");
            $stmt_filters_subject->execute($class_ids);
            $unique_subjects = $stmt_filters_subject->fetchAll(PDO::FETCH_COLUMN);
        }
    } 

} catch (PDOException $e) {
    $db_error = "Database error: " . $e->getMessage();
} catch (Exception $e) {
    $db_error = "System error: " . $e->getMessage();
}

// --- FIX: Set static page title and dynamic content title ---
$page_title = 'Grade Management'; // Static title for main header
$content_title = '';
if ($current_view === 'status') {
    $content_title = 'Approval Status';
    $main_header_icon = '<i class="fa-solid fa-list-check"></i>';
} elseif ($current_view === 'history') {
    $content_title = 'Grade History';
    $main_header_icon = '<i class="fa-solid fa-archive"></i>';
}
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $content_title ?> | Grade Management</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* --- UPDATED & Professional CSS (Aligned with Teacher Dashboard) --- */
:root{
    --nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f4f7fa; /* Lighter BG */
    --white:#fff; --text-dark:#102a43; --text-light:#627d98;
    --accent-light:#4db8ff; --critical:#b91c1c; --warning:#a16207; --muted:#6b7280; 
    --success-green: #166534; --shadow: 0 4px 12px rgba(0,0,0,0.05);
    --border-color: #e5e7eb;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100vh; overflow-x: hidden; } /* Allow vertical scroll on mobile */
body{font-family: 'Inter', 'Segoe UI', sans-serif;margin:0;background:var(--bg-light);color:var(--text-dark); display: flex;}

/* SIDEBAR - Aligned with teacher_dashboard.php */
.sidebar { 
    width: 260px; background: var(--nav-dark); color: var(--white); 
    display: flex; flex-direction: column; flex-shrink: 0; 
    height: 100vh; transition: transform 0.3s ease-in-out; 
    z-index: 2000;
}
.sidebar-header { padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; gap: 15px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar-header-logo { text-decoration: none; color: white; display: flex; align-items: center; gap: 10px; }
.sidebar-header img { width: 32px; height: 32px; border-radius: 4px; }
.sidebar-header h2 { font-size: 1.15rem; font-weight: 600; line-height: 1.3; margin: 0; }
.sidebar-header h2 span { font-size: 0.8rem; font-weight: 400; color: #9ac8ee; display: block; }
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

/* MAIN CONTENT */
.main-content { 
    flex-grow: 1; display: flex; flex-direction: column; 
    height: 100vh; overflow-y: auto; /* Allow content to scroll */
    margin-left: 0; /* Default for mobile/flex */
    transition: margin-left 0.3s ease;
    width: 100%;
}
.header { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; background: var(--white); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }

/* Hamburger Menu Button */
.menu-toggle {
    display: none; /* Hidden on desktop by default in other files, but shown here via media query */
    font-size: 1.5rem;
    color: var(--text-dark);
    background: none;
    border: none;
    cursor: pointer;
    padding: 5px;
    margin-right: 10px;
}
.toggle-button { background: none; border: none; cursor: pointer; color: var(--text-dark); padding: 4px; }
.header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
.user-profile { display: flex; align-items: center; gap: 12px; }
.user-profile .icon-button { font-size: 1.2rem; color: var(--text-light); text-decoration: none; padding: 8px; border-radius: 50%; position: relative; }
.user-profile .icon-button:hover { background: var(--bg-light); }
.user-profile-info { text-align: right; }
.user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
.user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
.unread-badge { background: var(--critical); color: var(--white); position: absolute; top: 0; right: 0; border-radius: 50%; padding: 2px 6px; font-size: 0.7rem; }

.page-view { flex-grow: 1; overflow-y: auto; padding: 24px; }
.container { max-width: 100%; margin: 0 auto; background: transparent; border-radius: 0; padding: 0; box-shadow: none; }
h1 { color: var(--text-dark); margin-bottom: 0; padding-bottom: 0; font-size: 1.75rem; font-weight: 700; display: flex; align-items: center; gap: 12px;}
h2 { color: var(--text-dark); margin-top: 0; margin-bottom: 8px; font-size: 1.25rem; font-weight: 600;}
.db-error { padding: 15px; background: #fef2f2; color: var(--critical); border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
.no-data { text-align: center; padding: 40px; color: var(--text-light); font-size: 1rem; }

/* NEW: Page Header/Content Wrapper */
.page-header {
    background: var(--white);
    padding: 24px 30px;
    border-radius: 12px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border-color);
}
.page-content {
    background: var(--white);
    padding: 30px;
    border-radius: 12px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border-color);
    margin-top: 24px; /* Space between header and content */
}
.page-content p {
    font-size: 0.95rem;
    color: var(--text-light);
    margin-bottom: 20px;
}

/* NEW: Toggle Bar Style */
.view-nav { 
    margin-top: 24px; 
    display: inline-flex; 
    gap: 5px; 
    border-radius: 8px;
    background-color: var(--bg-light);
    padding: 5px;
    border: 1px solid var(--border-color);
}
.view-nav a { 
    text-decoration: none; 
    padding: 8px 16px; 
    font-weight: 600; 
    color: var(--text-light); 
    border-radius: 6px;
    transition: all 0.2s;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.view-nav a:hover { 
    background-color: #e5e7eb; /* light gray hover */
    color: var(--text-dark); 
}
.view-nav a.active { 
    background: var(--white); 
    color: var(--panel-blue); 
    box-shadow: 0 2px 5px rgba(0,0,0,0.1); 
    font-weight: 700;
}

.table-container { border: 1px solid var(--border-color); border-radius: 8px; overflow-x: auto; }
.data-table{width:100%;border-collapse:collapse; background:var(--white); min-width: 600px; }
.data-table th{background: #f9fafb; color: var(--text-light); padding:12px 15px;text-align:left;font-weight:600;text-transform:uppercase;font-size:0.8rem; border-bottom: 1px solid var(--border-color); }
.data-table td{border-bottom:1px solid var(--border-color);padding:16px 15px; vertical-align: top; font-size: 0.9rem;}
.data-table tr:last-child td { border-bottom: none; }
.data-table strong { color: var(--text-dark); }

/* NEW: Status Tag Styles */
.status-tag { display: inline-block; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 0.8rem; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
.status-message { margin-top: 5px; color: var(--text-light); font-size: 0.85rem; }
.open { background: #f3f4f6; color: #4b5563; } /* Gray */
.pending, .warning { background: #fefce8; color: #a16207; } /* Yellow */
.critical { background: #fef2f2; color: #b91c1c; } /* Red */
.success { background: #f0fdf4; color: #166534; } /* Green */
.missing { background: #f3f4f6; color: #4b5563; } /* Gray */
.muted { background: #f3f4f6; color: #4b5563; } /* Gray */

.grade-pass { color: var(--success-green); font-weight: 700; }
.grade-fail { color: var(--critical); font-weight: 700; }
.filter-form { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; background: #f9fafb; padding: 15px; border-radius: 8px; align-items: center; border: 1px solid var(--border-color); }
.filter-form label, .filter-form select, .filter-form input { font-size: 0.9rem; font-weight: 500; }
.filter-form input[type="text"], .filter-form select { padding: 8px; border: 1px solid #ccc; border-radius: 6px; min-width: 150px; }
.filter-form button { background: var(--nav-dark); color: var(--white); border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; transition: background 0.2s; font-weight: 500; }
.filter-form button:hover { background: var(--panel-blue); }

.class-group-header {
    background: #eef2ff; /* Light indigo */
    padding: 12px 15px;
    margin-top: 25px;
    border-radius: 6px 6px 0 0;
    font-size: 1.1em;
    font-weight: 600;
    color: #312e81; /* Dark indigo */
    border: 1px solid #c7d2fe;
    border-bottom: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.class-group-header span {
    font-weight: 400;
    font-size: 0.9em;
    color: var(--text-dark);
}
.class-group-table {
    margin-top: 0; 
    border-radius: 0 0 6px 6px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.05); 
    border: 1px solid #c7d2fe;
    width: 100%;
    min-width: 600px;
}
.class-group-table th {
    background: #f9fafb; 
    color: var(--text-light);
    border-bottom: 1px solid var(--border-color);
}

/* NEW: Error Modal CSS */
.modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: none; align-items: center; justify-content: center;
    z-index: 1000;
}
.modal-container {
    background: white; border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    width: 90%; max-width: 450px;
    display: flex; flex-direction: column;
}
.modal-header {
    padding: 16px 24px; border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: space-between; align-items: center;
}
.modal-header h3 { font-size: 1.25rem; font-weight: 600; }
.modal-body { padding: 24px; overflow-y: auto; }
.modal-footer {
    padding: 16px 24px; border-top: 1px solid var(--border-color);
    background: #f9fafb; text-align: right; 
    border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;
}
.modal-close-btn {
    background: #e5e7eb; color: #374151;
    padding: 8px 16px; border: none; border-radius: 6px;
    font-weight: 500; cursor: pointer; transition: background 0.2s;
}
.modal-close-btn:hover { background: #d1d5db; }
.btn-blue {
    background: var(--panel-blue); color: white; padding: 8px 16px; border: none; 
    border-radius: 6px; font-weight: 500; cursor: pointer; transition: background 0.2s;
}
.btn-blue:hover { background: var(--nav-dark); }

/* NEW: Overlay for when sidebar is open */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1999;
    display: none; /* Hidden by default */
}
.sidebar-overlay.sidebar-visible {
    display: block;
}

/* Helper class for responsive hiding */
.lg-hidden { display: none; }

/* ====================================================== */
/* DESKTOP (Default > 1024px) */
/* ====================================================== */
@media (min-width: 1024px) {
    .sidebar { transform: translateX(0); position: fixed; }
    .main-content { margin-left: 260px; width: calc(100% - 260px); }
    .lg-hidden { display: none !important; }
}

/* ====================================================== */
/* MOBILE / TABLET (< 1024px) */
/* ====================================================== */
@media (max-width: 1023px) {
    .sidebar {
        position: fixed;
        transform: translateX(-100%); /* Hidden by default */
    }
    .sidebar.sidebar-visible {
        transform: translateX(0);
    }
    
    .main-content {
        width: 100%;
        margin-left: 0;
        height: auto;
        overflow-y: visible;
    }
    
    .header { padding: 12px 16px; }
    .header-title { display: none; }
    .lg-hidden { display: block; } /* Show hamburger and close buttons */
    .menu-toggle { display: block; margin-right: auto; }
    .user-profile-info { display: none; }

    .page-view { padding: 16px; }
    .page-header, .page-content { padding: 16px; }
    
    .view-nav { width: 100%; }
    .view-nav a { flex-grow: 1; justify-content: center; padding: 10px 5px; font-size: 0.85rem; }
    
    .filter-form { flex-direction: column; align-items: stretch; }
    .filter-form label { display: none; }
    .filter-form input[type="text"] { min-width: 0; }
    .class-group-header { flex-direction: column; align-items: flex-start; gap: 5px; }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<div id="error-modal" class="modal-overlay">
    <div class="modal-container" style="max-width: 450px;">
        <div class="modal-header" style="background-color: #fef2f2; border-bottom: 2px solid #dc2626;">
            <h3 class="text-xl font-bold text-red-700">Error</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('error-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <p id="error-modal-message" class="text-gray-700">An unexpected error occurred.</p>
        </div>
        <div class="modal-footer">
            <button type="button" id="close-error-modal-btn" class="btn-blue">
                OK
            </button>
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
            <li><a href="input_grades.php" class="nav-link" data-view="grade_input"><i class="fa-solid fa-file-upload"></i><span>Grade Input</span></a></li>
            <li><a href="grade_management.php" class="nav-link active" data-view="grade_management"><i class="fa-solid fa-archive"></i><span>Grade Management</span></a></li>
            <li><a href="teacher_message.php" class="nav-link" data-view="messages"><i class="fa-solid fa-comments"></i><span>Messages</span></a></li>
            <li><a href="teacher_manage_students.php" class="nav-link" data-view="manage_students"><i class="fa-solid fa-users-cog"></i><span>Manage Students</span></a></li>
            <li><a href="attendance.php" class="nav-link" data-view="attendance"><i class="fa-solid fa-clipboard-user"></i><span>Attendance</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="main-content" id="main-content">
    <header class="header">
        <button class="menu-toggle lg-hidden" id="menu-toggle" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>
        
        <h1 class="header-title"><?= $page_title ?></h1>
        
        <div class="user-profile">
            <a href="teacher_message.php" class="icon-button" title="Messages">
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
        <div class="container">
            
            <div class="page-header">
                <h1><?= $main_header_icon ?> <?= $content_title ?></h1>

                <div class="view-nav">
                    <a href="grade_management.php?view=status" class="<?= $current_view === 'status' ? 'active' : '' ?>">
                        <i class="fa-solid fa-list-check"></i> Approval Status
                    </a>
                    <a href="grade_management.php?view=history" class="<?= $current_view === 'history' ? 'active' : '' ?>">
                        <i class="fa-solid fa-archive"></i> Grade History
                    </a>
                </div>
            </div>

            <div class="page-content">
                <?php if ($db_error): ?>
                    <div class="db-error">❌ **Database Error:** <?php echo htmlspecialchars($db_error); ?></div>
                
                <?php elseif ($current_view === 'status'): ?>
                    <h2>Grade Approval Monitoring</h2>
                    <?php if (empty($class_statuses)): ?>
                        <div class="no-data">You are not currently assigned to any subjects or no data is available for status monitoring.</div>
                    <?php else: ?>
                        
                        <p>Monitor the **Program Head's review status** for each term.</p>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Subject / Section</th>
                                        <th>Midterm Approval Status</th>
                                        <th>Final Approval Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($class_statuses as $status): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($status['subject_code']) ?>:</strong> <?= htmlspecialchars($status['subject_name']) ?>
                                            <br><span style="color: var(--text-light);">Section: <?= htmlspecialchars($status['section']) ?></span>
                                        </td>
                                        
                                        <td>
                                            <span class="status-tag <?= $status['midterm_ph_approval']['class'] ?>"><?= htmlspecialchars($status['midterm_ph_approval']['status']) ?></span>
                                            <p class="status-message"><?= htmlspecialchars($status['midterm_ph_approval']['message']) ?></p>
                                        </td>
                                        
                                        <td>
                                            <span class="status-tag <?= $status['final_ph_approval']['class'] ?>"><?= htmlspecialchars($status['final_ph_approval']['status']) ?></span>
                                            <p class="status-message"><?= htmlspecialchars($status['final_ph_approval']['message']) ?></p>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                <?php elseif ($current_view === 'history'): ?>
                    <h2>Finalized Grade Records</h2>
                    <p>View permanent records of grades that have successfully completed the approval workflow.</p>
                    
                    <form class="filter-form" method="GET">
                        <input type="hidden" name="view" value="history">
                        
                        <label for="search">Search Student Name/ID:</label>
                        <input type="text" name="search" id="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="e.g., Dela Cruz, 12001">
                        
                        <label for="term">Academic Term:</label>
                        <select name="term" id="term">
                            <option value="">All Terms</option>
                            <?php foreach ($unique_terms as $term): ?>
                                <option value="<?= htmlspecialchars($term) ?>" <?= ($filter_term === $term) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($term) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <label for="subject">Subject:</label>
                        <select name="subject" id="subject">
                            <option value="">All Subjects</option>
                            <?php foreach ($unique_subjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject) ?>" <?= ($filter_subject === $subject) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <button type="submit"><i class="fa-solid fa-filter"></i> Apply Filters</button>
                        <button type="button" onclick="window.location.href='grade_management.php?view=history';" style="background: var(--muted);"><i class="fa-solid fa-times"></i> Clear</button>
                    </form>
                    
                    <?php if (empty($grouped_history) && empty($class_ids)): ?>
                        <div class="no-data">No classes assigned to this professor. Grade history cannot be displayed.</div>
                    <?php elseif (empty($grouped_history)): ?>
                        <p class="no-data">No finalized grades found matching your current filters. (Archived records only.)</p>
                    <?php else: ?>
                    <div class="table-container">
                        <?php foreach ($grouped_history as $group_key => $group): ?>
                            <div class="class-group-header">
                                <?= htmlspecialchars($group['subject_code']) ?> - <?= htmlspecialchars($group['subject_name']) ?>
                                <span>Section: <?= htmlspecialchars($group['section']) ?> (<?= htmlspecialchars($group['term']) ?>)</span>
                            </div>
                            <table class="data-table class-group-table">
                                <thead>
                                    <tr>
                                        <th>Student ID / Name</th>
                                        <th style="text-align: center;">Final Numerical Grade</th>
                                        <th style="text-align: center;">GWA Equivalent</th>
                                        <th style="text-align: center;">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['students'] as $grade_record): ?>
                                        <?php 
                                            $final_grade = round($grade_record['final_grade'], 2);
                                            $is_passing = $final_grade >= $PASSING_GRADE;
                                            $remarks = $is_passing ? 'PASSED' : 'FAILED';
                                            $grade_class = $is_passing ? 'grade-pass' : 'grade-fail';
                                        ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($grade_record['last_name'] . ', ' . $grade_record['first_name']) ?></strong>
                                            <br><span style="color: var(--text-light);">ID: <?= htmlspecialchars($grade_record['student_sid']) ?></span>
                                        </td>
                                        <td style="text-align: center;" class="<?= $grade_class ?>"><?= number_format($final_grade, 2) ?></td>
                                        <td style="text-align: center;"><?= number_format($grade_record['gwa'], 2) ?></td>
                                        <td style="text-align: center;" class="<?= $grade_class ?>">
                                            <strong><?= $remarks ?></strong>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
// --- START: Toggle Logic for Sidebar ---
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    
    // Toggle the class that handles visibility logic
    sidebar.classList.toggle('sidebar-visible');
    
    // Manage overlay visibility based on sidebar state
    if (sidebar.classList.contains('sidebar-visible')) {
        overlay.classList.add('sidebar-visible');
    } else {
        overlay.classList.remove('sidebar-visible');
    }
}

// Basic modal functions for the error modal
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if(modal) modal.style.display = 'flex';
}
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if(modal) modal.style.display = 'none';
}

// Close error modal with its button
const closeErrorBtn = document.getElementById('close-error-modal-btn');
if(closeErrorBtn) {
    closeErrorBtn.addEventListener('click', () => closeModal('error-modal'));
}

// Check for PHP error on load and show modal
const dbError = <?php echo json_encode($db_error); ?>;
if (dbError) {
    const errorMessageElement = document.getElementById('error-modal-message');
    if(errorMessageElement) errorMessageElement.textContent = dbError;
    openModal('error-modal');
}
</script>

</body>
</html>