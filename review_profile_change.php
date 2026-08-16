<?php
// FILE: review_profile_changes.php (Admin Review/Confirm/Reject)

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

// --- FIXED REDIRECT URL ---
// The Registrar should be redirected back to the specific Academic Manager page 
// where they were reviewing requests.
$redirect_url = "academic_management_master.php?view=change_requests"; // <-- Ensures redirection to the master file 

// -------------------------------------------------------------------------
// --- AUTHORIZATION: RESTRICTED TO REGISTRAR ONLY ---
// -------------------------------------------------------------------------
if (!isset($_SESSION['sid']) || $_SESSION['role'] !== 'Registrar') {
    // If the role is not exactly 'Registrar', redirect.
    header("Location: index.php");
    exit();
}
// -------------------------------------------------------------------------

$reviewer_sid = $_SESSION['sid'];
$message = '';
$message_type = 'error';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_id'], $_POST['action'])) {
    
    $request_id = (int)$_POST['request_id'];
    $action = $_POST['action']; // 'Approve' or 'Reject'
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    try {
        $conn->beginTransaction();

        // 1. Fetch the PENDING request details
        $stmt_request = $conn->prepare("
            SELECT student_sid, new_first_name, new_last_name, new_middle_name, new_email 
            FROM profile_change_requests 
            WHERE request_id = :id AND status = 'Pending'
        ");
        $stmt_request->execute([':id' => $request_id]);
        $request = $stmt_request->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception("Request not found, invalid, or already processed.");
        }
        
        $student_sid = $request['student_sid'];

        if ($action === 'Approve') {
            // 2. APPROVE: Update the student's main records (users and user_profiles)
            
            // A. Update users table (email)
            $stmt_update_user = $conn->prepare("UPDATE users SET email = :email WHERE sid = :sid");
            $stmt_update_user->execute([':email' => $request['new_email'], ':sid' => $student_sid]);

            // B. Update user_profiles table (names)
            $stmt_update_profile = $conn->prepare(
                "UPDATE user_profiles 
                 SET first_name = :fname, middle_name = :mname, last_name = :lname 
                 WHERE sid = :sid"
            );
            $stmt_update_profile->execute([
                ':fname' => $request['new_first_name'],
                ':mname' => $request['new_middle_name'],
                ':lname' => $request['new_last_name'],
                ':sid' => $student_sid
            ]);

            // C. Mark the request as Approved
            $stmt_update_request = $conn->prepare("
                UPDATE profile_change_requests 
                SET status = 'Approved', reviewed_by_sid = :reviewer_sid, rejection_reason = NULL 
                WHERE request_id = :id
            ");
            $stmt_update_request->execute([':reviewer_sid' => $reviewer_sid, ':id' => $request_id]);

            $message = "Profile changes for Student ID {$student_sid} confirmed and applied. ✅";
            $message_type = "success";

        } elseif ($action === 'Reject') {
            // 3. REJECT: Mark the request as Rejected
            if (empty($rejection_reason)) {
                // In a real application, you might prompt for a reason here. 
                // Since this is a server-side script, we'll use a generic rejection message.
            }
            
            $stmt_update_request = $conn->prepare("
                UPDATE profile_change_requests 
                SET status = 'Rejected', reviewed_by_sid = :reviewer_sid, rejection_reason = :reason 
                WHERE request_id = :id
            ");
            $stmt_update_request->execute([
                ':reviewer_sid' => $reviewer_sid, 
                ':reason' => $rejection_reason ?: 'Rejected by Registrar.', // Use rejection reason or generic message
                ':id' => $request_id
            ]);

            $message = "Profile change request for Student ID {$student_sid} rejected. ❌";
            $message_type = "warning";
        } else {
            throw new Exception("Invalid action specified.");
        }

        $conn->commit();

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $message = "Processing error: " . $e->getMessage();
        $message_type = "error";
    }

} else {
    $message = "Invalid request method or missing data.";
}

// Store the message for the next page load (on the Registrar Academic Manager)
$_SESSION['temp_message'] = ['text' => $message, 'type' => $message_type]; 
header("Location: " . $redirect_url);
exit();
?>