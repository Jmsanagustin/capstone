<?php
session_start();
include 'db.php'; // Include the database connection

// Load PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// IMPORTANT: Adjust these paths if your PHPMailer files are not in a 'phpmailer/src/' directory
require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';

// --- Helper Functions and Variables ---

// Function to generate a secure random token
function generateVerificationToken() {
    return bin2hex(random_bytes(32));
}

// Check for consent status in the URL
$consent_agreed_param = isset($_GET['consent']) && $_GET['consent'] === 'agreed';
$consent_declined_param = isset($_GET['consent']) && $_GET['consent'] === 'declined';

// Function to safely show the Signup Form (STAGE 3)
function showSignupForm($errorMessage = '') {
    // Collect all submitted values to pre-fill the form upon error
    $first_name = $_POST['first_name'] ?? '';
    $middle_name = $_POST['middle_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $role = $_POST['role'] ?? 'student';
    
    // ---
    // --- FIX: Corrected $_POST['program'] to $_POST['course']
    // ---     and fixed default logic. (This fix was already in your code)
    // ---
    $course = $_POST['course'] ?? 'BSIS'; // Default to BSIS
    if ($role === 'Registrar' || $role === 'Programhead') {
        $course = $_POST['course'] ?? 'N/A'; // Default to N/A for these roles
    }
    // --- END FIX ---
    
    // Ensure 'agreed' is passed to the next submission
    $action_url = 'signup.php?consent=agreed';

    // --- HTML for the Signup Form ---
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Sign Up</title>
        <link rel="stylesheet" href="signup.css">
        <style>
            .error-message { padding: 10px; margin-bottom: 20px; border: 1px solid #dc3545; background-color: #f8d7da; color: #721c24; border-radius: 5px; text-align: center; }
            .form-box select { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 5px; }
            /* This style is no longer needed */
            /* #course-selection-div.hidden { display: none; } */
        </style>
    </head>
    <body>

    <div class="wrapper">
        <div class="info-text">
            <h2>Welcome To DFCAMCLP!</h2>
            <p>Create your account to access.</p>
        </div>

        <div class="form-box">
            <h2>SignUp</h2>
            <?php if (!empty($errorMessage)) echo "<div class='error-message'>$errorMessage</div>"; ?>
            
            <form action="<?= $action_url; ?>" method="POST">
                <input type="hidden" name="privacy_consent" value="agreed"> 
                
                <div>
                <label for="role">Select Role:</label>
                <select id="role" name="role" required>
                    <option value="student" <?= ($role == 'student') ? 'selected' : ''; ?>>Student</option>
                    <option value="professor" <?= ($role == 'professor') ? 'selected' : ''; ?>>Professor</option>
                    <option value="Registrar" <?= ($role == 'Registrar') ? 'selected' : ''; ?>>Registrar</option>
                    <option value="Programhead" <?= ($role == 'Programhead') ? 'selected' : ''; ?>>Programhead</option>
                </select><br><br>
                </div>

                <div> 
                <label for="course">Select Course:</label>
                <select id="course" name="course" required> 
                    <option class="program-option" value="BSIS" <?= ($course == 'BSIS') ? 'selected' : ''; ?>>BS Information Systems</option>
                    <option class="program-option" value="BSCPE" <?= ($course == 'BSCPE') ? 'selected' : ''; ?>>BS Computer Science</option>
                    
                    <option class="admin-option" value="N/A" <?= ($course == 'N/A') ? 'selected' : ''; ?>>N/A (All Programs)</option>
                </select><br><br>
                </div>
                
                <div class="input-box">
                    <input type="text" name="first_name" value="<?= htmlspecialchars($first_name); ?>" required>
                    <label for="first_name">First Name</label>
                </div>
                
                <div class="input-box">
                    <input type="text" name="middle_name" value="<?= htmlspecialchars($middle_name); ?>" required>
                    <label for="middle_name">Middle Name </label>
                </div>
                
                <div class="input-box">
                    <input type="text" name="last_name" value="<?= htmlspecialchars($last_name); ?>" required>
                    <label for="last_name">Last Name</label>
                </div>
                
                <div class="input-box">
                    <input type="text" name="username" value="<?= htmlspecialchars($username); ?>" required>
                    <label for="username">Username</label>
                </div>

                <div class="input-box">
                    <input type="email" name="email" value="<?= htmlspecialchars($email); ?>" required>
                    <label for="email">Email</label>
                </div>

                <div class="input-box">
                    <input type="password" name="password" required>
                    <label for="password">Password</label>
                </div>

                <button type="submit" class="btn">Sign Up</button>

                <p>Already have an account? <a href="index.php">Login</a></p>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const roleDropdown = document.getElementById('role');
            const courseSelect = document.getElementById('course');
            // Selects options by their new class names
            const programOptions = courseSelect.querySelectorAll('.program-option');
            const adminOptions = courseSelect.querySelectorAll('.admin-option');

            // Get the initial PHP-set role (for page load)
            const initialRole = '<?= $role; ?>';
            const initialCourse = '<?= $course; ?>';

            function toggleCourseOptions() {
                const selectedRole = roleDropdown.value;
                var i; // Loop counter

                if (selectedRole === 'Registrar' || selectedRole === 'Programhead') {
                    // Hide program options, show admin options
                    for (i = 0; i < programOptions.length; i++) {
                        programOptions[i].hidden = true;
                        programOptions[i].disabled = true;
                    }
                    for (i = 0; i < adminOptions.length; i++) {
                        adminOptions[i].hidden = false;
                        adminOptions[i].disabled = false;
                    }
                    
                    // Handle which value is selected
                    if (initialRole === 'Registrar' || initialRole === 'Programhead') {
                        courseSelect.value = initialCourse; // 'N/A'
                    } else {
                        courseSelect.value = 'N/A'; // Default to N/A when switching TO admin
                    }

                } else { // Student or Professor
                    // Show program options, hide admin options
                    for (i = 0; i < programOptions.length; i++) {
                        programOptions[i].hidden = false;
                        programOptions[i].disabled = false;
                    }
                    for (i = 0; i < adminOptions.length; i++) {
                        adminOptions[i].hidden = true;
                        adminOptions[i].disabled = true;
                    }

                    // Handle which value is selected
                    if (initialRole === 'student' || initialRole === 'professor') {
                         courseSelect.value = initialCourse; // 'BSIS' or 'BSCPE'
                    } else {
                         courseSelect.value = 'BSIS'; // Default to first program when switching TO student
                    }
                }
            }

            // Run the function when the role changes
            roleDropdown.addEventListener('change', toggleCourseOptions);
            
            // Run the function on page load to set the correct initial state
            toggleCourseOptions();
        });
    </script>
    </body>
    </html>
    <?php
    exit(); 
}


// --------------------------------------------------------------------------------------
// STAGE 2: HANDLE FORM SUBMISSION, TRANSACTION, AND EMAIL SENDING (***FIXED***)
// --------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['privacy_consent']) && $_POST['privacy_consent'] === 'agreed') {
    
    // --- Collect and Validate Data ---
    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $role = $_POST['role'];
    
    // --- This logic is correct for your HTML ---
    $program = $_POST['course']; // This will be 'BSIS', 'BSCPE', or 'N/A'
    if ($program === 'N/A') {
        $program = null; // Convert to NULL for the database
    }
    
    // 1. Validation Checks (Email/Username Uniqueness)
    $stmt_check_user = $conn->prepare("SELECT email FROM users WHERE email = :email OR username = :username");
    $stmt_check_user->bindParam(':email', $email);
    $stmt_check_user->bindParam(':username', $username);
    $stmt_check_user->execute();

    if ($stmt_check_user->rowCount() > 0) {
        showSignupForm("Email or Username already exists. Please use another.");
    } 
    
    // 2. Prepare Data
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // --- FIX: Logic to handle auto-verification for admin roles ---
    $is_verified = 0; 
    $verification_token = null; 

    if ($role === 'Registrar' || $role === 'Programhead') {
        // Admin roles are pre-verified
        $is_verified = 1;
        $verification_token = null; 
    } else {
        // Student/Professor roles need email verification
        $is_verified = 0;
        $verification_token = generateVerificationToken(); 
    }
    // --- END FIX ---

    // 3. Insert User (Transactional Logic starts here)
    try {
        // Start Transaction
        $conn->beginTransaction();

        // 3a. INSERT INTO USERS
        $stmt_user = $conn->prepare("INSERT INTO users 
            (username, email, password, role, is_verified, verification_token) 
            VALUES (:username, :email, :password, :role, :is_verified, :token)");
        
        $stmt_user->bindParam(':username', $username);
        $stmt_user->bindParam(':email', $email);
        $stmt_user->bindParam(':password', $hashed_password);
        $stmt_user->bindParam(':role', $role);
        // --- FIX: Bind the new dynamic variables ---
        $stmt_user->bindParam(':is_verified', $is_verified);
        $stmt_user->bindParam(':token', $verification_token);
        // --- END FIX ---
        $stmt_user->execute();

        // Get the ID of the newly inserted user
        $new_sid = $conn->lastInsertId();

        // 3b. INSERT INTO user_profiles
        $stmt_profile = $conn->prepare("INSERT INTO user_profiles 
            (sid, first_name, middle_name, last_name, program) 
            VALUES (:sid, :first_name, :middle_name, :last_name, :program)");

        $stmt_profile->bindParam(':sid', $new_sid); 
        $stmt_profile->bindParam(':first_name', $first_name);
        $stmt_profile->bindParam(':middle_name', $middle_name);
        $stmt_profile->bindParam(':last_name', $last_name);
        
        // This now correctly binds $program (which is 'BSIS', 'BSCPE', or NULL)
        $stmt_profile->bindParam(':program', $program); 
        $stmt_profile->execute();
        
        // --- FIX: Conditional logic for email sending ---
        if ($is_verified == 0 && $verification_token != null) {
            // 4. Send Verification Email (Student/Professor)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $verification_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/verify.php?token=" . $verification_token;
            
            $mail = new PHPMailer(true);

            try {
                // Server settings (***MUST BE EDITED BY USER***)
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com'; 
                $mail->SMTPAuth   = true;
                
                // --- SECURITY RISK: Replace with a config file or environment variables ---
                $mail->Username   = 'johnmichaelsanagustin2@gmail.com'; 
                $mail->Password   = 'qgfqehpswgbmuwwn'; 
                // ---
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
                $mail->Port       = 465;

                // Recipients
                $mail->setFrom('noreply@dfcamclp.edu.ph', 'Smart Grade Monitoring System');
                $mail->addAddress($email, $first_name . ' ' . $last_name);

                // Content (HTML body is unchanged)
                $mail->isHTML(true); 
                $mail->Subject = 'SGMS Account Verification';
                $mail->Body    = "
                    <h2>Welcome to Smart Grade Monitoring System, $first_name!</h2>
                    <p>Thank you for registering. Please click the button below to 
                    <strong>verify your email address</strong> and activate your account.</p>
                    <table cellspacing='0' cellpadding='0' style='margin: 20px 0;'>
                        <tr>
                            <td align='center' bgcolor='#00aaff' style='border-radius: 5px;'>
                                <a href='$verification_link' target='_blank' style='padding: 10px 20px; border: 1px solid #00aaff; border-radius: 5px; font-family: Arial, sans-serif; font-size: 15px; color: #ffffff; text-decoration: none; display: inline-block;'>
                                    Verify My Email Address
                                </a>
                            </td>
                        </tr>
                    </table>
                    <p>If the button doesn't work, copy and paste this link into your browser:</p>
                    <p><a href='$verification_link'>$verification_link</a></p>
                    <p>If you did not sign up for this account, please ignore this email.</p>";
                
                $mail->send();
                
                // 5. Success (Email Sent): Commit the transaction AND redirect.
                $conn->commit();
                header("Location: signup_success.php");
                exit();

            } catch (Exception $e) {
                // --- CATCH BLOCK FOR PHPMailer ERROR ---
                $conn->rollBack(); 
                error_log("Mailer Error: " . $e->getMessage());
                showSignupForm("Account creation failed. The verification email could not be sent. Please contact administration.");
            }
        
        } else {
            // 5. Success (Pre-verified Admin): Commit the transaction AND redirect.
            // No email is needed for Registrar/Programhead.
            $conn->commit();
            
            // Set a session message and redirect to the login page.
            // (Make sure index.php has session_start() to read this message)
            $_SESSION['signup_message'] = "Account created. As an admin, your account is pre-verified. You may now log in.";
            header("Location: index.php?signup=verified");
            exit();
        }
        // --- END FIX ---

    } catch (PDOException $e) {
        // --- CATCH BLOCK FOR PDO (DATABASE) ERROR ---
        $conn->rollBack(); 
        error_log("Database Error: " . $e->getMessage());
        showSignupForm("Database Error: Could not create account. Please try again.");
    }
}

// --------------------------------------------------------------------------------------
// STAGE 1a: DECLINE MESSAGE PAGE (if ?consent=declined)
// --------------------------------------------------------------------------------------
if ($consent_declined_param) {
// (HTML is unchanged)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Refused</title>
    <link rel="stylesheet" href="signup.css">
    <meta http-equiv="refresh" content="15;url=index.php"> 
    <style>
        .message-box { max-width: 500px; margin: 100px auto; padding: 30px; border: 1px solid #dc3545; box-shadow: 0 0 15px rgba(220, 53, 69, 0.5); text-align: center; background-color: #fff; border-radius: 8px; }
        .message-box h2 { color: #dc3545; }
    </style>
</head>
<body>
<div class="message-box">
    <h2>Access Refused ❌</h2>
    <p>We respect your decision. You have **DECLINED** the Data Privacy Notice.</p>
    <p>Please note that the Smart Grade Monitoring System cannot create an account or process your data without your consent to the terms.</p>
    <p>You will be redirected to the Login page in 15 seconds.</p>
    <p>If you change your mind, you may try signing up again.</p>
</div>
</body>
</html>
<?php
exit(); 
}

// --------------------------------------------------------------------------------------
// STAGE 1b: CONSENT FORM (Default view)
// --------------------------------------------------------------------------------------
if (!$consent_agreed_param && $_SERVER['REQUEST_METHOD'] != 'POST') {
// (HTML is unchanged)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=devisl-scale=1.0">
<title>Privacy Consent Required</title>
<link rel="stylesheet" href="signup.css"> 
    <style>
        .privacy-box { max-width: 650px; margin: 50px auto; padding: 30px; border: 1px solid #007bff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); text-align: center; background-color: #f8f9fa; }
        .privacy-content { text-align: left; max-height: 350px; overflow-y: scroll; margin-bottom: 25px; border: 1px solid #dee2e6; padding: 20px; background-color: #ffffff; line-height: 1.6; font-size: 0.95em; }
        .btn-group { display: flex; justify-content: space-around; margin-top: 20px; }
        .btn-decline { background-color: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; }
    </style>
</head>
<body>
<div class="privacy-box">
<h2>Data Privacy Notice and Consent Form</h2>
<div class="privacy-content">
        <p style="font-weight: bold; font-size: 1.1em; color: #007bff;">SMART GRADE MONITORING SYSTEM</p>
        <p style="font-style: italic;">Dr. Filemon C. Aguilar Memorial College of Las Piñas – IT Campus</p>
        <h3>I. Compliance Statement</h3>
        <p>This Data Privacy Notice and Consent Form (the **“Notice”**) ...</p>
        <p style="text-align: center; margin-top: 25px; font-weight: bold; font-size: 1.2em; color: #28a745;">✅ CONSENT FORM</p>
        <p>I have read and fully understood the Smart Grade Monitoring System's Data Privacy Notice. ...</p>
        <p style="font-weight: bold;">By clicking "I Accept," I voluntarily give my consent ...</p>
</div>
    
    <p>By clicking "I Accept," you confirm you have read, understood, and consented to the Data Privacy Notice.</p>
    
    <div class="btn-group">
        <a href="signup.php?consent=agreed" class="btn">I Accept</a>
        <a href="signup.php?consent=declined" class="btn-decline">I Decline</a>
    </div>
</div>
</body>
</html>
<?php
} elseif ($consent_agreed_param) {
// 5. If consent is explicitly agreed via GET request, show the form
showSignupForm();
}
?>