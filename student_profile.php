<?php
// FILE: student_profile.php (Student Profile Display and Edit Form)

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php'; 

// Ensure user is logged in and is a student
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

// Get the logged-in student's system ID and email
$current_student_sid = $_SESSION['sid'];
$current_user_role = $_SESSION['role']; // <-- ADD THIS LINE
$db_error = "";
$message = $_SESSION['profile_message'] ?? '';
$message_type = $_SESSION['profile_message_type'] ?? '';

// Clear session messages after loading
unset($_SESSION['profile_message']);
unset($_SESSION['profile_message_type']);

// --- Live Data Fetching ---
$fname = '';
$lname = '';
$mname = '';
$email = ''; // Added email fetch
$year = '';
$section = '';
$program = '';
$student_name = $_SESSION['username'] ?? 'Student';
$student_id = 'N/A';
$has_pending_request = false;
$current_pending_request = null; // Stores details of the pending request

try {
    // 1. Fetch Profile Data (Name, Student ID, Academic Info)
    $stmt_profile = $conn->prepare("
        SELECT up.student_id, up.first_name, up.middle_name, up.last_name, 
               up.academic_year, up.section, up.program, u.email 
        FROM user_profiles up
        JOIN users u ON up.sid = u.sid
        WHERE up.sid = :sid
    ");
    $stmt_profile->execute([':sid' => $current_student_sid]);
    $profile = $stmt_profile->fetch(PDO::FETCH_ASSOC);

    if ($profile) {
        $fname = htmlspecialchars($profile['first_name'] ?? '');
        $lname = htmlspecialchars($profile['last_name'] ?? '');
        $mname = htmlspecialchars($profile['middle_name'] ?? '');
        $email = htmlspecialchars($profile['email'] ?? ''); // Fetch email from users table
        $year = htmlspecialchars($profile['academic_year'] ?? '');
        $section = htmlspecialchars($profile['section'] ?? '');
        $program = htmlspecialchars($profile['program'] ?? '');
        $student_id = htmlspecialchars($profile['student_id'] ?? 'N/A');
        
        // Set Header Data
        $middle_initial = !empty($mname) ? ' ' . mb_substr($mname, 0, 1) . '.' : '';
        $student_name = $fname . $middle_initial . ' ' . $lname;
    } else {
         $db_error = "Student profile not found.";
    }

    // 2. Check for Pending Request
    $stmt_pending = $conn->prepare("
        SELECT * FROM profile_change_requests 
        WHERE student_sid = :sid AND status = 'Pending'
        ORDER BY submitted_at DESC LIMIT 1
    ");
    $stmt_pending->execute([':sid' => $current_student_sid]);
    $current_pending_request = $stmt_pending->fetch(PDO::FETCH_ASSOC);

    if ($current_pending_request) {
        $has_pending_request = true;
    }

} catch (PDOException $e) {
    $db_error = "Database error: " . $e->getMessage();
}
// --- END Live Data Fetching ---

// Determine if the form should be displayed for editing or for review
$is_editing_allowed = !$has_pending_request; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DFCAMCLP SmartGrade: Update Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
    /* --- Root Variables (Match Dashboard) --- */
    :root{
        --nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f2f5f8;
        --white:#fff; --text-dark:#102a43; --text-light:#627d98;
        --accent-light:#4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);
        --shadow-md: 0 6px 15px rgba(0,0,0,0.08); --critical:#cc3333;
        --success-green: #28a745; 
        --warning-orange: #ffa500;
    }
    
    /* === GLOBAL & LAYOUT === */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { height: 100vh; overflow: hidden; }
    body{
        display: flex; 
        font-family: 'Inter', 'Segoe UI', sans-serif;
        background-color: var(--bg-light);
        color: var(--text-dark);
    }

    /* --- (FIX) Sidebar Layout (Copied from Dashboard) --- */
    .sidebar { 
        width: 260px; background: var(--nav-dark); color: var(--white); 
        display: flex; flex-direction: column; flex-shrink: 0; height: 100vh;
        /* For Toggle */
        position: fixed; top: 0; left: 0; z-index: 40;
        transition: transform 0.3s ease-in-out;
        transform: translateX(-100%); 
    }
    /* (NEW) This class makes the sidebar visible */
    .sidebar-visible {
        transform: translateX(0);
    }
    
    .sidebar-header { padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
    /* (NEW) Made logo a link */
    .sidebar-header-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; color: white; }
    .sidebar-header-logo i { font-size: 28px; color: var(--accent-light); }
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

    /* (NEW) Sidebar Toggle Classes */
    #sidebar-overlay { 
        position: fixed; inset: 0; background: #000; opacity: 0.5; 
        z-index: 30; transition: opacity 0.3s ease-in-out; 
        display: none; /* Hidden by default */
    }

    /* --- (FIX) Main Content (Copied from Dashboard) --- */
    .main-content { 
        flex-grow: 1; display: flex; flex-direction: column; 
        height: 100vh; overflow: hidden; 
        transition: margin-left 0.3s ease-in-out;
        width: 100%; 
    }
    .header { display: flex; justify-content: space-between; align-items: center; padding: 16px 32px; background: var(--white); border-bottom: 1px solid #e0e6ed; flex-shrink: 0; }
    /* (NEW) Added header-left */
    .header-left { display: flex; align-items: center; gap: 16px; }
    .header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
    .user-profile { display: flex; align-items: center; gap: 12px; }
    .user-profile-info { text-align: right; }
    .user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
    .user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
    .page-view { flex-grow: 1; overflow-y: auto; padding: 32px; }

    
    /* === PROFILE FORM STYLES (MATCHING DASHBOARD) === */
    
    .profile-header {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--panel-blue);
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .profile-form-container {
        background: var(--white);
        padding: 30px 40px;
        border-radius: 12px;
        box-shadow: var(--shadow);
        max-width: 700px;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    label {
        margin-bottom: 6px;
        font-weight: 600;
        color: var(--text-dark);
        font-size: 0.9rem;
    }
    
    /* --- Read-Only Display Styling --- */
    .read-only-group {
        display: flex;
        flex-direction: column;
        padding-top: 5px; /* Alignment with editable fields */
    }

    .read-only-value {
        padding: 10px;
        border: 1px solid #e0e6ed;
        border-radius: 8px;
        background-color: #f7f7f7; /* Grey background for read-only */
        color: var(--text-light);
        font-size: 1rem;
        font-weight: 500;
    }

    input, select {
        padding: 10px;
        border: 1px solid #e0e6ed;
        border-radius: 8px;
        outline: none;
        font-size: 1rem;
        transition: border-color 0.2s;
    }
    
    input:focus, select:focus {
        border-color: var(--panel-blue);
    }
    
    /* --- Disabled/Read-Only input visual cue --- */
    input[disabled], select[disabled] {
        background-color: #f7f7f7;
        color: var(--text-light);
        cursor: not-allowed;
    }
    
    .button-group {
        display: flex;
        justify-content: flex-start;
        gap: 15px;
        margin-top: 20px;
    }

    .action-button {
        padding: 12px 25px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        font-size: 1rem;
        transition: background 0.3s, opacity 0.3s;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .update-btn {
        background-color: var(--panel-blue);
        color: var(--white);
    }
    .update-btn:hover {
        background-color: var(--nav-dark);
    }
    
    .cancel-btn {
        background-color: #e0e6ed;
        color: var(--text-dark);
    }
    .cancel-btn:hover {
        background-color: #c9d2de;
    }
    
    .message-box {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 8px;
        font-weight: 500;
        border: 1px solid;
    }
    .success {
        background: #e6f7e6; 
        color: var(--success-green); 
        border-color: var(--success-green);
    }
    .error {
        background: #fde8e8; 
        color: var(--critical); 
        border-color: var(--critical); 
    }
    .warning {
        background: #fff3e0; 
        color: var(--warning-orange); 
        border-color: var(--warning-orange); 
    }
    
    /* (NEW) Responsive Styles from Dashboard */
    .toggle-button {
        background: none; border: none; cursor: pointer; color: var(--text-dark);
        padding: 4px;
    }
    .toggle-button i { font-size: 1.25rem; }
    .lg-hidden { display: none; } /* Utility class to hide on large screens */

    /* Responsive */
    @media (max-width: 1023px) { /* lg breakpoint */
        .lg-hidden { display: inline-block; } /* Show mobile-only buttons */
        .header, .page-view { padding-left: 16px; padding-right: 16px; }
        .header-title { font-size: 1.2rem; }
        .form-grid { grid-template-columns: 1fr; }
    }

    /* FIX: Desktop & Sidebar Toggle Behavior */
    @media (min-width: 1024px) { /* lg breakpoint */
        .sidebar {
            transform: translateX(0); /* Always visible on desktop */
        }
        .main-content {
            margin-left: 260px; /* Make room for the sticky sidebar */
        }
        .lg-hidden {
            display: none; /* Hide mobile-only buttons */
        }
        #sidebar-overlay {
            display: none !important; /* Overlay is NEVER used on desktop */
        }
    }
    </style>
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="students.php" class="sidebar-header-logo">
            <i class="fa-solid fa-graduation-cap"></i>
            <h2>SmartGrade<span>Student Portal</span></h2>
        </a>
        <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
            <i class="fa-solid fa-times" style="color: var(--white);"></i>
        </button>
    </div>
    <nav class="sidebar-nav">
        <ul>
            <li><a href="students.php" class="nav-link"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="messaging_center.php" class="nav-link"><i class="fa-solid fa-comments"></i><span>Messaging</span></a></li>
            <li><a href="student_profile.php" class="nav-link active"><i class="fa-solid fa-user"></i><span>Profile</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Log Out</a>
    </div>
</div>

<div id="sidebar-overlay" onclick="toggleSidebar()"></div>


<div class="main-content" id="main-content">
    <header class="header">
        <div class="header-left">
            <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h1 class="header-title">My Profile</h1>
        </div>
        
        <div class="user-profile">
            <div class="user-profile-info">
                <strong><?php echo htmlspecialchars($student_name); ?></strong>
                <span><?php echo htmlspecialchars(ucfirst($current_user_role)); ?></span>
            </div>
        </div>
    </header>

    <main class="page-view">
        <h1 class="profile-header">
            <i class="fa-solid fa-user-edit"></i> Manage Personal Information
        </h1>

        <?php if (!empty($message)): ?>
            <div class="message-box <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($db_error)): ?>
            <div class="message-box error">
                <?= $db_error ?>
            </div>
        <?php endif; ?>
        
        <div class="profile-form-container">
            <?php if ($has_pending_request): ?>
                
                <div class="message-box warning">
                    <i class="fa-solid fa-clock"></i> <strong>Request Pending Review:</strong> Your profile change submitted on 
                    <?php echo date("M d, Y", strtotime($current_pending_request['submitted_at'])); ?> 
                    is currently being reviewed by the administration. You cannot submit new changes until this one is processed.
                </div>
                
                <h3 class="profile-header" style="font-size: 1.2rem; border-bottom: 1px solid #e0e6ed; padding-bottom: 10px;">
                    Requested Changes:
                </h3>
                
                <div class="form-grid">
                    <?php 
                    // Display the requested changes vs current profile data
                    $fields = [
                        'First Name' => ['current' => $profile['first_name'], 'new' => $current_pending_request['new_first_name']],
                        'Last Name' => ['current' => $profile['last_name'], 'new' => $current_pending_request['new_last_name']],
                        'Middle Name' => ['current' => $profile['middle_name'], 'new' => $current_pending_request['new_middle_name']],
                        'Email Address' => ['current' => $profile['email'], 'new' => $current_pending_request['new_email']],
                    ];

                    foreach ($fields as $label => $data):
                        $current_val = htmlspecialchars($data['current']);
                        $new_val = htmlspecialchars($data['new']);
                        $is_changed = ($current_val !== $new_val);
                    ?>
                        <div class="form-group">
                            <label><?php echo $label; ?></label>
                            <div class="read-only-value" style="color: <?php echo $is_changed ? 'var(--warning-orange)' : 'var(--text-light)'; ?>">
                                <strong><?php echo $is_changed ? 'New:' : 'Current:'; ?></strong> <?php echo $new_val; ?>
                                <?php if ($is_changed): ?>
                                    <br><span style="color: var(--text-light); font-size: 0.75rem;">(Current: <?php echo $current_val; ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                
                <h3 class="profile-header" style="font-size: 1.2rem; border-bottom: 1px solid #e0e6ed; padding-bottom: 10px;">
                    Editable Fields (Requires Admin Approval)
                </h3>
                
                <form action="update_profile.php" method="POST">
                    <input type="hidden" name="action" value="submit_edit">
                    
                    <div class="form-grid">
                        
                        <div class="form-group">
                            <label for="fname">First Name:</label>
                            <input type="text" id="fname" name="first_name" value="<?= $fname ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="lname">Last Name:</label>
                            <input type="text" id="lname" name="last_name" value="<?= $lname ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="mname">Middle Name:</label>
                            <input type="text" id="mname" name="middle_name" value="<?= $mname ?>">
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address:</label>
                            <input type="email" id="email" name="email" value="<?= $email ?>" required>
                        </div>
                        
                        <div class="read-only-group">
                            <label for="student_id">Student ID:</label>
                            <div class="read-only-value"><?= $student_id ?></div>
                        </div>

                        <div class="read-only-group">
                            <label for="program">Program:</label>
                            <div class="read-only-value"><?= $program ?></div>
                        </div>

                        <div class="read-only-group">
                            <label for="year">Year Level:</label>
                            <div class="read-only-value"><?= $year ?></div>
                        </div>

                        <div class="read-only-group">
                            <label for="section">Section:</label>
                            <div class="read-only-value"><?= $section ?></div>
                        </div>
                        
                    </div>

                    <div class="button-group">
                        <button type="submit" class="action-button update-btn">
                            <i class="fa-solid fa-paper-plane"></i> Submit Change Request
                        </button>
                    </div>
                </form>

            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        
        // Toggle visibility class
        sidebar.classList.toggle('sidebar-visible');
        
        // Toggle overlay visibility
        if (sidebar.classList.contains('sidebar-visible')) {
            overlay.style.display = 'block';
        } else {
            overlay.style.display = 'none';
        }
    }

    function handleResize() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        
        if (window.innerWidth >= 1024) {
            // DESKTOP: Ensure sidebar is always visible and overlay is hidden
            sidebar.classList.add('sidebar-visible');
            overlay.style.display = 'none';
        } else {
            // MOBILE: Hide sidebar if the window is small and it's not explicitly toggled open
            if (!sidebar.classList.contains('sidebar-visible')) {
                 overlay.style.display = 'none';
            }
        }
    }

    // Initial page load handler
    document.addEventListener('DOMContentLoaded', () => {
        // Apply desktop state immediately if screen is large enough
        if (window.innerWidth >= 1024) {
            document.getElementById('sidebar').classList.add('sidebar-visible');
        }
    });
    
    // Run on every window resize
    window.addEventListener('resize', handleResize);
</script>

</body>
</html>