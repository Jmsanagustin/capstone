<?php
// FILE: ph_faculty.php (Faculty Management View)
// VERSION 5: Employee ID is now the username.

// 1. START ALL PHP PROCESSING FIRST
session_start();
include 'db.php'; 
include 'email_config.php'; // Required for emailing new faculty

// --- Load PHPMailer classes ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
// --- End PHPMailer includes ---


// --- Authorization Check ---
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
    header("Location: index.php"); 
    exit();
}

// Get Program Head's info (set during login)
$program_head_sid = $_SESSION['sid'] ?? 0;

// Initialize variables
$faculty_list = [];
$edit_data = null; // Holds faculty data when in "edit" mode
$message = '';
$message_type = '';

// Check for critical config error
if (empty($program_head_sid)) {
    $message = "Your user account ID is missing. Please log out and log back in.";
    $message_type = 'error';
    $db_error = $message;
}

// --- POST HANDLER: Add New Faculty ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_faculty'])) {
    
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $employee_id = trim($_POST['employee_id']);
    
    // *** MODIFIED: Username is the Employee ID ***
    $username = $employee_id; 
    
    // Generate temporary password
    $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
    
    // *** MODIFIED: Removed username from validation ***
    if (!empty($first_name) && !empty($last_name) && !empty($email) && !empty($employee_id)) {
        try {
            $conn->beginTransaction();
            
            // 1. Create the user account
            $stmt_user = $conn->prepare("
                INSERT INTO users (username, email, password, role, is_verified, must_change_password)
                VALUES (:username, :email, :password, 'professor', 1, 1)
            ");
            $stmt_user->execute([
                ':username' => $username, // Username is Employee ID
                ':email' => $email,
                ':password' => $hashed_password
            ]);
            
            // Get the new user's SID
            $new_sid = $conn->lastInsertId();
            
            // 2. Create the user profile
            $stmt_profile = $conn->prepare("
                INSERT INTO user_profiles (sid, student_id, first_name, last_name, program)
                VALUES (:sid, :employee_id, :first_name, :last_name, NULL)
            ");
            $stmt_profile->execute([
                ':sid' => $new_sid,
                ':employee_id' => $employee_id,
                ':first_name' => $first_name,
                ':last_name' => $last_name
            ]);
            
            // 3. Send email with temporary password
            $full_name = $first_name . ' ' . $last_name;
            send_credentials_email($email, $full_name, $username, $temp_password); // Send Employee ID as username
            
            $conn->commit();
            $_SESSION['temp_message'] = ['text' => 'Professor added successfully! Credentials have been emailed.', 'type' => 'success'];
            
        } catch (PDOException $e) {
            $conn->rollBack();
            if ($e->errorInfo[1] == 1062) { // Duplicate entry
                $_SESSION['temp_message'] = ['text' => 'Error: A user with that Username (Employee ID) or Email already exists.', 'type' => 'error'];
            } else {
                $_SESSION['temp_message'] = ['text' => 'Database error: ' . $e->getMessage(), 'type' => 'error'];
            }
        } catch (Exception $email_e) {
            $conn->rollBack();
            $_SESSION['temp_message'] = ['text' => 'Professor account was NOT created. The email server failed: ' . $email_e->getMessage(), 'type' => 'error'];
        }
    } else {
        $_SESSION['temp_message'] = ['text' => 'All fields are required to add a professor.', 'type' => 'error'];
    }
    header("Location: ph_faculty.php");
    exit();
}
// --- END ADD HANDLER ---


// --- POST HANDLER: Update Faculty ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_faculty'])) {
    
    $sid_to_update = filter_input(INPUT_POST, 'faculty_id', FILTER_VALIDATE_INT);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $employee_id = trim($_POST['employee_id']);
    
    // *** MODIFIED: Username is the Employee ID ***
    $username = $employee_id;
    
    // *** MODIFIED: Removed username from validation ***
    if (!empty($sid_to_update) && !empty($first_name) && !empty($last_name) && !empty($email) && !empty($employee_id)) {
        try {
            $conn->beginTransaction();
            
            // Update users table
            $stmt_user = $conn->prepare("UPDATE users SET username = :username, email = :email WHERE sid = :sid AND role = 'professor'");
            $stmt_user->execute([':username' => $username, ':email' => $email, ':sid' => $sid_to_update]);
            
            // Update user_profiles table
            $stmt_profile = $conn->prepare("
                UPDATE user_profiles 
                SET first_name = :first_name, last_name = :last_name, student_id = :employee_id
                WHERE sid = :sid
            ");
            $stmt_profile->execute([
                ':first_name' => $first_name, 
                ':last_name' => $last_name, 
                ':employee_id' => $employee_id,
                ':sid' => $sid_to_update
            ]);
            
            $conn->commit();
            $_SESSION['temp_message'] = ['text' => 'Professor details updated successfully!', 'type' => 'success'];
            
        } catch (PDOException $e) {
            $conn->rollBack();
            if ($e->errorInfo[1] == 1062) {
                $_SESSION['temp_message'] = ['text' => 'Error: That Username (Employee ID) or Email is already taken by another user.', 'type' => 'error'];
            } else {
                $_SESSION['temp_message'] = ['text' => 'Database error: ' . $e->getMessage(), 'type' => 'error'];
            }
        }
    } else {
        $_SESSION['temp_message'] = ['text' => 'All fields are required to update a professor.', 'type' => 'error'];
    }
    header("Location: ph_faculty.php");
    exit();
}
// --- END UPDATE HANDLER ---


// --- POST HANDLER: Remove Faculty ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_faculty'])) {
    
    $sid_to_remove = filter_input(INPUT_POST, 'faculty_id', FILTER_VALIDATE_INT);
    
    if (!empty($sid_to_remove)) {
        try {
            // Deleting from 'users' will cascade to 'user_profiles'
            $stmt_delete = $conn->prepare("DELETE FROM users WHERE sid = :sid AND role = 'professor'");
            $stmt_delete->execute([':sid' => $sid_to_remove]);
            
            if ($stmt_delete->rowCount() > 0) {
                $_SESSION['temp_message'] = ['text' => 'Professor account removed successfully.', 'type' => 'success'];
            } else {
                $_SESSION['temp_message'] = ['text' => 'Error: Could not find professor to remove.', 'type' => 'error'];
            }
            
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1451) { 
                 $_SESSION['temp_message'] = ['text' => 'Error: Cannot remove this professor. They are still assigned to one or more classes.', 'type' => 'error'];
            } else {
                 $_SESSION['temp_message'] = ['text' => 'Database error: ' . $e->getMessage(), 'type' => 'error'];
            }
        }
    } else {
        $_SESSION['temp_message'] = ['text' => 'Invalid faculty ID.', 'type' => 'error'];
    }
    header("Location: ph_faculty.php");
    exit();
}
// --- END REMOVE HANDLER ---


// --- GET HANDLER: Populate Edit Form ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    
    $sid_to_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($sid_to_edit && empty($db_error)) {
        // *** MODIFIED: Removed u.username, as it's the same as employee_id ***
        $stmt_edit = $conn->prepare("
            SELECT u.sid, u.email, up.first_name, up.last_name, up.student_id AS employee_id
            FROM users u
            JOIN user_profiles up ON u.sid = up.sid
            WHERE u.sid = :sid AND u.role = 'professor'
        ");
        $stmt_edit->execute([':sid' => $sid_to_edit]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_data) {
            $message = 'Error: No professor found with that ID.';
            $message_type = 'error';
        }
    }
}
// --- END GET HANDLER ---


// 2. NOW, INCLUDE THE HEADER AND RENDER THE PAGE
$current_view = 'faculty';
$page_title = 'Faculty Management';
include_once 'ph_header.php'; // Prints HTML

// Load temporary status message from session
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}

// 3. FETCH DATA for the table
try {
    if (empty($db_error)) {
        // *** MODIFIED: Removed u.username from SELECT and GROUP BY ***
        $stmt_faculty = $conn->prepare("
            SELECT 
                u.sid AS id, 
                up.student_id AS employee_id,
                CONCAT(up.first_name, ' ', up.last_name) AS name, 
                u.email,
                (SELECT GROUP_CONCAT(DISTINCT s.subject_code SEPARATOR ', ')
                 FROM classes c
                 JOIN subject s ON c.subject_id = s.subject_id
                 WHERE c.professor_sid = u.sid AND s.program_head_id = :ph_id
                ) AS subjects_in_my_program
            FROM users u
            JOIN user_profiles up ON u.sid = up.sid
            WHERE u.role = 'professor'
            GROUP BY u.sid, up.student_id, up.first_name, up.last_name, u.email
            ORDER BY up.last_name
        ");
        $stmt_faculty->execute([
            ':ph_id' => $program_head_sid
        ]);
        $faculty_list = $stmt_faculty->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    if (empty($message)) { // Only show if no other message is set
        $message = "Database error fetching faculty list: " . $e->getMessage();
        $message_type = 'error';
    }
}
?>

<!-- 4. RENDER HTML CONTENT -->

<?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>">
        <?php echo $message; // Use raw output for bold tags ?>
    </div>
<?php endif; ?>


<!-- --- ADD/EDIT FORM SECTION --- -->
<section class="content-section mb-8">
    
    <?php if ($edit_data): ?>
        <!-- ### EDIT FORM ### -->
        <h2><i class="fa-solid fa-user-pen"></i> Edit Professor (<?php echo htmlspecialchars($edit_data['first_name'] . ' ' . $edit_data['last_name']); ?>)</h2>
        <form 
            action="ph_faculty.php" 
            method="POST"
            style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: var(--bg-light); padding: 20px; border-radius: 8px;"
        >
            <input type="hidden" name="faculty_id" value="<?php echo $edit_data['sid']; ?>">
            
            <div>
                <label for="first_name" style="font-weight: 600; display: block; margin-bottom: 5px;">First Name:</label>
                <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($edit_data['first_name']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>
            <div>
                <label for="last_name" style="font-weight: 600; display: block; margin-bottom: 5px;">Last Name:</label>
                <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($edit_data['last_name']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>
            
            <!-- *** MODIFIED: Form Layout (Username removed) *** -->
            <div style="grid-column: 1 / -1;">
                <label for="employee_id" style="font-weight: 600; display: block; margin-bottom: 5px;">Employee ID (This is their username):</label>
                <input type="text" name="employee_id" id="employee_id" value="<?php echo htmlspecialchars($edit_data['employee_id']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>
            <div style="grid-column: 1 / -1;">
                <label for="email" style="font-weight: 600; display: block; margin-bottom: 5px;">Email:</label>
                <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($edit_data['email']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>
            
            <div style="grid-column: 1 / -1; text-align: right; display: flex; gap: 10px; justify-content: flex-end;">
                <a href="ph_faculty.php" class="reject-btn" style="text-decoration: none; padding: 10px 20px;"><i class="fa-solid fa-times"></i> Cancel</a>
                <button type="submit" name="update_faculty" class="approve-btn" style="background-color: var(--success-green);">
                    <i class="fa-solid fa-save"></i> Save Changes
                </button>
            </div>
        </form>

    <?php elseif (empty($db_error)): ?>
        <!-- ### ADD FORM ### -->
        <h2><i class="fa-solid fa-user-plus"></i> Add New Professor</h2>
        <p class="description">
            Create a new professor account. Their Employee ID will be their username.
            They will receive an email with their temporary password.
        </p>
        <form 
            action="ph_faculty.php" 
            method="POST"
            style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: var(--bg-light); padding: 20px; border-radius: 8px;"
        >
            <div>
                <label for="first_name" style="font-weight: 600; display: block; margin-bottom: 5px;">First Name:</label>
                <input type="text" name="first_name" id="first_name" placeholder="e.g., John" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>
            <div>
                <label for="last_name" style="font-weight: 600; display: block; margin-bottom: 5px;">Last Name:</label>
                <input type="text" name="last_name" id="last_name" placeholder="e.g., Smith" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>

            <!-- *** MODIFIED: Form Layout (Username removed) *** -->
            <div style="grid-column: 1 / -1;">
                <label for="employee_id" style="font-weight: 600; display: block; margin-bottom: 5px;">Employee ID (This will be their username):</label>
                <input type="text" name="employee_id" id="employee_id" placeholder="e.g., 2024-1001" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>
            <div style="grid-column: 1 / -1;">
                <label for="email" style="font-weight: 600; display: block; margin-bottom: 5px;">Email:</label>
                <input type="email" name="email" id="email" placeholder="e.g., j.smith@school.edu" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
            </div>

            <div style="grid-column: 1 / -1; text-align: right;">
                <button type="submit" name="add_faculty" class="approve-btn" style="background-color: var(--panel-blue);">
                    <i class="fa-solid fa-plus"></i> Add Professor
                </button>
            </div>
        </form>
    <?php endif; ?>
</section>


<!-- --- FACULTY LIST SECTION --- -->
<section class="content-section">
   <h2><i class="fa-solid fa-users"></i> Faculty Directory</h2>
   <p class="description">View and manage all professors in the system. You can assign any professor to your classes.</p>
   <div class="table-container">
        <?php if (!empty($faculty_list)): ?>
        <table class="review-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Employee ID / Username</th> <!-- <-- MODIFIED -->
                    <th>Email</th>
                    <th>Subjects (Your Dept)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($faculty_list as $faculty): ?>
            <tr>
                <td><strong><?= htmlspecialchars($faculty['name']); ?></strong></td>
                <td><?= htmlspecialchars($faculty['employee_id'] ?? 'N/A'); ?></td>
                <td><?= htmlspecialchars($faculty['email']); ?></td>
                <td><?= htmlspecialchars($faculty['subjects_in_my_program'] ?? 'N/A'); ?></td>
                <td class="action-buttons" style="display: flex; gap: 5px; flex-wrap: wrap;">
                    <!-- Edit Button -->
                    <a href="ph_faculty.php?action=edit&id=<?= $faculty['id']; ?>" class="approve-btn" style="background-color: var(--success-green); text-decoration: none;">
                        <i class="fa-solid fa-pen"></i> Edit
                    </a>
                    
                    <!-- Remove Button -->
                    <form action="ph_faculty.php" method="POST" onsubmit="return confirm('Are you sure you want to PERMANENTLY remove this professor? This action cannot be undone.');" style="display:inline;">
                        <input type="hidden" name="faculty_id" value="<?= $faculty['id']; ?>">
                        <button type="submit" name="remove_faculty" class="reject-btn" title="Remove Professor">
                            <i class="fa-solid fa-trash"></i> Remove
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php elseif(empty($db_error)): ?>
            <p class="no-data">No faculty found. Use the form above to add one.</p>
        <?php endif; ?>
   </div>
</section>
<?php include 'ph_footer.php'; // Include the footer template ?>