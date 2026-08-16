<?php
// FILE: registrar_staff_management_logic.php
// PURPOSE: Registrar's global management of Professors, Program Heads, and Staff (UI/Controller).
// MODIFICATION: ACTIVATED send_credentials_email() for single account creation.
// Global variables $conn, $current_user_id, $message, $message_type are inherited.

// --- Load PHPMailer classes (Needed for 'Add Account') ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// *** CSRF PROTECTION: Generate token ***
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize local variables for this script's scope
$staff_list = []; 
$edit_data = null; 
$target_redirect = "registrar_academic_manager.php?view=staff_management";


// ----------------------------------------------------------
// --- GLOBAL POST HANDLERS (Add, Update, Remove) ---
// ----------------------------------------------------------

// --- POST HANDLER: Add New Staff/Faculty (FIXED: Email Sending Activated) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff_account'])) {
    
    // *** CSRF Check ***
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['temp_message'] = ['text' => 'Invalid session token. Please try again.', 'type' => 'error'];
        header("Location: " . $target_redirect);
        exit();
    }
    
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $username_input = trim($_POST['username']);
    $role = trim($_POST['role']);
    
    // Simple split for name storage
    $name_parts = explode(' ', $full_name, 2);
    $first_name = $name_parts[0];
    $last_name = $name_parts[1] ?? '';
    $middle_name = null;
    
    if (empty($first_name) || empty($last_name) || empty($email) || empty($username_input) || empty($role)) {
        $_SESSION['temp_message'] = ['text' => 'All fields are required to add a staff account.', 'type' => 'error'];
    } else {
        $username = $username_input;
        $temp_password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
        
        try {
            $conn->beginTransaction();
            
            // 1. Create the user account
            $stmt_user = $conn->prepare("
                INSERT INTO users (username, email, password, role, is_verified, must_change_password)
                VALUES (:username, :email, :password, :role, 1, 1)
            ");
            $stmt_user->execute([
                ':username' => $username,
                ':email' => $email,
                ':password' => $hashed_password,
                ':role' => $role
            ]);
            
            $new_sid = $conn->lastInsertId();
            
            // 2. Create the user profile
            $stmt_profile = $conn->prepare("
                INSERT INTO user_profiles (sid, student_id, first_name, last_name, middle_name, program)
                VALUES (:sid, :username, :first_name, :last_name, :middle_name, NULL)
            ");
            $stmt_profile->execute([
                ':sid' => $new_sid,
                ':username' => $username,
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':middle_name' => $middle_name
            ]);
            
            // 3. Send email with temporary password (ACTIVE CALL)
            send_credentials_email($email, $full_name, $username, $temp_password); 
            
            $conn->commit();
            $_SESSION['temp_message'] = ['text' => "Account for {$role} {$full_name} created successfully! Credentials emailed.", 'type' => 'success'];
            
        } catch (PDOException $e) {
            $conn->rollBack();
            if ($e->errorInfo[1] == 1062) { 
                $_SESSION['temp_message'] = ['text' => 'Error: A user with that Username or Email already exists.', 'type' => 'error'];
            } else {
                $_SESSION['temp_message'] = ['text' => 'Database error: ' . $e->getMessage(), 'type' => 'error'];
            }
        } catch (Exception $email_e) {
            // Note: If the account was created but email failed, show warning
            $_SESSION['temp_message'] = ['text' => "Warning: Account created, but email sending failed: " . $email_e->getMessage(), 'type' => 'warning'];
        } 
    }
    header("Location: " . $target_redirect);
    exit();
}

// --- POST HANDLER: Update Staff/Faculty (Middle name logic included) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_staff_account'])) {
    
    // *** CSRF Check ***
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['temp_message'] = ['text' => 'Invalid session token. Please try again.', 'type' => 'error'];
        header("Location: " . $target_redirect);
        exit();
    }
    
    $sid_to_update = filter_input(INPUT_POST, 'staff_id', FILTER_VALIDATE_INT);
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $username_input = trim($_POST['username']);
    $role = trim($_POST['role']);
    
    if (!empty($sid_to_update) && !empty($first_name) && !empty($last_name) && !empty($email) && !empty($username_input) && !empty($role)) {
        try {
            $conn->beginTransaction();
            
            // 1. Update users table
            $stmt_user = $conn->prepare("UPDATE users SET username = :username, email = :email, role = :role WHERE sid = :sid");
            $stmt_user->execute([':username' => $username_input, ':email' => $email, ':role' => $role, ':sid' => $sid_to_update]);
            
            // 2. Update user_profiles table 
            $stmt_profile = $conn->prepare("
                 UPDATE user_profiles 
                 SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, student_id = :username
                 WHERE sid = :sid
            ");
            $stmt_profile->execute([
                ':first_name' => $first_name, 
                ':middle_name' => ($middle_name === '' ? NULL : $middle_name),
                ':last_name' => $last_name, 
                ':username' => $username_input,
                ':sid' => $sid_to_update
            ]);
            
            $conn->commit();
            $_SESSION['temp_message'] = ['text' => 'Account details updated successfully!', 'type' => 'success'];
            
        } catch (PDOException $e) {
            $conn->rollBack();
            if ($e->errorInfo[1] == 1062) {
                $_SESSION['temp_message'] = ['text' => 'Error: That Username or Email is already taken by another user.', 'type' => 'error'];
            } else {
                $_SESSION['temp_message'] = ['text' => 'Database error: ' . $e->getMessage(), 'type' => 'error'];
            }
        }
    } else {
        $_SESSION['temp_message'] = ['text' => 'All required fields are needed to update an account.', 'type' => 'error'];
    }
    header("Location: " . $target_redirect);
    exit();
}

// --- POST HANDLER: Remove Staff/Faculty ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_staff_account'])) {
    
    // *** CSRF Check ***
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['temp_message'] = ['text' => 'Invalid session token. Please try again.', 'type' => 'error'];
        header("Location: " . $target_redirect);
        exit();
    }
    
    $sid_to_remove = filter_input(INPUT_POST, 'staff_id', FILTER_VALIDATE_INT);
    
    // Prevent self-deletion
    if (!empty($sid_to_remove) && $sid_to_remove != $current_user_id) { 
        try {
            // Deleting from 'users' (role != student)
            $stmt_delete = $conn->prepare("DELETE FROM users WHERE sid = :sid AND role != 'student'");
            $stmt_delete->execute([':sid' => $sid_to_remove]);
            
            if ($stmt_delete->rowCount() > 0) {
                $_SESSION['temp_message'] = ['text' => 'Account removed successfully.', 'type' => 'success'];
            } else {
                $_SESSION['temp_message'] = ['text' => 'Error: Could not find account to remove.', 'type' => 'error'];
            }
            
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1451) { 
                 $_SESSION['temp_message'] = ['text' => 'Error: Cannot remove this account. It is still linked to classes or records (foreign key violation).', 'type' => 'error'];
            } else {
                 $_SESSION['temp_message'] = ['text' => 'Database error: ' . $e->getMessage(), 'type' => 'error'];
            }
        }
    } else {
        $_SESSION['temp_message'] = ['text' => 'Invalid account ID or cannot remove your own account.', 'type' => 'error'];
    }
    header("Location: " . $target_redirect);
    exit();
}


// --- GET HANDLER: Populate Edit Form (MODIFIED to include middle_name) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    
    $sid_to_edit = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($sid_to_edit) { 
        $stmt_edit = $conn->prepare("
             SELECT u.sid, u.username, u.email, u.role, up.first_name, up.middle_name, up.last_name
             FROM users u
             JOIN user_profiles up ON u.sid = up.sid
             WHERE u.sid = :sid AND u.role != 'student'
          ");
        $stmt_edit->execute([':sid' => $sid_to_edit]);
        $edit_data = $stmt_edit->fetch(PDO::FETCH_ASSOC);
        
        if (!$edit_data) {
             $_SESSION['temp_message'] = ['text' => 'Error: No staff/faculty found with that ID.', 'type' => 'error'];
             header("Location: " . $target_redirect);
             exit();
        }
    }
}


// ----------------------------------------------------------
// --- DATA FETCHING (FOR UI DISPLAY) ---
// ----------------------------------------------------------
try {
    // Fetch ALL non-student accounts, EXCLUDING the current user (Registrar)
    $stmt_staff = $conn->prepare("
         SELECT 
             u.sid AS id,
             u.username,
             u.email,
             u.role,
             up.first_name,
             up.last_name,
             (SELECT GROUP_CONCAT(DISTINCT s.subject_code SEPARATOR ', ')
              FROM classes c
              JOIN subject s ON c.subject_id = s.subject_id
              WHERE c.professor_sid = u.sid 
             ) AS subjects_taught
         FROM users u
         JOIN user_profiles up ON u.sid = up.sid
         WHERE u.role != 'student' AND u.sid != :current_user_id
         ORDER BY up.last_name, u.role
    ");
    $stmt_staff->execute([':current_user_id' => $current_user_id]);
    $staff_list = $stmt_staff->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    if (empty($db_error)) {
        $db_error = "Database error fetching staff list: " . $e->getMessage();
    }
}


// ----------------------------------------------------------
// --- UI RENDERING (Output captured to $staff_management_ui) ---
// ----------------------------------------------------------

// Start output buffering to capture the UI HTML
ob_start();
?>

<?php if ($edit_data): ?>
    <h3 style="color: var(--panel-blue);"><i class="fa-solid fa-user-pen"></i> Edit Account (<?php echo htmlspecialchars($edit_data['first_name'] . ' ' . ($edit_data['middle_name'] ?? '') . ' ' . $edit_data['last_name']); ?>)</h3>
    <form 
        action="registrar_academic_manager.php?view=staff_management" 
        method="POST"
        style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; background: var(--bg-light); padding: 20px; border-radius: 8px; margin-bottom: 20px;"
    >
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="update_staff_account" value="1">
        <input type="hidden" name="staff_id" value="<?php echo $edit_data['sid']; ?>">
        
        <div>
            <label for="edit_first_name" style="font-weight: 600; display: block; margin-bottom: 5px;">First Name:</label>
            <input type="text" name="first_name" id="edit_first_name" value="<?php echo htmlspecialchars($edit_data['first_name']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        </div>
        
        <div> 
            <label for="edit_middle_name" style="font-weight: 600; display: block; margin-bottom: 5px;">Middle Name (Optional):</label>
            <input type="text" name="middle_name" id="edit_middle_name" value="<?php echo htmlspecialchars($edit_data['middle_name'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        </div>
        
        <div>
            <label for="edit_last_name" style="font-weight: 600; display: block; margin-bottom: 5px;">Last Name:</label>
            <input type="text" name="last_name" id="edit_last_name" value="<?php echo htmlspecialchars($edit_data['last_name']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        </div>
        
        <div style="grid-column: 1 / 2;">
            <label for="edit_username" style="font-weight: 600; display: block; margin-bottom: 5px;">Username/ID:</label>
            <input type="text" name="username" id="edit_username" value="<?php echo htmlspecialchars($edit_data['username']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        </div>
        <div>
            <label for="edit_email" style="font-weight: 600; display: block; margin-bottom: 5px;">Email:</label>
            <input type="email" name="email" id="edit_email" value="<?php echo htmlspecialchars($edit_data['email']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        </div>
        <div>
            <label for="edit_role" style="font-weight: 600; display: block; margin-bottom: 5px;">Role:</label>
            <select name="role" id="edit_role" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                <option value="professor" <?= ($edit_data['role'] == 'professor') ? 'selected' : ''; ?>>Professor</option>
                <option value="Programhead" <?= ($edit_data['role'] == 'Programhead') ? 'selected' : ''; ?>>Program Head</option>
                <option value="Staff" <?= ($edit_data['role'] == 'Staff') ? 'selected' : ''; ?>>Staff (Registrar/Admissions)</option>
            </select>
        </div>
        
        <div style="grid-column: 1 / -1; text-align: right; display: flex; gap: 10px; justify-content: flex-end; padding-top: 10px;">
            <a href="registrar_academic_manager.php?view=staff_management" class="reject-btn" style="text-decoration: none; padding: 10px 20px;"><i class="fa-solid fa-times"></i> Cancel</a>
            <button type="submit" name="update_staff_account" class="approve-btn" style="background-color: var(--success-green);">
                <i class="fa-solid fa-save"></i> Save Changes
            </button>
        </div>
    </form>
<?php endif; ?>


<div class="group-header" onclick="toggleDirectory('single-add-staff-section')">
    <span><i class="fa-solid fa-user-plus"></i> Add New Staff/Faculty Account (Single)</span>
    <i id="single-add-staff-section-icon" class="fa-solid fa-chevron-right" style="float: right;"></i>
</div>
<div id="single-add-staff-section" class="group-content" style="display: none; padding: 20px; margin-bottom: 30px;">
    <p class="description">
        Create a new account for faculty, program heads, or general staff. Their Username/ID will be used for login.
    </p>
    <form 
        action="registrar_academic_manager.php?view=staff_management" 
        method="POST"
        style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;"
    >
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="add_staff_account" value="1">
        
        <div>
            <label for="new_full_name" style="font-weight: 600; display: block; margin-bottom: 5px;">Full Name (First Last):</label>
            <input type="text" name="full_name" id="new_full_name" placeholder="e.g., Jane Doe" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        </div>
        <div>
            <label for="new_username" style="font-weight: 600; display: block; margin-bottom: 5px;">Username/ID:</label>
            <input type="text" name="username" id="new_username" placeholder="e.g., prof.jane.d" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        </div>
        <div>
            <label for="new_email" style="font-weight: 600; display: block; margin-bottom: 5px;">Email:</label>
            <input type="email" name="email" id="new_email" placeholder="e.g., j.doe@school.edu" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        </div>
        
        <div style="grid-column: 1 / 2;">
            <label for="new_role" style="font-weight: 600; display: block; margin-bottom: 5px;">Role:</label>
            <select name="role" id="new_role" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
                <option value="professor">Professor</option>
                <option value="Programhead">Program Head</option>
                <option value="Staff">Staff (Registrar/Admissions)</option>
            </select>
        </div>

        <div style="grid-column: 3 / 4; text-align: right; align-self: end;">
            <button type="submit" class="approve-btn" style="background-color: var(--panel-blue); padding: 10px 20px;">
                <i class="fa-solid fa-plus"></i> Create Account
            </button>
        </div>
    </form>
</div>


<h3 style="color: var(--panel-blue); margin-top: 10px;"><i class="fa-solid fa-users"></i> Staff & Faculty Directory</h3>
<?php if (!empty($staff_list)): ?>
<div class="table-container">
    <table class="review-table">
        <thead>
            <tr>
                <th>Full Name</th>
                <th>Username/ID</th> 
                <th>Role</th>
                <th>Email</th>
                <th>Subjects Taught</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($staff_list as $staff): ?>
        <tr>
            <td><strong><?= htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?></strong></td>
            <td><?= htmlspecialchars($staff['username'] ?? 'N/A'); ?></td>
            <td><span style="text-transform: capitalize; font-weight: 600; color: var(--text-dark);"><?= htmlspecialchars($staff['role']); ?></span></td>
            <td><?= htmlspecialchars($staff['email']); ?></td>
            <td><?= htmlspecialchars($staff['subjects_taught'] ?? 'None'); ?></td>
            <td class="action-buttons" style="display: flex; gap: 5px; flex-wrap: wrap;">
                <a href="registrar_academic_manager.php?view=staff_management&action=edit&id=<?= $staff['id']; ?>" class="approve-btn" style="background-color: var(--success-green); text-decoration: none;">
                    <i class="fa-solid fa-pen"></i> Edit
                </a>
                
                <form action="registrar_academic_manager.php?view=staff_management" method="POST" onsubmit="return confirm('WARNING: Are you sure you want to PERMANENTLY remove this account? This action cannot be undone.');" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="remove_staff_account" value="1">
                    <input type="hidden" name="staff_id" value="<?= $staff['id']; ?>">
                    <button type="submit" class="reject-btn" title="Remove Account">
                        <i class="fa-solid fa-trash"></i> Remove
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
    <p class="no-data">No staff or faculty accounts found.</p>
<?php endif; ?>

<?php
$staff_management_ui = ob_get_clean();
?>