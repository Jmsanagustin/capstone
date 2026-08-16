<?php
// FILE: manage_scores.php
// This page manages all raw scores for a single component (e.g., all quizzes)

// Add these lines temporarily to see the real error message
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php'; 

// =========================================================================
// 1. GLOBAL SETUP & ACCESS CONTROL
// =========================================================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    header("Location: index.php");
    exit();
}

$current_user_id = $_SESSION['sid'];
$teacher_name = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Professor';

// Get the component_id from the URL (this is the main key for this page)
$component_id = filter_input(INPUT_GET, 'component_id', FILTER_VALIDATE_INT);
if (!$component_id) {
    die("Error: No component ID specified.");
}

// --- Data containers ---
$students = [];
$grade_items = []; // The columns (e.g., "Quiz 1", "Quiz 2")
$scores_grid = []; // The 2D array of [enrollment_id][item_name]
$class_id = null;
$component_name = "Component";
$error_message = "";
$success_message = "";

try {
    // =========================================================================
    // 2. AUTHORIZATION & CORE DATA FETCH
    // =========================================================================
    
    // Security Check: Verify this professor owns this component
    $stmt_auth = $conn->prepare("
        SELECT c.class_id, c.professor_sid, gc.component_name 
        FROM grade_components gc 
        JOIN classes c ON gc.class_id = c.class_id 
        WHERE gc.component_id = :component_id
    ");
    $stmt_auth->execute([':component_id' => $component_id]);
    $auth_data = $stmt_auth->fetch(PDO::FETCH_ASSOC);

    if (!$auth_data || $auth_data['professor_sid'] != $current_user_id) {
        die("Access Denied: You are not the professor for this class component.");
    }
    
    // Store component details
    $class_id = $auth_data['class_id'];
    $component_name = htmlspecialchars($auth_data['component_name']);

    // =========================================================================
    // 3. HANDLE POST REQUESTS (Save Scores / Add Item)
    // =========================================================================
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // --- ACTION 1: SAVE SCORES ---
        if (isset($_POST['save_scores'])) {
            $scores = $_POST['scores'] ?? [];
            
            $conn->beginTransaction();
            $stmt_update = $conn->prepare("
                UPDATE raw_scores 
                SET score = :score 
                WHERE score_id = :score_id 
                AND component_id = :component_id
            ");
            
            foreach ($scores as $score_id => $score_value) {
                $stmt_update->execute([
                    ':score' => $score_value,
                    ':score_id' => $score_id,
                    ':component_id' => $component_id // Security check
                ]);
            }
            $conn->commit();
            $success_message = "✅ Scores saved successfully!";
        }

        // --- ACTION 2: ADD NEW ITEM (e.g., "Quiz 3") ---
        if (isset($_POST['add_item'])) {
            $item_name = trim($_POST['item_name'] ?? '');
            $max_score = filter_input(INPUT_POST, 'max_score', FILTER_VALIDATE_FLOAT);

            if (!empty($item_name) && $max_score > 0) {
                // Find all students enrolled in this class
                $stmt_students = $conn->prepare("SELECT enrollment_id FROM enrollment WHERE class_id = :class_id");
                $stmt_students->execute([':class_id' => $class_id]);
                $enrolled_students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

                $conn->beginTransaction();
                $stmt_insert = $conn->prepare("
                    INSERT INTO raw_scores (enrollment_id, component_id, item_name, score, max_score) 
                    VALUES (:enrollment_id, :component_id, :item_name, 0, :max_score)
                ");

                foreach ($enrolled_students as $student) {
                    $stmt_insert->execute([
                        ':enrollment_id' => $student['enrollment_id'],
                        ':component_id' => $component_id,
                        ':item_name' => $item_name,
                        ':max_score' => $max_score
                    ]);
                }
                $conn->commit();
                $success_message = "✅ New item '$item_name' added successfully for all students.";

            } else {
                $error_message = "❌ Please provide a valid item name and max score > 0.";
            }
        }
    }

    // =========================================================================
    // 4. FETCH DATA FOR PAGE DISPLAY
    // =========================================================================

    // 1. Get all students in this class
    $stmt_students = $conn->prepare("
        SELECT u.sid, p.first_name, p.last_name, e.enrollment_id 
        FROM enrollment e 
        JOIN users u ON e.student_sid = u.sid 
        JOIN user_profiles p ON u.sid = p.sid 
        WHERE e.class_id = :class_id 
        ORDER BY p.last_name, p.first_name
    ");
    $stmt_students->execute([':class_id' => $class_id]);
    $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

    // 2. Get all unique grade items (columns) for this component
    $stmt_items = $conn->prepare("
        SELECT DISTINCT item_name, max_score, MIN(score_id) as first_id 
        FROM raw_scores 
        WHERE component_id = :component_id 
        GROUP BY item_name, max_score
        ORDER BY first_id
    ");
    $stmt_items->execute([':component_id' => $component_id]);
    $grade_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // 3. Get all scores and put them in a fast 2D lookup array
    $stmt_scores = $conn->prepare("
        SELECT score_id, enrollment_id, item_name, score 
        FROM raw_scores 
        WHERE component_id = :component_id
    ");
    $stmt_scores->execute([':component_id' => $component_id]);
    $all_scores_raw = $stmt_scores->fetchAll(PDO::FETCH_ASSOC);

    // Format: $scores_grid[enrollment_id]["Quiz 1"] = ['score_id' => 123, 'value' => 95]
    foreach ($all_scores_raw as $score) {
        $scores_grid[$score['enrollment_id']][$score['item_name']] = [
            'score_id' => $score['score_id'], 
            'value' => $score['score']
        ];
    }

} catch (PDOException $e) {
    $db_error = "Database Error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Scores: <?= $component_name ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f2f5f8;--white:#fff; --text-dark:#102a43; --text-light:#627d98;--accent-light:#4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);--shadow-md: 0 6px 15px rgba(0,0,0,0.08); --critical:#cc3333;--warning:#ff9900; --muted:#888; --success-green: #28a745;}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100vh; overflow: hidden; }
body{font-family: 'Inter', 'Segoe UI', sans-serif;margin:0;background:var(--bg-light);color:var(--text-dark); display: flex;}
.sidebar { width: 260px; background: var(--nav-dark); color: var(--white); display: flex; flex-direction: column; flex-shrink: 0; height: 100vh; transition: width 0.3s ease; }
.sidebar-header { padding: 24px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.sidebar-header i { font-size: 28px; color: var(--accent-light); }
.sidebar-header h2 { font-size: 1.15rem; font-weight: 600; line-height: 1.3; }
.sidebar-header h2 span { font-size: 0.8rem; font-weight: 400; color: #9ac8ee; display: block; }
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
.main-content { flex-grow: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
.header { display: flex; justify-content: space-between; align-items: center; padding: 16px 32px; background: var(--white); border-bottom: 1px solid #e0e6ed; flex-shrink: 0; }
.header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
.user-profile { display: flex; align-items: center; gap: 12px; }
.user-profile .icon-button { font-size: 1.2rem; color: var(--text-light); text-decoration: none; padding: 8px; border-radius: 50%; transition: background 0.2s ease; }
.user-profile .icon-button:hover { background: var(--bg-light); }
.user-profile-info { text-align: right; }
.user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
.user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
.page-view { flex-grow: 1; overflow-y: auto; padding: 32px; }
.content-section { background: var(--white); border-radius: 12px; padding: 24px; box-shadow: var(--shadow); margin-bottom: 32px; }
.content-section h2 { font-size: 1.25rem; font-weight: 700; color: var(--panel-blue); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.message-box{padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold;}
.error-message{background: #fde8e8; color: var(--critical); border: 1px solid var(--critical);}
.success-message{background: #e6f7e6; color: var(--success-green); border: 1px solid var(--success-green);}
input[type="text"], input[type="date"], input[type="number"], select, textarea {
    width: 100%;
    padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px;
}
.filter-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; align-items: end; margin-bottom: 20px;}
.filter-form .form-group { display: flex; flex-direction: column; }
.filter-form label{display:block; font-weight:bold; margin-bottom: 5px; font-size: 0.8rem; color: var(--text-light);}
.filter-form button, .action-button { background: var(--panel-blue); color: var(--white); font-weight: bold; border: none; cursor: pointer; transition: background 0.2s; padding: 10px; border-radius: 8px;}
.filter-form button:hover, .action-button:hover{background: var(--nav-dark);}
.table-container { width: 100%; overflow-x: auto; }
.data-table{width:100%;border-collapse:collapse;margin-top:15px; background:var(--white); border-radius: 8px; overflow: hidden; box-shadow: var(--shadow); }
.data-table th{background:var(--bg-light);color:var(--text-light);padding:12px 15px;text-align:left;font-weight:600;text-transform:uppercase;font-size:0.75rem;}
.data-table td{border-bottom:1px solid #e0e6ed;padding:8px 15px; vertical-align: middle; text-align: left;}
.data-table th.center, .data-table td.center { text-align: center; }
.data-table tbody tr:last-child td { border-bottom: none; }
.data-table input[type="number"] { width: 80px; text-align: center; }
.back-link { color: var(--panel-blue); text-decoration: none; font-weight: 600; margin-bottom: 20px; display: inline-block; }
.back-link:hover { text-decoration: underline; }
.custom-scrollbar::-webkit-scrollbar { width: 8px; }
.custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.footer{text-align:center;font-size:12px;color:var(--muted);margin-top:30px; padding: 10px 0;}
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <i class="fa-solid fa-chalkboard-teacher"></i>
        <h2>DFCAMCLP<span>Instructor Portal</span></h2>
    </div>
    <nav class="sidebar-nav custom-scrollbar">
        <ul>
            <li><a href="teacher_dashboard.php?view=dashboard" class="nav-link" data-view="dashboard"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="teacher_dashboard.php?view=grade_input" class="nav-link active" data-view="grade_input"><i class="fa-solid fa-file-upload"></i><span>Grade Input</span></a></li>
            <li><a href="teacher_dashboard.php?view=messages" class="nav-link" data-view="messages"><i class="fa-solid fa-comments"></i><span>Messages</span></a></li>
            <li><a href="teacher_dashboard.php?view=manage_students" class="nav-link" data-view="manage_students"><i class="fa-solid fa-users-cog"></i><span>Manage Students</span></a></li>
            <li><a href="teacher_dashboard.php?view=attendance" class="nav-link" data-view="attendance"><i class="fa-solid fa-clipboard-user"></i><span>Attendance</span></a></li>
            <li><a href="teacher_dashboard.php?view=profile" class="nav-link" data-view="profile"><i class="fa-solid fa-user"></i><span>Profile</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="main-content">
    <header class="header">
        <h1 class="header-title">Manage Scores</h1>
        <div class="user-profile">
            <div class="user-profile-info">
                <strong><?php echo htmlspecialchars($teacher_name); ?></strong>
                <span><?php echo htmlspecialchars($_SESSION['role']); ?></span>
            </div>
        </div>
    </header>

    <main class="page-view custom-scrollbar">

        <a href="teacher_dashboard.php?view=grade_input&class_id=<?= $class_id ?>" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Back to Component Setup
        </a>

        <?php if ($db_error): ?>
            <div class="message-box error-message">❌ <?php echo htmlspecialchars($db_error); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="message-box error-message">❌ <?php echo htmlspecialchars($error_message); ?></div>
        <?php elseif ($success_message): ?>
            <div class="message-box success-message">✅ <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <section class="content-section">
            <h2>Add New Item to "<?= $component_name ?>"</h2>
            <form method="POST" action="manage_scores.php?component_id=<?= $component_id ?>" class="filter-form" style="grid-template-columns: 2fr 1fr auto;">
                <div class="form-group">
                    <label for="item_name">New Item Name (e.g., Quiz 1, Lab 2):</label>
                    <input type="text" id="item_name" name="item_name" placeholder="e.g., Quiz 1" required>
                </div>
                <div class="form-group">
                    <label for="max_score">Max Score:</label>
                    <input type="number" id="max_score" name="max_score" placeholder="e.g., 100" min="1" required>
                </div>
                <button type="submit" name="add_item"><i class="fa-solid fa-plus"></i> Add Item Column</button>
            </form>
        </section>

        <section class="content-section">
            <h2>Gradebook for "<?= $component_name ?>"</h2>
            <form method="POST" action="manage_scores.php?component_id=<?= $component_id ?>">
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <?php foreach ($grade_items as $item): ?>
                                    <th class="center">
                                        <?= htmlspecialchars($item['item_name']) ?><br>
                                        <small>(Max: <?= htmlspecialchars($item['max_score']) ?>)</small>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="<?php echo count($grade_items) + 1; ?>" class="no-data">
                                        No students are enrolled in this class.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) ?></strong>
                                        </td>
                                        
                                        <?php foreach ($grade_items as $item): ?>
                                            <?php
                                                // Find the specific score for this student and this item
                                                $enrollment_id = $student['enrollment_id'];
                                                $item_name = $item['item_name'];
                                                
                                                // Look up the score in our 2D array
                                                $score_data = $scores_grid[$enrollment_id][$item_name] ?? null;
                                                
                                                // Get the ID and value, default to 0 if not found
                                                $score_id = $score_data['score_id'] ?? 0;
                                                $score_value = $score_data['value'] ?? 0;
                                            ?>
                                            <td class="center">
                                                <input type="number" 
                                                       name="scores[<?= $score_id ?>]" 
                                                       value="<?= htmlspecialchars($score_value) ?>"
                                                       min="0"
                                                       max="<?= htmlspecialchars($item['max_score']) ?>">
                                            </td>
                                        <?php endforeach; ?>
                                        
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!empty($students) && !empty($grade_items)): ?>
                    <button type="submit" name="save_scores" class="action-button" style="background: var(--success-green); margin-top: 20px;">
                        <i class="fa-solid fa-save"></i> Save All Changes
                    </button>
                <?php endif; ?>
            </form>
        </section>

        <div class="footer">© 2025 Smart Grade Monitoring | Gradebook Module</div>
    </main>
</div>

<script>
// Simple script to set the active link
document.addEventListener("DOMContentLoaded", function() {
    const navLinks = document.querySelectorAll('.sidebar-nav a.nav-link');
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('data-view') === 'grade_input') {
            link.classList.add('active');
        }
    });
});
</script>
</body>
</html>