<?php
// FILE: ph_class_review.php
// Displays the detailed grade report for Program Head approval, aligned with dashboard includes.
// STATUS: FINAL - Item-by-Item Transmutation, with PH Color Coding.

declare(strict_types=1); // Enforce strict type checking

ob_start(); // Start output buffering
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Note: Assumes 'db.php' is included in 'ph_header.php' or before it, but we keep it here for safety.
include_once 'db.php'; 

// --- Authorization Check ---
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
    header("Location: index.php");
    exit();
}

// --- Grade Calculation Helper Function (Item-by-Item Transmuted Grade) ---
/**
 * Calculates the final component grade by applying the transmutation formula to *each* raw score,
 * and then averaging the resulting transmuted grades.
 * Formula: Transmuted Grade (Item) = (Score / Max Score * 50) + 50
 *
 * @param array $scores Array of raw scores [{score: X, max_score: Y}, ...]
 * @return float The component's average transmuted grade.
 */
function calculate_avg_transmuted_grade_per_item(array $scores): float {
    if (empty($scores)) {
        return 50.00; // Base grade if no scores exist
    }

    $total_transmuted_grades = 0.0;
    $item_count = count($scores);

    foreach ($scores as $score) {
        $raw_score = (float)$score['score'];
        $max_score = (float)$score['max_score'];

        if ($max_score <= 0) {
            $transmuted_grade = 50.00;
        } else {
            // Apply Transmutation Formula: (Raw % * 50) + 50
            $transmuted_grade = (($raw_score / $max_score) * 50) + 50;
        }
        $total_transmuted_grades += $transmuted_grade;
    }
    
    // Return the Average of all Transmuted Grades
    return $total_transmuted_grades / $item_count;
}

// --- Get Class & Term from URL ---
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$term = filter_input(INPUT_GET, 'term', FILTER_SANITIZE_STRING); // 'Midterm' or 'Final'

if (!$class_id || !in_array($term, ['Midterm', 'Final'], true)) {
    $_SESSION['temp_message'] = ['text' => 'Invalid class or term specified.', 'type' => 'error'];
    header("Location: programhead_dashboard.php");
    exit();
}

$message = '';
$message_type = '';
$notation = ''; 

// --- FORM HANDLING (Approve Only) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_all') {
    $form_class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
    $form_term = filter_input(INPUT_POST, 'term', FILTER_SANITIZE_STRING);
    $notation = trim($_POST['ph_notation'] ?? '');

    if ($form_class_id === $class_id && $form_term === $term) {
        try {
            $db_notation = empty($notation) ? null : $notation;
            
            $stmt = $conn->prepare("
                UPDATE grades SET 
                    status = 'Approved by PH', 
                    review_date = NOW(), 
                    ph_rejection_reason = NULL,
                    ph_notation = :notation 
                WHERE class_id = :class_id AND term = :term AND status = 'Pending PH Approval'
            ");
            $stmt->execute([
                ':class_id' => $class_id, 
                ':term' => $term,
                ':notation' => $db_notation
            ]);
            
            $_SESSION['temp_message'] = ['text' => "Grades for class ID $class_id ($term) have been approved and saved.", 'type' => 'success'];
            header("Location: programhead_dashboard.php");
            exit();
        } catch (PDOException $e) {
            $message = "Database error during approval: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}
// --- END FORM HANDLING ---


// --- Include Header (START OF HTML OUTPUT) ---
$current_view = 'ph_class_review'; 
include_once 'ph_header.php';


// --- Data Fetching for Report ---
$students = [];
$components = [];
$scores_by_student = [];
$class_info = null;
$db_error = null;

try {
    // 1. Get Class Info
    $stmt_class = $conn->prepare("
        SELECT s.subject_code, s.subject_name, c.section, u.username AS professor_name
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        JOIN users u ON c.professor_sid = u.sid
        WHERE c.class_id = :class_id
    ");
    $stmt_class->execute([':class_id' => $class_id]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);

    // 2. Get students with pending grades
    $stmt_students = $conn->prepare("
        SELECT 
            g.grade_id, 
            u.username AS student_id_string, 
            up.first_name, 
            up.middle_name, 
            up.last_name, 
            g.term_grade,
            e.enrollment_id
        FROM grades g
        JOIN users u ON g.student_id_string = u.username
        JOIN user_profiles up ON u.sid = up.sid
        JOIN enrollment e ON u.sid = e.student_sid AND g.class_id = e.class_id
        WHERE g.class_id = :class_id AND g.term = :term AND g.status = 'Pending PH Approval'
        ORDER BY up.last_name, up.first_name
    ");
    $stmt_students->execute([':class_id' => $class_id, ':term' => $term]);
    $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

    if (empty($students)) {
        if (empty($message)) { 
            $message = "No pending grades found for this class. They may have already been reviewed.";
            $message_type = 'warning';
        }
    } else {
        // 3. Get Grade Components (Table Headers)
        $stmt_components = $conn->prepare("
            SELECT component_id, component_name, component_type, weight
            FROM grade_components
            WHERE class_id = :class_id AND term = :term
            ORDER BY component_type, component_id
        ");
        $stmt_components->execute([':class_id' => $class_id, ':term' => $term]);
        $components = $stmt_components->fetchAll(PDO::FETCH_ASSOC);

        // 4. Get ALL raw scores
        $stmt_scores = $conn->prepare("
            SELECT r.enrollment_id, r.component_id, r.score, r.max_score
            FROM raw_scores r
            JOIN grade_components gc ON r.component_id = gc.component_id
            WHERE gc.class_id = :class_id AND gc.term = :term
        ");
        $stmt_scores->execute([':class_id' => $class_id, ':term' => $term]);
        $all_raw_scores = $stmt_scores->fetchAll(PDO::FETCH_ASSOC);

        // Process scores into a fast-lookup array: [enrollment_id][component_id] => [scores]
        foreach ($all_raw_scores as $score) {
            $scores_by_student[(int)$score['enrollment_id']][(int)$score['component_id']][] = $score;
        }
    }

} catch (PDOException $e) {
    $db_error = "Database Error: " . $e->getMessage();
}

?>

<style>
    /* Retaining base styles */
    .report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
        margin-top: 20px;
    }
    .report-table th, .report-table td {
        border: 1px solid #ccc;
        padding: 8px;
        text-align: center;
        vertical-align: middle;
    }
    .report-table th {
        background: var(--bg-light);
        font-size: 0.75rem;
        text-transform: uppercase;
        text-align: center;
    }
    .report-table .student-col { width: 10%; text-align: left; }
    .report-table .student-name-col { width: 20%; text-align: left; }
    .report-table .component-col { width: 15%; }
    .report-table .grade-col { width: 10%; font-weight: bold; }

    /* New style for the Average Transmuted Grade display */
    .component-avg-grade {
        font-size: 1.1rem;
        font-weight: bold;
        color: black; /* Default color: Black ⚫ */
    }
    
    /* Specific Colors */
    .grade-low { color: red; } /* Below 80 🔴 */
    .grade-warning { color: orange; } /* Exactly 80 🟠 */
    
    .report-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
        padding: 20px;
        border-radius: 12px;
        background: var(--bg-light);
        border: 1px solid var(--border-color);
        align-items: flex-start;
    }
    
    .action-buttons-row {
        display: flex;
        width: 100%;
        gap: 10px;
        align-items: center;
    }
    
    .print-btn {
        background: var(--text-light);
        color: var(--white);
        margin-left: auto;
    }
    .print-btn:hover {
        background: var(--text-dark);
    }

    /* Print-specific styles */
    @media print {
        body { background: #fff; }
        .sidebar, .header, .report-actions, #sidebar-overlay, .notification-popover { display: none !important; }
        .main-content { margin-left: 0 !important; height: auto; }
        .page-view { padding: 0 !important; overflow: visible; }
        .content-section { box-shadow: none; border: none; padding: 0; margin: 0; }
        .report-table { font-size: 9pt; }
        .report-table th, .report-table td { padding: 5px; }
        @page { size: landscape; margin: 0.5in; }
    }
</style>


<section class="content-section">
    <?php if ($class_info): ?>
        <h2>
            <i class="fa-solid fa-file-invoice"></i> 
            Grade Review: <?= htmlspecialchars($class_info['subject_code'] . ' - ' . $class_info['subject_name']) ?>
        </h2>
        <p class="description">
            <strong>Section:</strong> <?= htmlspecialchars($class_info['section']) ?> | 
            <strong>Term:</strong> <?= htmlspecialchars($term) ?> | 
            <strong>Instructor:</strong> <?= htmlspecialchars($class_info['professor_name']) ?>
        </p>
    <?php else: ?>
        <h2><i class="fa-solid fa-file-invoice"></i> Grade Review</h2>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($db_error): ?>
        <div class="message error">
            <?php echo htmlspecialchars($db_error); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($students)): ?>
    <form method="POST" action="ph_class_review.php?class_id=<?= $class_id; ?>&term=<?= $term; ?>" onsubmit="return confirm('Are you sure you want to APPROVE all pending grades for this class? You can add an optional notation.');">
    <div class="report-actions">
        <div class="form-group" style="width: 100%; text-align: left;">
            <label for="ph_notation">Program Head Notation (Optional):</label>
            <textarea id="ph_notation" name="ph_notation" rows="2" style="width: 100%; font-family: 'Inter', sans-serif; font-size: 0.95rem; padding: 10px;"><?= htmlspecialchars($notation); ?></textarea>
        </div>
        
        <div class="action-buttons-row">
            <input type="hidden" name="action" value="approve_all">
            <input type="hidden" name="class_id" value="<?= $class_id; ?>">
            <input type="hidden" name="term" value="<?= $term; ?>">
            <button type="submit" class="approve-btn">
                <i class="fa-solid fa-check-double"></i> Approve All Grades
            </button>
            
            <button type="button" class="approve-btn print-btn" onclick="window.print();">
                <i class="fa-solid fa-print"></i> Print Report
            </button>
        </div>
    </div>
    </form>
    <?php endif; ?>

    <div class="table-container">
        <?php if (!empty($students)): ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th class="student-col">Student ID</th>
                        <th class="student-name-col">Student Name</th>
                        <?php foreach ($components as $component): ?>
                            <th class="component-col">
                                <?= htmlspecialchars(strtoupper($component['component_type'])) ?><br>
                                <strong><?= htmlspecialchars(strtoupper($component['component_name'])) ?></strong><br>
                                (<?= number_format((float)$component['weight'] * 100, 0) ?>%)
                            </th>
                        <?php endforeach; ?>
                        <th class="grade-col">Final Grade</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <?php 
                            $final_grade = (float)$student['term_grade'];
                            // Final grade styling: color the text of the final grade red if < 75
                            $final_grade_style_color = ($final_grade < 75) ? 'red' : 'var(--text-dark)';
                            $enrollment_id = (int)$student['enrollment_id'];
                        ?>
                        <tr>
                            <td class="student-col"><?= htmlspecialchars($student['student_id_string']) ?></td>
                            <td class="student-name-col"><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']) ?></td>
                            
                            <?php foreach ($components as $component): ?>
                                <td>
                                    <?php
                                    $component_id = (int)$component['component_id'];
                                    // Use the pre-processed array for lookup
                                    $scores = $scores_by_student[$enrollment_id][$component_id] ?? [];
                                    $avg_transmuted_grade = 0.00;
                                    
                                    if (!empty($scores)):
                                        // Calculate Transmuted Grade using the **Item-by-Item Average**
                                        $avg_transmuted_grade = calculate_avg_transmuted_grade_per_item($scores);
                                    endif;
                                    
                                    // Calculate and show component Average Transmuted Grade
                                    if (!empty($scores)) {
                                        
                                        // --- COLOR LOGIC (Below 80 = Red, Exactly 80 = Orange, Above 80 = Black) ---
                                        $grade_color_class = '';
                                        // Use number_format for precise comparison to 80.00
                                        $rounded_grade = round($avg_transmuted_grade, 2); 
                                        
                                        if ($rounded_grade < 80.00) {
                                            $grade_color_class = 'grade-low'; // RED 🔴
                                        } elseif ($rounded_grade == 80.00) {
                                            $grade_color_class = 'grade-warning'; // ORANGE 🟠
                                        } 
                                        
                                        // Display the Average Transmuted Grade
                                        echo '<div class="component-avg-grade ' . $grade_color_class . '">';
                                        echo number_format($avg_transmuted_grade, 2);
                                        echo '</div>';
                                    } else {
                                        echo '—'; // No scores recorded
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                            
                            <td class="grade-col">
                                <span style="font-size: 1.1rem; color: <?= $final_grade_style_color; ?>;">
                                    <?= number_format($final_grade, 2) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif(empty($db_error)): ?>
            <p class="no-data">No pending grades found for this class.</p>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // No JS interactivity needed for the grading logic itself
});
</script>

<?php 
// --- Include Footer (END OF HTML OUTPUT) ---
include 'ph_footer.php'; 
ob_end_flush(); // Send the buffered output
?>