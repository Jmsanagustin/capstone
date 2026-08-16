<?php
session_start();
include 'db.php'; // Database connection

// Check if the user is a professor
if ($_SESSION['role'] !== 'professor') {
    header("Location: index.php");
    exit();
}

// Initialize messages
$error_message = "";
$success_message = "";

// Handle student deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    $sid = htmlspecialchars($_POST['sid']); // Sanitize input

    try {
        $conn->beginTransaction();

        // Delete related records
        $conn->prepare("DELETE FROM grades WHERE sid = :sid")->execute([':sid' => $sid]);
        $conn->prepare("DELETE FROM attendance WHERE sid = :sid")->execute([':sid' => $sid]);
        $conn->prepare("DELETE FROM students WHERE sid = :sid")->execute([':sid' => $sid]);

        $conn->commit();
        $success_message = "✅ Student and all related records successfully deleted!";
    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = "❌ Error deleting student: " . $e->getMessage();
    }
}

// Search for students
$search_query = $_GET['search'] ?? "";
$students = [];

try {
    if ($search_query) {
        $stmt = $conn->prepare("SELECT * FROM students WHERE first_name LIKE :s OR last_name LIKE :s OR sid LIKE :s");
        $stmt->execute([':s' => "%$search_query%"]);
    } else {
        $stmt = $conn->query("SELECT * FROM students");
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching students: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Students - Smart Grade Monitoring</title>
<style>
:root{
    --nav-dark:#0f4a73; /* Aligning with teacher_profile style */
    --panel:#3d5470;--tile:#e9eef3;
    --accent:#2b77a3;--bg:#f2f5f8;--white:#fff;
    --text:#102a43;--muted:#667b8a;
}
/* Re-applying standard/consistent CSS for Navbar */
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:var(--bg);color:var(--text); padding-top: 60px;} /* Added padding-top */
.navbar{background:var(--nav-dark);color:var(--white);padding:14px 20px;display:flex;gap:20px;align-items:center; position: fixed; top: 0; width: 100%; z-index: 1000; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);}
.navbar a{color:var(--white);text-decoration:none;font-weight:bold; padding: 5px 10px; border-radius: 4px;}
.navbar a:hover{text-decoration:none; background: rgba(255, 255, 255, 0.1);} /* Adjusted hover */
.container{max-width:1200px;margin:20px auto;padding:20px;background:var(--white);border-radius:8px;box-shadow:0 4px 10px rgba(0,0,0,0.08);}
h2{margin-top:0;color:var(--panel);}
form{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:20px;}
form input[type="text"]{padding:8px 12px;border-radius:6px;border:1px solid #ccc;width:250px;}
form button{padding:8px 14px;border:none;border-radius:6px;background:var(--accent);color:white;cursor:pointer;}
form button:hover{background:#1d5e86;}
.error-message,.success-message{padding:10px;border-radius:6px;margin-bottom:10px;}
.error-message{background:#fce4e4;color:#b20000;}
.success-message{background:#e5f8e5;color:#1d7a1d;}
table{width:100%;border-collapse:collapse;}
th,td{border:1px solid #ddd;padding:8px;text-align:left;}
th{background:var(--accent);color:white;}
tr:nth-child(even){background:#f9f9f9;}
.delete-btn{background:#c92a2a;color:white;padding:6px 10px;border:none;border-radius:5px;cursor:pointer;}
.delete-btn:hover{background:#991b1b;}
.dashboard-banner{
    background:linear-gradient(90deg,var(--panel),#4c6787);
    color:white;padding:20px;border-radius:8px;margin-bottom:20px;
    display:flex;justify-content:space-between;align-items:center;
}
.timer{background:rgba(255,255,255,0.1);padding:10px 16px;border-radius:6px;font-weight:bold;}
.footer{text-align:center;font-size:13px;color:var(--muted);margin-top:20px;}
</style>
</head>
<body>

    <div class="navbar">
        <a href="teacher_dashboard.php?view=dashboard">Home</a>
        <a href="teacher_dashboard.php?view=messages">Messaging</a>
        <a href="stud_manage.php">Manage Students</a>
        <a href="attendance_monitoring.php">Attendance</a>
        <a href="teacher_profile.php">Profile</a>
        <a href="logout.php" style="margin-left: auto;">Logout</a>
    </div>

<div class="container">
    <div class="dashboard-banner">
        <div>
            <h2>Manage Students</h2>
            <p>Smart Grade Monitoring System — Teacher Portal</p>
        </div>
        <div class="timer" id="clock">00:00:00</div>
    </div>

    <?php if ($error_message): ?>
        <div class="error-message"><?= htmlspecialchars($error_message) ?></div>
    <?php elseif ($success_message): ?>
        <div class="success-message"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <form method="GET" action="">
        <input type="text" name="search" placeholder="Search by ID, First Name, or Last Name" value="<?= htmlspecialchars($search_query) ?>">
        <button type="submit">Search</button>
        <a href="stud_manage.php"><button type="button">Clear</button></a>
    </form>

    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Year</th>
                <th>Section</th>
                <th>Course</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($students)): ?>
                <?php foreach ($students as $st): ?>
                    <tr>
                        <td><?= htmlspecialchars($st['sid']) ?></td>
                        <td><?= htmlspecialchars($st['fname']) ?></td>
                        <td><?= htmlspecialchars($st['lname']) ?></td>
                        <td><?= htmlspecialchars($st['year']) ?></td>
                        <td><?= htmlspecialchars($st['section']) ?></td>
                        <td><?= htmlspecialchars($st['course']) ?></td>
                        <td>
                            <form method="POST" action="stud_manage.php" style="display:inline;">
                                <input type="hidden" name="sid" value="<?= htmlspecialchars($st['sid']) ?>">
                                <button type="submit" name="delete_student" class="delete-btn" onclick="return confirm('Are you sure you want to delete this student?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7">No students found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="footer">© 2025 Smart Grade Monitoring | Teacher Management Interface</div>
</div>

<script>
// simple live clock
function pad(n){return n<10?'0'+n:n;}
function updateClock(){
    const d = new Date();
    document.getElementById('clock').textContent = pad(d.getHours())+":"+pad(d.getMinutes())+":"+pad(d.getSeconds());
}
setInterval(updateClock,1000);updateClock();
</script>

</body>
</html>