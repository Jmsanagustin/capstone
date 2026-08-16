<?php
session_start();
include 'db.php'; // Database connection file

// --- Authorization Check ---
// Students must be logged in and typically forced here after temp password authentication.
if (!isset($_SESSION['sid']) || ($_SESSION['role'] !== 'student' && $_SESSION['role'] !== 'professor')) {
    header("Location: index.php");
    exit();
}

$user_sid = $_SESSION['sid'];
$user_role = $_SESSION['role'];
$message = '';
$message_type = '';

// Determine dashboard redirect path
$dashboard_path = ($user_role === 'student') ? 'students.php' : 'teacher_dashboard.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // --- Removed redundant $current_password check ---
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // 1. Validate new password match and length
    if ($new_password !== $confirm_password) {
        $message = "New password and confirmation password do not match.";
        $message_type = 'error';
    } 
    elseif (strlen($new_password) < 8) {
        $message = "New password must be at least 8 characters long.";
        $message_type = 'error';
    } 
    else {
        // 2. Hash the new password
        $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        try {
            $conn->beginTransaction();
            
            // Critical Update: Set password AND reset must_change_password flag to 0
            $stmt_update = $conn->prepare("
                UPDATE users 
                SET password = :new_password, must_change_password = 0 
                WHERE sid = :sid
            ");
            
            if ($stmt_update->execute([':new_password' => $new_hashed_password, ':sid' => $user_sid])) {
                
                // 3. IMMEDIATE REDIRECT UPON SUCCESS
                $conn->commit(); // Commit before redirecting
                header("Location: " . $dashboard_path);
                exit();
                
            } else {
                $conn->rollBack();
                $message = "Failed to update password due to a database error.";
                $message_type = 'error';
            }
            $conn->commit();
        } catch (PDOException $e) {
             $conn->rollBack();
             $message = "Database transaction failed: " . $e->getMessage();
             $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --panel-blue: #1f6ea3; --bg-light: #f2f5f8; --white: #ffffff;
            --text-dark: #102a43; --success-green: #28a745; --critical: #cc3333;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg-light); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background: var(--white); padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); width: 100%; max-width: 450px; }
        h2 { color: var(--panel-blue); text-align: center; margin-bottom: 20px; font-weight: 700; }
        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
        .message.success { background: #e6f7e6; color: var(--success-green); border: 1px solid var(--success-green); }
        .message.error { background: #fde8e8; color: var(--critical); border: 1px solid var(--critical); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: var(--text-dark); }
        input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; font-size: 1rem; }
        .btn { width: 100%; padding: 12px; background: var(--panel-blue); color: var(--white); border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.3s; margin-top: 10px; }
        .btn:hover { background: #145b88; }
        .info { font-size: 0.9em; color: #627d98; margin-top: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Set New Password</h2>

        <?php if ($message): ?>
            <div class="message <?= $message_type; ?>"><?= htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form action="student_change_password.php" method="POST">
            <p class="info">
                You have successfully authenticated with your temporary password. Please set a new permanent password below.
            </p>
            
            <!-- Removed current_password field -->
            
            <div class="form-group">
                <label for="new_password">New Password (Min 8 characters)</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn" <?= ($message_type === 'success') ? 'disabled' : ''; ?>>Change Password</button>
            
            <?php if ($message_type === 'success'): ?>
                <!-- This link is now redundant as the script will auto-redirect, but we keep it disabled for fallback UI integrity. -->
                <a href="<?= htmlspecialchars($dashboard_path); ?>" class="btn" style="background-color: var(--success-green); margin-top: 15px; text-align: center; display: block; text-decoration: none;">Go to Dashboard</a>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>