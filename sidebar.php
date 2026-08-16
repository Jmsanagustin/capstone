<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// This ensures $currentPage is set, even if the including file forgets.
// (student_dashboard.php, schedules.php, etc., should set this variable *before* including this file)
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF']); // Default to the filename
}
?>

<link rel="stylesheet" href="sidebar.css">
<div class="sidebar" id="sidebar">
    <div class="logo">
        <span class="connect">CONNECT</span>
    </div>
    <ul class="menu">
        <li><a href="students.php" class="<?php echo ($currentPage == 'students.php') ? 'active' : ''; ?>">🏠 <span>Dashboard</span></a></li>
        <li><a href="student_dashboard.php" class="<?php echo ($currentPage == 'student_dashboard.php') ? 'active' : ''; ?>">📊 <span>View Grades</span></a></li>
        <li><a href="messaging_center.php" class="<?php echo ($currentPage == 'messaging_center.php') ? 'active' : ''; ?>">📘 <span>Message</span></a></li>
        <li><a href="schedules.php" class="<?php echo ($currentPage == 'schedules.php') ? 'active' : ''; ?>">🗓 <span>Class Schedule</span></a></li>
        <li><a href="downloads.php" class="<?php echo ($currentPage == 'downloads.php') ? 'active' : ''; ?>">📥 <span>Downloadable Forms</span></a></li>
        <li><a href="handbook.php" class="<?php echo ($currentPage == 'handbook.php') ? 'active' : ''; ?>">📘 <span>Student Handbook</span></a></li>
    </ul>
</div>