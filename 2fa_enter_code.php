<?php
session_start();
include 'db.php'; // Include database connection ($conn PDO object)

// Constants for time check
define('OTP_EXPIRATION_SECONDS', 300); // 5 minutes
define('RESEND_COOLDOWN_SECONDS', 30); // Prevent resend abuse: 30 seconds

// If user isn't in the 2FA process, redirect them to login
if (!isset($_SESSION['2fa_user_sid'])) {
    header("Location: index.php");
    exit();
}

// Check for and display any success message from the resend script
$resend_message = $_SESSION['resend_message'] ?? '';
unset($_SESSION['resend_message']); // Clear the message after displaying

$error_message = '';
$user_sid = $_SESSION['2fa_user_sid'];

// --- FIX: Ensure we always have an email to display ---
$user_email = $_SESSION['2fa_email'] ?? '';
if (empty($user_email)) {
    try {
        $stmt_e = $conn->prepare("SELECT email FROM users WHERE sid = :sid");
        $stmt_e->execute([':sid' => $user_sid]);
        $user_email = $stmt_e->fetchColumn();
        // Update session to prevent re-querying
        if ($user_email) $_SESSION['2fa_email'] = $user_email;
    } catch (PDOException $e) {
        // error_log("Email fetch failed");
    }
}


// Fetch the existing token's creation time for the client-side countdown
$token_creation_time_unix = null;
try {
    $stmt_time = $conn->prepare("SELECT token_creation_time FROM users WHERE sid = :sid");
    $stmt_time->bindParam(':sid', $user_sid);
    $stmt_time->execute();
    $user_time = $stmt_time->fetch(PDO::FETCH_ASSOC);

    if ($user_time && $user_time['token_creation_time']) {
        $token_creation_time_unix = strtotime($user_time['token_creation_time']);
    }
} catch (PDOException $e) {
    // Log the error but continue to allow code entry
    error_log("DB Time Fetch Error: " . $e->getMessage());
}

// Handle the code submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $submitted_code = trim($_POST['otp_code']); // Trim whitespace
    
    // Basic input validation
    if (empty($submitted_code) || !is_numeric($submitted_code) || strlen($submitted_code) != 6) {
        $error_message = "Please enter a 6-digit verification code.";
    } else {
        try {
            // 1. Get the correct code and creation time from the database
            $stmt = $conn->prepare("SELECT sid, username, role, verification_token, token_creation_time FROM users WHERE sid = :sid");
            $stmt->bindParam(':sid', $user_sid);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $correct_code = $user['verification_token'];
                $token_creation_time = strtotime($user['token_creation_time']); // Convert timestamp string to Unix time
                $current_time = time();

                // --- CRITICAL STEP 2: TIME EXPIRATION CHECK ---
                if (!$correct_code || ($current_time - $token_creation_time) > OTP_EXPIRATION_SECONDS) {
                    // Token has expired or was already cleared/null
                    $error_message = "This verification code has expired. Please request a new one.";
                    // Clear the expired token (good practice)
                    $stmt_clear = $conn->prepare("UPDATE users SET verification_token = NULL, token_creation_time = NULL WHERE sid = :sid");
                    $stmt_clear->execute([':sid' => $user_sid]);

                } else if ($submitted_code === $correct_code) {
                    // --- SUCCESS! Code is correct AND not expired ---
                    
                    // 1. Clear the token immediately (prevent replay)
                    $stmt_clear = $conn->prepare("UPDATE users SET verification_token = NULL, token_creation_time = NULL WHERE sid = :sid");
                    $stmt_clear->execute([':sid' => $user_sid]);

                    // 2. Log the user in securely (set final session)
                    session_regenerate_id(true); // Secure the session
                    $_SESSION['sid'] = $user['sid'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];

                    // 3. Clear the temporary 2FA session variables and cooldown timer
                    unset($_SESSION['2fa_user_sid']);
                    unset($_SESSION['2fa_email']);
                    unset($_SESSION['last_resend_time']);

                    // 4. Redirect to the correct dashboard (RBAC)
                    switch ($user['role']) {
                        case 'student':
                            header("Location: students.php");
                            break;
                        case 'professor':
                            header("Location: teacher_dashboard.php");
                            break;
                        case 'Registrar':
                            header("Location: registrar_dashboard.php");
                            break; 
                        case 'Programhead':
                            header("Location: programhead_dashboard.php");
                            break;
                        default:
                            header("Location: index.php");
                            break;
                    }
                    exit();

                } else {
                    // --- FAILURE ---
                    $error_message = "Invalid code. Please check your email and try again.";
                }
            } else {
                $error_message = "An internal error occurred. Please try logging in again.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error. Please try again.";
            error_log("2FA Code Submission DB Error: " . $e->getMessage());
        }
    }
}

// Calculate Resend Cooldown status
$cooldown_remaining = 0;
$last_resend_time = $_SESSION['last_resend_time'] ?? 0;
$time_since_resend = time() - $last_resend_time;

if ($last_resend_time > 0 && $time_since_resend < RESEND_COOLDOWN_SECONDS) {
    $cooldown_remaining = RESEND_COOLDOWN_SECONDS - $time_since_resend;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Identity</title>
    <link rel="stylesheet" href="main.css"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* --- Enhanced Styles Using Your Dashboard's Theme --- */
        :root{
            --nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f2f5f8;
            --white:#fff; --text-dark:#102a43; --text-light:#627d98;
            --accent-light:#4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);
            --critical:#cc3333; --success-green: #28a745;
        }
        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background-color: var(--bg-light);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .form-box { 
            max-width: 420px; 
            margin: 20px;
            padding: 32px; 
            border: 1px solid #e0e6ed; 
            border-radius: 12px; 
            background-color: var(--white); 
            text-align: center; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }
        .form-box h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 12px;
        }
        .form-box .icon {
            font-size: 2.5rem;
            color: var(--panel-blue);
            margin-bottom: 16px;
        }
        .instructions {
            font-size: 0.95rem;
            color: var(--text-light);
            margin-bottom: 16px;
            line-height: 1.5;
        }
        .instructions strong {
            color: var(--text-dark);
            font-weight: 600;
        }
        .timer-text {
            font-size: 0.9em;
            color: var(--text-light);
            margin-bottom: 24px;
        }
        .timer-text span {
            font-weight: 600;
            color: var(--critical);
        }
        
        .input-box {
            margin-bottom: 24px;
        }
        .input-box input {
            text-align: center;
            font-size: 2rem; 
            font-weight: 600;
            letter-spacing: 8px;
            padding: 12px;
            width: 100%;
            border: 2px solid #e0e6ed;
            border-radius: 8px;
            box-sizing: border-box; /* Important for padding */
        }
        .input-box input:focus {
            border-color: var(--panel-blue);
            box-shadow: 0 0 0 4px rgba(31, 110, 163, 0.1);
            outline: none;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            border-radius: 8px;
            background-color: var(--panel-blue);
            color: var(--white);
            transition: background-color 0.2s;
        }
        .btn:hover {
            background-color: var(--nav-dark);
        }
        
        /* Message Boxes from your theme */
        .message-box{
            padding:12px 15px; 
            border-radius:8px; 
            margin: 20px 0; 
            font-weight:500;
            text-align: left;
        }
        .error-message{
            background: #fde8e8; 
            color: var(--critical); 
            border: 1px solid var(--critical);
        }
        .success-message{
            background: #e6f7e6; 
            color: var(--success-green); 
            border: 1px solid var(--success-green);
        }

        .resend-container {
            margin-top: 24px;
            font-size: 0.9rem;
            color: var(--text-light);
        }
        .resend-link {
            color: var(--panel-blue);
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.3s;
        }
        .resend-link:hover {
            text-decoration: underline;
        }
        .resend-disabled {
            pointer-events: none;
            opacity: 0.6; 
            color: var(--text-light);
            text-decoration: none;
        }
        .resend-container .timer {
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .cancel-link {
            margin-top: 20px;
            display: inline-block;
            font-size: 0.85rem;
            color: var(--text-light);
            text-decoration: none;
        }
        .cancel-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="form-box">
        <div class="icon"><i class="fa-solid fa-shield-halved"></i></div>
        <h2>Verify Your Identity</h2>
        
        <?php if ($resend_message): ?>
            <div class="message-box success-message"><?= $resend_message; ?></div>
        <?php endif; ?>

        <p class="instructions">We sent a 6-digit verification code to 
            <strong><?= htmlspecialchars($user_email); ?></strong>. 
            Please enter the code below to log in.
        </p>
        <p class="timer-text">This code will expire in: <span id="code-timer">5:00</span></p>
        
        <form action="2fa_enter_code.php" method="POST">
            <div class="input-box">
                <input type="tel" name="otp_code" id="otp_code" required maxlength="6" pattern="\d{6}" title="Please enter a 6-digit number." autocomplete="off" placeholder="------">
            </div>
            
            <button type="submit" class="btn">Verify & Log In</button>
            
            <?php if ($error_message): ?>
                <div class="message-box error-message"><?= $error_message; ?></div>
            <?php endif; ?>
        </form>
        
        <div class="resend-container">
            <span id="resend-text">Didn't receive the code?</span>
            <a href="2fa_resend_code.php" id="resend-link" class="resend-link <?= ($cooldown_remaining > 0) ? 'resend-disabled' : ''; ?>">
                Resend Code
            </a>
            <span id="cooldown-timer" class="timer" style="display: <?= ($cooldown_remaining > 0) ? 'inline' : 'none'; ?>;">(wait <?= $cooldown_remaining; ?>s)</span>
        </div>
        
        <a href="index.php" class="cancel-link">Cancel and return to login</a>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const expirationTime = <?= OTP_EXPIRATION_SECONDS; ?>;
    const creationTime = <?= $token_creation_time_unix ?? 'null'; ?>;
    const codeTimerElement = document.getElementById('code-timer');
    const resendLink = document.getElementById('resend-link');
    const cooldownTimerElement = document.getElementById('cooldown-timer');
    const cooldownText = document.getElementById('resend-text');
    let cooldownRemaining = <?= $cooldown_remaining; ?>;
    const currentTime = Math.floor(Date.now() / 1000);
    
    // --- Code Expiration Timer ---
    if (creationTime !== null) {
        let remaining = expirationTime - (currentTime - creationTime);
        let timerInterval;

        function updateCodeTimer() {
            if (remaining <= 0) {
                clearInterval(timerInterval);
                codeTimerElement.textContent = 'EXPIRED';
                codeTimerElement.style.color = 'var(--critical)';
                // Disable the input field
                document.getElementById('otp_code').disabled = true;
                document.getElementById('otp_code').placeholder = 'EXPIRED';
                return;
            }
            
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            codeTimerElement.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
            
            remaining--;
        }

        timerInterval = setInterval(updateCodeTimer, 1000);
        updateCodeTimer(); // Run immediately
    }
    
    // --- Resend Cooldown Timer ---
    if (cooldownRemaining > 0) {
        let resendTimerInterval;
        
        function updateResendCooldown() {
            if (cooldownRemaining <= 0) {
                clearInterval(resendTimerInterval);
                resendLink.classList.remove('resend-disabled');
                cooldownTimerElement.style.display = 'none';
                cooldownText.textContent = "Didn't receive the code?";
                return;
            }

            resendLink.classList.add('resend-disabled');
            cooldownTimerElement.style.display = 'inline';
            cooldownTimerElement.textContent = `(wait ${cooldownRemaining}s)`;
            cooldownText.textContent = "You can resend in:";
            
            cooldownRemaining--;
        }
        
        resendTimerInterval = setInterval(updateResendCooldown, 1000);
        updateResendCooldown(); // Run immediately
    }

    // Focus on the input field for faster entry
    document.getElementById('otp_code').focus();
});
</script>
</body>
</html>