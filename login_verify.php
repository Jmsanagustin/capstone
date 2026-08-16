<?php
include 'db.php'; // Include the database connection
session_start();

$token = $_GET['token'] ?? '';
$message = "";

if (empty($token)) {
    $message = "Login link is missing or invalid.";
} else {
    try {
        // --- FIX IS HERE ---
        // 1. Find user by LOGIN token and check if it's expired
        $stmt = $conn->prepare("SELECT * FROM users WHERE login_token = :token AND login_token_expires > NOW()");
        $stmt->bindParam(':token', $token);
        // --- END FIX ---

        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // --- FIX IS HERE ---
            // 2. User found. Clear the LOGIN token.
            $stmt_update = $conn->prepare("UPDATE users SET login_token = NULL, login_token_expires = NULL WHERE sid = :sid");
            $stmt_update->bindParam(':sid', $user['sid']);
            $stmt_update->execute();
            // --- END FIX ---
            
            // 3. Log the user in by setting all session variables
            session_regenerate_id(true); // Secure the session
            $_SESSION['userId'] = $user['sid']; 
            $_SESSION['sid'] = $user['sid'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            unset($_SESSION['2fa_email']); // Clear the temp session
            
            // 4. Redirect to the correct dashboard (Your logic is perfect)
            switch ($user['role']) {
                case 'student':
                    header("Location: students.php");
                    break;
                case 'professor':
                    header("Location: teacher_dashboard.php");
                    break;
                case 'registrar':
                    header("Location: registrar_dashboard.php");
                case 'Programhead': // Correctly handles both
                    header("Location: programhead_approval.php");
                    break;
                default:
                    header("Location: index.php");
                    break;
            }
            exit();

        } else {
            // 3. Token not found, already used, or expired
            $message = "Login failed. The link is expired or invalid.";
        }
    } catch (PDOException $e) {
        $message = "Database error during login. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login Verification</title>
<link rel="stylesheet" href="signup.css">
<style>
.message-box { max-width: 500px; margin: 100px auto; padding: 30px; border-radius: 8px; text-align: center; background-color: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.1); border: 1px solid #dc3545; background-color: #f8d7da; color: #721c24; }
.message-box a { color: #721c24; font-weight: bold; }
</style>
</head>
<body>
<div class="message-box error">
    <h2>Login Failed</h2>
    <p><?php echo $message; ?></p>
    <p><a href="index.php">Return to Login Page</a></p>
</div>
</body>
</html>