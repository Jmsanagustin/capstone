<?php
session_start();
include 'db.php'; // Include database connection

// --- Load PHPMailer classes ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
// --- End PHPMailer includes ---

// --- CONFIGURATION ---
// !!! SET THIS TO YOUR WEBSITE'S URL !!!
define('APP_URL', 'http://localhost/your-project-folder'); // Example: https://www.my-grade-system.com
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USERNAME', 'johnmichaelsanagustin2@gmail.com'); // Your email
define('SMTP_PASSWORD', 'qgfqehpswgbmuwwn'); // Your app password
define('SMTP_PORT', 465);
define('SMTP_FROM_EMAIL', 'noreply@dfcamclp.edu.ph');
define('SMTP_FROM_NAME', 'Smart Grade System Security');
// --- END CONFIGURATION ---

// Constants for max attempts and lockout duration
define('MAX_ATTEMPTS', 5);
define('LOCKOUT_TIME', 300); // 300 seconds = 5 minutes

/**
 * Generates a 6-digit OTP.
 * @return string
 */
function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Authenticates a user, handles role-based redirects, and 2FA.
 * (This is your original function, unchanged)
 *
 * @param string $username
 * @param string $password
 * @param PDO $conn Database connection
 * @return string Error message or empty on success (before exit)
 */
function authenticateUser($username, $password, $conn) {
    
    // 1. Fetch user data from the database
    $stmt = $conn->prepare("SELECT sid, username, password, role, email, must_change_password FROM users WHERE username = :username");
    $stmt->bindParam(':username', $username);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Verify Password
        if (password_verify($password, $user['password'])) {
            
            // --- PASSWORD IS CORRECT ---
            // 1. Reset login attempts
            $_SESSION['login_attempts'] = 0;
            $_SESSION['last_attempt_time'] = null;

            // 2. Check for mandatory password change
            if (isset($user['must_change_password']) && $user['must_change_password'] == 1) {
                $_SESSION['sid'] = $user['sid'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                header("Location: student_change_password.php");
                exit();
            }

            // 3. Role-based routing
            $role = $user['role'];

            if ($role === 'Registrar' || $role === 'Programhead') {
                // --- ADMIN LOGIN (BYPASS 2FA) ---
                $_SESSION['sid'] = $user['sid'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                if ($role === 'Registrar') {
                    header("Location: registrar_dashboard.php");
                    exit();
                
                } else { // Programhead
                    // Fetch program
                    $stmt_profile = $conn->prepare("SELECT program FROM user_profiles WHERE sid = :sid");
                    $stmt_profile->execute([':sid' => $user['sid']]);
                    $profile = $stmt_profile->fetch(PDO::FETCH_ASSOC);

                    if ($profile && !empty($profile['program'])) {
                        $_SESSION['program'] = $profile['program'];
                        header("Location: programhead_dashboard.php");
                        exit();
                    } else {
                        session_unset();
                        session_destroy();
                        return "Login failed: Your Program Head account is not assigned to a program.";
                    }
                }

            } else {
                // --- STUDENT/PROFESSOR LOGIN (USE 2FA) ---
                $otp_code = generateOTP();

                // Store OTP in DB
                $stmt_token = $conn->prepare("UPDATE users SET verification_token = :otp, token_creation_time = NOW() WHERE sid = :sid");
                $stmt_token->execute([':otp' => $otp_code, ':sid' => $user['sid']]);
                
                // Send 2FA email
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USERNAME;
                    $mail->Password   = SMTP_PASSWORD;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = SMTP_PORT;

                    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                    $mail->addAddress($user['email']); 

                    $mail->isHTML(true);
                    $mail->Subject = 'Your SGMS Login Code';
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; text-align: center;'>
                            <h2 style='color: #333;'>Your One-Time Passcode</h2>
                            <p>Please use the following code to log in:</p>
                            <p style='font-size: 36px; font-weight: bold; letter-spacing: 5px; margin: 25px 0; padding: 10px; background-color: #f4f4f4; border-radius: 5px;'>
                                $otp_code
                            </p>
                            <p>This code is valid for 5 minutes.</p>
                        </div>";
                    
                    $mail->send();
                    
                    // Redirect to 2FA page
                    $_SESSION['2fa_user_sid'] = $user['sid'];
                    $_SESSION['2fa_email'] = $user['email'];
                    header("Location: 2fa_enter_code.php");
                    exit();

                } catch (Exception $e) {
                    return "Login successful, but 2FA email could not be sent. Mailer Error: {$mail->ErrorInfo}";
                }
            }

        } else {
            return "Incorrect username or password."; // Incorrect password
        }
    } else {
        return "No user found with that username."; // No user
    }
}

/**
 * Handles sending a password reset link.
 *
 * @param string $email
 * @param PDO $conn
 * @return array ['success' => string, 'error' => string]
 */
function sendResetLink($email, $conn) {
    try {
        // 1. Find user by email
        $stmt = $conn->prepare("SELECT sid, email FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. (Security) ALWAYS return a success message, even if user not found.
        if (!$user) {
            return ['success' => "If an account with that email exists, a reset link has been sent.", 'error' => null];
        }

        // 3. Generate a secure, long-lived token
        $token = bin2hex(random_bytes(32)); // 64-char hex string
        $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry

        // 4. Store token in database
        $stmt_update = $conn->prepare("UPDATE users SET reset_token = :token, reset_token_expiry = :expiry WHERE sid = :sid");
        $stmt_update->execute([':token' => $token, ':expiry' => $expiry, ':sid' => $user['sid']]);

        // 5. Send the email
        $reset_link = APP_URL . "/reset_password.php?token=" . $token;

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($user['email']);

        $mail->isHTML(true);
        $mail->Subject = 'Your Password Reset Request';
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; padding: 20px;'>
                <h2 style='color: #333;'>Password Reset</h2>
                <p>We received a request to reset your password. Click the link below to set a new one:</p>
                <p style='margin: 25px 0;'>
                    <a href='$reset_link' style='background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>
                        Reset Your Password
                    </a>
                </p>
                <p>This link is valid for 1 hour.</p>
                <p>If you did not request this, please ignore this email.</p>
            </div>";

        $mail->send();

        return ['success' => "If an account with that email exists, a reset link has been sent.", 'error' => null];

    } catch (Exception $e) {
        return ['success' => null, 'error' => "Could not send reset email. Please try again later."];
    }
}

// --- SCRIPT EXECUTION STARTS HERE ---

// Initialize variables
$error_message = '';
$success_message = ''; // For password reset
$attempts_left = MAX_ATTEMPTS;
$is_locked_out = false;

// 1. Check if the user is locked out
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
    $time_since_last_attempt = time() - $_SESSION['last_attempt_time'];

    if ($time_since_last_attempt < LOCKOUT_TIME) {
        $remaining_time = LOCKOUT_TIME - $time_since_last_attempt;
        $error_message = "Too many failed attempts. Please try again in " . ceil($remaining_time / 60) . " minute(s).";
        $attempts_left = 0;
        $is_locked_out = true;
    } else {
        // Reset attempts after the lockout period
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = null;
    }
}

// 2. Process POST request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$is_locked_out) {

    // --- Check if it's a LOGIN attempt ---
    if (isset($_POST['action']) && $_POST['action'] == 'login') {
        
        $error_message = authenticateUser($_POST['username'], $_POST['password'], $conn);

        // Track failed login attempts
        if (!empty($error_message)) {
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['last_attempt_time'] = time();
            $attempts_left = MAX_ATTEMPTS - $_SESSION['login_attempts'];

            if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
                $error_message = "Too many failed attempts. Please try again in 5 minutes.";
                $attempts_left = 0;
            }
        }
    }

    // --- Check if it's a FORGOT PASSWORD attempt ---
    if (isset($_POST['action']) && $_POST['action'] == 'forgot') {
        $email = $_POST['email'] ?? '';
        $result = sendResetLink($email, $conn);
        
        if ($result['error']) {
            $error_message = $result['error'];
        } else {
            $success_message = $result['success'];
        }
    }
}

// Clear any messages from other pages (e.g., reset_password.php)
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DFCAMCLP - Smart Grade Monitoring System</title>
    
    <style>
        :root {
            --primary-color: #007bff;
            --primary-dark: #0056b3;
            --bg-color: #f0f2f5;
            --text-color: #333;
            --text-secondary: #555;
            --light-gray: #f9f9f9;
            --border-color: #ddd;
            --error-color: #dc3545;
            --success-color: #28a745;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            /* --- NEW BACKGROUND --- */
            background-image: linear-gradient(135deg, rgba(0, 123, 255, 0.8), rgba(0, 86, 179, 0.8)), url('dfcam-background.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed; /* Keeps bg still on scroll */
            /* --- End Background --- */

            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .wrapper {
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }

        /* --- NEW BRAND HEADER --- */
        .brand-header {
            padding: 40px 40px 20px 40px;
            text-align: center;
        }
        .brand-header .logo {
            max-width: 100px;
            height: auto;
            margin: 0 auto 15px auto;
            display: block;
        }
        .brand-header h2 {
            font-size: 1.1em; /* School name */
            color: var(--text-color);
            font-weight: 600;
            margin-bottom: 5px;
        }
        .brand-header p {
            font-size: 0.9em; /* System name */
            color: var(--text-secondary);
        }
        /* --- End Brand Header --- */

        .form-container {
            padding: 20px 40px 40px 40px; /* Reduced top padding */
        }

        /* Hide forgot form by default */
        #forgot-form {
            display: none;
        }
        
        /* This header is just for the 'Forgot Password' form */
        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .form-header h2 {
            font-size: 1.5em;
            color: var(--text-color);
            margin-bottom: 5px;
        }
        .form-header p {
            font-size: 0.9em;
            color: #777;
        }

        .input-box {
            position: relative;
            width: 100%;
            margin-bottom: 25px;
        }

        .input-box input {
            width: 100%;
            height: 50px;
            background: transparent;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            padding: 0 45px 0 15px;
            font-size: 1em;
            color: var(--text-color);
            outline: none;
        }
        .input-box input:focus {
            border-color: var(--primary-color);
        }

        /* Floating label effect */
        .input-box label {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            font-size: 1em;
            color: #777;
            pointer-events: none;
            transition: 0.3s ease;
        }
        .input-box input:focus ~ label,
        .input-box input:valid ~ label {
            top: 0px;
            left: 10px;
            font-size: 0.8em;
            background-color: white;
            padding: 0 5px;
            color: var(--primary-color);
        }
        
        /* Input icons */
        .input-box ion-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2em;
            color: #777;
        }

        /* Specific for password toggle */
        .input-box #toggle-password {
            cursor: pointer;
        }

        .options {
            display: flex;
            justify-content: space-between;
            font-size: 0.9em;
            margin-bottom: 20px;
        }
        .options a {
            color: var(--primary-color);
            text-decoration: none;
        }
        .options a:hover {
            text-decoration: underline;
        }

        .btn {
            width: 100%;
            height: 45px;
            background: var(--primary-color);
            border: none;
            border-radius: 5px;
            color: white;
            font-size: 1em;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        .btn:hover {
            background: var(--primary-dark);
        }
        .btn:disabled {
            background-color: #aaa;
            cursor: not-allowed;
        }

        /* Message Styling */
        .message {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 0.9em;
            text-align: center;
        }
        .error-message {
            background-color: #f8d7da;
            color: var(--error-color);
            border: 1px solid #f5c6cb;
        }
        .success-message {
            background-color: #d4edda;
            color: var(--success-color);
            border: 1px solid #c3e6cb;
        }

        .attempts-text {
            text-align: center;
            font-size: 0.9em;
            color: #777;
            margin-top: 15px;
        }
    </style>
</head>
<body>

<div class="wrapper">
    
    <div class="brand-header">
        <img src="dfcam-logo.png" alt="DFCAMCLP Logo" class="logo">
        <h2>Dr. Filemon C. Aguilar Memorial College of Las Pinas - IT Campus</h2>
        <p>Smart Grade Monitoring System</p>
    </div>

    <div class="form-container" id="login-form">
        <?php if ($error_message): ?>
            <div class="message error-message"><?= htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="message success-message"><?= htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <form action="index.php" method="POST">
            <input type="hidden" name="action" value="login">
            
            <div class="input-box">
                <input type="text" name="username" required>
                <label>Username</label>
                <ion-icon name="person-outline"></ion-icon>
            </div>
            
            <div class="input-box">
                <input type="password" name="password" id="password-input" required>
                <label>Password</label>
                <ion-icon name="eye-outline" id="toggle-password"></ion-icon>
            </div>
            
            <div class="options">
                <label><input type="checkbox" name="remember"> Remember me</label>
                <a href="#" id="forgot-link">Forgot Password?</a>
            </div>
            
            <button type="submit" class="btn" <?= ($attempts_left === 0) ? 'disabled' : ''; ?>>Login</button>

            <?php if ($attempts_left > 0 && $attempts_left < MAX_ATTEMPTS): ?>
                <p class="attempts-text">You have <?= $attempts_left; ?> attempt(s) left.</p>
            <?php endif; ?>
        </form>
    </div>

    <div class="form-container" id="forgot-form">
        <div class="form-header">
            <h2>Reset Password</h2>
            <p>Enter your email to receive a reset link.</p>
        </div>

        <form action="index.php" method="POST">
            <input type="hidden" name="action" value="forgot">
            
            <div class="input-box">
                <input type="email" name="email" required>
                <label>Email Address</label>
                <ion-icon name="mail-outline"></ion-icon>
            </div>
            
            <button type="submit" class="btn">Send Reset Link</button>

            <div class="options" style="justify-content: center; margin-top: 20px;">
                <a href="#" id="back-to-login">Back to Login</a>
            </div>
        </form>
    </div>

</div>

<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // --- Password Show/Hide Toggle ---
        const passwordInput = document.getElementById('password-input');
        const togglePassword = document.getElementById('toggle-password');

        if (togglePassword) {
            togglePassword.addEventListener('click', function() {
                // Check current type
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Change icon
                this.setAttribute('name', type === 'password' ? 'eye-outline' : 'eye-off-outline');
            });
        }

        // --- Form Switching ---
        const loginForm = document.getElementById('login-form');
        const forgotForm = document.getElementById('forgot-form');
        const forgotLink = document.getElementById('forgot-link');
        const backToLoginLink = document.getElementById('back-to-login');

        if (forgotLink) {
            forgotLink.addEventListener('click', function(e) {
                e.preventDefault();
                loginForm.style.display = 'none';
                forgotForm.style.display = 'block';
            });
        }

        if (backToLoginLink) {
            backToLoginLink.addEventListener('click', function(e) {
                e.preventDefault();
                forgotForm.style.display = 'none';
                loginForm.style.display = 'block';
                
                // Clear any messages when switching back
                document.querySelectorAll('.message').forEach(msg => msg.style.display = 'none');
            });
        }

        // --- Handle Floating Labels on inputs ---
        document.querySelectorAll('.input-box input').forEach(input => {
            // Check on load
            if (input.value.trim() !== '') {
                input.classList.add('has-value');
            }
            
            // Check on change
            input.addEventListener('blur', function() {
                if (input.value.trim() !== '') {
                    input.classList.add('has-value');
                } else {
                    input.classList.remove('has-value');
                }
            });
        });

    });
</script>

</body>
</html>