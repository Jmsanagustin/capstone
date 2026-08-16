<?php
// FILE: student_dashboard.php (Revised: Fix Grade Retrieval Logic)

// Add these lines temporarily to see the real error message
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
// IMPORTANT: Make sure you have REMOVED session_start() from your db.php file!
include 'db.php'; 

// --- NEW: STUDENT AUTHENTICATION ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php"); // Redirect to login if not a student
    exit();
}
// Get the logged-in student's system ID
$current_student_sid = $_SESSION['sid'];
// --- END: STUDENT AUTHENTICATION ---


// --- Grading System Logic ---
function get_gwa_equivalent($numerical_grade) {
    if ($numerical_grade >= 97 && $numerical_grade <= 100) return 1.00;
    if ($numerical_grade >= 94 && $numerical_grade <= 96) return 1.25;
    if ($numerical_grade >= 91 && $numerical_grade <= 93) return 1.50;
    if ($numerical_grade >= 88 && $numerical_grade <= 90) return 1.75;
    if ($numerical_grade >= 85 && $numerical_grade <= 87) return 2.00;
    if ($numerical_grade >= 82 && $numerical_grade <= 84) return 2.25;
    if ($numerical_grade >= 79 && $numerical_grade <= 81) return 2.50;
    if ($numerical_grade >= 76 && $numerical_grade <= 78) return 2.75;
    if ($numerical_grade == 75) return 3.00;
    if ($numerical_grade < 75) return 5.00; // Anything less than 75 is 5.00
    return 5.00;
}

// --- Data Retrieval (Live Data) ---
$student_name = "Student";
$student_id = "N/A";
$student_first_name = "Student";
$db_error = ""; // Initialize db_error

try {
    // Fetch the student's profile information using their session ID
    $stmt = $conn->prepare("
        SELECT student_id, first_name, middle_name, last_name 
        FROM user_profiles 
        WHERE sid = :sid
    ");
    $stmt->execute([':sid' => $current_student_sid]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($profile) {
        $student_first_name = htmlspecialchars($profile['first_name'] ?? '');
        $middle_name = htmlspecialchars($profile['middle_name'] ?? '');
        $last_name = htmlspecialchars($profile['last_name'] ?? '');
        
        // Build the full name (e.g., John Michael M. San Agustin)
        $middle_initial = !empty($middle_name) ? ' ' . mb_substr($middle_name, 0, 1) . '.' : '';
        $student_name = $student_first_name . $middle_initial . ' ' . $last_name;
        
        $student_id = htmlspecialchars($profile['student_id'] ?? 'N/A'); // This $student_id is used for the grades query
        
    } else {
        // Fallback if profile is missing
        $student_name = $_SESSION['username'] ?? 'Student'; 
        $student_first_name = $student_name;
    }

} catch (PDOException $e) {
    // You can set an error message to display in the HTML
    $db_error = "Error fetching profile: " . $e->getMessage();
}

$view = $_GET['view'] ?? 'dashboard'; // Simple view routing for the student


// --- START: Live Subject Data Fetch ---
$subjects_data = []; // Initialize as empty array
try {
    // FIX APPLIED: Only join to the 'Final' term grade record to get the overall final_grade
    $stmt_subjects = $conn->prepare("
        SELECT
            s.subject_code,
            s.subject_name,
            s.description, 
            g_final.final_grade,  /* <-- This should hold the 89.00 calculated grade */
            g_final.status
        FROM
            enrollment e
        JOIN
            classes c ON e.class_id = c.class_id
        JOIN
            subject s ON c.subject_id = s.subject_id
        LEFT JOIN
            grades g_final ON 
                s.subject_id = g_final.subject_id AND 
                g_final.student_id_string = :student_id_string AND
                g_final.term = 'Final' /* <-- Crucial filter to get the final course grade only */
        WHERE
            e.student_sid = :current_student_sid
        ORDER BY
            s.subject_code
    ");
    
    $stmt_subjects->execute([
        ':current_student_sid' => $current_student_sid,
        ':student_id_string' => $student_id // Fetched from the profile query
    ]);
    
    $raw_subjects = $stmt_subjects->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_subjects as $row) {
        // Parse units from description (e.g., "3 Units")
        $units = 3; // Default
        if (preg_match('/(\d+)\s+Units/', $row['description'], $matches)) {
            $units = (int)$matches[1];
        }

        $numerical_grade = $row['final_grade'] ?? 0;
        $status = $row['status'] ?? 'Enrolled'; // If no grade, just "Enrolled"
        
        $subjects_data[] = [
            'code' => $row['subject_code'],
            'title' => $row['subject_name'],
            'numerical_grade' => (float)$numerical_grade,
            'units' => $units,
            'status' => $status,
            // --- (CHANGE 1 APPLIED) ---
            // FIX: Flag any finalized grade below 80% (which is 'warning' or 'failing')
            'is_at_risk' => ($numerical_grade > 0 && $numerical_grade < 80) 
        ];
    }

} catch (PDOException $e) {
    $db_error .= " | Error fetching subjects: " . $e->getMessage();
}
// --- END: Live Subject Data Fetch ---


// --- NEW: Program-Level Stats (Honors & Residency) ---
$overall_gwa = 'N/A';
$failed_subjects_count = 0;
$all_grades_total_points = 0;
$all_grades_total_units = 0;
$residency_limit = 10; // 10 subjects = 5 years max (assuming 4 year program + 1 year extension)

try {
    // This query fetches ALL approved grades for the student from all terms
    // FIX APPLIED: Only query for the overall final grade (term = 'Final')
    $stmt_all_grades = $conn->prepare("
        SELECT g.final_grade, s.description
        FROM grades g
        JOIN subject s ON g.subject_id = s.subject_id
        WHERE g.student_id_string = :student_id_string 
        AND g.term = 'Final' /* <-- Crucial filter to get the final course grade only */
        AND (g.status = 'Approved by PH' OR g.status = 'Pending Registrar' OR g.status = 'Acknowledged by Registrar')
    ");
    $stmt_all_grades->execute([':student_id_string' => $student_id]);
    $all_grades = $stmt_all_grades->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_grades as $grade) {
        $units = 3; // Default
        if (preg_match('/(\d+)\s+Units/', $grade['description'], $matches)) {
            $units = (int)$matches[1];
        }
        
        $gwa_eq = get_gwa_equivalent($grade['final_grade']);
        
        $all_grades_total_points += $gwa_eq * $units;
        $all_grades_total_units += $units;

        // Count 5.00 grades for residency
        if ($gwa_eq == 5.00) {
            $failed_subjects_count++;
        }
    }

    if ($all_grades_total_units > 0) {
        $overall_gwa = number_format($all_grades_total_points / $all_grades_total_units, 2);
    }

} catch (PDOException $e) {
    $db_error .= " | Error fetching academic history: " . $e->getMessage();
}
// --- END: Program-Level Stats ---


// --- GWA Calculation (For CURRENT Term) ---
$total_grade_points = 0;
$total_units = 0;

foreach ($subjects_data as &$subject) {
    $subject['gwa_equivalent'] = get_gwa_equivalent($subject['numerical_grade']);
    // Only Approved final course grades contribute to the official GWA calculation
    if ($subject['status'] === 'Approved by PH' || $subject['status'] === 'Pending Registrar' || $subject['status'] === 'Acknowledged by Registrar') {
        $total_grade_points += $subject['gwa_equivalent'] * $subject['units'];
        $total_units += $subject['units'];
    }
}
unset($subject);

$current_gwa = ($total_units > 0) ? number_format($total_grade_points / $total_units, 2) : 'N/A';
$at_risk_count = count(array_filter($subjects_data, function($s) { return $s['is_at_risk']; }));

// --- (NEW) Prepare Data for Chart.js ---
$chart_labels = [];
$chart_data = [];
$chart_colors = [];

foreach ($subjects_data as $subject) {
    $chart_labels[] = $subject['code']; // e.g., "ENG 01"
    $grade = $subject['numerical_grade'];
    $chart_data[] = $grade;
    
    // Set bar color based on grade
    if ($grade == 0) $chart_colors[] = 'rgba(100, 100, 100, 0.6)'; // Pending (grey)
    else if ($grade >= 80) $chart_colors[] = 'rgba(40, 167, 69, 0.7)'; // --success-green
    else if ($grade >= 75) $chart_colors[] = 'rgba(255, 153, 0, 0.7)'; // --warning
    else $chart_colors[] = 'rgba(204, 51, 51, 0.7)'; // --critical
}
// --- END (NEW) ---

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DFCAMCLP SmartGrade: Student Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    
    <style>
    /* --- Root Variables (Standardized) --- */
    :root{
        --nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f2f5f8;
        --white:#fff; --text-dark:#102a43; --text-light:#627d98;
        --accent-light:#4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);
        --shadow-md: 0 6px 15px rgba(0,0,0,0.08); --critical:#cc3333;
        --warning:#ff9900; --muted:#888; --success-green: #28a745;
    }
    /* --- Global Reset & Base --- */
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100vh; overflow: hidden; }
    body{font-family: 'Inter', 'Segoe UI', sans-serif;margin:0;background:var(--bg-light);color:var(--text-dark); display: flex;}
    
    /* --- Sidebar (Adapted for Student) --- */
    .sidebar { 
        width: 260px; background: var(--nav-dark); color: var(--white); 
        display: flex; flex-direction: column; flex-shrink: 0; height: 100vh;
        /* --- (MODIFIED) For Toggle --- */
        position: fixed; top: 0; left: 0; z-index: 40;
        transition: transform 0.3s ease-in-out;
        transform: translateX(-100%); 
    }
    /* (NEW) This class makes the sidebar visible */
    .sidebar-visible {
        transform: translateX(0);
    }
    
    .sidebar-header { padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
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
    
    /* --- (NEW) Sidebar Toggle Classes --- */
    #sidebar-overlay { 
        position: fixed; inset: 0; background: #000; opacity: 0.5; 
        z-index: 30; transition: opacity 0.3s ease-in-out; 
        display: none; /* Hidden by default */
    }
    
    /* --- Main Content --- */
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
    .user-profile-info { text-align: right; }
    .user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
    .user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
    .page-view { flex-grow: 1; overflow-y: auto; padding: 32px; }
    
    /* --- (NEW) Toggle Buttons --- */
    .toggle-button {
        background: none; border: none; cursor: pointer; color: var(--text-dark);
        padding: 4px;
    }
    .toggle-button i { font-size: 1.25rem; }
    .lg-hidden { display: none; } /* Utility class to hide on large screens */

    /* --- Dashboard Content Sections (Student specific) --- */
    .dashboard-banner{ background: linear-gradient(135deg, var(--panel-blue), #2b77a3); color: white; padding: 25px; border-radius: 12px; margin-bottom: 30px; display:flex; justify-content:space-between; align-items:center; box-shadow: var(--shadow); }
    .dashboard-banner h2{margin:0; font-size: 1.8em;}
    
    .overview-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .card { background: var(--white); border-radius: 12px; padding: 20px; box-shadow: var(--shadow); }
    .card h3 { font-size: 1.1rem; color: var(--text-light); margin-bottom: 10px; }

    .gwa-card { border-left: 5px solid var(--panel-blue); }
    .gwa-value { font-size: 3rem; font-weight: 700; color: var(--text-dark); line-height: 1; }
    
    .alert-card { border-left: 5px solid var(--warning); }
    .alert-red { border-left-color: var(--critical); }
    .alert-card p { font-size: 1rem; line-height: 1.4; font-weight: 500;}
    .goal-impossible { color: var(--critical); font-weight: 600; }
    
    /* Table Styling (Aligned with Instructor Dash) */
    .table-container { 
        width: 100%; border-radius: 8px; overflow: hidden; 
        box-shadow: var(--shadow); background: var(--white);
    }
    .data-table{width:100%;border-collapse:collapse; }
    .data-table th{background:var(--bg-light);color:var(--text-light);padding:12px 15px;text-align:left;font-weight:600;text-transform:uppercase;font-size:0.75rem;}
    .data-table td{border-bottom:1px solid #e0e6ed;padding:15px; vertical-align: middle; text-align: left;}
    .data-table tbody tr:last-child td { border-bottom: none; }
    
    /* --- NEW Grade Color Logic --- */
    .grade-high { color: var(--success-green); font-weight: 700; } /* 80+ */
    .grade-warning { color: var(--warning); font-weight: 700; } /* 75-79 */
    .grade-low { color: var(--critical); font-weight: 700; } /* < 75 */
    .grade-pending { color: var(--text-light); font-weight: 500; }

    .details-button { background: var(--panel-blue); color: var(--white); padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.8rem; transition: background 0.2s; }
    .details-button:hover { background: var(--nav-dark); }
    .forecast-alert { background: var(--critical); color: var(--white); padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; margin-left: 10px; }
    .footer { text-align: center; padding: 20px 0; font-size: 0.8rem; color: var(--text-light); }

    /* Responsive */
    @media (max-width: 1023px) { /* lg breakpoint */
        .lg-hidden { display: inline-block; } /* Show mobile-only buttons */
        .header, .page-view { padding-left: 16px; padding-right: 16px; }
    }

    /* --- FIX: Desktop & Sidebar Toggle Behavior --- */
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
    
    /* Error Message Style */
    .message.error {
        padding: 15px 20px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; border: 1px solid;
        background: #fde8e8; color: var(--critical); border-color: var(--critical); 
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
            <li><a href="students.php?view=dashboard" class="nav-link active"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="messaging_center.php" class="nav-link"><i class="fa-solid fa-comments"></i><span>Messaging</span></a></li>
            <li><a href="student_profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a></li>
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
            <h1 class="header-title">Student Dashboard</h1>
        </div>
        
        <div class="user-profile">
            <div class="user-profile-info">
                <strong><?php echo htmlspecialchars($student_name); ?></strong>
                <span>Student</span>
            </div>
        </div>
    </header>

    <main class="page-view">
        
        <?php if ($db_error): // Show database error if it exists ?>
            <div class="message error">
                <?php echo htmlspecialchars($db_error); ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-banner">
            <div>
                <h2>Hello <?php echo htmlspecialchars($student_first_name); ?> 👋</h2>
            </div>
        </div>

        <section class="overview-cards" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
            
            <div class="card gwa-card">
                <h3>Overall GWA (All Terms)</h3>
                <p class="gwa-value"><?php echo $overall_gwa; ?></p>
                <?php if ($overall_gwa != 'N/A' && $overall_gwa <= 1.75): ?>
                    <p style="color: var(--success-green); font-weight: 600;">🎉 On track for Honors!</p>
                <?php elseif($overall_gwa != 'N/A' && $overall_gwa <= 2.00): ?>
                    <p style="color: var(--panel-blue); font-weight: 600;">On track for Dean's List!</p>
                <?php else: ?>
                    <p style="color: var(--text-light);">(GWA 2.00+ for Dean's List)</p>
                <?php endif; ?>
            </div>
            
            <div class="card alert-card <?php echo ($failed_subjects_count > $residency_limit / 2) ? 'alert-red' : ''; ?>">
                <h3>Failed Subjects (Residency Limit)</h3>
                <p class="gwa-value" style="font-size: 2.5rem;"><?php echo $failed_subjects_count; ?></p>
                
                <p>Failed subject(s) recorded. Limit: <strong><?php echo $residency_limit; ?></strong></p>
                <?php 
                $subjects_remaining = $residency_limit - $failed_subjects_count;
                if ($failed_subjects_count >= $residency_limit): ?>
                        <p class="goal-impossible" style="font-size: 0.8rem;">🚨 <strong>RESIDENCY ALERT: Maximum limit reached.</strong></p>
                <?php elseif ($failed_subjects_count > $residency_limit / 2): ?>
                        <p class="goal-impossible" style="color: var(--critical); font-weight: 600;"><strong>WARNING:</strong> You have failed <strong><?php echo $failed_subjects_count; ?></strong> subjects. Only <strong><?php echo $subjects_remaining; ?></strong> remaining before the maximum residency failure limit is reached.</p>
                <?php else: ?>
                        <p style="color: var(--text-light);">(<?php echo $subjects_remaining; ?> subjects remaining before critical limit.)</p>
                <?php endif; ?>
            </div>

            <div class="card alert-card <?php echo $at_risk_count > 0 ? 'alert-red' : ''; ?>">
                <h3>Current Term</h3>
                
                <p class="gwa-value" style="font-size: 2.5rem; margin-bottom: 10px;"><?php echo $current_gwa; ?></p>
                <p style="margin-top: -10px; margin-bottom: 10px; font-size: 0.9rem; color: var(--text-light);">(Current Term GWA)</p>

                <p>
                    <?php echo $at_risk_count > 0 ?
                        "🚨 <strong>{$at_risk_count} Subject(s) At Risk.</strong>" :
                        "✅ <strong>On Track.</strong>";
                    ?>
                    <br>
                    <span style="font-size: 0.9rem;"><?php echo $at_risk_count > 0 ? "Check subject breakdowns." : "Current term is positive."; ?></span>
                </p>
            </div>
            </section>
        
        <section class="grade-chart-section" style="margin-bottom: 30px;">
            <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; color: var(--text-dark);">Current Term Grades</h2>
            <div class="card" style="padding: 20px; height: 350px; position: relative;">
                <canvas id="termGradeChart"></canvas>
            </div>
        </section>
        <section class="grade-table-section">
            <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; color: var(--text-dark);">Term Grade Summary</h2>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Subject Code</th>
                            <th>Subject Title</th>
                            <th>Numerical Grade</th>
                            <th>GWA Equivalent</th>
                            <th>Forecast</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($subjects_data)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-light); padding: 20px;">
                                    You are not yet enrolled in any subjects for this term.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($subjects_data as $subject): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($subject['code']); ?></td>
                                <td><?php echo htmlspecialchars($subject['title']); ?></td>
                                <td class="<?php 
                                    // --- MODIFIED: New Color Logic (Checks if grade is > 0) ---
                                    if ($subject['numerical_grade'] == 0) echo 'grade-pending';
                                    elseif ($subject['numerical_grade'] >= 80) echo 'grade-high';
                                    elseif ($subject['numerical_grade'] >= 75) echo 'grade-warning';
                                    else echo 'grade-low';
                                    ?>">
                                    <?php 
                                    // FIX: Only display numerical grade if it's greater than 0
                                    if ($subject['numerical_grade'] > 0) {
                                        // Use number_format to control display (e.g., 89)
                                        echo number_format($subject['numerical_grade'], 0) . '%';
                                    } else {
                                        echo 'N/A'; 
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                    // FIX: Only display GWA equivalent if numerical grade > 0
                                    if ($subject['numerical_grade'] > 0) {
                                        echo number_format($subject['gwa_equivalent'], 2);
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="subject_breakdown.php?subject_code=<?php echo urlencode($subject['code']); ?>" class="details-button">View Breakdown</a>
                                    <?php if ($subject['is_at_risk']): ?>
                                        <span class="forecast-alert">⚠️ Risk Detected</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <div class="footer">© 2025 Smart Grade Monitoring | Student Module</div>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('termGradeChart');
    if (ctx && <?php echo json_encode(!empty($chart_labels)); ?>) {
        // Get data from PHP
        const labels = <?php echo json_encode($chart_labels); ?>;
        const data = <?php echo json_encode($chart_data); ?>;
        const backgroundColors = <?php echo json_encode($chart_colors); ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Numerical Grade (%)',
                    data: data,
                    backgroundColor: backgroundColors,
                    borderColor: backgroundColors.map(color => color.replace('0.7', '1')), // Make border solid
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Allows chart to fill the container's height
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Grade (%)',
                            font: {
                                weight: '600',
                                size: '14px',
                                family: 'Inter'
                            },
                            color: 'var(--text-light)'
                        },
                        ticks: {
                             color: 'var(--text-light)',
                             font: { family: 'Inter' }
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Subject',
                             font: {
                                weight: '600',
                                size: '14px',
                                family: 'Inter'
                            },
                            color: 'var(--text-light)'
                        },
                         ticks: {
                             color: 'var(--text-light)',
                             font: { family: 'Inter' }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false // Hide legend, colors are self-explanatory
                    },
                    tooltip: {
                        backgroundColor: 'var(--text-dark)',
                        titleFont: { family: 'Inter', weight: 'bold', size: 14 },
                        bodyFont: { family: 'Inter', size: 12 },
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    // Show 'N/A' for 0 grades, otherwise show the percentage
                                    label += (context.parsed.y === 0) ? 'N/A (Pending)' : context.parsed.y + '%';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    } else if(ctx) {
        // (NEW) Fallback if no subjects are enrolled
        ctx.parentElement.innerHTML = '<p style="text-align: center; color: var(--text-light); margin-top: 50px;">No subject grades to display in a chart yet.</p>';
    }
});
</script>
</body>
</html>