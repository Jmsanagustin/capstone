<?php
// FILE: registrar_dashboard.php
// VERSION: FINAL - Modularized Router (Production Ready)

ob_start(); // Start output buffering.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Assume db.php exists and establishes $conn
require_once 'db.php'; 

// 1. ROLE-BASED ACCESS CONTROL
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Registrar'])) {
    header("Location: index.php");
    exit();
}
$current_user_id = $_SESSION['sid'];
$current_user_name = $_SESSION['username'] ?? 'Registrar';

// 2. GET CURRENT VIEW
$view = $_GET['view'] ?? 'dashboard';

// --- Initialize Variables (Must be defined before including header) ---
$message = '';
$message_type = '';
$db_error = '';
// RENAME: This variable will now hold ALL grades that require or have had Registrar action.
$grades_for_report_view = []; 
$pending_grades_for_registrar = [];
$total_students = 0;
$programs_count = 0;
$pending_final_acknowledgements = 0;
$student_records = [];
$all_sections = [];
$conversations = [];
$total_unread_messages = 0;
$selected_conversation_id = $_GET['convo_id'] ?? null;
$messages = [];
$other_user_name = 'Select Conversation';
$other_user_role = '';
$current_conversation_receiver_id = null;
$start_conversation_error = '';
$send_error = '';
$is_part_of_conversation = false;
$student_list = [];

// Check for temp session messages 
if (isset($_SESSION['temp_message'])) {
    $message = $_SESSION['temp_message']['text'];
    $message_type = $_SESSION['temp_message']['type'];
    unset($_SESSION['temp_message']);
}

// =================================================================================
// 3. PROCESSING LOGIC (Routes handled first)
// =================================================================================

// --- 3.1. Route to External Logic ---
if ($view === 'reports' && isset($_GET['export']) && $_GET['export'] == '1') {
    require 'registrar_reports.php';
}
if ($view === 'students' || (isset($_GET['download_template']) && $_GET['download_template'] == 1)) {
    require 'registrar_student_management.php';
    exit();
}
if (in_array($view, ['change_requests', 'batch_promotion', 'staff_management'])) {
    require 'registrar_academic_manager.php';
    exit();
}

// --- 3.2. Delete Conversation Logic (KEPT) ---
if ($view === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_conversation') {
    $conversation_id_to_delete = filter_input(INPUT_POST, 'conversation_id', FILTER_VALIDATE_INT);
    if ($conversation_id_to_delete) {
        try {
            $stmt_check = $conn->prepare("SELECT user_1_id FROM conversations WHERE conversation_id = :conv_id AND (user_1_id = :user_id OR user_2_id = :user_id)");
            $stmt_check->execute([':conv_id' => $conversation_id_to_delete, ':user_id' => $current_user_id]);
            $convo = $stmt_check->fetch();

            if ($convo) {
                if ($convo['user_1_id'] == $current_user_id) {
                    $sql_hide = "UPDATE conversations SET user_1_hidden = 1, updated_at = NOW() WHERE conversation_id = :conv_id";
                } else {
                    $sql_hide = "UPDATE conversations SET user_2_hidden = 1, updated_at = NOW() WHERE conversation_id = :conv_id";
                }
                $stmt_hide = $conn->prepare($sql_hide);
                $stmt_hide->execute([':conv_id' => $conversation_id_to_delete]);
            }
        } catch (PDOException $e) {
            $db_error = "Error hiding conversation: " . $e->getMessage();
        }
    }
    header("Location: registrar_dashboard.php?view=messages");
    exit();
}

// --- 3.3. Grade Acknowledgement (Bulk) (KEPT) ---
if (($view === 'dashboard' || $view === 'approval') && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'acknowledge_section') {
    $section_name = $_POST['section_name'] ?? '';

    if (!empty($section_name)) {
        try {
            $new_status = 'Acknowledged by Registrar';
            $stmt_update = $conn->prepare("
                UPDATE grades g
                JOIN users u ON g.student_id_string = u.username
                JOIN user_profiles up ON u.sid = up.sid
                SET g.status = :new_status, g.review_date = NOW()
                WHERE up.section = :section_name AND g.status = 'Approved by PH'
            ");
            $stmt_update->execute([':new_status' => $new_status, ':section_name' => $section_name]);

            $count = $stmt_update->rowCount();

            if ($count > 0) {
                $message = "Successfully acknowledged **{$count}** grades for Section: **{$section_name}**.";
                $message_type = 'success';
            } else {
                $message = "No pending grades found for Section: {$section_name} (or they were already acknowledged).";
                $message_type = 'warning';
            }
        } catch (PDOException $e) {
            $message = "Database error during bulk acknowledgement: " . $e->getMessage();
            $message_type = 'error';
            $db_error = $message;
        }
    } else {
        $message = "Invalid Section provided.";
        $message_type = 'error';
    }
}

// --- 3.4. New Conversation Logic (KEPT) ---
if ($view === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_conversation'])) {
    $recipient_id = (int)($_POST['recipient_sid'] ?? 0);

    if ($recipient_id > 0) {
        if ($recipient_id == $current_user_id) {
            $start_conversation_error = "You cannot start a conversation with yourself.";
        } else {
            try {
                $stmt_check_conv = $conn->prepare("SELECT conversation_id FROM conversations WHERE (user_1_id = :user1 AND user_2_id = :user2) OR (user_1_id = :user2 AND user_2_id = :user1)");
                $stmt_check_conv->execute([':user1' => $current_user_id, ':user2' => $recipient_id]);
                $existing_conversation = $stmt_check_conv->fetch(PDO::FETCH_ASSOC);

                if ($existing_conversation) {
                    header("Location: registrar_dashboard.php?view=messages&convo_id=" . $existing_conversation['conversation_id']);
                    exit();
                } else {
                    $stmt_create_conv = $conn->prepare("INSERT INTO conversations (user_1_id, user_2_id, updated_at) VALUES (:user1, :user2, NOW())");
                    $user1 = min($current_user_id, $recipient_id);
                    $user2 = max($current_user_id, $recipient_id);
                    $stmt_create_conv->execute([':user1' => $user1, ':user2' => $user2]);
                    $new_conversation_id = $conn->lastInsertId();
                    header("Location: registrar_dashboard.php?view=messages&convo_id=" . $new_conversation_id);
                    exit();
                }
            } catch (PDOException $e) {
                $start_conversation_error = "Database error: " . $e->getMessage();
                $db_error = $start_conversation_error;
            }
        }
    } else {
        $start_conversation_error = "Please select a valid user from the search suggestions.";
    }
}

// --- 3.5. Message Sending Logic (KEPT) ---
if ($view === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && $selected_conversation_id) {
    $body = trim($_POST['body'] ?? '');
    $receiver_id = filter_input(INPUT_POST, 'receiver_id', FILTER_VALIDATE_INT);

    if (!empty($body) && $receiver_id) {
        try {
            $conn->beginTransaction();
            $stmt_msg = $conn->prepare("
                INSERT INTO messages (conversation_id, sender_id, receiver_id, body, subject, sent_at)
                VALUES (:conv_id, :sender_id, :receiver_id, :body, :subject, NOW())
            ");
            $stmt_msg->execute([
                ':conv_id' => $selected_conversation_id,
                ':sender_id' => $current_user_id,
                ':receiver_id' => $receiver_id,
                ':body' => $body,
                ':subject' => ''
            ]);

            $stmt_conv = $conn->prepare("UPDATE conversations SET updated_at = NOW() WHERE conversation_id = :conv_id");
            $stmt_conv->execute([':conv_id' => $selected_conversation_id]);
            $conn->commit();
            header("Location: registrar_dashboard.php?view=messages&convo_id=" . $selected_conversation_id);
            exit();
        } catch (PDOException $e) {
            $conn->rollBack();
            $send_error = "Database error sending message: " . $e->getMessage();
            $db_error = $send_error;
        }
    } else {
        $send_error = "Message body cannot be empty or receiver is invalid.";
    }
}

// =================================================================================
// 4. DATA FETCHING (Common & View Specific)
// =================================================================================

try {
    // --- 4.1. Fetch Conversations (Common for unread counts) ---
    $stmt_conv_fetch = $conn->prepare("
        SELECT
            c.conversation_id, c.updated_at, c.user_1_id, c.user_2_id,
            up1.first_name as u1_first, up1.last_name as u1_last, u1.role as user_1_role,
            up2.first_name as u2_first, up2.last_name as u2_last, u2.role as user_2_role,
            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.conversation_id AND m.receiver_id = :current_user_id AND m.is_read = 0) AS unread_count
        FROM conversations c
        JOIN users u1 ON c.user_1_id = u1.sid
        JOIN users u2 ON c.user_2_id = u2.sid
        LEFT JOIN user_profiles up1 ON u1.sid = up1.sid
        LEFT JOIN user_profiles up2 ON u2.sid = up2.sid
        WHERE
            (
                (c.user_1_id = :current_user_id AND c.user_1_hidden = 0) OR
                (c.user_2_id = :current_user_id AND c.user_2_hidden = 0)
            )
        ORDER BY c.updated_at DESC
    ");
    $stmt_conv_fetch->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
    $stmt_conv_fetch->execute();
    $raw_conversations = $stmt_conv_fetch->fetchAll(PDO::FETCH_ASSOC);

    $conversations = [];
    $total_unread_messages = 0;
    foreach ($raw_conversations as $raw_conv) {
        $total_unread_messages += (int)$raw_conv['unread_count'];
        $other_user_id = ($raw_conv['user_1_id'] == $current_user_id) ? $raw_conv['user_2_id'] : $raw_conv['user_1_id'];

        $processed_conv = [
            'conversation_id' => $raw_conv['conversation_id'],
            'updated_at' => $raw_conv['updated_at'],
            'unread_count' => $raw_conv['unread_count'],
            'user_1_id' => $raw_conv['user_1_id'],
            'user_2_id' => $raw_conv['user_2_id']
        ];

        if ($other_user_id == $raw_conv['user_1_id']) {
            $processed_conv['other_full_name'] = trim($raw_conv['u1_first'] . ' ' . $raw_conv['u1_last']);
            $processed_conv['other_role'] = $raw_conv['user_1_role'];
        } else {
            $processed_conv['other_full_name'] = trim($raw_conv['u2_first'] . ' ' . $raw_conv['u2_last']);
            $processed_conv['other_role'] = $raw_conv['user_2_role'];
        }

        if(empty($processed_conv['other_full_name'])) {
            $stmt_user = $conn->prepare("SELECT username FROM users WHERE sid = ?");
            $stmt_user->execute([$other_user_id]);
            $processed_conv['other_full_name'] = $stmt_user->fetchColumn() ?? 'User ' . $other_user_id;
        }
        $conversations[] = $processed_conv;
    }

    // --- 4.2. VIEW SPECIFIC FETCHING ---
    if ($view === 'dashboard' || $view === 'approval') {

        $stmt_students_count = $conn->prepare("SELECT COUNT(DISTINCT up.student_id) FROM user_profiles up JOIN users u ON up.sid = u.sid WHERE up.student_id IS NOT NULL AND u.role = 'student'");
        $stmt_students_count->execute();
        $total_students = $stmt_students_count->fetchColumn();

        $stmt_programs = $conn->prepare("SELECT COUNT(DISTINCT program) FROM user_profiles WHERE program IS NOT NULL");
        $stmt_programs->execute();
        $programs_count = $stmt_programs->fetchColumn();

        // MODIFIED QUERY: Fetch grades that are either PENDING acknowledgment 
        // ('Approved by PH') or ALREADY Acknowledged ('Acknowledged by Registrar').
        $stmt_grades_report = $conn->prepare("
            SELECT
                g.grade_id, g.student_id_string, g.status, CONCAT(up.first_name, ' ', up.last_name) AS student_full_name,
                sub.subject_code, sub.subject_name, g.term_grade AS grade_value,
                g.term,
                CONCAT(upt.first_name, ' ', upt.last_name) AS teacher_name,
                ph_user.username AS program_head_name,
                DATE(g.review_date) AS review_date_registrar,
                DATE(g.review_date) AS review_date_ph,
                up.section
            FROM grades g
            JOIN users stu_user ON g.student_id_string = stu_user.username
            JOIN user_profiles up ON stu_user.sid = up.sid
            JOIN subject sub ON g.subject_id = sub.subject_id
            LEFT JOIN user_profiles upt ON g.teacher_id = upt.sid
            JOIN users ph_user ON g.program_head_id = ph_user.sid
            WHERE g.status IN ('Approved by PH', 'Acknowledged by Registrar') AND g.program_head_id IS NOT NULL
            ORDER BY up.section ASC, g.status DESC, sub.subject_name ASC, up.last_name ASC
        ");
        $stmt_grades_report->execute();
        $raw_grades_data = $stmt_grades_report->fetchAll(PDO::FETCH_ASSOC);

        // Separate pending grades for the dashboard summary card
        $pending_grades_for_registrar = array_filter($raw_grades_data, function($grade) {
            return $grade['status'] === 'Approved by PH';
        });
        
        // Use all grades for the detailed view/reports
        $grades_for_report_view = $raw_grades_data;
        $pending_final_acknowledgements = count($pending_grades_for_registrar);
    }
    
    // Fetch all sections needed for the legacy reports view form
    $stmt_sections = $conn->query("SELECT DISTINCT up.section FROM user_profiles up JOIN users u ON up.sid = u.sid WHERE up.section IS NOT NULL AND u.role = 'student' ORDER BY up.section");
    $all_sections = $stmt_sections->fetchAll(PDO::FETCH_ASSOC);

    // Message details logic for the chat interface
    if ($view === 'messages') {
        if (!empty($conversations)) {
            $conv_ids = array_column($conversations, 'conversation_id');
            if (!empty($conv_ids)) {
                $placeholders = implode(',', array_fill(0, count($conv_ids), '?'));
                $stmt_last_msg = $conn->prepare("
                    SELECT m.conversation_id, m.body
                    FROM messages m
                    INNER JOIN (
                        SELECT conversation_id, MAX(sent_at) AS max_sent_at
                        FROM messages
                        WHERE conversation_id IN ($placeholders)
                        GROUP BY conversation_id
                    ) AS latest ON m.conversation_id = latest.conversation_id AND m.sent_at = latest.max_sent_at
                ");
                $stmt_last_msg->execute($conv_ids);
                $last_msg_data = $stmt_last_msg->fetchAll(PDO::FETCH_KEY_PAIR);

                foreach ($conversations as $i => $conv) {
                    if (isset($last_msg_data[$conv['conversation_id']])) {
                        $conversations[$i]['last_message'] = $last_msg_data[$conv['conversation_id']];
                    } else {
                        $conversations[$i]['last_message'] = 'No messages yet...';
                    }
                }
            } else {
                foreach ($conversations as $i => $conv) {
                    $conversations[$i]['last_message'] = 'No messages yet...';
                }
            }
        }

        if ($selected_conversation_id) {
            foreach ($conversations as $conv) {
                if ($conv['conversation_id'] == $selected_conversation_id) {
                    $is_part_of_conversation = true;
                    $other_user_name = $conv['other_full_name'] ?? 'User';
                    $other_user_role = $conv['other_role'] ?? 'User';
                    $current_conversation_receiver_id = ($conv['user_1_id'] == $current_user_id) ? (int)$conv['user_2_id'] : (int)$conv['user_1_id'];
                    break;
                }
            }

            if ($is_part_of_conversation) {
                $stmt_msgs = $conn->prepare("
                    SELECT m.*, CONCAT(up.first_name, ' ', up.last_name) AS sender_full_name
                    FROM messages m
                    JOIN users u ON m.sender_id = u.sid
                    LEFT JOIN user_profiles up ON u.sid = up.sid
                    WHERE m.conversation_id = :conv_id ORDER BY m.sent_at ASC
                ");
                $stmt_msgs->bindParam(':conv_id', $selected_conversation_id, PDO::PARAM_INT);
                $stmt_msgs->execute();
                $messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

                $stmt_read = $conn->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = :conv_id AND receiver_id = :current_user_id AND is_read = 0");
                $stmt_read->bindParam(':conv_id', $selected_conversation_id, PDO::PARAM_INT);
                $stmt_read->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
                $stmt_read->execute();

            } else {
                $selected_conversation_id = null;
                $other_user_name = "Invalid Conversation";
                if(empty($db_error)) $db_error = "You don't have permission to view this conversation or it doesn't exist.";
            }
        }
    }

} catch (PDOException $e) {
    $db_error = "Database error fetching data: " . $e->getMessage();
}

// ---------------------------------------------------------------------------------
// --- HTML RENDERING ---
// ---------------------------------------------------------------------------------

require_once 'registrar_header.php'; 
?>
            <?php
            switch ($view):

                // --- Case 1: Dashboard View (Pending Grades) ---
                case 'dashboard':
            ?>
                    <div class="dashboard-grid">
                        <div class="dashboard-cards" style="grid-column: 1 / 3;">
                            <section class="quick-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                                <div class="stat-card" style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; text-align: center; background-color: #ffffff;">
                                    <h3 style="font-size: 1em; color: #4a5568; margin-bottom: 5px;">Total Students</h3>
                                    <p style="font-size: 1.8em; font-weight: 700; color: #3182ce;"><i class="fas fa-users" style="font-size: 0.7em; margin-right: 5px; opacity: 0.7;"></i><?= $total_students; ?></p>
                                </div>
                                <div class="stat-card" style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; text-align: center; background-color: #ffffff;">
                                    <h3 style="font-size: 1em; color: #4a5568; margin-bottom: 5px;">Programs Offered</h3>
                                    <p style="font-size: 1.8em; font-weight: 700; color: #38a169;"><i class="fas fa-graduation-cap" style="font-size: 0.7em; margin-right: 5px; opacity: 0.7;"></i><?= $programs_count; ?></p>
                                </div>
                                <div class="stat-card urgent" style="border: 1px solid #f6ad55; border-radius: 8px; padding: 15px; text-align: center; background-color: #fffaf0;">
                                    <h3 style="font-size: 1em; color: #9c4221; margin-bottom: 5px;">Grades for Acknowledgment</h3>
                                    <p style="font-size: 1.8em; font-weight: 700; color: #d69e2e;"><i class="fas fa-check-double" style="font-size: 0.7em; margin-right: 5px; opacity: 0.7;"></i><?= $pending_final_acknowledgements; ?></p>
                                </div>
                            </section>
                        </div>

                        <div class="dashboard-main" style="grid-column: 1 / 3;">
                            <section class="content-section" style="min-width: 100%;">
                                <h2 style="color: #2c5282; margin-bottom: 5px; font-weight: 600;"><i class="fa-solid fa-stamp"></i> Grade Acknowledgment</h2>
                                <p class="description" style="color: #718096; margin-bottom: 20px;">Review and acknowledge final grades (Grouped by Section).</p>

                                <?php
                                // Data Grouping Logic - Using ONLY PENDING grades for the DASHBOARD view
                                $grouped_pending_data = [];
                                if (!empty($pending_grades_for_registrar)) {
                                    foreach ($pending_grades_for_registrar as $row) {
                                        $sec = $row['section'] ?? 'Unassigned';
                                        $subj_key = $row['subject_code'] . '||' . $row['subject_name'] . '||' . $row['teacher_name'];
                                        $grouped_pending_data[$sec][$subj_key][] = $row;
                                    }
                                }
                                ?>

                                <?php if (!empty($grouped_pending_data)): ?>
                                    <div class="sections-list" style="display: grid; gap: 20px;">
                                        <?php foreach ($grouped_pending_data as $section_name => $subjects): ?>
                                            <?php $sec_id = md5($section_name); ?>
                                            <div class="section-group" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background-color: #ffffff;">
                                                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background-color: #f0f4f8; border-bottom: 1px solid #e2e8f0;">
                                                    <div class="section-title" style="font-weight: 700; font-size: 1.1em; color: #1a202c;">
                                                        Section: <?= htmlspecialchars($section_name); ?>
                                                        <small style="font-weight: 400; color: #718096; margin-left: 10px; font-size: 0.85em;">(<?= count($subjects); ?> Subject(s) Pending)</small>
                                                    </div>

                                                    <div style="display: flex; gap: 12px; align-items: center;">
                                                        <form action="registrar_dashboard.php?view=dashboard" method="POST">
                                                            <input type="hidden" name="section_name" value="<?= htmlspecialchars($section_name); ?>">
                                                            <button type="submit" name="action" value="acknowledge_section" class="approve-btn" style="padding: 8px 15px; background-color: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; transition: background-color 0.2s;" onclick="return confirm('Acknowledge ALL grades for Section <?= htmlspecialchars($section_name); ?>?');">
                                                                <i class="fas fa-check-circle"></i> Acknowledge Whole Section
                                                            </button>
                                                        </form>
                                                        <button onclick="toggleSection('sec-<?= $sec_id; ?>')" class="toggle-btn" style="background: none; border: 1px solid #cbd5e1; border-radius: 6px; padding: 5px 10px; cursor: pointer; color: #4a5568; transition: transform 0.2s;">
                                                            <i class="fa-solid fa-chevron-down" id="icon-<?= $sec_id; ?>"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <div id="sec-<?= $sec_id; ?>" style="display: block; padding: 15px;">
                                                    <?php foreach ($subjects as $subj_key => $rows):
                                                        list($s_code, $s_name, $t_name) = explode('||', $subj_key);
                                                   ?>
                                                    <div class="subject-wrapper" style="margin-bottom: 20px; border: 1px solid #ebf4ff; border-radius: 6px; background-color: #fcfdfe; padding: 15px;">
                                                        <div class="subject-header" style="display: flex; justify-content: space-between; margin-bottom: 10px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 8px;">
                                                            <div class="subject-info" style="font-weight: 600; color: #2c5282;">
                                                                <span class="subject-badge" style="display: inline-block; background-color: #e0f2fe; color: #2c5282; padding: 3px 8px; border-radius: 4px; font-size: 0.8em; margin-right: 8px;"><?= htmlspecialchars($s_code); ?></span>
                                                                <?= htmlspecialchars($s_name); ?>
                                                            </div>
                                                            <div class="teacher-info" style="font-size: 0.9em; color: #718096;">
                                                                <i class="fa-solid fa-chalkboard-user" style="margin-right: 4px;"></i>
                                                                <?= htmlspecialchars($t_name); ?>
                                                            </div>
                                                        </div>

                                                        <table class="grade-table" style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                                                            <thead>
                                                                <tr style="background-color: #f7fafc;">
                                                                    <th style="width: 50%; padding: 10px; text-align: left; font-size: 0.9em; color: #4a5568; border-bottom: 1px solid #e2e8f0;">Student Name</th>
                                                                    <th style="width: 25%; padding: 10px; text-align: left; font-size: 0.9em; color: #4a5568; border-bottom: 1px solid #e2e8f0;">Term</th>
                                                                    <th style="width: 25%; padding: 10px; text-align: left; font-size: 0.9em; color: #4a5568; border-bottom: 1px solid #e2e8f0;">Final Grade</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($rows as $grade): ?>
                                                                    <tr>
                                                                        <td style="font-weight: 500; padding: 8px; border-bottom: 1px solid #ebf4ff; background-color: #ffffff;">
                                                                            <i class="fa-regular fa-user" style="color: #94a3b8; margin-right: 8px;"></i>
                                                                            <?= htmlspecialchars($grade['student_full_name']); ?>
                                                                        </td>
                                                                        <td style="padding: 8px; border-bottom: 1px solid #ebf4ff; background-color: #ffffff;"><?= htmlspecialchars($grade['term']); ?></td>
                                                                        <td style="padding: 8px; border-bottom: 1px solid #ebf4ff; background-color: #ffffff;"><span class="grade-pill" style="display: inline-block; background-color: #e6fffa; color: #38a169; padding: 4px 10px; border-radius: 9999px; font-weight: 600;"><?= number_format($grade['grade_value'] ?? 0, 2); ?></span></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-data" style="background:white; border-radius:12px; padding:40px; border:1px solid #e2e8f0; text-align: center; color: #718096;">
                                        <i class="fa-solid fa-check-circle" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; display:block;"></i>
                                        No grades pending acknowledgement.
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </div>

                    <script>
                        function toggleSection(id) {
                            var x = document.getElementById(id);
                            var icon = document.getElementById('icon-' + id.replace('sec-', ''));
                            if (x.style.display === "none" || x.style.display === "") {
                                x.style.display = "block";
                                icon.classList.remove('fa-chevron-right');
                                icon.classList.add('fa-chevron-down');
                            } else {
                                x.style.display = "none";
                                icon.classList.remove('fa-chevron-down');
                                icon.classList.add('fa-chevron-right');
                            }
                        }
                    </script>

            <?php
                    break; // End Dashboard case

                // --- Case 2: Approval View (Detailed report including historical acknowledgment) ---
                case 'approval':
            ?>
                    <section class="content-section" style="min-width: 0;">
                        <h2><i class="fa-solid fa-file-invoice"></i> Final Grade Report & History</h2>
                        <p class="description" style="color: #718096; margin-bottom: 20px;">This report includes all grades that are either **pending acknowledgment** (Awaiting Action) or have been **officially acknowledged** by the Registrar for complete historical tracking.</p>

                        <?php
                        // Data Grouping Logic - Using ALL grades (pending and acknowledged)
                        $grouped_report_data = [];
                        if (!empty($grades_for_report_view)) {
                            foreach ($grades_for_report_view as $row) {
                                $sec = $row['section'] ?? 'Unassigned';
                                $subj_key = $row['subject_code'] . '||' . $row['subject_name'] . '||' . $row['teacher_name'];
                                // Group by Section and then Subject
                                $grouped_report_data[$sec][$subj_key][] = $row;
                            }
                        }
                        ?>

                        <?php if (!empty($grouped_report_data)): ?>
                            <div class="sections-list" style="display: grid; gap: 25px;">
                            <?php foreach ($grouped_report_data as $section_name => $subjects): ?>
                                <?php $sec_id = 'history-' . md5($section_name); ?>
                                <div class="section-group" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background-color: #ffffff;">
                                    <div class="group-header" style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background-color: #f0f4f8; border-bottom: 1px solid #e2e8f0;">
                                        <div class="section-title" style="font-weight: 700; font-size: 1.1em; color: #2c5282;">Section: <?= htmlspecialchars($section_name); ?></div>
                                        <?php 
                                            $has_pending_in_section = false;
                                            foreach ($subjects as $subj_data) {
                                                foreach ($subj_data as $grade) {
                                                    if ($grade['status'] === 'Approved by PH') {
                                                        $has_pending_in_section = true;
                                                        break 2;
                                                    }
                                                }
                                            }
                                        ?>
                                        <div style="display: flex; gap: 12px; align-items: center;">
                                            <?php if ($has_pending_in_section): ?>
                                                <form action="registrar_dashboard.php?view=approval" method="POST">
                                                    <input type="hidden" name="section_name" value="<?= htmlspecialchars($section_name); ?>">
                                                    <button type="submit" name="action" value="acknowledge_section" class="approve-btn" style="padding: 8px 15px; background-color: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; transition: background-color 0.2s;" onclick="return confirm('Acknowledge ALL PENDING grades for Section <?= htmlspecialchars($section_name); ?>?');">
                                                        <i class="fas fa-check-double"></i> Acknowledge Pending
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <button onclick="toggleSection('<?= $sec_id; ?>')" class="toggle-btn" style="background: none; border: 1px solid #cbd5e1; border-radius: 6px; padding: 5px 10px; cursor: pointer; color: #4a5568; transition: transform 0.2s;">
                                                <i class="fa-solid fa-chevron-down" id="icon-<?= $sec_id; ?>"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div id="<?= $sec_id; ?>" class="group-content" style="padding: 15px;">
                                        <?php foreach ($subjects as $subj_key => $rows):
                                                       list($s_code, $s_name, $t_name) = explode('||', $subj_key);
                                            ?>
                                                <div class="subject-wrapper" style="margin-top: 10px; padding: 15px; border: 1px solid #ebf4ff; border-radius: 6px; background-color: #fcfdfe;">
                                                    <div class="subject-header" style="display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 10px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 5px;">
                                                        <div class="subject-info"><span class="badge-new" style="background-color: #e0f2fe; color: #2c5282; padding: 3px 8px; border-radius: 4px; font-size: 0.85em; margin-right: 8px;"><?= htmlspecialchars($s_code); ?></span> <?= htmlspecialchars($s_name); ?></div>
                                                        <div class="teacher-info" style="font-size: 0.85rem; color: #718096;"><i class="fa-solid fa-chalkboard-user" style="margin-right: 4px;"></i>Instructor: <?= htmlspecialchars($t_name); ?></div>
                                                    </div>
                                                    <div class="table-container" style="box-shadow: none;">
                                                        <table class="review-table" style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                                                            <thead>
                                                                <tr style="background-color: #edf2f7;">
                                                                    <th style="padding: 10px; text-align: left; font-size: 0.9em; color: #4a5568; border-bottom: 1px solid #e2e8f0; width: 30%;">Student/Term</th>
                                                                    <th style="padding: 10px; text-align: left; font-size: 0.9em; color: #4a5568; border-bottom: 1px solid #e2e8f0; width: 15%;">Grade</th>
                                                                    <th style="padding: 10px; text-align: left; font-size: 0.9em; color: #4a5568; border-bottom: 1px solid #e2e8f0; width: 30%;">Status</th>
                                                                    <th style="padding: 10px; text-align: left; font-size: 0.9em; color: #4a5568; border-bottom: 1px solid #e2e8f0; width: 25%;">PH Review Date</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($rows as $grade): ?>
                                                                <?php 
                                                                    $is_pending = $grade['status'] === 'Approved by PH';
                                                                    $status_color = $is_pending ? '#fff3e0' : '#e6fffa'; // Light Orange/Yellow vs Light Teal
                                                                    $text_color = $is_pending ? '#e06e00' : '#065f46';   // Darker Orange vs Dark Teal
                                                                    $border_style = $is_pending ? '2px solid #f6ad55' : '1px solid #ebf4ff';
                                                                ?>
                                                                <tr class="grade-row" style="border-left: <?= $border_style; ?>;">
                                                                    <td style="font-weight: 500; padding: 8px; border-bottom: 1px solid #f7fafc;"><?= htmlspecialchars($grade['student_full_name']); ?> (<span style="color: #718096; font-weight: 400;"><?= htmlspecialchars($grade['term']); ?></span>)</td>
                                                                    <td style="padding: 8px; border-bottom: 1px solid #f7fafc;"><span style="display: inline-block; background-color: #e6fffa; color: #38a169; padding: 4px 10px; border-radius: 9999px; font-weight: 600;"><?= number_format($grade['grade_value'] ?? 0, 2); ?></span></td>
                                                                    <td style="padding: 8px; border-bottom: 1px solid #f7fafc;">
                                                                        <span style="display: inline-block; background-color: <?= $status_color; ?>; color: <?= $text_color; ?>; padding: 4px 8px; border-radius: 4px; font-size: 0.8em; font-weight: 600; text-transform: uppercase;">
                                                                            <?php if ($is_pending): ?>
                                                                                <i class="fas fa-hourglass-half"></i> Awaiting Action
                                                                            <?php else: ?>
                                                                                <i class="fas fa-check-circle"></i> Acknowledged
                                                                            <?php endif; ?>
                                                                        </span>
                                                                    </td>
                                                                    <td style="font-size: 0.9em; color: #718096; padding: 8px; border-bottom: 1px solid #f7fafc;">
                                                                        <?php if($is_pending): ?>
                                                                            PH: <?= htmlspecialchars($grade['review_date_ph']); ?>
                                                                        <?php else: ?>
                                                                            Reg: <?= htmlspecialchars($grade['review_date_registrar']); ?>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                    </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                            <script>
                                // Re-using the toggleSection function from the Dashboard for the History view
                                function toggleSection(id) {
                                    var x = document.getElementById(id);
                                    var icon = document.getElementById('icon-' + id);
                                    
                                    // Fallback for elements created outside the toggle loop in the dashboard section
                                    if (!icon) {
                                        icon = document.querySelector(`.section-group #${id}`).closest('.section-group').querySelector('.toggle-btn i');
                                    }

                                    if (x.style.display === "none" || x.style.display === "") {
                                        x.style.display = "block";
                                        icon.classList.remove('fa-chevron-right');
                                        icon.classList.add('fa-chevron-down');
                                    } else {
                                        x.style.display = "none";
                                        icon.classList.remove('fa-chevron-down');
                                        icon.classList.add('fa-chevron-right');
                                    }
                                }
                            </script>
                        <?php else: ?>
                            <div class="no-data" style="background:white; border-radius:12px; padding:40px; border:1px solid #e2e8f0; text-align: center; color: #718096;">
                                <i class="fa-solid fa-check-circle" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; display:block;"></i>
                                No grades found in the system requiring or having received Registrar acknowledgment.
                            </div>
                        <?php endif; ?>
                    </section>
            <?php
                    break; // End Approval case
                    
                // --- Case 3: Reports View ---
                case 'reports':
                    require 'registrar_reports.php';
                    break;

                // --- Case 4: Messages View ---
                case 'messages':
            ?>
                    <div class="messaging-layout">
                        <aside class="conversation-sidebar">
                            <div class="conversation-header">
                                <h2>Conversations</h2>
                                <p>Logged in as: <?= htmlspecialchars($current_user_name); ?></p>
                                <button id="new-conversation-btn" class="new-conv-btn"><i class="fas fa-plus mr-1"></i> New Conversation</button>
                            </div>

                            <nav class="conversation-list custom-scrollbar" id="conversation-list-area">
                                <?php if (empty($conversations)): ?>
                                    <p class="no-data">No conversations yet. Start one above.</p>
                                <?php else: ?>
                                    <?php foreach ($conversations as $conv): ?>
                                        <?php
                                            $is_active = ($selected_conversation_id == $conv['conversation_id']);

                                            $name_parts = explode(' ', $conv['other_full_name']);
                                            $initials = '';
                                            if (count($name_parts) > 0 && !empty($name_parts[0])) {
                                                $initials .= strtoupper(substr($name_parts[0], 0, 1));
                                            }
                                            if (count($name_parts) > 1) {
                                                $initials .= strtoupper(substr(end($name_parts), 0, 1));
                                            }
                                            if (empty($initials)) {
                                                $initials = 'U'; // Fallback
                                            }
                                        ?>
                                        <a href="registrar_dashboard.php?view=messages&convo_id=<?= $conv['conversation_id'] ?>"
                                            class="conversation-item <?= $is_active ? 'active' : '' ?>">

                                            <form method="POST" action="registrar_dashboard.php?view=messages" class="delete-conversation-btn-wrapper" onsubmit="return confirm('Are you sure you want to hide this conversation?');">
                                                <input type="hidden" name="action" value="delete_conversation">
                                                <input type="hidden" name="conversation_id" value="<?= $conv['conversation_id'] ?>">
                                                <button type="submit" class="delete-conversation-btn" title="Hide Conversation">
                                                    <i class="fa-solid fa-times"></i>
                                                </button>
                                            </form>

                                            <div class="conversation-avatar"><?= htmlspecialchars($initials) ?></div>
                                            <div class="conversation-details">
                                                <span class="conversation-name"><?= htmlspecialchars($conv['other_full_name']) ?></span>
                                                <span class="conversation-last-message">
                                                    <?= htmlspecialchars($conv['last_message'] ?? '...'); ?>
                                                </span>
                                            </div>
                                            <div class="conversation-meta">
                                                <span class="conversation-timestamp">
                                                    <?= date('M j', strtotime($conv['updated_at'])) ?>
                                                </span>
                                                <?php if ($conv['unread_count'] > 0): ?>
                                                    <span class="unread-badge"><?= $conv['unread_count'] ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </nav>
                        </aside>

                        <div class="message-view">
                            <header class="message-header">
                                <a href="registrar_dashboard.php?view=messages" class="back-button">
                                    <i class="fa-solid fa-arrow-left"></i>
                                </a>
                                <i class="fa-solid fa-user"></i>
                                <div>
                                    <h3><?= htmlspecialchars($other_user_name); ?></h3>
                                    <span><?= htmlspecialchars($other_user_role); ?></span>
                                </div>
                            </header>
                            <div id="message-area" class="custom-scrollbar">
                                <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
                                    <?php if (!empty($messages)): ?>
                                        <?php foreach ($messages as $msg): ?>
                                        <div class="message-bubble-container <?= ($msg['sender_id'] == $current_user_id) ? 'sent' : 'received'; ?>">
                                            <div class="message-bubble <?= ($msg['sender_id'] == $current_user_id) ? 'sent' : 'received'; ?>">
                                                <?php if ($msg['sender_id'] != $current_user_id): ?>
                                                    <p class="sender-name"><?= htmlspecialchars($msg['sender_full_name'] ?? 'User'); ?></p>
                                                <?php endif; ?>
                                                <p><?= nl2br(htmlspecialchars($msg['body'])); ?></p>
                                                <p class="timestamp"><?= date('M j, g:i A', strtotime($msg['sent_at'])); ?></p>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="no-data">No messages yet. Say hello!</p>
                                    <?php endif; ?>
                                <?php elseif ($db_error && $view === 'messages'): ?>
                                    <p class="message error" style="margin: 20px;"><?= htmlspecialchars($db_error) ?></p>
                                <?php else: ?>
                                    <p class="select-conversation-prompt">Select a conversation or start a new one.</p>
                                <?php endif; ?>
                            </div>
                            <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
                            <footer class="message-input-area">
                                <?php if ($send_error): ?><p class="message error" style="text-align: left; margin-bottom: 10px;"><?= htmlspecialchars($send_error); ?></p><?php endif; ?>
                                <form action="registrar_dashboard.php?view=messages&convo_id=<?= $selected_conversation_id; ?>" method="POST">
                                    <input type="hidden" name="receiver_id" value="<?= htmlspecialchars($current_conversation_receiver_id); ?>">
                                    <textarea name="body" placeholder="Type your message..." rows="1" required></textarea>
                                    <button type="submit" name="send_message" title="Send"><i class="fas fa-paper-plane"></i></button>
                                </form>
                            </footer>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div id="new-conversation-modal" class="modal">
                        <div class="modal-content">
                            <button class="modal-close-btn" id="modal-close-x-btn">&times;</button>
                            <h3><i class="fas fa-plus-circle"></i> Start New Conversation</h3>
                            <p class="description">Search for a Professor, Program Head, or Staff member to start a chat.</p>
                            
                            <?php if ($start_conversation_error): ?>
                                <p class="message error"><?= htmlspecialchars($start_conversation_error); ?></p>
                            <?php endif; ?>

                            <form action="registrar_dashboard.php?view=messages" method="POST">
                                <input type="hidden" name="start_conversation" value="1">
                                <input type="hidden" name="recipient_sid" id="recipient-sid-input">
                                
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label for="recipient-search">Search User (Name or Username):</label>
                                    <input type="text" id="recipient-search" placeholder="E.g., Professor Smith or prof.jsmith" required>
                                </div>
                                
                                <div id="search-suggestions" style="margin-bottom: 20px; border-radius: 6px;">
                                    </div>

                                <div style="text-align: right;">
                                    <button type="button" class="reject-btn" id="modal-cancel-btn">Cancel</button>
                                    <button type="submit" class="approve-btn" id="start-conv-submit-btn" disabled>
                                        <i class="fas fa-comment"></i> Start Chat
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <script>
                        // JS for chat interface (new conversation modal logic and search placeholder)
                        document.addEventListener('DOMContentLoaded', () => {
                            const modal = document.getElementById('new-conversation-modal');
                            const newConvBtn = document.getElementById('new-conversation-btn');
                            const closeModalBtn = document.getElementById('modal-close-x-btn');
                            const cancelModalBtn = document.getElementById('modal-cancel-btn');
                            const searchInput = document.getElementById('recipient-search');
                            const suggestionsBox = document.getElementById('search-suggestions');
                            const recipientInput = document.getElementById('recipient-sid-input');
                            const startConvBtn = document.getElementById('start-conv-submit-btn');

                            const showModal = () => {
                                if(modal) modal.classList.add('show');
                                if(searchInput) searchInput.focus();
                            };
                            const closeModal = () => {
                                if(modal) modal.classList.remove('show');
                                // Clear input/suggestions on close
                                if(searchInput) searchInput.value = '';
                                if(recipientInput) recipientInput.value = '';
                                if(suggestionsBox) suggestionsBox.innerHTML = '';
                                if(startConvBtn) startConvBtn.disabled = true;
                            };

                            if (newConvBtn) newConvBtn.addEventListener('click', showModal);
                            if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
                            if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

                            if (modal) {
                                modal.addEventListener('click', (event) => {
                                    if (event.target === modal) {
                                        closeModal();
                                    }
                                });
                            }
                            
                            // *** AJAX Search Simulation/Placeholder ***
                            if (searchInput) {
                                searchInput.addEventListener('input', () => {
                                    const query = searchInput.value.trim();
                                    
                                    if (recipientInput) recipientInput.value = '';
                                    if (startConvBtn) startConvBtn.disabled = true;

                                    if (query.length < 2) {
                                        if (suggestionsBox) suggestionsBox.innerHTML = '';
                                        return;
                                    }

                                    // Placeholder for AJAX call to registrar_ajax_search_users.php
                                    // The real logic would look like this:
                                    // fetch(`registrar_ajax_search_users.php?query=${encodeURIComponent(query)}`)
                                    //      .then(response => response.json())
                                    //      .then(data => { /* ... update suggestions ... */ })
                                    
                                    // *** MOCK DATA FOR DEMO/TESTING ***
                                    const mockUsers = [
                                        { sid: 2201070834, name: 'Maverick Ursolino', role: 'professor' },
                                        { sid: 18, name: 'John Zapanta', role: 'Programhead' },
                                        { sid: 2205010553, name: 'Anton Cypress', role: 'student' }
                                    ];

                                    const filtered = mockUsers.filter(u => 
                                        u.name.toLowerCase().includes(query.toLowerCase()) || 
                                        u.role.toLowerCase().includes(query.toLowerCase())
                                    ).filter(u => u.sid !== <?= $current_user_id ?>); // Exclude self

                                    if (!suggestionsBox) return;
                                    suggestionsBox.innerHTML = '';

                                    if (filtered.length > 0) {
                                        filtered.forEach(user => {
                                            const div = document.createElement('div');
                                            div.className = 'suggestion-item';
                                            div.style.cssText = 'padding: 10px; cursor: pointer; border-bottom: 1px solid #eee;';
                                            div.innerHTML = `<strong>${user.name}</strong> (${user.role})`;
                                            div.dataset.sid = user.sid;
                                            
                                            div.addEventListener('click', () => {
                                                searchInput.value = `${user.name} (${user.role})`;
                                                recipientInput.value = user.sid;
                                                startConvBtn.disabled = false;
                                                suggestionsBox.innerHTML = '';
                                            });
                                            suggestionsBox.appendChild(div);
                                        });
                                    } else {
                                        suggestionsBox.innerHTML = '<p style="padding: 10px; color: var(--text-light);">No users found.</p>';
                                    }
                                });
                            }
                        });
                    </script>
            <?php
                    break; // End Messages case


                // --- Default View ---
                default:
            ?>
                    <section class="content-section">
                        <h2>Page Not Found</h2>
                        <p>The view '<?php echo htmlspecialchars($view); ?>' does not exist. <a href="registrar_dashboard.php?view=dashboard">Go to Dashboard</a></p>
                    </section>
            <?php
                endswitch;
                // --- End PHP View Router ---
                ?>

<?php
require_once 'registrar_footer.php';
ob_end_flush();
?>