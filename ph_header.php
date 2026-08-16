<?php
// FILE: ph_header.php
// (MODIFIED: Notification bell now ONLY shows general/system alerts, not student interventions)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db.php'; // Use include_once to prevent re-inclusion

// --- Authorization Check ---
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
    header("Location: index.php");
    exit();
}
$current_user_id = $_SESSION['sid'];
$current_user_role = $_SESSION['role'];
$program_head_sid = $_SESSION['sid'];
$program_head_program = $_SESSION['program'] ?? '';

// --- Initialize Variables (for all pages) ---
if (!isset($message)) $message = ''; 
if (!isset($message_type)) $message_type = '';
if (!isset($db_error)) $db_error = ''; 
$conversations = [];
$PASSING_GRADE = 75.00; // This is no longer used here, but may be used by other pages.

// (NEW) Initialize Alert Data
$general_notifications = []; // For grade approvals
$total_general_notifs = 0;
$total_alerts = 0;


// --- Load temporary status message from session ---
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}

// --- Load PH Name for header ---
$current_user_name = $_SESSION['name'] ?? 'Program Head';
if ($current_user_name === 'Program Head' && isset($_SESSION['username'])) {
    $current_user_name = $_SESSION['username']; // Fallback
}
try {
    $stmt_ph_name = $conn->prepare("SELECT first_name, last_name FROM user_profiles WHERE sid = ?");
    $stmt_ph_name->execute([$current_user_id]);
    $ph_profile = $stmt_ph_name->fetch();
    if ($ph_profile) {
        $current_user_name = $ph_profile['first_name'] . ' ' . $ph_profile['last_name'];
        $_SESSION['name'] = $current_user_name; // Save for other pages
    }
} catch (PDOException $e) { /* fail silently */ }


// --- (MODIFIED) Fetch Universal Data (Conversations & Alerts) ---
try {
    if (!$conn) {
        throw new PDOException("Database connection object (\$conn) is not initialized.");
    }
    
    // 1. Fetches full names for MESSAGES
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
    
    
    // 2. (KEPT) Fetch GENERAL NOTIFICATIONS (e.g., Pending Grade Approvals) - SUMMARY
    $stmt_general = $conn->prepare("
        SELECT c.class_id, s.subject_code, c.section, COUNT(g.grade_id) AS approval_count
        FROM grades g
        JOIN classes c ON g.class_id = c.class_id
        JOIN subject s ON c.subject_id = s.subject_id
        WHERE g.status = 'Pending PH Approval' AND s.program_head_id = :ph_id
        GROUP BY c.class_id, s.subject_code, c.section
        ORDER BY g.submission_date ASC
    ");
    $stmt_general->execute([':ph_id' => $program_head_sid]);
    $general_notifications = $stmt_general->fetchAll(PDO::FETCH_ASSOC);

    // 3. (MODIFIED) Calculate Totals
    $total_general_notifs = count($general_notifications);
    $total_alerts = $total_general_notifs; // Total is now ONLY general notifications


} catch (PDOException $e) {
    $db_error = "Database error fetching data: " . $e->getMessage();
}

// Set the header title based on the view
$page_title = "Dashboard"; // Default
$parent_view = ''; 
if (isset($current_view)) {
    switch ($current_view) {
        case 'students': 
            $page_title = "Student Management"; 
            $parent_view = 'users';
            break;
        case 'faculty': 
            $page_title = "Faculty Management"; 
            $parent_view = 'users';
            break;
        case 'subjects': 
            $page_title = "Subject Management"; 
            $parent_view = 'academics';
            break;
        case 'courses': 
            $page_title = "Class Management";
            $parent_view = 'academics';
            break;
        case 'promotion': 
            $page_title = "Batch Promotion"; 
            $parent_view = 'users';
            break;
        case 'reports': 
            $page_title = "Generate Reports"; 
            $parent_view = 'tools';
            break;
        case 'messages': $page_title = "Message Center"; break; 
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PH Portal - <?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- Root Variables --- */
        :root {
            --nav-dark: #0f4a73; --panel-blue: #1f6ea3; --bg-light: #f2f5f8;
            --white: #ffffff; --text-dark: #102a43; --text-light: #627d98;
            --accent-light: #4db8ff; --shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.08); --critical: #cc3333;
            --success-green: #28a745; --warning-orange: #f5a623;
        }
        /* --- Global Reset & Base --- */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--bg-light);
            color: var(--text-dark); display: flex; height: 100vh; overflow: hidden;
        }
        
        /* --- Sidebar Layout --- */
        .sidebar {
            width: 260px; background: var(--nav-dark); color: var(--white);
            display: flex; flex-direction: column; flex-shrink: 0; height: 100vh;
            position: fixed; top: 0; left: 0; z-index: 40;
            transition: transform 0.3s ease-in-out;
            transform: translateX(-100%);
        }
        .sidebar-visible {
            transform: translateX(0);
        }
        .sidebar-header {
            padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
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
        .sidebar-nav a {
            display: flex; align-items: center; gap: 15px; padding: 14px 20px;
            color: #e0f2fe; text-decoration: none; font-weight: 500; border-radius: 8px;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .sidebar-nav a i { font-size: 1.1rem; width: 20px; text-align: center; color: #9ac8ee; }
        .sidebar-nav a:hover { background: rgba(255, 255, 255, 0.1); }
        .sidebar-nav a.active { background: var(--accent-light); color: var(--nav-dark); font-weight: 700; }
        .sidebar-nav a.active i { color: var(--nav-dark); }
        
        /* Collapsible Menu Styles */
        .sidebar-nav a.nav-link-parent {
            justify-content: space-between; 
        }
        .sidebar-nav .nav-arrow {
            font-size: 0.9rem;
            color: #9ac8ee;
            transition: transform 0.2s ease;
        }
        .sidebar-nav li.open > a.nav-link-parent .nav-arrow {
            transform: rotate(180deg);
        }
        .sidebar-nav .nav-submenu {
            list-style: none;
            display: none; 
            background: rgba(0,0,0,0.15);
            margin: 0 10px 5px 10px; 
            border-radius: 0 0 6px 6px;
        }
        .sidebar-nav li.open > .nav-submenu {
            display: block; 
        }
        .sidebar-nav a.submenu-link {
            padding-left: 50px; 
            font-size: 0.9rem;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        .sidebar-nav a.submenu-link:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        .sidebar-nav a.submenu-link.active {
            background: var(--accent-light);
            color: var(--nav-dark);
            font-weight: 700;
        }
        .sidebar-nav li.open > a.nav-link-parent {
            background: rgba(255, 255, 255, 0.05);
            color: var(--white);
            border-radius: 8px 8px 0 0;
        }
        .sidebar-nav li.open > a.nav-link-parent i {
            color: var(--white);
        }


        .sidebar-footer { padding: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-footer a {
            display: block; text-align: center; background: rgba(0,0,0,0.2);
            padding: 10px; border-radius: 8px; color: var(--white); text-decoration: none;
            font-weight: 500; transition: background 0.2s ease;
        }
        .sidebar-footer a:hover { background: rgba(0,0,0,0.4); }

        #sidebar-overlay { 
            position: fixed; inset: 0; background: #000; opacity: 0.5; 
            z-index: 30; transition: opacity 0.3s ease-in-out; 
            display: none; 
        }

        /* --- Main Content Area --- */
        .main-content { 
            flex-grow: 1; display: flex; flex-direction: column; height: 100vh;
            transition: margin-left 0.3s ease-in-out;
            width: 100%;
        }
        /* --- Header Bar --- */
        .header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 32px; background: var(--white); border-bottom: 1px solid #e0e6ed;
            flex-shrink: 0;
        }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-profile .icon-button {
            font-size: 1.2rem; color: var(--text-light); text-decoration: none;
            padding: 8px; border-radius: 50%; transition: background 0.2s ease;
            position: relative;
            background: none;
            border: none;
            cursor: pointer;
        }
        .user-profile .icon-button:hover { background: var(--bg-light); }
        .user-profile-info { text-align: right; }
        .user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
        .user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
        
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
        
        /* Notification Popover (REMOVED HTML, KEEPING STYLES JUST IN CASE) */
        .notification-popover {
            display: none; 
            position: absolute;
            top: 65px; 
            right: 150px; 
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
        .popover-list .alert-icon.warning { color: var(--warning-orange); }
        .popover-list .alert-icon.missing { color: var(--text-light); }
        
        .popover-list .alert-text {
            display: flex;
            flex-direction: column;
            flex-grow: 1; 
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
        
        /* --- Page View (Scrollable Content) --- */
        .page-view { flex-grow: 1; overflow-y: auto; padding: 32px; }

        /* --- General Dashboard/Content Styles --- */
        .message { padding: 15px 20px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; border: 1px solid; }
        .message.success { background: #e6f7e6; color: var(--success-green); border-color: var(--success-green); }
        .message.error { background: #fde8e8; color: var(--critical); border-color: var(--critical); }
        .message.warning { background: #fef6e6; color: var(--warning-orange); border-color: var(--warning-orange); }
        .content-section { background: var(--white); border-radius: 12px; padding: 24px; box-shadow: var(--shadow); margin-bottom: 32px; }
        .content-section h2 { font-size: 1.25rem; font-weight: 700; color: var(--panel-blue); margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
        .content-section p.description { font-size: 0.95rem; color: var(--text-light); margin-bottom: 20px; }
        .analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .analytic-item { background: var(--bg-light); border: 1px solid #e0e6ed; border-radius: 8px; padding: 20px; }
        .analytic-item span { display: block; font-size: 0.9rem; color: var(--text-light); margin-bottom: 8px; }
        .analytic-item strong { display: block; font-size: 2rem; font-weight: 700; color: var(--panel-blue); }
        .monitoring-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }
        .table-container { width: 100%; overflow-x: auto; }
        .review-table, .monitoring-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .review-table th, .monitoring-table th { background: var(--bg-light); color: var(--text-light); padding: 12px 15px; text-align: left; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; }
        .review-table td, .monitoring-table td { border-bottom: 1px solid #e0e6ed; padding: 15px; vertical-align: middle; }
        .review-table tbody tr:last-child td, .monitoring-table tbody tr:last-child td { border-bottom: none; }
        .no-data { text-align: center; padding: 30px; color: var(--text-light); }
        .action-buttons { display: flex; gap: 8px; }
        .approve-btn, .reject-btn { border: none; border-radius: 6px; padding: 8px 12px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: opacity 0.2s ease; }
        .approve-btn { background: var(--success-green); color: var(--white); }
        .reject-btn { background: var(--critical); color: var(--white); }
        .approve-btn:hover, .reject-btn:hover { opacity: 0.85; }
        .risk-level, .status { padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 0.8rem; }
        .risk-level.high { background: #fde8e8; color: var(--critical); }
        .risk-level.medium { background: #fef6e6; color: var(--warning-orange); }
        .status.approved-by-ph { background: #e6f7e6; color: var(--success-green); }
        .status.rejected-by-ph { background: #fde8e8; color: var(--critical); }
        .monitoring-table .fa-eye { color: var(--panel-blue); font-size: 1.1rem; }

        /* Toggle Buttons */
        .toggle-button {
            background: none; border: none; cursor: pointer; color: var(--text-dark);
            padding: 4px;
        }
        .toggle-button i { font-size: 1.25rem; }
        .lg-hidden { display: none; }

        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar { width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1ff; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* --- (NEW) Responsive FIX --- */
        @media (max-width: 1023px) { /* lg breakpoint */
            .lg-hidden { display: inline-block; }
            .header, .page-view { padding-left: 16px; padding-right: 16px; }
            .header-title { font-size: 1.2rem; }
            .monitoring-grid { grid-template-columns: 1fr; }
            .notification-popover {
                right: 16px;
                width: 320px;
            }
        }

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
    
    <script>
        // (NEW) Responsive Toggle Script
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

        document.addEventListener('DOMContentLoaded', () => {
            // (NEW) Sidebar toggle init
            if (window.innerWidth >= 1024) {
                document.getElementById('sidebar').classList.add('sidebar-visible');
            }
            window.addEventListener('resize', handleResize);
            
            // (NEW) Collapsible Menu Logic
            const parentLinks = document.querySelectorAll('.nav-link-parent');
            
            parentLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault(); // Stop link from navigating
                    const parentLi = this.parentElement;
                    parentLi.classList.toggle('open');
                });
            });

            // (NEW) Auto-open the active menu
            const activeSublink = document.querySelector('.nav-submenu .nav-link.active');
            if (activeSublink) {
                // Find the parent <li> and add 'open'
                const parentLi = activeSublink.closest('li.nav-item-parent');
                if (parentLi) {
                    parentLi.classList.add('open');
                }
            }
            
            // (REMOVED) Notification Popover Logic
            // const bellBtn = document.getElementById('notification-bell-btn');
            // const popover = document.getElementById('notification-popover');
            
            // if (bellBtn && popover) {
            //     bellBtn.addEventListener('click', function(event) {
            //         event.stopPropagation(); // Stop click from bubbling to window
            //         popover.classList.toggle('visible'); 
            //     });
            // }
            
            // (REMOVED) Close popover if clicking outside
            // window.addEventListener('click', function(event) {
            //     if (popover && popover.classList.contains('visible')) {
            //         if (!popover.contains(event.target) && !bellBtn.contains(event.target)) {
            //             popover.classList.remove('visible');
            //         }
            //     }
            // });
        });
    </script>
    </head>
<body>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="programhead_dashboard.php" class="sidebar-header-logo">
                <img src="dfcam-logo.png" alt="DFCAMCLP Logo">
                <h2>DFCAMCLP<span>Program Head Portal</span></h2>
            </a>
            <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
                <i class="fa-solid fa-times" style="color: var(--white);"></i>
            </button>
        </div>
        
        <nav class="sidebar-nav custom-scrollbar">
            <ul>
                <li>
                    <a href="programhead_dashboard.php" class="nav-link <?php echo ($current_view === 'dashboard') ? 'active' : ''; ?>" data-view="dashboard">
                        <i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span>
                    </a>
                </li>
                
                <li class="nav-item-parent <?php echo ($parent_view === 'academics') ? 'open' : ''; ?>">
                    <a href="#" class="nav-link nav-link-parent">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span>Academics</span>
                        <i class="fa-solid fa-caret-down nav-arrow"></i>
                    </a>
                    <ul class="nav-submenu">
                        <li>
                            <a href="ph_subjects.php" class="nav-link submenu-link <?php echo ($current_view === 'subjects') ? 'active' : ''; ?>" data-view="subjects">
                                <span>Subject Management</span>
                            </a>
                        </li>
                        <li>
                            <a href="ph_courses.php" class="nav-link submenu-link <?php echo ($current_view === 'courses') ? 'active' : ''; ?>" data-view="courses">
                                <span>Class Management</span>
                            </a>
                        </li>
                    </ul>
                </li>
                
                <li class="nav-item-parent <?php echo ($parent_view === 'tools') ? 'open' : ''; ?>">
                    <a href="#" class="nav-link nav-link-parent">
                        <i class="fa-solid fa-screwdriver-wrench"></i>
                        <span>Tools</span>
                        <i class="fa-solid fa-caret-down nav-arrow"></i>
                    </a>
                    <ul class="nav-submenu">
                         <li>
                            <a href="ph_reports.php" class="nav-link submenu-link <?php echo ($current_view === 'reports') ? 'active' : ''; ?>" data-view="reports">
                                <span>Generate Reports</span>
                            </a>
                        </li>
                    </ul>
                </li>
                
                <li>
                    <a href="ph_messages.php" class="nav-link <?php echo ($current_view === 'messages') ? 'active' : ''; ?>" data-view="messages">
                        <i class="fa-solid fa-comments"></i><span>Messages</span>
                    </a>
                </li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div id="sidebar-overlay" onclick="toggleSidebar()"></div>

    <div class="main-content" id="mainContent">
            <header class="header" id="header">
                <div class="header-left">
                    <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <h1 class="header-title">
                        <?php echo $page_title; ?>
                    </h1>
                </div>
                <div class="user-profile">
                    
                    <a href="ph_messages.php" class="icon-button" title="Messages">
                        <i class="fa-solid fa-envelope"></i>
                        <?php 
                            $total_unread = array_sum(array_column($conversations, 'unread_count'));
                            if ($total_unread > 0): 
                        ?>
                            <span class="notification-badge" style="background: var(--panel-blue);"><?= $total_unread ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="user-profile-info">
                        <strong><?php echo htmlspecialchars($current_user_name); ?></strong>
                        <span><?php echo htmlspecialchars(ucfirst($_SESSION['role'])); ?></span>
                    </div>
                </div>
            </header>
            
            <main class="page-view custom-scrollbar">
                <?php if ($message): ?>
                    <div class="message <?php echo $message_type; ?>">
                        <?php echo $message; // Use raw output for bold tags ?>
                    </div>
                <?php endif; ?>
                <?php if ($db_error): // Show general DB errors on any page ?>
                    <div class="message error">
                        <?php echo htmlspecialchars($db_error); ?>
                    </div>
                <?php endif; ?>