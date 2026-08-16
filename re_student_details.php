<?php
session_start();
include 'db.php'; // Include your database connection

// --- 1. ROLE-BASED ACCESS CONTROL ---
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Registrar'])) { 
    header("Location: index.php");
    exit();
}

$student_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_STRING);
$student_data = null;
$grade_history = [];
$gwa_data = ['gwa' => 'N/A', 'total_units' => 0];
$db_error = '';

if (!$student_id) {
    $db_error = "Error: Student ID not provided in the URL.";
} else {
    try {
        // --- START OF FIX #1 ---
        // Fetch Student Profile
        // FIX: The query was incorrectly searching for 'up.sid' (System ID)
        // It must search for 'up.student_id' (the ID from the URL).
        $stmt_profile = $conn->prepare("
            SELECT 
                up.first_name, up.middle_name, up.last_name, up.program, up.academic_year, up.section,
                u.email, u.sid
            FROM user_profiles up
            JOIN users u ON up.sid = u.sid
            WHERE up.student_id = :student_id AND u.role = 'student'
        ");
        $stmt_profile->bindParam(':student_id', $student_id, PDO::PARAM_STR);
        // --- END OF FIX #1 ---

        $stmt_profile->execute();
        $student_data = $stmt_profile->fetch(PDO::FETCH_ASSOC);

        if (!$student_data) {
            $db_error = "Error: Student with ID '{$student_id}' not found or is not an active student.";
        } else {
            // --- START OF FIX #2 ---
            // Fetch Grades Approved by Registrar
            // FIX: The original query was completely wrong.
            // 1. It searched for 'g.sid', which doesn't exist. Changed to 'g.student_id_string'.
            // 2. It searched for 'g.approval_status', which doesn't exist. Changed to 'g.status'.
            // 3. The status 'Approved by Registrar' was wrong. Changed to 'Acknowledged by Registrar'
            //    to match the status you set in registrar_dashboard.php.
            $stmt_grades = $conn->prepare("
                SELECT
                    g.final_grade AS numerical_grade, g.term, sub.subject_code, sub.subject_name, g.status
                FROM grades g
                JOIN subject sub ON g.subject_id = sub.subject_id
                WHERE g.student_id_string = :student_id AND g.status = 'Acknowledged by Registrar'
                ORDER BY g.term, sub.subject_code
            ");
            $stmt_grades->bindParam(':student_id', $student_id, PDO::PARAM_STR);
            // --- END OF FIX #2 ---

            $stmt_grades->execute();
            $grade_history = $stmt_grades->fetchAll(PDO::FETCH_ASSOC);
            
            // --- Mock GWA Calculation (Replace with actual GWA function/logic if available) ---
            $total_grade_points = 0;
            $total_units = 0; 
            
            foreach ($grade_history as $grade) {
                // Mock GWA conversion (adjust if you have the official table/function)
                // Assuming 3 units per subject and a simple conversion for mock GWA
                $numerical_grade = $grade['numerical_grade'];
                
                // Example simplified GWA conversion (to simulate quality points)
                $gwa_points = 4.0 - (($numerical_grade - 75) / 100) * 3;
                $gwa_points = max(1.00, min(5.00, $gwa_points)); // Clamp between 1.00 and 5.00
                
                $total_grade_points += $gwa_points * 3; 
                $total_units += 3;
            }
            
            $gwa_data['gwa'] = ($total_units > 0) ? number_format($total_grade_points / $total_units, 2) : 'N/A';
            $gwa_data['total_units'] = $total_units;
        }

    } catch (PDOException $e) {
        $db_error = "Database Error: " . $e->getMessage();
    }
}

// Prepare full name for display
$full_name = $student_data ? 
    htmlspecialchars($student_data['first_name']) . ' ' . 
    (empty($student_data['middle_name']) ? '' : htmlspecialchars($student_data['middle_name']) . ' ') . 
    htmlspecialchars($student_data['last_name']) : 'N/A';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record: <?= htmlspecialchars($student_id); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* --- Base Styles (Adapted from Dashboard) --- */
    :root {
        --nav-dark: #0f4a73; --panel-blue: #1f6ea3; --bg-light: #f2f5f8;
        --white: #ffffff; --text-dark: #102a43; --text-light: #627d98;
        --shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        --success-green: #28a745; --critical: #cc3333; --warning-orange: #f5a623;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: var(--bg-light); color: var(--text-dark); }
    .container { max-width: 900px; margin: 50px auto; padding: 20px; }

    /* --- Header & Navigation --- */
    .header { background: var(--white); padding: 20px 30px; border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 30px; }
    .header h1 { font-size: 1.8rem; font-weight: 700; color: var(--panel-blue); margin-bottom: 5px; }
    .header p { color: var(--text-light); font-size: 0.9rem; }
    .back-link { font-size: 0.9rem; color: var(--text-light); text-decoration: none; display: inline-block; margin-bottom: 15px; transition: color 0.2s; }
    .back-link:hover { color: var(--panel-blue); }

    /* --- Layout --- */
    .profile-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
    .detail-card { background: var(--white); padding: 25px; border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 20px; }
    .detail-card h2 { font-size: 1.3rem; margin-bottom: 15px; color: var(--text-dark); border-bottom: 2px solid var(--bg-light); padding-bottom: 10px; display: flex; align-items: center; gap: 10px;}
    
    /* --- Key-Value Alignment --- */
    .detail-item { 
        padding: 10px 0; 
        border-bottom: 1px dashed #e0e6ed; 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
    }
    .detail-item:last-child { border-bottom: none; }
    .detail-item strong { 
        font-size: 0.85rem; 
        color: var(--text-light); 
        flex-basis: 40%; 
    }
    .detail-item span { 
        font-size: 1rem; 
        font-weight: 600; 
        flex-basis: 60%; 
        text-align: right; 
    }
    /* --- Status Card --- */
    .status-card { 
        background: linear-gradient(135deg, var(--panel-blue), #2b77a3); 
        color: var(--white); 
        padding: 25px; 
        border-radius: 12px; 
        text-align: center;
        margin-bottom: 20px;
    }
    .status-card h3 { font-size: 1rem; opacity: 0.8; margin-bottom: 10px; }
    .status-card p { font-size: 3rem; font-weight: 700; line-height: 1; }

    /* --- Grade Table --- */
    .grade-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; margin-top: 15px; }
    .grade-table th { background: var(--bg-light); color: var(--text-light); padding: 12px 15px; text-align: left; font-weight: 600; text-transform: uppercase; font-size: 0.75rem;}
    .grade-table td { border-bottom: 1px solid #e0e6ed; padding: 10px 15px; }
    .grade-low { color: var(--critical); font-weight: 700; }
    .grade-high { color: var(--success-green); font-weight: 700; }
    .grade-table .num-col { text-align: right; font-weight: 600;}
    .grade-table .status-approved { color: var(--panel-blue); font-weight: 600; }
    
    .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; border: 1px solid; background: #fde8e8; color: var(--critical); border-color: var(--critical); }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <a href="registrar_dashboard.php?view=records" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Student Records</a>
        <h1><?= $full_name; ?></h1>
        <p>Student ID: <strong><?= htmlspecialchars($student_id); ?></strong> | <strong>Official Academic Record</strong></p>
    </div>

    <?php if ($db_error): ?>
        <div class="message"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($db_error); ?></div>
    <?php elseif ($student_data): ?>
        <div class="profile-grid">
            
            <div>
                <div class="detail-card">
                    <h2><i class="fa-solid fa-id-card"></i> Personal & Enrollment Details</h2>
                    <div class="detail-item"><strong>First Name</strong><span><?= htmlspecialchars($student_data['first_name']); ?></span></div>
                    <div class="detail-item"><strong>Middle Name</strong><span><?= htmlspecialchars($student_data['middle_name'] ?? 'N/A'); ?></span></div>
                    <div class="detail-item"><strong>Last Name</strong><span><?= htmlspecialchars($student_data['last_name']); ?></span></div>
                    <div class="detail-item"><strong>Email</strong><span><?= htmlspecialchars($student_data['email']); ?></span></div>
                </div>

                <div class="detail-card">
                    <h2><i class="fa-solid fa-graduation-cap"></i> Enrollment Status</h2>
                    <div class="detail-item"><strong>Program</strong><span><?= htmlspecialchars($student_data['program']); ?></span></div>
                    <div class="detail-item"><strong>Current Year Level</strong><span><?= htmlspecialchars($student_data['academic_year']); ?></span></div>
                    <div class="detail-item"><strong>Section</strong><span><?= htmlspecialchars($student_data['section']); ?></span></div>
                </div>
            </div>

            <div>
                 <div class="status-card">
                    <h3>Cumulative GWA</h3>
                    <p><?= htmlspecialchars($gwa_data['gwa']); ?></p>
                 </div>
                 <div class="status-card" style="background: var(--warning-orange);">
                    <h3>Total Units Earned</h3>
                    <p><?= htmlspecialchars($gwa_data['total_units']); ?></p>
                 </div>
                
            </div>
        </div>

        <div class="detail-card">
            <h2><i class="fa-solid fa-scroll"></i> Final Grade History (Approved Records)</h2>
            <div class="table-container">
            <table class="grade-table">
                <thead>
                    <tr>
                        <th>Subject Code</th>
                        <th>Subject Title</th>
                        <th>Term</th>
                        <th class="num-col">Numerical Grade</th>
                        <th class="num-col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($grade_history)): ?>
                        <?php foreach ($grade_history as $grade): ?>
                            <tr>
                                <td><?= htmlspecialchars($grade['subject_code']); ?></td>
                                <td><?= htmlspecialchars($grade['subject_name']); ?></td>
                                <td><?= htmlspecialchars($grade['term']); ?></td>
                                <td class="num-col <?= ($grade['numerical_grade'] < 75) ? 'grade-low' : 'grade-high'; ?>">
                                    <?= number_format($grade['numerical_grade'], 2); ?>%
                                </td>
                                <td class="status-approved num-col"><?= htmlspecialchars(str_replace('_', ' ', $grade['status'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align: center; padding: 20px; color: var(--text-light);">No final, acknowledged grades found in the system for this student.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        
    <?php endif; ?>
</div>

</body>
</html>