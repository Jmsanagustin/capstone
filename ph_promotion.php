<?php
// FILE: ph_promotion.php (Student Batch Promotion Tool)

session_start(); 
include 'db.php'; 

// --- Authorization Check ---
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
    header("Location: index.php");
    exit();
}

$program_head_sid = $_SESSION['sid'] ?? 0;
$program_head_program = $_SESSION['program'] ?? ''; 
$temp_message = null;

// --- (NEW) ADVANCED FUNCTION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_promotion'])) {
    
    // 1. Get data from the form
    $from_year = $_POST['from_year']; // e.g., "1st Year"
    $to_year = $_POST['to_year'];     // e.g., "2nd Year"
    $new_academic_year = $_POST['new_academic_year']; // e.g., "2026-2027"
    $new_semester = $_POST['new_semester'];     // e.g., "1st Semester"

    try {
        $conn->beginTransaction();

        // 2. Find all students in the "from" batch
        $stmt_students = $conn->prepare("
            SELECT sid FROM user_profiles 
            WHERE program = :program AND academic_year = :from_year
        ");
        $stmt_students->execute([
            ':program' => $program_head_program,
            ':from_year' => $from_year
        ]);
        $students_to_promote = $stmt_students->fetchAll(PDO::FETCH_COLUMN);

        if (empty($students_to_promote)) {
            throw new Exception("No students found in $from_year to promote.");
        }

        // 3. Find all curriculum subjects for the "to" year
        $stmt_subjects = $conn->prepare("
            SELECT subject_id FROM subject 
            WHERE year_level = :to_year_num AND (program_offered = :program OR program_offered = 'ALL')
        ");
        // Convert "2nd Year" to 2
        $year_num = (int)filter_var($to_year, FILTER_SANITIZE_NUMBER_INT);
        $stmt_subjects->execute([
            ':to_year_num' => $year_num,
            ':program' => $program_head_program
        ]);
        $subject_ids_to_enroll = $stmt_subjects->fetchAll(PDO::FETCH_COLUMN);

        // 4. Find the corresponding class_ids for the new term
        // This assumes you (PH) already created the class shells in ph_courses.php
        $placeholders = implode(',', array_fill(0, count($subject_ids_to_enroll), '?'));
        $sql_classes = "
            SELECT subject_id, class_id FROM classes
            WHERE academic_year = ? AND semester = ? AND subject_id IN ($placeholders)
        ";
        
        $params = array_merge([$new_academic_year, $new_semester], $subject_ids_to_enroll);
        $stmt_classes = $conn->prepare($sql_classes);
        $stmt_classes->execute($params);
        
        // Create a simple map: [subject_id] => class_id
        $class_map = $stmt_classes->fetchAll(PDO::FETCH_KEY_PAIR);

        if (count($class_map) !== count($subject_ids_to_enroll)) {
            throw new Exception("Error: Mismatch found. Please ensure you have created all new class shells for $to_year in ph_courses.php first.");
        }

        // 5. UPDATE all student profiles to their new year_level
        $stmt_update_profile = $conn->prepare("
            UPDATE user_profiles SET academic_year = :to_year WHERE sid = :sid
        ");
        
        // 6. INSERT all students into the 'enrollment' table
        $stmt_enroll = $conn->prepare("
            INSERT IGNORE INTO enrollment (student_sid, class_id) VALUES (:sid, :class_id)
        ");

        $enrollment_count = 0;
        foreach ($students_to_promote as $student_sid) {
            // Update their profile
            $stmt_update_profile->execute([
                ':to_year' => $to_year, 
                ':sid' => $student_sid
            ]);

            // Enroll them in all the new classes
            foreach ($subject_ids_to_enroll as $subject_id) {
                $class_id_to_enroll = $class_map[$subject_id];
                $stmt_enroll->execute([
                    ':sid' => $student_sid,
                    ':class_id' => $class_id_to_enroll
                ]);
                $enrollment_count += $stmt_enroll->rowCount();
            }
        }

        // 7. Commit
        $conn->commit();
        $temp_message = ['type' => 'success', 'text' => "Promotion successful! " . count($students_to_promote) . " students promoted to $to_year. Total new enrollments created: $enrollment_count."];

    } catch (Exception $e) {
        $conn->rollBack();
        $temp_message = ['type' => 'error', 'text' => "Promotion Failed: " . $e->getMessage()];
    }
    
    $_SESSION['temp_message'] = $temp_message;
    header("Location: ph_promotion.php");
    exit();
}
// --- END HANDLER ---


// --- Page Render ---
$current_view = 'promotion';
$page_title = 'Batch Promotion Tool';
include_once 'ph_header.php'; // Include the header

// Load temporary status message from session
$message = '';
$message_type = '';
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}
?>

<?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<section class="content-section mb-8">
    <h2><i class="fa-solid fa-users-gear"></i> Batch Student Promotion</h2>
    <p class="description">
        Use this tool to promote an entire batch of students to their next academic year.
        This will automatically update their profiles and enroll them in the correct classes for the new term.
    </p>
    <p class="description" style="color: var(--critical); font-weight: 600;">
        <i class="fa-solid fa-triangle-exclamation"></i> 
        **CRITICAL:** Before you run this tool, you **must** go to "Class Management" and create all new class shells for the upcoming term.
    </p>

    <form action="ph_promotion.php" method="POST" onsubmit="return confirm('Are you sure you want to promote this entire batch? This action is irreversible.');" style="margin-top: 20px;">
        <input type="hidden" name="run_promotion" value="1">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            
            <div>
                <label for="from_year" style="font-weight: 600; display: block; margin-bottom: 5px;">Promote Batch FROM:</label>
                <select name="from_year" id="from_year" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                    <option value="1st Year">1st Year (Current)</option>
                    <option value="2nd Year">2nd Year (Current)</option>
                    <option value="3rd Year">3rd Year (Current)</option>
                </select>
            </div>
            
            <div>
                <label for="to_year" style="font-weight: 600; display: block; margin-bottom: 5px;">TO Year Level:</label>
                <select name="to_year" id="to_year" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                    <option value="2nd Year">2nd Year</option>
                    <option value="3rd Year">3rd Year</option>
                    <option value="4th Year">4th Year</option>
                </select>
            </div>

            <div>
                <label for="new_academic_year" style="font-weight: 600; display: block; margin-bottom: 5px;">New Academic Year:</label>
                <input type="text" name="new_academic_year" id="new_academic_year" placeholder="e.g., 2026-2027" required 
                       style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>

            <div>
                <label for="new_semester" style="font-weight: 600; display: block; margin-bottom: 5px;">New Semester:</label>
                <select name="new_semester" id="new_semester" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                    <option value="1st Semester">1st Semester</option>
                    <option value="2nd Semester">2nd Semester</option>
                </select>
            </div>
        </div>

        <div style="text-align: right; margin-top: 30px;">
            <button type="submit" class="approve-btn" style="background-color: var(--critical); padding: 12px 25px; font-size: 1rem;">
                <i class="fa-solid fa-bolt"></i> Run Batch Promotion
            </button>
        </div>
    </form>
</section>

<?php include 'ph_footer.php'; // Include the footer template ?>