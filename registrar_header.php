<?php
// FILE: registrar_header.php
// PURPOSE: Centralized header, sidebar, and CSS for the Registrar Portal.
// VERSION 7.14: Cleaned up markdown and stabilized layout, added CSV review styles.

// Set default values if not provided by the calling script
if (!isset($view)) $view = 'dashboard';
if (!isset($_SESSION['username'])) $_SESSION['username'] = 'Registrar';
if (!isset($current_user_name)) $current_user_name = $_SESSION['username'] ?? 'Registrar';
if (!isset($total_unread_messages)) $total_unread_messages = 0;

// Helper function to get the current page title based on the view
$title_map = [
    'dashboard' => 'Registrar Dashboard',
    'approval' => 'Final Grade Report',
    'students' => 'Student Management',
    'change_requests' => 'Profile Change Requests',
    'batch_promotion' => 'Batch Promotion',
    'staff_management' => 'Staff/Faculty Management',
    'faculty_csv_upload' => 'Faculty CSV Upload',
    'review_upload' => 'Faculty Upload Review', // Added Review view title
    'reports' => 'Generate Reports',
    'messages' => 'Message Center',
    // Renamed 'subjects' view title to reflect the read-only catalog
    'subjects' => 'Academic Subject Catalog', 
];
$current_title = $title_map[$view] ?? 'Registrar Portal';

// Ensure the session role is set for the header display
if (!isset($_SESSION['role'])) {
    $_SESSION['role'] = 'Registrar'; 
}

// Logic to determine if the Academic Management grouping should be active
$academic_manager_views = ['change_requests', 'batch_promotion', 'staff_management', 'faculty_csv_upload', 'review_upload'];
// Use in_array to check if the current $view (e.g., 'change_requests') is one of the academic manager tabs
$is_academic_active = in_array($view, $academic_manager_views);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($current_title); ?> - DFCAMCLP</title>
    <link rel="stylesheet" href="css/all.min.css"> 
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <script src="registrar_scripts.js" defer></script> 
    
    <style>
        /* --- Root Variables (Consolidated from previous code) --- */
        :root {
            --nav-dark: #0f4a73; --panel-blue: #1f6ea3; --bg-light: #f2f5f8;
            --white: #ffffff; --text-dark: #102a43; --text-light: #627d98;
            --accent-light: #4db8ff; --shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.08); --critical: #cc3333;
            --success-green: #28a745; --warning-orange: #f5a623; --info-blue: #17a2b8;
            --border-color: #e0e6ed;
            
            /* Custom Dashboard Variables for consistency */
            --navy: var(--nav-dark);
            --mid: var(--text-dark);
            --accent: var(--accent-light);
            --background: var(--bg-light);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--bg-light); color: var(--text-dark); display: flex; height: 100vh; overflow: hidden; }
        
        /* === SIDEBAR AND HEADER === */
        .sidebar { width: 260px; background: var(--nav-dark); color: var(--white); display: flex; flex-direction: column; flex-shrink: 0; transition: width 0.3s ease; z-index: 100; }
        .sidebar-header { padding: 24px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-header i { font-size: 28px; color: var(--accent-light); }
        .sidebar-header h2 { font-size: 1.15rem; font-weight: 600; line-height: 1.3; }
        .sidebar-header h2 span { font-size: 0.8rem; font-weight: 400; color: var(--text-light); display: block; }
        .sidebar-nav { flex-grow: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-nav ul { list-style: none; }
        .sidebar-nav li { margin: 0 10px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 15px; padding: 14px 20px; color: #e0f2fe; text-decoration: none; font-weight: 500; border-radius: 8px; transition: background 0.2s ease, color 0.2s ease; position: relative; }
        .sidebar-nav a i { font-size: 1.1rem; width: 20px; text-align: center; color: #9ac8ee; }
        .sidebar-nav a:hover { background: rgba(255, 255, 255, 0.1); }
        .sidebar-nav a.active { background: var(--accent-light); color: var(--nav-dark); font-weight: 700; }
        .sidebar-nav a.active i { color: var(--nav-dark); }
        .sidebar-footer { padding: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-footer a { display: block; text-align: center; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; color: var(--white); text-decoration: none; font-weight: 500; transition: background 0.2s ease; }
        .sidebar-footer a:hover { background: rgba(0,0,0,0.4); }
        .main-content { flex-grow: 1; display: flex; flex-direction: column; height: 100vh; }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 16px 32px; background: var(--white); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
        .header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); margin-right: auto; }
        .sidebar-toggle { display: none; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-profile .icon-button { font-size: 1.2rem; color: var(--text-light); text-decoration: none; padding: 8px; border-radius: 50%; transition: background 0.2s ease; position: relative; }
        .user-profile .icon-button:hover { background: var(--bg-light); }
        .user-profile-info { text-align: right; }
        .user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
        .user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
        .page-view { flex-grow: 1; overflow-y: auto; padding: 32px; }
        .message { padding: 15px 20px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; border: 1px solid; }
        .message.success { background: #e6f7e6; color: var(--success-green); border-color: var(--success-green); }
        .message.error { 
            background: #fdd; 
            color: var(--critical); 
            border-color: var(--critical); 
            font-weight: 600;
        }
        .message.warning { background: #fff3cd; color: #856404; border-color: #ffeeba; }
        .message.info { background: #d0f0ff; color: var(--panel-blue); border-color: var(--panel-blue); }
        .content-section { background: var(--white); border-radius: 12px; padding: 24px; box-shadow: var(--shadow); margin-bottom: 32px; }
        .content-section h2 { font-size: 1.25rem; font-weight: 700; color: var(--panel-blue); margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
        .content-section p.description { font-size: 0.95rem; color: var(--text-light); margin-bottom: 20px; }
        
        /* Critical Message Flash Animation */
        @keyframes flash {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .critical-flash-message {
            animation: flash 1.5s ease-in-out infinite;
        }


        .message-badge { position: absolute; top: 2px; right: 0px; background-color: var(--critical); color: var(--white); font-size: 0.7rem; font-weight: 600; border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; border: 2px solid var(--white); }
        .sidebar-nav a .message-badge { top: 8px; right: 12px; border: 2px solid var(--nav-dark); }
        
        /* === DASHBOARD AND CARDS === */
        .dashboard-grid { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 32px; align-items: flex-start; }
        .quick-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .stat-card {
            background-color: var(--white); padding: 20px; border-radius: 8px; box-shadow: var(--shadow);
            border-left: 5px solid var(--panel-blue); text-align: center;
        }
        .stat-card.urgent { border-left-color: var(--critical); }
        .stat-card h3 { margin: 0 0 10px 0; font-size: 0.9rem; color: var(--text-light); text-transform: uppercase; }
        .stat-card p { margin: 0; font-size: 2.5em; font-weight: 700; color: var(--text-dark); }
        .stat-card.urgent p { color: var(--critical); }

        /* === TAB CONTROLS (Academic/Student Management) === */
        .tab-controls { 
            display: flex; 
            margin-bottom: 20px; 
            border-bottom: 2px solid var(--border-color); 
            background: var(--white);
            border-radius: 8px 8px 0 0;
            padding: 0 5px;
            box-shadow: var(--shadow);
        }
        .tab-button { 
            padding: 12px 20px; 
            font-size: 0.95rem; 
            font-weight: 600; 
            cursor: pointer; 
            border: none; 
            background: transparent; 
            color: var(--text-light); 
            transition: color 0.2s, border-bottom 0.2s, background 0.2s; 
            margin-bottom: -2px; 
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tab-button:hover {
            background: var(--bg-light);
        }
        .tab-button.active { 
            color: var(--panel-blue); 
            border-bottom: 3px solid var(--panel-blue); /* Thicker active border */
            background: var(--bg-light); 
        }

        /* === TABLE STYLES (Review/Records/Requests) === */
        .table-container { width: 100%; overflow-x: auto; border: 1px solid var(--border-color); border-radius: 8px; box-shadow: var(--shadow); }
        .approval-table, .review-table, .records-table, .req-table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 0.9rem; 
            background-color: #fff; 
        }
        .approval-table th, .review-table th, .records-table th, .req-table th { 
            background: var(--nav-dark); 
            color: var(--white); 
            padding: 12px 15px; 
            text-align: left; 
            font-weight: 600; 
            text-transform: uppercase; 
            font-size: 0.75rem; 
            border-bottom: 1px solid var(--border-color);
        }
        .approval-table td, .review-table td, .records-table td, .req-table td { 
            border-bottom: 1px solid var(--border-color); 
            padding: 12px 15px; 
            vertical-align: middle; 
        }
        .req-table td:last-child, .review-table td:last-child {
            border-right: none; 
        }
        
        .req-table td {
            line-height: 1.4;
        }
        .req-table thead th {
            background: var(--bg-light);
            color: var(--text-dark);
        }
        .req-table tbody tr:hover {
            background: #fafcff; /* Light hover effect for readability */
        }

        /* === CSV Review Specific Styles (INTEGRATED) === */
        .critical-error-row {
            background-color: #ffeaea; /* Light red for rows with critical errors */
        }
        .warning-row {
            background-color: #fffde7; /* Light yellow for rows with conflicts/warnings */
        }
        .highlight-error {
            border: 2px solid var(--critical) !important; /* Red border on the cell with the issue */
            background-color: #fee;
            font-weight: 600;
        }


        /* === GROUP HEADERS (Collapsible sections) === */
        .group-header {
            background: var(--panel-blue); 
            color: var(--white);
            padding: 14px 20px;
            margin-top: 15px;
            font-size: 1.05rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer; 
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .group-header:hover {
            background: #175d8d; /* Slightly darker hover */
        }
        .group-content {
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 8px 8px;
            margin-bottom: 30px;
        }

        /* === BADGES AND BUTTONS === */
        .badge-new-data, .badge-current-data {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            display: inline-block; 
            margin-top: 4px;
        }
        .badge-new-data { 
            background: #e6f7ff; /* Light blue for consistency with subject page */ 
            color: var(--panel-blue); 
            font-weight: 700;
        }
        .badge-current-data { 
            color: var(--text-light); 
            font-size: 0.85rem; 
            margin-top: 0;
            padding: 0; 
            background: none;
            display: block;
        }
        
        /* Student Status Badges */
        .badge-new { background-color: var(--info-blue); color: white; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 0.75rem; }
        .badge-revision { background-color: var(--warning-orange); color: #856404; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 0.75rem; }
        
        /* Action Buttons */
        .action-link, .btn-primary, .approve-btn, .reject-btn { border: none; border-radius: 6px; padding: 8px 12px; font-size: 0.85rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: opacity 0.2s ease; text-decoration: none; }
        .approve-btn { background: var(--success-green); color: var(--white); }
        .reject-btn { background: var(--critical); color: var(--white); }
        .action-link { background: var(--info-blue); color: var(--white); }
        .approve-btn:hover, .reject-btn:hover, .action-link:hover, .btn-primary:hover { opacity: 0.85; }

        /* Review Table Specific */
        .review-table .needs-verification.data-name {
            background-color: #ffe0e0; 
            border: 1px solid var(--critical) !important;
            color: var(--critical);
            font-weight: 600;
        }

        /* --- TOOLTIP (Clickable JS Support) --- */
        .info-tooltip { 
            position: relative; 
            display: inline-block; 
            margin-left: 10px; 
            cursor: pointer; 
            font-size: 1rem; 
            vertical-align: middle; 
        }
        .info-tooltip .tooltip-text { 
            visibility: hidden; 
            width: 380px; 
            background-color: #333; 
            color: #fff; 
            text-align: left; 
            border-radius: 6px; 
            padding: 10px 15px; 
            position: absolute; 
            z-index: 1002; 
            top: 150%; 
            left: 50%; 
            margin-left: -190px; 
            opacity: 0; 
            transition: opacity 0.3s; 
            font-size: 0.85rem; 
            line-height: 1.5; 
            font-weight: 400; 
        }
        /* Class toggled by JavaScript on click */
        .info-tooltip.tooltip-active .tooltip-text { 
            visibility: visible; 
            opacity: 1; 
        }
        /* Default style (removed :hover dependence) */
        .info-tooltip i { color: var(--panel-blue); }
        .info-tooltip.tooltip-active i { color: var(--accent-light); }

        /* Modal Styles - Kept for compatibility */
        .modal-body .form-group { margin-bottom: 15px; }
        .modal-body .form-group input { width: 100%; padding: 8px; border: 1px solid var(--border-color); border-radius: 4px; }
        .modal { 
            display: none; 
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5); 
            align-items: center;
            justify-content: center;
        }
        .modal.show { display: flex; }
        .modal-content {
            background-color: var(--white);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
            width: 90%;
            max-width: 600px;
            position: relative;
        }
        .modal-close-btn { 
            position: absolute; top: 10px; right: 20px; font-size: 28px; background: none; border: none; cursor: pointer; 
        }

        /* --- Responsive Overrides --- */
        @media (max-width: 1200px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .quick-stats-grid { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
        }
        @media (max-width: 768px) { 
            .sidebar { width: 0; overflow: hidden; } 
            .sidebar-toggle { display: block; background: none; border: none; font-size: 1.5rem; color: var(--text-dark); cursor: pointer; padding: 0 10px; margin-left: -10px; }
            .sidebar.mobile-open { width: 260px; position: fixed; left: 0; top: 0; height: 100%; z-index: 1001; box-shadow: 0 0 15px rgba(0,0,0,0.2); }
            .header, .page-view { padding-left: 16px; padding-right: 16px; } 
            .header-title { font-size: 1.2rem; } 
            .tab-controls { flex-direction: column; }
            .tab-button { width: 100%; border-bottom: 1px solid var(--border-color); }
            .tab-button.active { border-bottom: 3px solid var(--panel-blue); }
        }
    </style>
</head>
<body>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fa-solid fa-university"></i>
            <h2>DFCAMCLP<span>Registrar Portal</span></h2>
        </div>
        <nav class="sidebar-nav custom-scrollbar">
            <ul>
                <li <?php if ($view == 'dashboard') echo 'class="active"'; ?>>
                    <a href="registrar_dashboard.php?view=dashboard" class="nav-link" data-view="dashboard">
                        <i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span>
                    </a>
                </li>
                <li <?php if ($view == 'approval') echo 'class="active"'; ?>>
                    <a href="registrar_dashboard.php?view=approval" class="nav-link" data-view="approval">
                        <i class="fa-solid fa-stamp"></i><span>Final Grade Report</span>
                    </a>
                </li>
                <li <?php if ($view == 'students') echo 'class="active"'; ?>>
                    <a href="registrar_student_management.php?view=students" class="nav-link" data-view="students">
                        <i class="fa-solid fa-user-plus"></i><span>Student Management</span>
                    </a>
                </li>
                
                <li style="margin: 10px 0 0 10px; padding: 5px 10px; font-size: 0.85rem; color: #e0f2fe; font-weight: 600; text-transform: uppercase;">ACADEMIC MANAGEMENT</li>
                
                <li <?php if ($view == 'subjects') echo 'class="active"'; ?>>
                    <a href="registrar_subjects.php?view=subjects" class="nav-link" data-view="subjects">
                        <i class="fa-solid fa-book"></i><span>Subject Management</span>
                    </a>
                </li>
                
                <li <?php if ($is_academic_active) echo 'class="active"'; ?>>
                    <a href="registrar_academic_manager.php?view=change_requests" class="nav-link" data-view="academic_manager">
                        <i class="fa-solid fa-graduation-cap"></i><span>Academic Management</span>
                    </a>
                </li>
                <li <?php if ($view == 'reports') echo 'class="active"'; ?>>
                    <a href="registrar_dashboard.php?view=reports" class="nav-link" data-view="reports">
                        <i class="fa-solid fa-file-alt"></i><span>Generate Reports</span>
                    </a>
                </li>
                <li <?php if ($view == 'messages') echo 'class="active"'; ?>>
                    <a href="registrar_messages.php?" class="nav-link" data-view="messages">
                        <i class="fa-solid fa-envelope"></i><span>Messages</span>
                        <?php if ($total_unread_messages > 0): ?>
                            <span class="message-badge"><?= $total_unread_messages ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main-content">
        <header class="header">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h1 class="header-title">
                <?= htmlspecialchars($current_title); ?>
            </h1>
            <div class="user-profile">
                <a href="registrar_dashboard.php?view=messages" class="icon-button" title="Messages">
                    <i class="fa-solid fa-envelope"></i>
                    <?php if ($total_unread_messages > 0): ?>
                        <span class="message-badge"><?= $total_unread_messages ?></span>
                    <?php endif; ?>
                </a>

                <a href="#" class="icon-button" title="Notifications"><i class="fa-solid fa-bell"></i></a>
                <div class="user-profile-info">
                    <strong><?php echo htmlspecialchars($current_user_name); ?></strong>
                    <span><?php echo htmlspecialchars($_SESSION['role']); ?></span>
                </div>
            </div>
        </header>

        <main class="page-view custom-scrollbar">

            <?php if (isset($message) && $message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($db_error) && $db_error): ?>
                <div class="message error">
                    <?php echo htmlspecialchars($db_error); ?>
                </div>
            <?php endif; ?>