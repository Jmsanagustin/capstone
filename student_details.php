<?php
// FILE: student_details.php
// VERSION 3:
// - Added full "Edit Profile" functionality for PH (Name, Email, Year, Section).
// - Added "Provisional GWA" to show GWA including pending grades.
// - Replaced "Back to Directory" link with a proper "Back" button (history.go(-1)).

session_start();
include 'db.php'; // Include your database connection

// --- Load Temp Message (from POST redirect) ---
$message = '';
$message_type = '';
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}

$student_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_STRING); // This is the student's SID
$student_data = null;
$db_error = '';


// --- +++ NEW: POST Request Handler (for Profile Update) +++ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    
    // Check if the student ID from the form matches the one in the URL
    if ($_POST['student_sid'] !== $student_id) {
        $_SESSION['temp_message'] = ['text' => 'Error: Form mismatch. Please try again.', 'type' => 'error'];
        header("Location: student_details.php?id=" . urlencode($student_id));
        exit();
    }

    // --- 1. Sanitize and Validate ---
    $first_name = trim(filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING));
    $middle_name = trim(filter_input(INPUT_POST, 'middle_name', FILTER_SANITIZE_STRING));
    $last_name = trim(filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $academic_year = trim(filter_input(INPUT_POST, 'academic_year', FILTER_SANITIZE_STRING));
    $section = trim(filter_input(INPUT_POST, 'section', FILTER_SANITIZE_STRING));

    // Simple validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($academic_year) || empty($section)) {
        $_SESSION['temp_message'] = ['text' => 'Error: All fields (except Middle Name) are required.', 'type' => 'error'];
        header("Location: student_details.php?id=" . urlencode($student_id));
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['temp_message'] = ['text' => 'Error: Invalid email format.', 'type' => 'error'];
        header("Location: student_details.php?id=" . urlencode($student_id));
        exit();
    }
    
    // Make middle name null if it's an empty string
    $middle_name = empty($middle_name) ? null : $middle_name;

    // --- 2. Database Update in a Transaction ---
    try {
        $conn->beginTransaction();
        
        // Update user_profiles table
        $stmt_profile = $conn->prepare("
            UPDATE user_profiles 
            SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, 
                academic_year = :academic_year, section = :section
            WHERE sid = :sid
        ");
        $stmt_profile->execute([
            ':first_name' => $first_name,
            ':middle_name' => $middle_name,
            ':last_name' => $last_name,
            ':academic_year' => $academic_year,
            ':section' => $section,
            ':sid' => $student_id
        ]);

        // Update users table (for email)
        $stmt_user = $conn->prepare("UPDATE users SET email = :email WHERE sid = :sid");
        $stmt_user->execute([':email' => $email, ':sid' => $student_id]);

        $conn->commit();
        
        $_SESSION['temp_message'] = ['text' => 'Student profile updated successfully.', 'type' => 'success'];
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['temp_message'] = ['text' => 'Database Error: ' . $e->getMessage(), 'type' => 'error'];
    }
    
    // Redirect back to this page (PRG Pattern)
    header("Location: student_details.php?id=" . urlencode($student_id));
    exit();
}
// --- +++ END: POST Request Handler +++ ---


// --- GET Request: Fetch Student Data ---
if (!$student_id) {
    $db_error = "Error: Student ID not provided in the URL.";
} else {
    try {
        // Fetch User Account info (email) and Profile info
        $stmt = $conn->prepare("
            SELECT 
                up.first_name, up.middle_name, up.last_name, up.program, up.academic_year, up.section,
                u.email, u.sid, u.username
            FROM user_profiles up
            JOIN users u ON up.sid = u.sid
            WHERE up.sid = :sid AND u.role = 'student'
        ");
        $stmt->bindParam(':sid', $student_id, PDO::PARAM_STR);
        $stmt->execute();
        $student_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student_data) {
            $db_error = "Error: Student with ID '{$student_id}' not found or is not an active student.";
        }
        
        // --- UPDATED: Fetch REAL Grade History ---
        if ($student_data) {
            
            $stmt_grades = $conn->prepare("
                SELECT 
                    s.subject_code,
                    s.subject_name,
                    g.term,
                    g.term_grade, 
                    g.final_grade,
                    g.status,
                    g.remarks
                FROM grades g
                JOIN subject s ON g.subject_id = s.subject_id
                WHERE g.student_id_string = :username
                ORDER BY g.term, s.subject_code
            ");
            
            $stmt_grades->execute([':username' => $student_data['username']]);
            $student_data['grades'] = $stmt_grades->fetchAll(PDO::FETCH_ASSOC);
            
            // --- +++ UPDATED: Calculate REAL GWA (Official vs Provisional) +++ ---
            $total_grade_points_official = 0;
            $total_subjects_official = 0;
            $total_grade_points_provisional = 0;
            $total_subjects_provisional = 0;
            
            foreach ($student_data['grades'] as $grade) {
                // Only count the 'Final' term row
                if ($grade['term'] == 'Final' && $grade['final_grade'] !== null) {
                    
                    // 1. Official GWA: Only counts fully approved grades
                    if ($grade['status'] == 'Approved by PH') {
                        $total_grade_points_official += $grade['final_grade'];
                        $total_subjects_official++;
                        
                        // Approved grades also count towards provisional GWA
                        $total_grade_points_provisional += $grade['final_grade'];
                        $total_subjects_provisional++;
                    }
                    // 2. Provisional GWA: Counts Approved AND Pending grades
                    elseif ($grade['status'] == 'Pending PH Approval') {
                         $total_grade_points_provisional += $grade['final_grade'];
                         $total_subjects_provisional++;
                    }
                }
            }
            
            $student_data['official_gwa'] = ($total_subjects_official > 0) ? number_format($total_grade_points_official / $total_subjects_official, 2) : 'N/A';
            $student_data['provisional_gwa'] = ($total_subjects_provisional > 0) ? number_format($total_grade_points_provisional / $total_subjects_provisional, 2) : 'N/A';
        }

    } catch (PDOException $e) {
        $db_error = "Database Error: " . $e->getMessage();
    }
}

// Prepare full name for display
$full_name = $student_data ? 
    htmlspecialchars($student_data['first_name']) . ' ' . 
    (empty($student_data['middle_name']) ? '' : htmlspecialchars($student_data['middle_name'][0]) . '. ') . 
    htmlspecialchars($student_data['last_name']) : 'N/A';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile: <?= htmlspecialchars($student_id); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    :root {
        --nav-dark: #0f4a73; --panel-blue: #1f6ea3; --bg-light: #f2f5f8;
        --white: #ffffff; --text-dark: #102a43; --text-light: #627d98;
        --accent-light: #4db8ff; --shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        --success-green: #28a745; --critical: #cc3333; --warning-yellow: #f5a623;
        --border-color: #e0e6ed;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: var(--bg-light); color: var(--text-dark); }
    .container { max-width: 900px; margin: 50px auto; padding: 20px; }
    
    .header { background: var(--white); padding: 20px 30px; border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 30px; }
    .header h1 { font-size: 1.8rem; font-weight: 700; color: var(--panel-blue); margin-bottom: 5px; }
    .header p { color: var(--text-light); font-size: 0.9rem; }
    
    /* +++ NEW: Back Button +++ */
    .back-btn {
        font-size: 0.9rem; color: var(--panel-blue); text-decoration: none; display: inline-block;
        margin-bottom: 15px; background: none; border: none; cursor: pointer; padding: 0;
        font-family: 'Inter', sans-serif; font-weight: 600;
    }
    .back-btn:hover { text-decoration: underline; }

    .profile-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
    
    .detail-card { background: var(--white); padding: 25px; border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 20px; }
    .detail-card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--bg-light); padding-bottom: 10px; margin-bottom: 15px; }
    .detail-card-header h2 { font-size: 1.3rem; color: var(--text-dark); border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    
    /* +++ NEW: Edit Button Styles +++ */
    .edit-btn, .save-btn, .cancel-btn {
        background: none; border: 1px solid transparent; border-radius: 6px;
        padding: 5px 12px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.2s;
    }
    .edit-btn { color: var(--panel-blue); background: #eef2ff; }
    .edit-btn:hover { background: #e0e7ff; }
    .save-btn { color: var(--success-green); background: #e6f7e6; }
    .save-btn:hover { background: #d1f0d1; }
    .cancel-btn { color: var(--text-light); background: var(--bg-light); }
    .cancel-btn:hover { background: var(--border-color); }
    .form-buttons { display: flex; gap: 10px; }

    .detail-item { padding: 10px 0; border-bottom: 1px dashed #e0e6ed; }
    .detail-item:last-child { border-bottom: none; }
    .detail-item strong { display: block; font-size: 0.85rem; color: var(--text-light); margin-bottom: 3px; }
    .detail-item span { font-size: 1rem; font-weight: 600; }
    
    /* +++ NEW: Form Input Styles +++ */
    .detail-item input[type="text"], .detail-item input[type="email"] {
        font-size: 1rem; font-weight: 600; font-family: 'Inter', sans-serif;
        border: 2px solid transparent; border-radius: 6px; padding: 4px 8px;
        width: 100%; margin-left: -8px;
    }
    .detail-item input[readonly] {
        background: none; color: var(--text-dark); cursor: default;
        border-color: transparent;
    }
    .detail-item input:not([readonly]) {
        border-color: var(--border-color);
        background: var(--bg-light);
        color: var(--text-dark);
    }
    .detail-item input:not([readonly]):focus {
        border-color: var(--panel-blue);
        background: var(--white);
        outline: none;
    }
    
    .status-card { background: var(--panel-blue); color: var(--white); padding: 25px; border-radius: 12px; text-align: center; }
    .status-card h3 { font-size: 1rem; opacity: 0.8; margin-bottom: 10px; }
    .status-card p.gwa-main { font-size: 3rem; font-weight: 700; line-height: 1; }
    /* +++ NEW: Provisional GWA Style +++ */
    .status-card p.gwa-provisional {
        font-size: 0.9rem; opacity: 0.8; margin-top: 10px;
        border-top: 1px solid rgba(255,255,255,0.3); padding-top: 10px;
    }

    .grade-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; margin-top: 15px; }
    .grade-table th { background: var(--bg-light); color: var(--text-light); padding: 10px; text-align: left; font-weight: 600; }
    .grade-table td { border-bottom: 1px solid #e0e6ed; padding: 10px; }
    .grade-low { color: var(--critical); font-weight: 700; }
    .grade-high { color: var(--success-green); font-weight: 700; }
    
    /* Message Styles (for success/error) */
    .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; border: 1px solid; }
    .message.error { background: #fde8e8; color: var(--critical); border-color: var(--critical); }
    .message.success { background: #e6f7e6; color: var(--success-green); border-color: var(--success-green); }
    .message.warning { background: #fef6e6; color: var(--warning-yellow); border-color: var(--warning-yellow); }

    .status-tag { padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 0.8rem; }
    .status-tag.approved-by-ph { background: #e6f7e6; color: var(--success-green); }
    .status-tag.rejected-by-ph { background: #fde8e8; color: var(--critical); }
    .status-tag.pending-ph-approval { background: #fef6e6; color: var(--warning-yellow); }
    .status-tag.pending-registrar { background: #eef2ff; color: #4338ca; }
    .status-tag.acknowledged-by-registrar { background: #e0f2fe; color: #0284c7; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <button onclick="history.go(-1);" class="back-btn">
            <i class="fa-solid fa-arrow-left"></i> Back
        </button>
        <h1><?= $full_name; ?></h1>
        <p>Student ID: <?= htmlspecialchars($student_data['username'] ?? $student_id); ?> | Program Head View</p>
    </div>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($db_error): ?>
        <div class="message error"><?= htmlspecialchars($db_error); ?></div>
    <?php elseif ($student_data): ?>
        <div class="profile-grid">
            
            <div>
                <form id="profile-form" method="POST" action="student_details.php?id=<?= htmlspecialchars($student_id); ?>">
                    <input type="hidden" name="student_sid" value="<?= htmlspecialchars($student_id); ?>">
                    <input type="hidden" name="update_student" value="1">
                    
                    <div class="detail-card">
                        <div class="detail-card-header">
                            <h2><i class="fa-solid fa-id-card"></i> Personal Details</h2>
                            <div class="form-buttons">
                                <button type="button" id="edit-btn" class="edit-btn"><i class="fa-solid fa-pen"></i> Edit</button>
                                <button type="submit" id="save-btn" class="save-btn" style="display: none;"><i class="fa-solid fa-check"></i> Save Changes</button>
                                <button type="button" id="cancel-btn" class="cancel-btn" style="display: none;">Cancel</button>
                            </div>
                        </div>
                        
                        <div class="detail-item"><strong>First Name</strong>
                            <input type="text" name="first_name" value="<?= htmlspecialchars($student_data['first_name']); ?>" readonly>
                        </div>
                        <div class="detail-item"><strong>Middle Name</strong>
                            <input type="text" name="middle_name" value="<?= htmlspecialchars($student_data['middle_name'] ?? ''); ?>" readonly>
                        </div>
                        <div class="detail-item"><strong>Last Name</strong>
                            <input type="text" name="last_name" value="<?= htmlspecialchars($student_data['last_name']); ?>" readonly>
                        </div>
                        <div class="detail-item"><strong>Email</strong>
                            <input type="email" name="email" value="<?= htmlspecialchars($student_data['email']); ?>" readonly>
                        </div>
                    </div>

                    <div class="detail-card">
                        <div class="detail-card-header">
                            <h2><i class="fa-solid fa-graduation-cap"></i> Academic Status</h2>
                        </div>
                        <div class="detail-item"><strong>Program</strong>
                            <input type="text" value="<?= htmlspecialchars($student_data['program']); ?>" readonly style="background: var(--bg-light); color: var(--text-light); cursor: not-allowed;">
                        </div>
                        <div class="detail-item"><strong>Current Year Level</strong>
                            <input type="text" name="academic_year" value="<?= htmlspecialchars($student_data['academic_year']); ?>" readonly>
                        </div>
                        <div class="detail-item"><strong>Section</strong>
                            <input type="text" name="section" value="<?= htmlspecialchars($student_data['section']); ?>" readonly>
                        </div>
                    </div>
                </form>
            </div>

            <div>
                <div class="status-card">
                    <h3>Official GWA (Approved)</h3>
                    <p class="gwa-main"><?= htmlspecialchars($student_data['official_gwa']); ?></p>
                    
                    <?php if ($student_data['provisional_gwa'] !== $student_data['official_gwa']): ?>
                    <p class="gwa-provisional">
                        Provisional GWA (inc. pending): 
                        <strong><?= htmlspecialchars($student_data['provisional_gwa']); ?></strong>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="detail-card">
            <h2><i class="fa-solid fa-book-open"></i> Term Grade History</h2>
            <table class="grade-table">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Term</th>
                        <th>Term Grade</th>
                        <th>Final Course Grade</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($student_data['grades'])): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-light); padding: 20px;">No official grades found for this student.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($student_data['grades'] as $grade): ?>
                            <tr>
                                <td><?= htmlspecialchars($grade['subject_code'] . ' - ' . $grade['subject_name']); ?></td>
                                <td><?= htmlspecialchars($grade['term']); ?></td>
                                
                                <td><?= ($grade['term_grade'] !== null) ? number_format($grade['term_grade'], 2) : 'N/A'; ?></td> 
                                
                                <td class="<?= ($grade['final_grade'] < 75 && $grade['term'] == 'Final') ? 'grade-low' : 'grade-high'; ?>">
                                    <?= ($grade['final_grade'] !== null) ? number_format($grade['final_grade'], 2) : 'N/A'; ?>
                                </td>
                                
                                <td><span class="status-tag <?= strtolower(str_replace(' ','-',$grade['status'])) ?? 'pending-registrar'; ?>"><?= htmlspecialchars($grade['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                </tbody>
            </table>
        </div>
        
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const editBtn = document.getElementById('edit-btn');
    if (editBtn) {
        const saveBtn = document.getElementById('save-btn');
        const cancelBtn = document.getElementById('cancel-btn');
        const form = document.getElementById('profile-form');
        const editableInputs = form.querySelectorAll('input[readonly]:not([style*="cursor: not-allowed"])');

        // Store original values in case of cancel
        let originalValues = {};
        editableInputs.forEach(input => {
            originalValues[input.name] = input.value;
        });

        editBtn.addEventListener('click', function() {
            // Make fields editable
            editableInputs.forEach(input => {
                input.readOnly = false;
            });
            editableInputs[0].focus(); // Focus the first field

            // Toggle buttons
            editBtn.style.display = 'none';
            saveBtn.style.display = 'inline-block';
            cancelBtn.style.display = 'inline-block';
        });

        cancelBtn.addEventListener('click', function() {
            // Restore original values
            editableInputs.forEach(input => {
                input.value = originalValues[input.name];
                input.readOnly = true;
            });
            
            // Toggle buttons
            editBtn.style.display = 'inline-block';
            saveBtn.style.display = 'none';
            cancelBtn.style.display = 'none';
        });
    }
});
</script>

</body>
</html>