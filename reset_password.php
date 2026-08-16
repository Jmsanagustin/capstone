<?php
// FILE: reset_password.php (Ready for Live Web)
// PURPOSE: Handles token validation and processing of the new password form.

session_start();
include 'db.php'; // Must contain live Hostinger credentials

$error_message = '';
$user = null; 

// 1. Get token from POST (if submitting) or GET (if clicking link)
$token = $_POST['token'] ?? $_GET['token'] ?? '';

if (empty($token)) {
    // This redirect will go to the live site's index.php
    $_SESSION['error_message'] = "Invalid or missing reset token.";
    header("Location: index.php");
    exit();
}

try {
    // 2. Find user by token ONLY (Check expiry in PHP to avoid timezone issues)
    $stmt = $conn->prepare("SELECT sid, email, reset_token_expiry FROM users WHERE reset_token = :token");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3. Validation Logic
    if (!$user) {
        $error_message = "Invalid reset link. The token does not match any account.";
    } elseif (strtotime($user['reset_token_expiry']) < time()) {
        $error_message = "This link has expired. Please request a new one.";
    } else {
        // Token is valid and not expired.

        // 4. Handle Password Update
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $password = $_POST['password'];
            $password_confirm = $_POST['password_confirm'];

            if (empty($password) || empty($password_confirm)) {
                $error_message = "Please fill out both password fields.";
            } elseif ($password !== $password_confirm) {
                $error_message = "Passwords do not match.";
            } elseif (strlen($password) < 8) {
                $error_message = "Password must be at least 8 characters long.";
            } else {
                // Update password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt_update = $conn->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_token_expiry = NULL, must_change_password = 0 WHERE sid = :sid");
                $stmt_update->execute([
                    ':password' => $hashed_password,
                    ':sid' => $user['sid']
                ]);

                $_SESSION['success_message'] = "Your password has been reset successfully! Please log in.";
                // This redirect correctly sends the user to the live index.php
                header("Location: index.php");
                exit();
            }
        }
    }

} catch (PDOException $e) {
    error_log("Password Reset DB Error: " . $e->getMessage());
    $error_message = "An internal system error occurred. Please try again later.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password</title>
    <style>
        /* CSS remains the same */
        :root { --primary-color: #007bff; --primary-dark: #0056b3; --bg-color: #f0f2f5; --text-color: #333; --border-color: #ddd; --error-color: #dc3545; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; }
        body { background-image: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%); display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .wrapper { background-color: #ffffff; border-radius: 10px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); max-width: 420px; width: 100%; overflow: hidden; }
        .form-container { padding: 40px; }
        .form-header { text-align: center; margin-bottom: 30px; }
        .form-header h2 { font-size: 2em; color: var(--text-color); margin-bottom: 5px; }
        .form-header p { font-size: 0.9em; color: #777; }
        .input-box { position: relative; width: 100%; margin-bottom: 25px; }
        .input-box input { width: 100%; height: 50px; background: transparent; border: 1px solid var(--border-color); border-radius: 5px; padding: 0 15px; font-size: 1em; color: var(--text-color); outline: none; }
        .input-box input:focus { border-color: var(--primary-color); }
        .btn { width: 100%; height: 45px; background: var(--primary-color); border: none; border-radius: 5px; color: white; font-size: 1em; font-weight: bold; cursor: pointer; }
        .btn:hover { background: var(--primary-dark); }
        .message { padding: 12px; border-radius: 5px; margin-bottom: 20px; font-size: 0.9em; text-align: center; background-color: #f8d7da; color: var(--error-color); border: 1px solid #f5c6cb; }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="form-container">
        <div class="form-header">
            <h2>Set New Password</h2>
            <p>Please enter your new password below.</p>
        </div>

        <?php if ($error_message): ?>
            <div class="message"><?= htmlspecialchars($error_message); ?></div>
            <div style="text-align:center; margin-top:10px;">
                <a href="index.php" style="color: #007bff; text-decoration: none;">Back to Login</a>
            </div>
        <?php else: ?>

            <form action="" method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">

                <div class="input-box">
                    <input type="password" name="password" required placeholder="New Password">
                </div>
                
                <div class="input-box">
                    <input type="password" name="password_confirm" required placeholder="Confirm Password">
                </div>
                
                <button type="submit" class="btn">Set New Password</button>
            </form>

        <?php endif; ?>
    </div>
</div>

</body>
</html>