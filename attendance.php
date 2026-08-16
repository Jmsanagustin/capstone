<?php
// FILE: attendance.php
// MODIFICATION:
// 1. Added "Manage Students" to the sidebar navigation.
// 2. Maintained mobile responsiveness and toggle logic.

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
$teacher_name = $_SESSION['name'] ?? 'Professor';
$db_error = "";
$conversations = [];
$instructor_classes = [];
$active_page = basename($_SERVER['PHP_SELF']); // For sidebar highlighting

try {
    if (!$conn) {
        throw new PDOException("Database connection object (\$conn) is not initialized.");
    }
    
    // --- Always fetch conversations for sidebar header ---
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

    // --- Fetch instructor's classes for the dropdown ---
    $stmt_classes = $conn->prepare("
        SELECT c.class_id, s.subject_code, s.subject_name, c.section
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        WHERE c.professor_sid = :professor_sid
        ORDER BY s.subject_code, c.section
    ");
    $stmt_classes->execute([':professor_sid' => $current_user_id]);
    $instructor_classes = $stmt_classes->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = "Database error: " . $e->getMessage();
}

// Get today's date in YYYY-MM-DD format for the date picker
$today_date = date('Y-m-d');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instructor Portal - Attendance</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* --- Base Styles --- */
:root{
    --nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f4f7fa;
    --white:#fff; --text-dark:#102a43; --text-light:#627d98;
    --accent-light:#4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);
    --shadow-md: 0 6px 15px rgba(0,0,0,0.08); --critical:#b91c1c;
    --warning:#a16207; --muted:#888; --success-green: #166534;
    --border-color: #e5e7eb;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100vh; overflow-x: hidden; } /* Allow vertical scroll on mobile */
body{font-family: 'Inter', 'Segoe UI', sans-serif;margin:0;background:var(--bg-light);color:var(--text-dark); display: flex;}
.sidebar { 
    width: 260px; background: var(--nav-dark); color: var(--white); 
    display: flex; flex-direction: column; flex-shrink: 0; 
    height: 100vh; transition: width 0.3s ease, transform 0.3s ease; 
    position: fixed; /* Changed for mobile */
    z-index: 1000;
}
.sidebar-header { padding: 24px 20px; display: flex; align-items: center; gap: 15px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar-header img.sidebar-logo { width: 40px; height: 40px; border-radius: 4px; }
.sidebar-header h2 { font-size: 1.15rem; font-weight: 600; line-height: 1.3; }
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

.main-content { 
    flex-grow: 1; display: flex; flex-direction: column; 
    height: 100vh; overflow-y: auto; /* Allow content to scroll */
    margin-left: 260px; /* Make space for sidebar */
    transition: margin-left 0.3s ease;
    width: calc(100% - 260px);
}
.header { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; background: var(--white); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
/* NEW: Hamburger Menu Button */
.menu-toggle {
    display: none; /* Hidden on desktop */
    font-size: 1.5rem;
    color: var(--text-dark);
    background: none;
    border: none;
    cursor: pointer;
    padding: 5px;
    margin-right: 10px;
}
.header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
.user-profile { display: flex; align-items: center; gap: 12px; }
.user-profile .icon-button { font-size: 1.2rem; color: var(--text-light); text-decoration: none; padding: 8px; border-radius: 50%; transition: background 0.2s ease; position: relative; }
.user-profile .icon-button:hover { background: var(--bg-light); }
.user-profile .unread-badge {
    background: var(--critical); color: var(--white); position: absolute; top: 0; right: 0;
    border-radius: 50%; padding: 2px 6px; font-size: 0.7rem; font-weight: 700;
}
.user-profile-info { text-align: right; }
.user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
.user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
.page-view { flex-grow: 1; overflow-y: auto; padding: 24px; }
.content-section { background: var(--white); border-radius: 12px; padding: 24px; box-shadow: var(--shadow); margin-bottom: 24px; border: 1px solid var(--border-color); }
.content-section h2 { font-size: 1.25rem; font-weight: 700; color: var(--text-dark); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.message-box{padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold;}
.error-message{background: #fef2f2; color: var(--critical); border: 1px solid #fecaca;}
.success-message{background: #f0fdf4; color: var(--success-green); border: 1px solid #bbf7d0;}
.custom-scrollbar::-webkit-scrollbar { width: 8px; }
.custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.footer{text-align:center;font-size:12px;color:var(--muted);margin-top:30px; padding: 10px 0;}

/* --- Content Header for Toggle Button --- */
.content-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap; /* Allow wrapping on mobile */
    gap: 10px;
}
.content-section h2 {
    margin-bottom: 0;
}
#mark-all-present-btn {
    display: none; /* Hide by default */
    margin: 0; 
    padding: 10px 16px; 
    font-size: 0.9rem;
    background-color: var(--success-green);
}
#mark-all-present-btn:hover {
    background-color: #218838; /* Darker green */
}

/* --- Form & Table Styles --- */
.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    font-weight: 500;
    color: var(--text-dark);
    margin-bottom: 8px;
}
.form-group select, .form-group input {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 1rem;
    font-family: 'Inter', sans-serif;
    background-color: #f9fafb;
}
.form-group select:focus, .form-group input:focus {
    outline: none;
    border-color: var(--panel-blue);
    background: var(--white);
    box-shadow: 0 0 0 2px rgba(31, 110, 163, 0.2);
}
.table-container { width: 100%; overflow-x: auto; border: 1px solid var(--border-color); border-radius: 8px; }
.data-table{width:100%;border-collapse:collapse; background:var(--white); }
.data-table th{background: #f9fafb; color: var(--text-light); padding:12px 15px;text-align:left;font-weight:600;text-transform:uppercase;font-size:0.75rem; border-bottom: 1px solid var(--border-color);}
.data-table td{border-bottom:1px solid var(--border-color);padding:15px; vertical-align: middle; text-align: left; font-size: 0.9rem;}
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table td:last-child { width: auto; } /* Removed fixed width */

.action-button { background: var(--panel-blue); color: var(--white); font-weight: bold; border: none; cursor: pointer; transition: background 0.2s; padding: 12px 18px; border-radius: 8px; font-size: 1rem; text-decoration: none; display: block; width: 100%; margin-top: 20px; }
.action-button:hover{background: var(--nav-dark);}
.action-button:disabled { background: var(--muted); cursor: not-allowed; }
.action-button.update-btn {
    background-color: var(--warning);
    color: var(--text-dark);
}
.action-button.update-btn:hover {
    background-color: #e68a00;
}


/* --- Attendance Radio Button Styles --- */
.attendance-status-group {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.attendance-status-group label {
    display: inline-block;
    padding: 8px 12px;
    border-radius: 20px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.85rem;
    border: 2px solid transparent;
    transition: all 0.2s ease;
}
.attendance-status-group input[type="radio"] {
    display: none; /* Hide the actual radio button */
}
/* Default (Present) */
.attendance-status-group input[type="radio"] + label {
    background-color: #f0fdf4;
    color: #166534;
    border-color: #f0fdf4;
}
/* Checked (Present) */
.attendance-status-group input[type="radio"]:checked + label {
    background-color: var(--success-green);
    color: var(--white);
    border-color: var(--success-green);
}
/* Absent */
.attendance-status-group input[type="radio"][value="Absent"] + label {
    background-color: #fef2f2;
    color: #b91c1c;
    border-color: #fef2f2;
}
.attendance-status-group input[type="radio"][value="Absent"]:checked + label {
    background-color: var(--critical);
    color: var(--white);
    border-color: var(--critical);
}
/* Late */
.attendance-status-group input[type="radio"][value="Late"] + label {
    background-color: #fefce8;
    color: #a16207;
    border-color: #fefce8;
}
.attendance-status-group input[type="radio"][value="Late"]:checked + label {
    background-color: #facc15; /* Brighter yellow */
    color: #422006;
    border-color: #facc15;
}
/* Excused */
.attendance-status-group input[type="radio"][value="Excused"] + label {
    background-color: #f3f4f6;
    color: var(--text-light);
    border-color: #f3f4f6;
}
.attendance-status-group input[type="radio"][value="Excused"]:checked + label {
    background-color: var(--text-light);
    color: var(--white);
    border-color: var(--text-light);
}

.remarks-input {
    width: 100%;
    min-width: 150px; /* Give remarks some space */
    padding: 8px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.9rem;
}
.no-data-row td {
    text-align: center;
    color: var(--text-light);
    padding: 40px;
    font-style: italic;
}

/* --- Review Modal Styles --- */
.modal-overlay {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: none; /* Hidden by default */
    align-items: center; justify-content: center;
    z-index: 1000;
}
.modal-container {
    background: white; border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    width: 90%; max-width: 700px;
    max-height: 90vh;
    display: flex; flex-direction: column;
}
.modal-header {
    padding: 16px 24px; border-bottom: 1px solid var(--border-color);
    display: flex; justify-content: space-between; align-items: center;
}
.modal-header h3 { font-size: 1.25rem; font-weight: 600; color: var(--text-dark); }
.modal-body { padding: 24px; overflow-y: auto; }
.modal-footer {
    padding: 16px 24px; border-top: 1px solid var(--border-color);
    background: #f9fafb;
    display: flex; justify-content: flex-end; gap: 12px;
    border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;
}
.modal-button {
    padding: 10px 16px; border: none; border-radius: 6px;
    font-weight: 500; cursor: pointer; transition: background 0.2s;
    font-size: 0.9rem;
}
.modal-button.cancel-btn {
    background: #e5e7eb; color: #374151;
}
.modal-button.cancel-btn:hover { background: #d1d5db; }
.modal-button.confirm-btn {
    background: var(--success-green); color: var(--white);
}
.modal-button.confirm-btn:hover { background: #15803d; }

.review-summary {
    font-size: 1.1rem;
    color: var(--text-dark);
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-color);
}
.review-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}
.review-table th, .review-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}
.review-table th {
    background: var(--bg-light);
    font-weight: 600;
    color: var(--text-light);
    font-size: 0.75rem;
    text-transform: uppercase;
}
.review-table .status-absent { font-weight: 600; color: var(--critical); }
.review-table .status-late { font-weight: 600; color: var(--warning); }
.review-table .status-excused { font-weight: 600; color: var(--text-light); }
.review-table .remarks-empty { color: var(--muted); font-style: italic; }

.is-readonly .attendance-status-group label {
    cursor: not-allowed;
    opacity: 0.8;
}
.is-readonly .attendance-status-group input[type="radio"] {
    pointer-events: none;
}
.is-readonly .remarks-input {
    background-color: #f3f4f6;
    border-color: #e5e7eb;
    cursor: not-allowed;
    pointer-events: none;
    opacity: 0.8;
}

/* ====================================================== */
/* NEW: Mobile Responsiveness */
/* ====================================================== */
@media (max-width: 768px) {
    body {
        flex-direction: column;
        height: auto;
        overflow-y: auto; /* Allow body to scroll */
    }
    
    .sidebar {
        width: 260px;
        height: 100vh;
        position: fixed;
        left: 0;
        top: 0;
        transform: translateX(-260px); /* Hidden by default */
        z-index: 2000;
        box-shadow: var(--shadow-md);
    }
    
    .sidebar.is-open {
        transform: translateX(0);
    }

    .main-content {
        width: 100%;
        margin-left: 0;
        height: auto;
        overflow-y: visible;
    }
    
    .header {
        padding: 12px 16px;
    }

    .header-title {
        display: none; /* Hide desktop title */
    }

    .menu-toggle {
        display: block; /* Show hamburger */
    }

    .user-profile-info strong {
        font-size: 0.9rem;
    }

    .page-view {
        padding: 16px;
    }
    
    .content-section {
        padding: 16px;
    }

    .filter-grid {
        grid-template-columns: 1fr; /* Stack filters vertically */
    }
    
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
    .sidebar-overlay.is-open {
        display: block;
    }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

<div id="review-attendance-modal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Confirm Attendance</h3>
            <button class="modal-button cancel-btn" onclick="closeModal('review-attendance-modal')">&times;</button>
        </div>
        <div class="modal-body" id="review-modal-body">
            </div>
        <div class="modal-footer">
            <button id="modal-cancel-btn" class="modal-button cancel-btn">Cancel</button>
            <button id="modal-confirm-btn" class="modal-button confirm-btn">
                <i class="fa-solid fa-check"></i> Confirm & Save
            </button>
        </div>
    </div>
</div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="dfcam-logo.png" alt="DFCAM Logo" class="sidebar-logo">
        <h2>DFCAMCLP<span>Instructor Portal</span></h2>
    </div>
    <nav class="sidebar-nav custom-scrollbar">
        <ul>
            <li><a href="teacher_dashboard.php" class="nav-link <?= $active_page == 'teacher_dashboard.php' ? 'active' : '' ?>" data-view="dashboard"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="input_grades.php" class="nav-link <?= $active_page == 'input_grades.php' ? 'active' : '' ?>" data-view="grade_input"><i class="fa-solid fa-file-upload"></i><span>Grade Input</span></a></li>
            <li><a href="grade_management.php" class="nav-link <?= $active_page == 'grade_management.php' ? 'active' : '' ?>" data-view="grade_management"><i class="fa-solid fa-archive"></i><span>Grade Management</span></a></li>
            <li><a href="teacher_message.php" class="nav-link <?= $active_page == 'teacher_message.php' ? 'active' : '' ?>" data-view="messages"><i class="fa-solid fa-comments"></i><span>Messages</span></a></li>
            <li><a href="teacher_manage_students.php" class="nav-link <?= $active_page == 'teacher_manage_students.php' ? 'active' : '' ?>" data-view="manage_students"><i class="fa-solid fa-users-cog"></i><span>Manage Students</span></a></li>
            <li><a href="attendance.php" class="nav-link <?= $active_page == 'attendance.php' ? 'active' : '' ?>" data-view="attendance"><i class="fa-solid fa-clipboard-user"></i><span>Attendance</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="main-content" id="main-content">

    <header class="header">
        <button class="menu-toggle" id="menu-toggle">
            <i class="fa-solid fa-bars"></i>
        </button>
        
        <h1 class="header-title">Attendance</h1>
        
        <div class="user-profile">
            <a href="teacher_message.php" class="icon-button" title="Messages">
                <i class="fa-solid fa-envelope"></i>
                <?php 
                    $total_unread = array_sum(array_column($conversations, 'unread_count'));
                    if ($total_unread > 0): 
                ?>
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

        <?php if ($db_error): ?>
            <div class="message-box error-message"><?php echo $db_error; ?></div>
        <?php endif; ?>
        
        <div id="form-message-box" class="message-box" style="display: none;"></div>

        <section class="content-section">
            <div class="content-header">
                <h2 id="page-title"><i class="fa-solid fa-clipboard-user"></i> Take Attendance</h2>
                <button id="mark-all-present-btn" class="action-button">
                    <i class="fa-solid fa-check-double"></i> Mark All as Present
                </button>
            </div>

            <div class="filter-grid">
                <div class="form-group">
                    <label for="class-switcher">Select Class</label>
                    <select id="class-switcher">
                        <option value="">-- Please select a class --</option>
                        <?php foreach ($instructor_classes as $class): ?>
                            <option value="<?= $class['class_id'] ?>">
                                <?= htmlspecialchars($class['subject_code'] . " - " . $class['subject_name'] . " (" . $class['section'] . ")") ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="attendance-date">Select Date</label>
                    <input type="date" id="attendance-date" value="<?= $today_date ?>">
                </div>
            </div>

            <form id="attendanceForm">
                <input type="hidden" id="form_class_id" name="class_id" value="">
                <input type="hidden" id="form_attendance_date" name="attendance_date" value="">

                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Attendance Status</th>
                                <th>Remarks (Optional)</th>
                            </tr>
                        </thead>
                        <tbody id="studentList">
                            <tr class="no-data-row">
                                <td colspan="3">Please select a class and date to load students.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <button type="submit" id="submit-btn" class="action-button" disabled>Submit Attendance</button>
                <button type="button" id="edit-btn" class="action-button update-btn" style="display: none;">
                    <i class="fa-solid fa-pen"></i> Edit This Day's Attendance
                </button>
                </form>
        </section>

        <div class="footer">© 2025 Smart Grade Monitoring | Integrated Teacher Module</div>
    </main>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {

    // --- 1. NEW: Mobile Sidebar Toggle ---
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
    
    // --- 2. Get DOM Elements ---
    const classSwitcher = document.getElementById('class-switcher');
    const datePicker = document.getElementById('attendance-date');
    const studentListBody = document.getElementById('studentList');
    const attendanceForm = document.getElementById('attendanceForm');
    const submitBtn = document.getElementById('submit-btn');
    const formMessageBox = document.getElementById('form-message-box');
    const markAllPresentBtn = document.getElementById('mark-all-present-btn');
    const pageTitle = document.getElementById('page-title'); 
    const editBtn = document.getElementById('edit-btn');
    
    // Hidden form inputs
    const formClassId = document.getElementById('form_class_id');
    const formAttendanceDate = document.getElementById('form_attendance_date');
    
    // Modal Elements
    const reviewModal = document.getElementById('review-attendance-modal');
    const reviewModalBody = document.getElementById('review-modal-body');
    const modalCancelBtn = document.getElementById('modal-cancel-btn');
    const modalConfirmBtn = document.getElementById('modal-confirm-btn');
    
    let currentAttendanceData = null; // To hold form data for confirmation

    // --- 3. Event Listeners ---
    classSwitcher.addEventListener('change', fetchStudents);
    datePicker.addEventListener('change', fetchStudents);
    attendanceForm.addEventListener('submit', reviewAttendance); 
    markAllPresentBtn.addEventListener('click', markAllPresent);
    editBtn.addEventListener('click', enableEditing);
    
    modalCancelBtn.addEventListener('click', () => closeModal('review-attendance-modal'));
    modalConfirmBtn.addEventListener('click', saveAttendance); 

    // --- 4. Modal Utility Functions ---
    function openModal(modalId) {
        document.getElementById(modalId).style.display = 'flex';
    }
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    // --- 5. Editing Function ---
    function enableEditing() {
        studentListBody.classList.remove('is-readonly');
        submitBtn.style.display = 'block';    // Show "Update" button
        editBtn.style.display = 'none';       // Hide "Edit" button
    }

    // --- 6. Main Function to Fetch Students (AJAX) ---
    async function fetchStudents() {
        const classId = classSwitcher.value;
        const date = datePicker.value;

        // Get today's date as a string (YYYY-MM-DD)
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        const todayString = `${yyyy}-${mm}-${dd}`;
        
        const isViewingPast = (date < todayString);

        if (!classId || !date) {
            studentListBody.innerHTML = '<tr class="no-data-row"><td colspan="3">Please select a class and date to load students.</td></tr>';
            submitBtn.disabled = true;
            markAllPresentBtn.style.display = 'none';
            // Reset title and button
            pageTitle.innerHTML = '<i class="fa-solid fa-clipboard-user"></i> Take Attendance';
            submitBtn.innerHTML = 'Submit Attendance';
            submitBtn.classList.remove('update-btn');
            
            submitBtn.style.display = 'block';
            editBtn.style.display = 'none';
            studentListBody.classList.remove('is-readonly');
            return;
        }

        // Show loading state
        studentListBody.innerHTML = '<tr class="no-data-row"><td colspan="3"><i class="fa-solid fa-spinner fa-spin"></i> Loading students...</td></tr>';
        submitBtn.disabled = true;
        markAllPresentBtn.style.display = 'none';
        
        try {
            const response = await fetch(`get_attendance_data.php?class_id=${classId}&date=${date}`);
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            const students = await response.json();

            if (students.error) {
                throw new Error(students.error);
            }

            if (students.length === 0) {
                studentListBody.innerHTML = '<tr class="no-data-row"><td colspan="3">No students are enrolled in this class.</td></tr>';
                markAllPresentBtn.style.display = 'none';
                return;
            }

            let html = '';
            students.forEach((student, index) => {
                html += `
                    <tr>
                        <td>
                            <strong>${student.last_name}, ${student.first_name}</strong>
                            <input type="hidden" name="student_sid[${index}]" value="${student.sid}">
                        </td>
                        <td>
                            <div class="attendance-status-group">
                                <input type="radio" id="present_${student.sid}" name="status[${index}]" value="Present" ${student.status === 'Present' ? 'checked' : ''}>
                                <label for="present_${student.sid}">Present</label>

                                <input type="radio" id="absent_${student.sid}" name="status[${index}]" value="Absent" ${student.status === 'Absent' ? 'checked' : ''}>
                                <label for="absent_${student.sid}">Absent</label>

                                <input type="radio" id="late_${student.sid}" name="status[${index}]" value="Late" ${student.status === 'Late' ? 'checked' : ''}>
                                <label for="late_${student.sid}">Late</label>

                                <input type="radio" id="excused_${student.sid}" name="status[${index}]" value="Excused" ${student.status === 'Excused' ? 'checked' : ''}>
                                <label for="excused_${student.sid}">Excused</label>
                            </div>
                        </td>
                        <td>
                            <input type="text" name="remarks[${index}]" class="remarks-input" placeholder="e.g., Tardy 10:15am" value="${student.remarks || ''}">
                        </td>
                    </tr>
                `;
            });
            
            studentListBody.innerHTML = html;
            formClassId.value = classId;
            formAttendanceDate.value = date;
            submitBtn.disabled = false;
            
            if (isViewingPast) {
                // VIEW/EDIT PAST MODE
                pageTitle.innerHTML = '<i class="fa-solid fa-clock-rotate-left"></i> Reviewing Past Attendance';
                submitBtn.innerHTML = 'Update Attendance';
                submitBtn.classList.add('update-btn');
                markAllPresentBtn.style.display = 'none'; 
                
                studentListBody.classList.add('is-readonly'); // Disable form
                submitBtn.style.display = 'none';             // Hide "Update" button
                editBtn.style.display = 'block';              // Show "Edit" button
            } else {
                // TAKE ATTENDANCE (TODAY) MODE
                pageTitle.innerHTML = '<i class="fa-solid fa-clipboard-user"></i> Take Attendance';
                submitBtn.innerHTML = 'Submit Attendance';
                submitBtn.classList.remove('update-btn'); 
                markAllPresentBtn.style.display = 'inline-block'; 
                
                studentListBody.classList.remove('is-readonly');
                submitBtn.style.display = 'block';
                editBtn.style.display = 'none';
            }

        } catch (error) {
            studentListBody.innerHTML = `<tr class="no-data-row"><td colspan="3" style="color: var(--critical);">${error.message}</td></tr>`;
        }
    }

    // --- 7. Review Function ---
    function reviewAttendance(e) {
        e.preventDefault(); 
        
        currentAttendanceData = new FormData(attendanceForm);
        
        const exceptions = [];
        const studentCount = attendanceForm.querySelectorAll('input[name^="student_sid"]').length;

        for (let i = 0; i < studentCount; i++) {
            const status = currentAttendanceData.get(`status[${i}]`);
            
            if (status !== 'Present') {
                const name = attendanceForm.querySelector(`input[name="student_sid[${i}]"]`).closest('tr').querySelector('strong').textContent;
                const remarks = currentAttendanceData.get(`remarks[${i}]`);
                exceptions.push({ name, status, remarks });
            }
        }

        if (exceptions.length === 0) {
            saveAttendance();
            return;
        }

        const totalStudents = studentCount;
        const presentCount = totalStudents - exceptions.length;
        
        let tableHTML = `
            <p class="review-summary">You are submitting attendance for <strong>${totalStudents} students</strong>:
                <strong style="color: var(--success-green);">${presentCount} Present</strong> and 
                <strong style="color: var(--critical);">${exceptions.length} Other</strong>.
                <br>Please confirm the exceptions:
            </p>
            <table class="review-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Status</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
        `;

        exceptions.forEach(ex => {
            const remarksText = ex.remarks ? ex.remarks : '(No remarks)';
            const remarksClass = ex.remarks ? '' : 'remarks-empty';
            
            tableHTML += `
                <tr>
                    <td>${ex.name}</td>
                    <td class="status-${ex.status.toLowerCase()}">${ex.status}</td>
                    <td class="${remarksClass}">${remarksText}</td>
                </tr>
            `;
        });

        tableHTML += `</tbody></table>`;
        
        reviewModalBody.innerHTML = tableHTML;
        openModal('review-attendance-modal');
    }

    // --- 8. Main Function to Save Attendance (AJAX) ---
    async function saveAttendance() {
        
        closeModal('review-attendance-modal');
        
        if (!currentAttendanceData) {
            console.error('No attendance data to save.');
            return;
        }
        
        const formData = currentAttendanceData;
        
        submitBtn.disabled = true;
        markAllPresentBtn.style.display = 'none';
        editBtn.style.display = 'none'; // Hide edit button during save
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
        formMessageBox.style.display = 'none';

        try {
            const response = await fetch('save_attendance.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.status === 'success') {
                formMessageBox.className = 'message-box success-message';
                formMessageBox.innerHTML = `✅ ${result.message}`;
                formMessageBox.style.display = 'block';
            } else {
                throw new Error(result.message || 'An unknown error occurred.');
            }

        } catch (error) {
            formMessageBox.className = 'message-box error-message';
            formMessageBox.innerHTML = `❌ Error saving attendance: ${error.message}`;
            formMessageBox.style.display = 'block';
        } finally {
            fetchStudents(); 
            currentAttendanceData = null; // Clear the stored data
            document.querySelector('.page-view').scrollTop = 0;
        }
    }

    // --- 9. "Mark All Present" Toggle Function ---
    function markAllPresent(e) {
        if (e) e.preventDefault(); 
        
        const presentButtons = document.querySelectorAll('input[type="radio"][value="Present"]');
        presentButtons.forEach(btn => {
            btn.checked = true;
        });
    }

    // Run fetchStudents on page load
    fetchStudents();

});
</script>
</body>
</html>