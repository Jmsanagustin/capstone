<?php
// FILE: update_profile.php (Student's Profile Edit/Submit Request with Change Detection)

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php'; 

$current_student_sid = $_SESSION['sid'];
$message = '';
$message_type = 'error';

if (!isset($_SESSION['sid']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_edit') {

    // 1. Get and sanitize submitted form data
    $submitted_fname = trim($_POST['first_name'] ?? '');
    $submitted_mname = trim($_POST['middle_name'] ?? '');
    $submitted_lname = trim($_POST['last_name'] ?? '');
    $submitted_email = trim($_POST['email'] ?? '');

    // 2. Initial Validation
    if (empty($submitted_fname) || empty($submitted_lname) || empty($submitted_email) || !filter_var($submitted_email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please fill in all required fields with a valid email.";
    } else {
        try {
            $conn->beginTransaction();

            // 3. Fetch current profile data from the database for comparison
            $stmt_current = $conn->prepare("
                SELECT up.first_name, up.last_name, up.middle_name, u.email
                FROM user_profiles up
                JOIN users u ON up.sid = u.sid
                WHERE up.sid = :sid
            ");
            $stmt_current->execute([':sid' => $current_student_sid]);
            $current_profile = $stmt_current->fetch(PDO::FETCH_ASSOC);

            // Normalize names (handling NULL middle name/empty string)
            $current_fname = $current_profile['first_name'] ?? '';
            $current_lname = $current_profile['last_name'] ?? '';
            $current_mname = $current_profile['middle_name'] ?? '';
            $current_email = $current_profile['email'] ?? '';
            
            // --- CRITICAL CHECK: Detect if any change was actually made ---
            $is_data_changed = (
                strcasecmp($submitted_fname, $current_fname) !== 0 ||
                strcasecmp($submitted_lname, $current_lname) !== 0 ||
                strcasecmp($submitted_mname, $current_mname) !== 0 ||
                strcasecmp($submitted_email, $current_email) !== 0
            );

            if (!$is_data_changed) {
                // If the submitted data matches the current database data, throw an exception
                throw new Exception("No new changes detected. Your profile information is already up-to-date.");
            }
            // --- END CRITICAL CHECK ---

            // 4. Check for existing PENDING request
            $stmt_check_pending = $conn->prepare("
                SELECT request_id FROM profile_change_requests 
                WHERE student_sid = :sid AND status = 'Pending'
            ");
            $stmt_check_pending->execute([':sid' => $current_student_sid]);
            
            if ($stmt_check_pending->fetch()) {
                throw new Exception("You already have a pending profile change request. Please wait for approval.");
            }
            
            // 5. Check if the new email is already used by someone else
            $stmt_check_email = $conn->prepare("SELECT sid FROM users WHERE email = :email AND sid != :sid");
            $stmt_check_email->execute([':email' => $submitted_email, ':sid' => $current_student_sid]);
            if ($stmt_check_email->fetch()) {
                throw new Exception("The new email address is already in use by another account.");
            }

            // 6. Insert new request into the profile_change_requests table
            $stmt_insert = $conn->prepare("
                INSERT INTO profile_change_requests 
                (student_sid, new_first_name, new_last_name, new_middle_name, new_email, status)
                VALUES (:sid, :fname, :lname, :mname, :email, 'Pending')
            ");
            $stmt_insert->execute([
                ':sid' => $current_student_sid,
                ':fname' => $submitted_fname,
                ':lname' => $submitted_lname,
                ':mname' => $submitted_mname,
                ':email' => $submitted_email
            ]);

            $conn->commit();
            $message = "Profile change request submitted successfully! Await review by the administration(programhead)";
            $message_type = "success";

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            // Use 'warning' for the 'No Changes Detected' message
            $message_type = (strpos($e->getMessage(), 'No new changes detected') !== false) ? "warning" : "error";
            $message = $e->getMessage();
        }
    }
}

$_SESSION['profile_message'] = $message;
$_SESSION['profile_message_type'] = $message_type;

// Redirect directly to student_profile.php
header("Location: student_profile.php"); 
exit();
?>