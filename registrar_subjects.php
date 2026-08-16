<?php
// FILE: registrar_subjects.php
// PURPOSE: Registrar's READ-ONLY dashboard for viewing the complete Academic Catalog.
// VERSION: Read-Only Catalog View (Final Cleaned - HTML Bold Fixed)

// 1. START ALL PHP PROCESSING FIRST
ob_start(); // Start output buffering
session_status() === PHP_SESSION_NONE ? session_start() : true;
// NOTE: Assuming 'db.php' is available and handles the PDO connection $conn.
include 'db.php'; 

// --- Authorization Check (Run before any header output) ---
// Role check is strictly for 'Registrar'
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Registrar')) {
    header("Location: index.php"); // Redirect to login if not authorized
    exit();
}

$registrar_sid = $_SESSION['sid'] ?? 0;
$registrar_username = $_SESSION['username'] ?? 'Registrar';

// Initialize variables
$catalog_data = []; // Will hold the hierarchical data
$message = '';
$message_type = '';
$db_error = '';

// Force no filter
$selected_program_filter = ''; 
$class_details_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);

// --- Helper: Fetch all available programs (Used for the Catalog grouping) ---
$available_programs = ['ALL']; 

try {
    // --- Fetch Programs from DB ---
    $stmt_programs = $conn->prepare("SELECT DISTINCT program_offered FROM subject ORDER BY program_offered");
    $stmt_programs->execute();
    $db_programs = $stmt_programs->fetchAll(PDO::FETCH_COLUMN);
    $available_programs = array_merge($available_programs, $db_programs);
    $available_programs = array_unique(array_filter($available_programs)); 
    if (($key = array_search('ALL', $available_programs)) !== false) {
        unset($available_programs[$key]);
        array_unshift($available_programs, 'ALL');
    }
    $known_programs = ['BSIS', 'BSCPE'];
    foreach ($known_programs as $p) {
        if (!in_array($p, $available_programs)) {
            $available_programs[] = $p;
        }
    }
} catch (PDOException $e) {
    $available_programs = ['ALL', 'BSIS', 'BSCPE']; 
    $db_error = "Error fetching program list: " . $e->getMessage();
}


// =================================================================================
// --- CRITICAL: POST HANDLER REMOVED / BLOCKED ---
// Blocks all attempts at CRUD operations.
// =================================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Redirect all POST attempts to view page with an error.
    $_SESSION['temp_message'] = ['text' => 'Action Blocked: This page is in Read-Only mode for the Registrar.', 'type' => 'error'];
    header("Location: registrar_subjects.php"); 
    exit();
}


// =================================================================================
// 3. DATA FETCHING for Catalog View
// =================================================================================

// 3.1. Main Catalog Data Query (Fetches Program > Subject > Class > Teacher)
try {
    $params = [];
    
    // The query retrieves all necessary data for the catalog view
    $stmt_catalog = $conn->prepare("
        SELECT
            s.subject_id, s.subject_code, s.subject_name, s.program_offered, s.year_level, s.semester, s.has_lab,
            c.class_id, c.academic_year, c.semester as class_semester, c.section,
            p.sid as professor_sid, up_prof.first_name as prof_first_name, up_prof.last_name as prof_last_name
        FROM subject s
        LEFT JOIN classes c ON s.subject_id = c.subject_id
        LEFT JOIN users p ON c.professor_sid = p.sid AND p.role = 'professor'
        LEFT JOIN user_profiles up_prof ON p.sid = up_prof.sid
        ORDER BY s.program_offered, s.year_level, s.semester, s.subject_code, c.academic_year, c.section
    ");
    $stmt_catalog->execute($params);
    $raw_data = $stmt_catalog->fetchAll(PDO::FETCH_ASSOC);

    // Organize raw data into a hierarchical structure: Program > Subject > Class
    $catalog_data = [];
    foreach ($raw_data as $row) {
        $program_key = $row['program_offered'];
        $subject_key = $row['subject_id'];
        $class_key = $row['class_id'];

        // Program Level
        if (!isset($catalog_data[$program_key])) {
            $catalog_data[$program_key] = [
                'name' => $program_key,
                'subjects' => []
            ];
        }

        // Subject Level
        if (!isset($catalog_data[$program_key]['subjects'][$subject_key])) {
            $catalog_data[$program_key]['subjects'][$subject_key] = [
                'code' => $row['subject_code'],
                'name' => $row['subject_name'],
                'year' => $row['year_level'],
                'sem' => $row['semester'],
                'has_lab' => $row['has_lab'],
                'classes' => []
            ];
        }

        // Class Level (Only if a class exists for the subject)
        if ($row['class_id'] !== null) {
            $catalog_data[$program_key]['subjects'][$subject_key]['classes'][$class_key] = [
                'id' => $row['class_id'],
                'acad_year' => $row['academic_year'],
                'semester' => $row['class_semester'],
                'section' => $row['section'],
                'professor' => [
                    'sid' => $row['professor_sid'],
                    'name' => ($row['prof_last_name'] && $row['prof_first_name']) 
                        ? htmlspecialchars($row['prof_last_name'] . ', ' . $row['prof_first_name'])
                        : 'N/A'
                ],
                'students' => [] 
            ];
        }
    }

} catch (PDOException $e) {
    $db_error = "Database error fetching catalog data: " . $e->getMessage();
}

// 3.2. Fetch Enrolled Students for the Selected Class (if any)
$enrolled_students = [];
$class_info = null; 
if ($class_details_id) {
    try {
        $stmt_students = $conn->prepare("
            SELECT 
                u.sid, 
                up.student_id, 
                up.last_name, 
                up.first_name, 
                up.middle_name,
                up.section,
                up.academic_year
            FROM enrollment e
            JOIN users u ON e.student_sid = u.sid
            JOIN user_profiles up ON u.sid = up.sid
            WHERE e.class_id = ?
            ORDER BY up.last_name, up.first_name
        ");
        $stmt_students->execute([$class_details_id]);
        $enrolled_students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

        // Find the class details to display the breadcrumb/title
        foreach ($catalog_data as $program) {
            foreach ($program['subjects'] as $subject) {
                if (isset($subject['classes'][$class_details_id])) {
                    $class_info = $subject['classes'][$class_details_id];
                    $class_info['subject_code'] = $subject['code'];
                    $class_info['subject_name'] = $subject['name'];
                    $class_info['program'] = $program['name'];
                    break 2;
                }
            }
        }

        if (!$class_info) {
             $message = "Error: Class ID {$class_details_id} not found in the catalog.";
             $message_type = 'error';
             $class_details_id = null;
        }

    } catch (PDOException $e) {
        $db_error = "Database error fetching enrolled students: " . $e->getMessage();
        $class_details_id = null;
    }
}


// 2. INCLUDE THE HEADER AND RENDER THE PAGE
$current_view = 'subjects_catalog'; 
$view = $current_view; 
$page_title = 'Academic Catalog (Read-Only)';
// NOTE: Assuming 'registrar_header.php' exists
include_once 'registrar_header.php'; 

// Load temporary status message from session
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}

// *** START HTML OUTPUT ***
?>

<?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if ($class_details_id && $class_info): ?>
<section class="content-section" id="class-roster-section">
    <button type="button" class="collapsible-header active" data-default-open="true">
        <h2>
            <i class="fa-solid fa-users"></i> Class Roster: <?= htmlspecialchars($class_info['subject_code']); ?> (<?= htmlspecialchars($class_info['section']); ?>)
        </h2>
        <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>
    
    <div class="collapsible-content">
        <p class="description">
            Viewing student roster for <strong><?= htmlspecialchars($class_info['subject_name']); ?></strong>, Section <?= htmlspecialchars($class_info['section']); ?> (<?= htmlspecialchars($class_info['acad_year']); ?> / <?= htmlspecialchars($class_info['semester']); ?>).
            <a href="registrar_subjects.php" class="reject-btn" style="float: right;">
                <i class="fa-solid fa-arrow-left"></i> Back to Catalog
            </a>
        </p>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div class="info-card">
                <label>Program / Subject:</label>
                <p><strong><?= htmlspecialchars($class_info['program']); ?></strong> / <?= htmlspecialchars($class_info['subject_name']); ?></p>
            </div>
            <div class="info-card">
                <label>Professor:</label>
                <p><?= htmlspecialchars($class_info['professor']['name']); ?></p>
            </div>
        </div>

        <?php if (!empty($enrolled_students)): ?>
            <div class="table-container">
                <table class="review-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Program Section</th>
                            <th>Acad Year</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrolled_students as $student): ?>
                        <tr>
                            <td><?= htmlspecialchars($student['student_id']); ?></td>
                            <td><strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . substr($student['middle_name'], 0, 1) . '.'); ?></strong></td>
                            <td><?= htmlspecialchars($student['section']); ?></td>
                            <td><?= htmlspecialchars($student['academic_year']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" style="text-align: right;">Total Enrolled Students:</th>
                            <th><?= count($enrolled_students); ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php else: ?>
            <p class="no-data">No students are currently enrolled in this section.</p>
        <?php endif; ?>
    </div>
</section>

<?php else: ?>
<section class="content-section">
    <button type="button" class="collapsible-header active" data-default-open="true">
        <h2><i class="fa-solid fa-book-open"></i> Full Academic Catalog</h2>
        <i class="fa-solid fa-chevron-down collapse-icon"></i>
    </button>
    
    <div class="collapsible-content">
        <p class="description">
            Click <strong>View Roster</strong> next to a class to see the list of enrolled students.
        </p>
        
        <?php if (!empty($catalog_data)): ?>
            <div id="catalog-directory">
                <?php foreach ($catalog_data as $program_key => $program): ?>
                    <div class="program-entry">
                        <div class="group-header" onclick="toggleDirectory('program-<?= htmlspecialchars($program_key); ?>')">
                            <span style="font-size: 1.1em; font-weight: bold;"><i class="fa-solid fa-chalkboard"></i> <?= htmlspecialchars($program['name']); ?></span>
                            <i id="program-<?= htmlspecialchars($program_key); ?>-icon" class="fa-solid fa-chevron-right" style="float: right;"></i>
                        </div>
                        <div id="program-<?= htmlspecialchars($program_key); ?>" class="group-content" style="display: none;">
                            
                            <?php if (!empty($program['subjects'])): ?>
                                <ul class="subject-list">
                                    <?php foreach ($program['subjects'] as $subject_id => $subject): ?>
                                        <li class="subject-item" onclick="toggleDirectory('subject-<?= $subject_id; ?>')">
                                            <div class="subject-header">
                                                <span class="subject-code-name">
                                                    <i class="fa-solid fa-book-bookmark"></i>
                                                    <strong>[<?= htmlspecialchars($subject['year']); ?>-<?= htmlspecialchars($subject['sem']); ?>] <?= htmlspecialchars($subject['code']); ?>:</strong> <?= htmlspecialchars($subject['name']); ?>
                                                </span>
                                                <span class="subject-info">
                                                    <?= count($subject['classes']); ?> Classes
                                                    <i id="subject-<?= $subject_id; ?>-icon" class="fa-solid fa-chevron-right" style="margin-left: 10px;"></i>
                                                </span>
                                            </div>
                                            
                                            <ul id="subject-<?= $subject_id; ?>" class="class-list group-content" style="display: none;">
                                                <?php if (!empty($subject['classes'])): ?>
                                                    <?php foreach ($subject['classes'] as $class_id => $class): ?>
                                                        <li class="class-item">
                                                            <span class="class-details">
                                                                <i class="fa-solid fa-calendar-alt"></i> 
                                                                <strong><?= htmlspecialchars($class['acad_year']); ?></strong> - Sec: <strong><?= htmlspecialchars($class['section']); ?></strong> | 
                                                                Prof: <span class="badge-new-data"><?= htmlspecialchars($class['professor']['name']); ?></span>
                                                            </span>
                                                            <a href="registrar_subjects.php?class_id=<?= $class_id; ?>" class="action-btn-view" title="View Student Roster">
                                                                <i class="fa-solid fa-eye"></i> View Roster
                                                            </a>
                                                        </li>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <li class="no-data">No classes currently offered for this subject.</li>
                                                <?php endif; ?>
                                            </ul>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="no-data">No subjects defined for this program.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="no-data">No academic catalog data available.</p>
        <?php endif; ?>
    </div>
</section>

<?php endif; ?>

<style>
/* --- Custom Read-Only Styles for this Catalog View --- */
.group-header {
    background-color: var(--panel-blue);
    color: white;
    padding: 12px 20px;
    margin-top: 10px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background-color 0.2s;
}
.group-header:hover {
    background-color: #0069d9; 
}
.group-content {
    border: 1px solid var(--border-color);
    border-top: none;
    border-radius: 0 0 8px 8px;
    padding: 15px;
    background-color: var(--bg-light);
    margin-bottom: 10px;
}
.subject-list {
    list-style: none;
    padding: 0;
}
.subject-item {
    border: 1px solid #ddd;
    border-radius: 6px;
    margin-bottom: 8px;
}
.subject-header {
    padding: 10px 15px;
    background-color: #f9f9f9;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 6px;
}
.subject-code-name {
    flex-grow: 1;
    font-size: 0.95em;
}
.subject-info {
    font-size: 0.85em;
    color: var(--text-light);
}
.class-list {
    list-style: none;
    padding: 10px 0 0 0;
    margin: 0;
    border-top: 1px dashed #eee;
}
.class-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 15px;
    border-bottom: 1px solid #eee;
    font-size: 0.9em;
}
.class-item:last-child {
    border-bottom: none;
}
.class-details {
    flex-grow: 1;
}
.action-btn-view {
    background-color: var(--panel-blue);
    color: white;
    padding: 4px 10px;
    border-radius: 4px;
    text-decoration: none;
    font-size: 0.8rem;
    white-space: nowrap;
}
.action-btn-view:hover {
    background-color: #0069d9;
}
.badge-new-data {
    display: inline-block;
    padding: 3px 8px;
    background-color: #e6f7ff; 
    color: var(--panel-blue, #007bff);
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.info-card {
    background: #fff;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 6px;
}
.info-card label {
    font-size: 0.85em;
    font-weight: 600;
    color: var(--text-light);
    display: block;
    margin-bottom: 5px;
}
.info-card p {
    margin: 0;
    font-size: 1em;
}
/* Style for Back button on Roster view */
.reject-btn {
    background-color: #6c757d;
    color: white;
    padding: 8px 15px;
    border: none;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.9rem;
}
/* --- General Styles (Kept from original) --- */
.collapsible-header {
    background: none;
    border: none;
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0;
    cursor: pointer;
    text-align: left;
    margin-bottom: 1.5rem; 
}
.collapsible-header h2 {
    margin: 0;
    font-size: 1.4rem; 
    color: var(--text-dark);
}
.collapsible-header .collapse-icon {
    font-size: 1.2rem;
    color: var(--panel-blue, #007bff);
    transition: transform 0.3s ease;
    margin-left: 15px; 
}
.collapsible-content.collapsed {
    display: none;
}
.collapsible-header:not(.active) .collapse-icon {
    transform: rotate(-90deg);
}
.collapsible-header.active .collapse-icon {
    transform: rotate(0deg);
}
</style>

<script>
    const phpMessage = <?php echo json_encode($message); ?>;
    const phpMessageType = <?php echo json_encode($message_type); ?>;
    
    // Function to toggle directory sections
    function toggleDirectory(contentId) {
        const content = document.getElementById(contentId);
        const iconId = contentId + '-icon';
        const icon = document.getElementById(iconId);
        
        if (!content) return; 

        const isHidden = (content.style.display === 'none' || content.style.display === '');
        
        // Toggle visibility
        content.style.display = isHidden ? 'block' : 'none';
        
        // Toggle icon
        if (icon) {
            icon.classList.toggle('fa-chevron-right', !isHidden);
            icon.classList.toggle('fa-chevron-down', isHidden);
        }
    }
    window.toggleDirectory = toggleDirectory;


    document.addEventListener('DOMContentLoaded', () => {

        if (phpMessage) {
            // Simplified modal display using alert for read-only status messages
            alert(phpMessage + ' (' + phpMessageType.toUpperCase() + ')');
        }
        
        // --- Collapsible Section Handler ---
        document.querySelectorAll('.collapsible-header').forEach(header => {
            if (!header.hasAttribute('disabled')) {
                const content = header.nextElementSibling;
                if (!content) return;

                const defaultOpen = header.dataset.defaultOpen === 'true';

                if (defaultOpen || header.classList.contains('active')) {
                    header.classList.add('active');
                    content.classList.remove('collapsed');
                } else {
                    header.classList.remove('active');
                    content.classList.add('collapsed');
                }

                header.addEventListener('click', () => {
                    content.classList.toggle('collapsed');
                    header.classList.toggle('active');
                });
            }
        });

    });
</script>

<?php 
// Include the new registrar footer
include 'registrar_footer.php'; 
// Flush output buffer and turn off output buffering
ob_end_flush();
?>