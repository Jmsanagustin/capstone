<?php
// FILE: class_details.php
// PURPOSE: Program Head's tool to manually add/remove students from a class.

session_start();
include 'db.php';

// -----------------------------------------------------------------
// 1. HANDLE AJAX SEARCH REQUEST
// This block must be BEFORE any HTML output.
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json');

    // Authorization
    if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    // Get Inputs
    $query = trim($_GET['q'] ?? '');
    $class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);

    if (empty($query) || empty($class_id)) {
        echo json_encode([]); // Return empty on no query
        exit();
    }

    // Search Database
    try {
        // Find students matching the query who are NOT already in this class
        $stmt = $conn->prepare("
            SELECT 
                up.sid, 
                up.student_id, 
                up.first_name, 
                up.last_name
            FROM user_profiles up
            JOIN users u ON up.sid = u.sid
            WHERE 
                u.role = 'Student'
                AND (up.student_id LIKE ? OR CONCAT(up.first_name, ' ', up.last_name) LIKE ?)
                AND up.sid NOT IN (
                    SELECT student_sid FROM enrollment WHERE class_id = ?
                )
            LIMIT 10
        ");
        
        $search_term = "%" . $query . "%";
        $stmt->execute([$search_term, $search_term, $class_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($students);

    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit(); // IMPORTANT: Stop script execution for AJAX requests
}


// -----------------------------------------------------------------
// 2. REGULAR PAGE LOAD & POST HANDLING
// -----------------------------------------------------------------

// 2A. AUTHORIZATION & HEADER
$current_view = 'courses';
$page_title = 'Class Details';

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
    header("Location: index.php");
    exit();
}

$program_head_sid = $_SESSION['sid'] ?? 0;
$class_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$message = '';
$message_type = '';

if (empty($class_id)) {
    $_SESSION['temp_message'] = ['type' => 'error', 'text' => 'Invalid Class ID.'];
    header("Location: ph_courses.php");
    exit();
}

// 2B. HANDLE POST REQUESTS (ADD/REMOVE)
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // --- (MODIFIED) ACTION 1: ADD MULTIPLE STUDENTS ---
        if ($action === 'add_multiple_students') {
            // student_sids will be a JSON array like "[101, 102, 103]"
            $student_sids_json = $_POST['student_sids'] ?? '[]';
            $student_sids = json_decode($student_sids_json, true);

            if (empty($student_sids) || !is_array($student_sids)) {
                $message = 'No students were selected to add.';
                $message_type = 'error';
            } else {
                $conn->beginTransaction();
                try {
                    $added_count = 0;
                    $stmt_add = $conn->prepare("INSERT INTO enrollment (student_sid, class_id, date_enrolled) VALUES (?, ?, NOW())");
                    $stmt_check = $conn->prepare("SELECT 1 FROM enrollment WHERE student_sid = ? AND class_id = ?");

                    foreach ($student_sids as $student_sid) {
                        // Sanitize just in case
                        $student_sid = (int)$student_sid; 
                        
                        if ($student_sid > 0) {
                            // Check if student is already enrolled (double-check)
                            $stmt_check->execute([$student_sid, $class_id]);
                            if (!$stmt_check->fetch()) {
                                // 3. Enroll the student
                                $stmt_add->execute([$student_sid, $class_id]);
                                $added_count++;
                            }
                        }
                    }
                    $conn->commit();
                    $message = "Success: $added_count student(s) have been manually enrolled.";
                    $message_type = 'success';

                } catch (Exception $e) {
                    $conn->rollBack();
                    $message = "Error enrolling students: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }

        // --- ACTION 2: REMOVE STUDENT ---
        elseif ($action === 'remove_student') {
            $enrollment_id = filter_input(INPUT_POST, 'enrollment_id', FILTER_VALIDATE_INT);

            if (empty($enrollment_id)) {
                $message = 'Invalid enrollment ID.';
                $message_type = 'error';
            } else {
                $conn->beginTransaction();
                try {
                    // 1. Get info needed to delete grades
                    $stmt_info = $conn->prepare("
                        SELECT up.student_id, c.subject_id 
                        FROM enrollment e 
                        JOIN user_profiles up ON e.student_sid = up.sid 
                        JOIN classes c ON e.class_id = c.class_id 
                        WHERE e.enrollment_id = ?
                    ");
                    $stmt_info->execute([$enrollment_id]);
                    $info = $stmt_info->fetch(PDO::FETCH_ASSOC);

                    if ($info) {
                        // 2. Delete from 'grades' (final submitted grades)
                        $stmt_del_grades = $conn->prepare("
                            DELETE FROM grades 
                            WHERE student_id_string = ? AND subject_id = ? AND class_id = ?
                        ");
                        $stmt_del_grades->execute([$info['student_id'], $info['subject_id'], $class_id]);

                        // 3. Delete from 'raw_scores' (all individual items)
                        $stmt_del_raw = $conn->prepare("DELETE FROM raw_scores WHERE enrollment_id = ?");
                        $stmt_del_raw->execute([$enrollment_id]);
                        
                        // 4. Delete from 'enrollment' (the main record)
                        $stmt_del_en = $conn->prepare("DELETE FROM enrollment WHERE enrollment_id = ?");
                        $stmt_del_en->execute([$enrollment_id]);

                        $conn->commit();
                        $message = "Success: Student (" . htmlspecialchars($info['student_id']) . ") and all their grades for this class have been removed.";
                        $message_type = 'success';
                    } else {
                        throw new Exception("Could not find enrollment info.");
                    }
                } catch (Exception $e) {
                    $conn->rollBack();
                    $message = "Error during removal: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
    }
} catch (PDOException $e) {
    $message = "Database Error: " . $e->getMessage();
    $message_type = 'error';
}


// -----------------------------------------------------------------
// 3. FETCH DATA FOR PAGE DISPLAY
// -----------------------------------------------------------------
try {
    // --- Security check: Verify PH owns this class ---
    $stmt_check = $conn->prepare("
        SELECT s.subject_name, s.subject_code, c.section, c.academic_year, c.semester, 
               CONCAT(up.first_name, ' ', up.last_name) AS prof_name
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        JOIN users u ON c.professor_sid = u.sid
        JOIN user_profiles up ON u.sid = up.sid
        WHERE c.class_id = :cid AND s.program_head_id = :ph_id
    ");
    $stmt_check->execute([':cid' => $class_id, ':ph_id' => $program_head_sid]);
    $class_info = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$class_info) {
        $_SESSION['temp_message'] = ['type' => 'error', 'text' => 'Access Denied: You do not manage this class.'];
        header("Location: ph_courses.php");
        exit();
    }
    
    $page_title = htmlspecialchars($class_info['subject_code'] . ' - ' . $class_info['section']);

    // --- Fetch Enrolled Students ---
    $stmt_students = $conn->prepare("
        SELECT e.enrollment_id, up.student_id, up.first_name, up.last_name, u.email
        FROM enrollment e
        JOIN users u ON e.student_sid = u.sid
        JOIN user_profiles up ON u.sid = up.sid
        WHERE e.class_id = ?
        ORDER BY up.last_name, up.first_name
    ");
    $stmt_students->execute([$class_id]);
    $enrolled_students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = "Database Error: " . $e->getMessage();
    $message_type = 'error';
    $class_info = null; // Prevent page from loading
}

// -----------------------------------------------------------------
// 4. INCLUDE HEADER (now that POST logic is done)
// -----------------------------------------------------------------
include_once 'ph_header.php'; 

?>

<?php if ($message): ?>
    <div class="message <?php echo htmlspecialchars($message_type); ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if ($class_info): // Only show page if class was loaded ?>
<section class="content-section mb-8">
    
    <a href="ph_courses.php" class="back-link btn-back">&larr; Back to All Classes</a>
    
    <h2>
        <i class="fa-solid fa-users"></i> 
        Manage Roster: <?= htmlspecialchars($class_info['subject_code'] . ' - ' . $class_info['subject_name']) ?>
    </h2>
    <div class="class-details-grid">
        <span><strong>Section:</strong> <?= htmlspecialchars($class_info['section']) ?></span>
        <span><strong>Term:</strong> <?= htmlspecialchars($class_info['academic_year'] . ' / ' . $class_info['semester']) ?></span>
        <span><strong>Professor:</strong> <?= htmlspecialchars($class_info['prof_name']) ?></span>
    </div>
</section>

<section class="content-section mb-8">
    <h3><i class="fa-solid fa-user-plus"></i> Add Students to Roster</h3>
    <p>Search by Student ID or Name to add them to the list.</p>

    <form action="class_details.php?id=<?= $class_id ?>" method="POST" id="add-students-form">
        <input type="hidden" name="action" value="add_multiple_students">
        <input type="hidden" name="student_sids" id="hidden-student-sids">

        <div class="multi-select-container">
            <ul class="selected-students-list" id="selected-students-list">
            </ul>
            <input type="text" id="student-search-input" 
                   placeholder="Type to search for a student..."
                   autocomplete="off">
        </div>
        
        <ul class="search-results-list" id="search-results-list">
        </ul>
        
        <button type="submit" class="approve-btn" id="add-selected-students-btn" disabled>
             <i class="fa-solid fa-plus"></i> Add Selected Students
        </button>
    </form>
</section>


<section class="content-section">
    <h3><i class="fa-solid fa-list-ul"></i> Enrolled Students (<?= count($enrolled_students) ?>)</h3>
    <div class="table-container">
        <?php if (empty($enrolled_students)): ?>
            <p class="no-data">No students are currently enrolled in this class.</p>
        <?php else: ?>
        <table class="review-table">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($enrolled_students as $student): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($student['student_id']) ?></strong></td>
                    <td><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></td>
                    <td><?= htmlspecialchars($student['email']) ?></td>
                    <td class="action-buttons">
                        <form action="class_details.php?id=<?= $class_id ?>" method="POST" onsubmit="return confirm('Are you sure you want to remove this student? This will delete ALL their scores and grades for THIS CLASS permanently.');">
                            <input type="hidden" name="action" value="remove_student">
                            <input type="hidden" name="enrollment_id" value="<?= $student['enrollment_id'] ?>">
                            <button type="submit" class="reject-btn">
                                <i class="fa-solid fa-user-minus"></i> Remove
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</section>

<?php else: ?>
    <div class="message error">
        Critical error: Class data could not be loaded.
    </div>
<?php endif; ?>

<style>
    /* --- Back Button Style --- */
    .btn-back {
        display: inline-block; /* Behave like a button */
        margin-bottom: 15px;
        padding: 8px 15px; /* Give it button padding */
        background-color: #f0f0f0; /* Light gray background */
        color: #333; /* Dark text */
        text-decoration: none;
        font-weight: 500;
        border: 1px solid #ccc; /* Add a border */
        border-radius: 6px; /* Rounded corners */
        transition: background-color 0.2s;
    }
    .btn-back:hover {
        background-color: #e0e0e0; /* Darken on hover */
        color: #000;
    }

    /* --- Other Page Styles --- */
    .class-details-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 10px 20px;
        background: var(--bg-light);
        padding: 15px;
        border-radius: 8px;
        border: 1px solid #e0e6ed;
    }
    .class-details-grid span {
        font-size: 0.9rem;
    }

    /* --- Styles for new Multi-Select --- */
    #add-students-form {
        background: var(--bg-light);
        padding: 15px;
        border-radius: 8px;
    }
    .multi-select-container {
        border: 1px solid #ccc;
        border-radius: 6px;
        padding: 8px;
        display: flex;
        flex-wrap: wrap; /* Allows tags to wrap to new lines */
        align-items: center;
        background: #fff;
        margin-top: 15px;
        margin-bottom: 10px;
    }
    .selected-students-list {
        display: flex;
        flex-wrap: wrap;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .student-tag {
        background-color: #e0efff;
        color: #0056b3;
        border-radius: 16px;
        padding: 5px 10px 5px 12px;
        margin: 4px;
        display: flex;
        align-items: center;
        font-size: 0.9em;
        font-weight: 500;
    }
    .student-tag .remove-tag {
        background: none;
        border: none;
        color: #0056b3;
        cursor: pointer;
        font-size: 1.2em;
        margin-left: 6px;
        padding: 0 5px;
    }
    .multi-select-container input {
        border: none !important; 
        outline: none;
        flex-grow: 1; /* Takes up remaining space */
        padding: 8px !important;
        min-width: 200px; /* Ensures it's always usable */
        width: auto; /* Override defaults */
        border-radius: 0 !important;
    }
    .search-results-list {
        list-style: none;
        padding: 0;
        margin: -10px 0 10px 0; /* Aligns with box */
        border: 1px solid #ddd;
        border-radius: 0 0 6px 6px;
        background: #fff;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        z-index: 100;
        display: none; /* Hidden by default */
        max-height: 250px;
        overflow-y: auto;
    }
    .search-results-list li {
        padding: 12px 15px;
        cursor: pointer;
        border-bottom: 1px solid #f0f0f0;
    }
    .search-results-list li:last-child { border-bottom: none; }
    .search-results-list li:hover { background-color: #f4f7f6; }

    #add-selected-students-btn {
        padding-top: 10px;
        padding-bottom: 10px;
    }
    #add-selected-students-btn:disabled {
        background-color: #ccc;
        cursor: not-allowed;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {

    // --- 1. Get Elements ---
    const classId = <?= (int)$class_id ?>; // Get Class ID from PHP
    const searchInput = document.getElementById('student-search-input');
    const resultsList = document.getElementById('search-results-list');
    const selectedList = document.getElementById('selected-students-list');
    const addForm = document.getElementById('add-students-form');
    const hiddenInput = document.getElementById('hidden-student-sids');
    const addBtn = document.getElementById('add-selected-students-btn');

    // This object stores the students we've selected
    // We use an object for fast lookups: { "sid": "Name (ID)" }
    let selectedStudents = {};

    // --- 2. Handle Searching (Typing) ---
    let searchTimer;
    searchInput.addEventListener('keyup', (e) => {
        const query = searchInput.value.trim();
        
        clearTimeout(searchTimer);

        if (query.length < 2) {
            resultsList.style.display = 'none';
            return;
        }

        searchTimer = setTimeout(() => {
            const fetchUrl = `class_details.php?action=search&q=${encodeURIComponent(query)}&class_id=${classId}`;
            
            fetch(fetchUrl)
                .then(response => response.json())
                .then(data => {
                    resultsList.innerHTML = ''; 
                    if (data.error) {
                        console.error("AJAX Error:", data.error);
                        resultsList.style.display = 'none';
                        return;
                    }
                    
                    if (data.length > 0) {
                        data.forEach(student => {
                            if (!selectedStudents[student.sid]) {
                                const li = document.createElement('li');
                                li.innerHTML = `<strong>${student.student_id}</strong> - ${student.last_name}, ${student.first_name}`;
                                li.dataset.sid = student.sid;
                                li.dataset.name = `${student.last_name}, ${student.first_name} (${student.student_id})`;
                                resultsList.appendChild(li);
                            }
                        });
                    } else {
                        const li = document.createElement('li');
                        li.textContent = 'No matching students found.';
                        resultsList.appendChild(li);
                    }
                    resultsList.style.display = 'block';
                })
                .catch(err => console.error("Search Fetch Error:", err));
        }, 300);
    });

    // --- 3. Handle Selecting a Student (Clicking on floating list) ---
    resultsList.addEventListener('click', (e) => {
        const li = e.target.closest('li');
        if (li && li.dataset.sid) {
            selectedStudents[li.dataset.sid] = li.dataset.name;
            renderTags();
            
            searchInput.value = '';
            resultsList.style.display = 'none';
            searchInput.focus();
        }
    });

    // --- 4. Function to build the "tags" ---
    function renderTags() {
        selectedList.innerHTML = '';
        let hasStudents = false;
        
        for (const sid in selectedStudents) {
            hasStudents = true;
            const name = selectedStudents[sid];
            
            const tag = document.createElement('li');
            tag.className = 'student-tag';
            
            const nameSpan = document.createElement('span');
            nameSpan.textContent = name;
            
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-tag';
            removeBtn.innerHTML = '&times;';
            removeBtn.dataset.sid = sid;
            
            tag.appendChild(nameSpan);
            tag.appendChild(removeBtn);
            selectedList.appendChild(tag);
        }
        
        addBtn.disabled = !hasStudents;
    }

    // --- 5. Handle Removing a "Tag" ---
    // **FIXED** Removed stray HTML line that was breaking the script
    selectedList.addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-tag')) {
            const sidToRemove = e.target.dataset.sid;
            delete selectedStudents[sidToRemove];
            renderTags();
        }
    });
    
    // --- 6. Handle Form Submission ---
    addForm.addEventListener('submit', (e) => {
        const sids = Object.keys(selectedStudents);
        
        if (sids.length === 0) {
            e.preventDefault();
            alert("Please select at least one student to add.");
            return;
        }
        
        hiddenInput.value = JSON.stringify(sids);
    });
    
    // Hide results list if clicking elsewhere
    document.addEventListener('click', (e) => {
        if (!addForm.contains(e.target)) {
            resultsList.style.display = 'none';
        }
    });

});
</script>

<?php include 'ph_footer.php'; ?>