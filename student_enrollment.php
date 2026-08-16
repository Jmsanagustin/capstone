<?php
session_start();
include 'db.php'; // Ensure this file provides the $conn PDO connection

// =========================================================================
// 1. ACCESS CONTROL AND DATA SETUP
// =========================================================================

// --- CRITICAL: Replace 'professor' with 'student' for student access ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

$current_user_id = $_SESSION['sid'] ?? $_SESSION['user_id'] ?? 0;

if ($current_user_id === 0) {
    die("Error: User session not properly initialized.");
}

$error_message = "";
$success_message = "";

// --- Fetch Student's Current Program and Year ---
try {
    $stmt = $conn->prepare("SELECT program, academic_year FROM user_profiles WHERE sid = :sid");
    $stmt->execute([':sid' => $current_user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    $student_program_id = $profile['program'] ?? null; // e.g., 'BSIT'
    $target_academic_year = '1st Year'; // Assuming the enrollment form is for 1st Year subjects

    if (!$student_program_id) {
        die("Error: Program details not found for enrollment.");
    }
} catch (PDOException $e) {
    die("Database Error fetching profile: " . $e->getMessage());
}

// =========================================================================
// 2. CORE FUNCTION: GET AVAILABLE SUBJECTS
// =========================================================================

/**
 * Retrieves subjects the student is ELIGIBLE to enroll in (i.e., not completed, not currently enrolled).
 * @param PDO $conn Database connection
 * @param int $sid Student ID
 * @param string $program_id Student's program (e.g., 'BSIT')
 * @param string $target_year Academic year to fetch subjects for (e.g., '1st Year')
 * @return array List of available subjects
 */
function get_available_subjects(PDO $conn, $sid, $program_id, $target_year) {
    // We assume the following table links:
    // subjects.academic_year holds the required year string ('1st Year')
    // subjects.program_id holds the required program string ('BSIT')

    $sql = "
    SELECT 
        s.subject_id, 
        s.subject_name
    FROM 
        subjects s
    -- LEFT JOIN 1: Check completed subjects in the grades table
    LEFT JOIN 
        grades g ON s.subject_id = g.subject_id AND g.sid = :user_id 
    -- LEFT JOIN 2: Check current enrollments in the enrollments table
    LEFT JOIN 
        enrollments e ON s.subject_id = e.subject_id AND e.sid = :user_id 
    WHERE 
        s.academic_year = :target_year 
        AND s.program_id = :program_id
        -- *** CRUCIAL EXCLUSION LOGIC ***
        -- Only include subjects that do NOT exist in the grades table (g.subject_id IS NULL)
        -- AND do NOT exist in the current enrollments table (e.subject_id IS NULL).
        AND g.subject_id IS NULL 
        AND e.subject_id IS NULL
    ORDER BY s.subject_name;
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':user_id' => $sid,
        ':program_id' => $program_id,
        ':target_year' => $target_year
    ]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$available_subjects = get_available_subjects($conn, $current_user_id, $student_program_id, $target_academic_year);


// =========================================================================
// 3. HANDLE ENROLLMENT SUBMISSION (POST)
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_subjects'])) {
    $selected_subjects = $_POST['subjects'] ?? [];
    $current_term = $_POST['current_term'] ?? 'Fall 2025'; // Get current term from form/session

    if (empty($selected_subjects)) {
        $error_message = "❌ Please select at least one subject to enroll in.";
    } else {
        try {
            $conn->beginTransaction();
            $stmt = $conn->prepare("INSERT INTO enrollments (sid, subject_id, term, status) VALUES (:sid, :subject_id, :term, 'Enrolled')");

            foreach ($selected_subjects as $subject_id) {
                $stmt->execute([
                    ':sid' => $current_user_id,
                    ':subject_id' => $subject_id,
                    ':term' => $current_term
                ]);
            }

            $conn->commit();
            $success_message = "✅ Successfully enrolled in " . count($selected_subjects) . " subjects for the " . htmlspecialchars($current_term) . " term!";
            
            // To update the list immediately after success
            $available_subjects = get_available_subjects($conn, $current_user_id, $student_program_id, $target_academic_year);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "❌ Enrollment Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subject Enrollment</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f7f6; }
        .container { max-width: 800px; margin: auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h1 { color: #1f6ea3; border-bottom: 2px solid #1f6ea3; padding-bottom: 10px; margin-bottom: 20px; }
        .subject-list { max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; border-radius: 6px; }
        .subject-item { margin-bottom: 10px; padding: 8px; border-bottom: 1px dashed #eee; }
        .subject-item:last-child { border-bottom: none; }
        .subject-item label { display: block; cursor: pointer; font-weight: normal; }
        .subject-item input { margin-right: 10px; transform: scale(1.2); }
        .message-box { padding: 10px; margin-bottom: 15px; border-radius: 4px; font-weight: bold; }
        .success-message { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error-message { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        button { padding: 10px 15px; background-color: #2b77a3; color: white; border: none; border-radius: 6px; cursor: pointer; margin-top: 15px; }
        button:hover { background-color: #1d5e86; }
    </style>
</head>
<body>

<div class="container">
    <h1>Enroll in 1st Year Subjects</h1>
    <p>Program: **<?= htmlspecialchars($student_program_id) ?>** | Target Year: **<?= htmlspecialchars($target_academic_year) ?>**</p>
    
    <?php if ($error_message): ?>
        <div class="message-box error-message"><?= htmlspecialchars($error_message) ?></div>
    <?php elseif ($success_message): ?>
        <div class="message-box success-message"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <form method="POST" action="student_enrollment.php">
        <input type="hidden" name="current_term" value="Fall 2025"> <h2>Available Subjects (<?= count($available_subjects) ?>)</h2>

        <?php if (empty($available_subjects)): ?>
            <div class="message-box success-message">
                🥳 **Congratulations!** You are currently enrolled in, or have completed, all required 1st Year subjects for your program.
            </div>
        <?php else: ?>
            <div class="subject-list">
                <?php foreach ($available_subjects as $subject): ?>
                    <div class="subject-item">
                        <label>
                            <input type="checkbox" name="subjects[]" value="<?= htmlspecialchars($subject['subject_id']) ?>">
                            <?= htmlspecialchars($subject['subject_name']) ?> (ID: <?= htmlspecialchars($subject['subject_id']) ?>)
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="enroll_subjects">Enroll Selected Subjects</button>
        <?php endif; ?>
    </form>
</div>

</body>
</html>