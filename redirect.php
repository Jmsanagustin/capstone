<?php
session_start();

if (isset($_POST['role'])) {
    $role = $_POST['role'];
    $_SESSION['role'] = $role; // Store the selected role in the session

    switch ($role) {
        case 'student':
            header("Location: student_dashboard.php");
            break;
        case 'teacher':
            header("Location: teacher_dashboard.php");
            break;
        case 'admin':
            header("Location: admin_dashboard.php");
            break;
        case 'system_admin':
            header("Location: system_admin_dashboard.php");
            break;
        default:
            header("Location: role_selection.php");
            break;
    }
    exit();
} else {
    header("Location: role_selection.php");
    exit();
}
?>