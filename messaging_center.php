<?php
// messaging_center.php
// (MODIFIED 3: Added Search, Delete, Read Receipts, Emojis, UI Cleanup)

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

// --- (FROM DASHBOARD) STUDENT AUTHENTICATION ---
if (!isset($_SESSION['sid']) || !isset($_SESSION['role'])) {
    header("Location: index.php");
    exit();
}
$current_user_id = $_SESSION['sid'];
$current_user_role = $_SESSION['role']; 

// --- (FROM DASHBOARD) Fetch student's full name for header ---
$student_name = "User"; // Default
try {
    $stmt_user = $conn->prepare("SELECT first_name, middle_name, last_name FROM user_profiles WHERE sid = :sid");
    $stmt_user->execute([':sid' => $current_user_id]);
    $profile = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($profile) {
        $first_name = htmlspecialchars($profile['first_name'] ?? '');
        $middle_name = htmlspecialchars($profile['middle_name'] ?? '');
        $last_name = htmlspecialchars($profile['last_name'] ?? '');
        $middle_initial = !empty($middle_name) ? ' ' . mb_substr($middle_name, 0, 1) . '.' : '';
        $student_name = $first_name . $middle_initial . ' ' . $last_name;
    }
} catch (PDOException $e) { /* Fail silently */ }
// --- END (FROM DASHBOARD) ---


$selected_conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : null;
$messages = [];
$other_user_name = "No Conversation Selected";
$current_conversation_receiver_id = null;
$is_part_of_conversation = false;
$send_error = ''; 

// --- Logic for Starting a New Conversation ---
$start_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_conversation'])) {
    $recipient_id = (int)$_POST['recipient_id'];
    
    if ($recipient_id > 0) {
        try {
            if ($recipient_id == $current_user_id) {
                $start_error = "You cannot start a conversation with yourself.";
            } else {
                $stmt_check_exist = $conn->prepare("
                    SELECT conversation_id
                    FROM conversations
                    WHERE (user_1_id = :u1 AND user_2_id = :u2) OR (user_1_id = :u2 AND user_2_id = :u1)
                ");
                $u1 = min($current_user_id, $recipient_id);
                $u2 = max($current_user_id, $recipient_id);
                $stmt_check_exist->execute([':u1' => $u1, ':u2' => $u2]);
                $existing_conv = $stmt_check_exist->fetch(PDO::FETCH_ASSOC);
                if ($existing_conv) {
                    $new_conv_id = $existing_conv['conversation_id'];
                } else {
                    $stmt_create = $conn->prepare("INSERT INTO conversations (user_1_id, user_2_id) VALUES (:u1, :u2)");
                    $stmt_create->execute([':u1' => $u1, ':u2' => $u2]);
                    $new_conv_id = $conn->lastInsertId();
                }
                header("Location: messaging_center.php?conversation_id={$new_conv_id}");
                exit();
            }
        } catch (PDOException $e) {
            error_log("Conversation Start Error: " . $e->getMessage());
            $start_error = "Error starting conversation: Database issue.";
        }
    } else {
        $start_error = "You must select a recipient from the list.";
    }
}
// --- END Logic for Starting a New Conversation ---


// --- Fetch list of potential recipients (Students and Professors) ---
$stmt_recipients = $conn->prepare("
    SELECT u.sid, up.first_name, up.last_name, u.role
    FROM users u
    JOIN user_profiles up ON u.sid = up.sid
    WHERE u.sid != :current_user_id AND (u.role = 'student' OR u.role = 'professor')
    ORDER BY up.last_name, up.first_name
");
$stmt_recipients->execute([':current_user_id' => $current_user_id]);
$recipient_list = $stmt_recipients->fetchAll(PDO::FETCH_ASSOC);


// --- (MODIFIED) Fetch Conversation List (Respects 'hidden' flag) ---
$stmt_conv = $conn->prepare("
    SELECT c.conversation_id,
           CASE 
               WHEN c.user_1_id = :user_id 
               THEN CONCAT(up2.first_name, ' ', up2.last_name) 
               ELSE CONCAT(up1.first_name, ' ', up1.last_name) 
           END AS other_full_name,
           SUM(CASE WHEN m.is_read = 0 AND m.receiver_id = :user_id THEN 1 ELSE 0 END) AS unread_count
    FROM conversations c
    LEFT JOIN users u1 ON c.user_1_id = u1.sid
    LEFT JOIN users u2 ON c.user_2_id = u2.sid
    LEFT JOIN user_profiles up1 ON u1.sid = up1.sid
    LEFT JOIN user_profiles up2 ON u2.sid = up2.sid
    LEFT JOIN messages m ON c.conversation_id = m.conversation_id
    WHERE 
        (c.user_1_id = :user_id AND c.user_1_hidden = 0) OR 
        (c.user_2_id = :user_id AND c.user_2_hidden = 0)
    GROUP BY c.conversation_id, other_full_name, c.updated_at
    ORDER BY c.updated_at DESC
");
$stmt_conv->execute([':user_id' => $current_user_id]);
$conversations = $stmt_conv->fetchAll();

// --- (MODIFIED) Fetch Messages for Selected Conversation (Respects 'hidden' flag) ---
if ($selected_conversation_id) {
    // 1. Check access
    $stmt_check = $conn->prepare("
        SELECT c.*,
               CASE WHEN c.user_1_id = :user_id THEN c.user_2_id ELSE c.user_1_id END AS receiver_id,
               CASE WHEN c.user_1_id = :user_id THEN CONCAT(up2.first_name, ' ', up2.last_name) ELSE CONCAT(up1.first_name, ' ', up1.last_name) END AS other_full_name
        FROM conversations c
        JOIN users u1 ON c.user_1_id = u1.sid
        JOIN users u2 ON c.user_2_id = u2.sid
        LEFT JOIN user_profiles up1 ON u1.sid = up1.sid
        LEFT JOIN user_profiles up2 ON u2.sid = up2.sid
        WHERE c.conversation_id = :conv_id 
          AND ((c.user_1_id = :user_id AND c.user_1_hidden = 0) OR (c.user_2_id = :user_id AND c.user_2_hidden = 0))
    ");
    $stmt_check->execute([':conv_id' => $selected_conversation_id, ':user_id' => $current_user_id]);
    $conversation_details = $stmt_check->fetch();

    if ($conversation_details) {
        $is_part_of_conversation = true;
        $other_user_name = htmlspecialchars($conversation_details['other_full_name']);
        $current_conversation_receiver_id = $conversation_details['receiver_id'];

        // 2. Fetch messages
        $stmt_msgs = $conn->prepare("
            SELECT m.message_id, m.sender_id, m.body, m.sent_at
            FROM messages m
            WHERE m.conversation_id = :conv_id ORDER BY m.sent_at ASC
        ");
        $stmt_msgs->bindParam(':conv_id', $selected_conversation_id, PDO::PARAM_INT);
        $stmt_msgs->execute();
        $messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

        // 3. Mark messages as read
        $stmt_read = $conn->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = :conv_id AND receiver_id = :current_user_id AND is_read = 0");
        $stmt_read->bindParam(':conv_id', $selected_conversation_id, PDO::PARAM_INT);
        $stmt_read->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
        $stmt_read->execute();
    } else {
        // User tried to access a hidden or non-existent conversation
        $selected_conversation_id = null; // Invalidate selection
        $other_user_name = "No Conversation Selected";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DFCAMCLP SmartGrade: Messaging Center</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js"></script>

    <style>
        /* --- Dashboard Root Variables --- */
        :root {
            --nav-dark: #0f4a73; --panel-blue: #1f6ea3; --bg-light: #f2f5f8;
            --white: #fff; --text-dark: #102a43; --text-light: #627d98;
            --accent-light: #4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);
            --shadow-md: 0 6px 15px rgba(0,0,0,0.08); --critical: #cc3333;
            --warning: #ff9900; --muted: #888; --success-green: #28a745;
        }

        /* --- Dashboard Global Reset & Base --- */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100vh; overflow: hidden; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; margin: 0; background: var(--bg-light); color: var(--text-dark); display: flex; }

        /* --- Dashboard Sidebar --- */
        .sidebar {
            width: 260px; background: var(--nav-dark); color: var(--white);
            display: flex; flex-direction: column; flex-shrink: 0; height: 100vh;
            position: fixed; top: 0; left: 0; z-index: 40;
            transition: transform 0.3s ease-in-out;
            transform: translateX(-100%);
        }
        .sidebar-visible { transform: translateX(0); }
        .sidebar-header { padding: 24px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-header-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; color: white; }
        .sidebar-header-logo i { font-size: 28px; color: var(--accent-light); }
        .sidebar-header-logo h2 { font-size: 1.15rem; font-weight: 600; line-height: 1.3; }
        .sidebar-header-logo h2 span { font-size: 0.8rem; font-weight: 400; color: #9ac8ee; display: block; }
        .sidebar-nav { flex-grow: 1; padding: 20px 0; overflow-y: auto; }
        .sidebar-nav ul { list-style: none; }
        .sidebar-nav li { margin: 0 10px; }
        .sidebar-nav a { display: flex; align-items: center; gap: 15px; padding: 14px 20px; color: #e0f2fe; text-decoration: none; font-weight: 500; border-radius: 8px; transition: background 0.2s ease, color 0.2s ease; }
        .sidebar-nav a i { font-size: 1.1rem; width: 20px; text-align: center; color: #9ac8ee; }
        .sidebar-nav a:hover { background: rgba(255, 255, 255, 0.1); }
        .sidebar-nav a.active { background: var(--accent-light); color: var(--nav-dark); font-weight: 700; }
        .sidebar-nav a.active i { color: var(--nav-dark); }
        .sidebar-footer { padding: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-footer a { display: block; text-align: center; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; color: var(--white); text-decoration: none; font-weight: 500; transition: background 0.2s ease; }
        .sidebar-footer a:hover { background: rgba(0,0,0,0.4); }
        #sidebar-overlay {
            position: fixed; inset: 0; background: #000; opacity: 0.5;
            z-index: 30; transition: opacity 0.3s ease-in-out;
            display: none;
        }

        /* --- Dashboard Main Content --- */
        .main-content {
            flex-grow: 1; display: flex; flex-direction: column;
            height: 100vh; overflow: hidden;
            transition: margin-left 0.3s ease-in-out;
            width: 100%;
        }
        .header { display: flex; justify-content: space-between; align-items: center; padding: 16px 32px; background: var(--white); border-bottom: 1px solid #e0e6ed; flex-shrink: 0; }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .user-profile-info { text-align: right; }
        .user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
        .user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
        
        .page-view {
            flex-grow: 1;
            overflow: hidden; 
            padding: 0; 
            display: flex; 
        }
        
        .toggle-button { background: none; border: none; cursor: pointer; color: var(--text-dark); padding: 4px; }
        .toggle-button i { font-size: 1.25rem; }
        .lg-hidden { display: none; }
        .mr-1 { margin-right: 5px; } 

        /* --- Messaging Layout --- */
        .messaging-layout {
            display: flex;
            width: 100%;
            height: 100%; 
            background: var(--white);
        }

        .conversation-sidebar {
            width: 300px;
            border-right: 1px solid #e0e6ed;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            background: #f8fafc; 
        }
        .conversation-header {
            padding: 20px;
            border-bottom: 1px solid #e0e6ed;
            flex-shrink: 0;
        }
        .conversation-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 15px; /* (MODIFIED) Increased margin */
        }
        /* (REMOVED) "Logged in as" P tag */
        
        .new-conv-btn {
            width: 100%;
            padding: 10px;
            font-weight: 600;
            color: var(--white);
            background: var(--panel-blue);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .new-conv-btn:hover { background: var(--nav-dark); }
        
        #conversation-search {
            width: 100%;
            padding: 10px;
            margin-top: 15px;
            border: 1px solid #e0e6ed;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .conversation-list {
            flex-grow: 1;
            overflow-y: auto;
            padding: 10px 0;
        }
        .conversation-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            text-decoration: none;
            color: var(--text-dark);
            border-bottom: 1px solid #e0e6ed;
            transition: background 0.2s ease;
            position: relative; /* (NEW) For delete button */
        }
        .conversation-item:hover { background: var(--bg-light); }
        .conversation-item.active {
            background: var(--panel-blue);
            color: var(--white);
        }
        .conversation-item-details {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        .conversation-item.active .conversation-item-details { font-weight: 700; }
        .conversation-item i { font-size: 1.2rem; color: var(--text-light); }
        .conversation-item.active i { color: var(--white); }
        .unread-badge {
            background: var(--critical);
            color: var(--white);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
        }

        /* (NEW) Delete Conversation Button */
        .delete-conversation-btn {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            font-size: 0.9rem;
            padding: 5px;
            border-radius: 50%;
            display: none; /* Hide by default */
        }
        .conversation-item:hover .delete-conversation-btn {
            display: block;
        }
        .delete-conversation-btn:hover {
            color: var(--critical);
            background: #fde8e8;
        }
        .conversation-item.active .delete-conversation-btn {
            color: var(--white);
            opacity: 0.7;
        }
        .conversation-item.active .delete-conversation-btn:hover {
            opacity: 1;
            background: rgba(255,255,255,0.2);
        }
        /* Make space for the delete button */
        .conversation-item .conversation-item-details {
            max-width: calc(100% - 60px); /* Adjust as needed */
        }
        .conversation-item .conversation-item-details span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .conversation-item .unread-badge {
            margin-right: 25px; /* Space for delete btn */
        }
        .conversation-item:not(:hover) .unread-badge {
             margin-right: 0; /* No space if not hovered */
        }


        .message-view {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .message-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 24px;
            border-bottom: 1px solid #e0e6ed;
            flex-shrink: 0;
        }
        .message-header i { font-size: 1.2rem; color: var(--text-dark); }
        .message-header h3 { font-size: 1.1rem; font-weight: 700; }
        
        #message-area {
            flex-grow: 1;
            overflow-y: auto;
            padding: 24px;
            background: var(--bg-light);
        }
        .message-bubble-container { display: flex; margin-bottom: 15px; }
        .message-bubble-container.sent { justify-content: flex-end; }
        .message-bubble-container.received { justify-content: flex-start; }
        
        .message-bubble { max-width: 70%; padding: 12px 16px; border-radius: 18px; font-size: 0.95rem; line-height: 1.5; }
        .message-bubble.sent { background: var(--panel-blue); color: var(--white); border-bottom-right-radius: 4px; }
        .message-bubble.received { background: var(--white); color: var(--text-dark); border: 1px solid #e0e6ed; border-bottom-left-radius: 4px; }
        .message-bubble p { margin: 0; }
        .message-bubble .timestamp { font-size: 0.75rem; margin-top: 5px; text-align: right; opacity: 0.8; }
        .message-bubble.received .timestamp { opacity: 0.6; }
        .select-conversation-prompt { text-align: center; padding-top: 100px; font-size: 1.1rem; color: var(--text-light); }

        .message-input-area {
            flex-shrink: 0;
            padding: 16px 24px;
            border-top: 1px solid #e0e6ed;
            background: var(--white);
            position: relative; /* (NEW) For emoji picker */
        }
        
        /* (NEW) Emoji Picker */
        emoji-picker {
            position: absolute;
            bottom: 80px; /* Position above the input area */
            right: 24px;
            display: none; /* Hide by default */
            z-index: 50;
        }
        emoji-picker.visible {
            display: block;
        }

        #send-message-form {
            display: flex;
            align-items: flex-end; 
            gap: 10px;
        }
        /* (NEW) Emoji toggle button */
        #emoji-toggle-btn {
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            border: 1px solid #ccc;
            border-radius: 50%;
            background: var(--white);
            color: var(--text-light);
            cursor: pointer;
            font-size: 1.3rem;
            transition: all 0.2s ease;
        }
        #emoji-toggle-btn:hover {
            color: var(--text-dark);
            border-color: var(--panel-blue);
        }
        
        #send-message-form textarea {
            flex-grow: 1;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            resize: none;
            overflow-y: auto; 
            max-height: 120px; 
        }
        #send-message-form button[type="submit"] {
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            border: none;
            border-radius: 50%;
            background: var(--panel-blue);
            color: var(--white);
            cursor: pointer;
            font-size: 1.2rem;
            transition: background 0.2s ease;
        }
        #send-message-form button[type="submit"]:hover { background: var(--nav-dark); }
        
        /* (NEW) Read Receipt */
        #read-status-indicator {
            font-size: 0.8rem;
            color: var(--text-light);
            text-align: right;
            display: block;
            height: 1.2em; /* Reserve space */
            margin-top: 5px;
            padding-right: 60px; /* Align with send button */
        }

        .message.error {
            padding: 10px 15px; border-radius: 8px; margin-bottom: 10px; font-weight: 500; border: 1px solid;
            background: #fde8e8; color: var(--critical); border-color: var(--critical); 
            text-align: left;
        }

        /* --- Modal Styles --- */
        .modal {
            display: none;
            position: fixed;
            z-index: 100;
            left: 0; top: 0;
            width: 100%; height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: var(--white);
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            width: 90%;
            max-width: 500px;
            position: relative;
            text-align: center;
        }
        .modal-content h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 20px;
        }
        .modal-close-btn {
            position: absolute;
            top: 10px; right: 20px;
            font-size: 2rem;
            font-weight: 300;
            color: var(--muted);
            background: none;
            border: none;
            cursor: pointer;
        }
        .modal-content form {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        /* (NEW) Search bar for modal */
        #modal-recipient-search {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1rem;
            margin-bottom: 5px;
        }
        
        .modal-content select {
            width: 100%;
            padding: 12px;
            margin-bottom: 5px; 
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1rem;
            background-color: #f9f9f9;
        }
        .modal-content label {
            display: block;
            margin-bottom: 5px;
            text-align: left;
            font-weight: 600;
            color: #333;
        }
        .modal-content button[type="submit"] {
            padding: 12px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--white);
            background: var(--panel-blue);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .modal-content button[type="submit"]:hover { background: var(--nav-dark); }
        .modal-cancel-btn {
            padding: 12px;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-light);
            background: var(--bg-light);
            border: 1px solid #e0e6ed;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .modal-cancel-btn:hover { background: #e0e6ed; }
        

        /* --- Dashboard Responsive --- */
        @media (max-width: 1023px) {
            .lg-hidden { display: inline-block; }
            .header { padding-left: 16px; padding-right: 16px; }
            
            .page-view { padding: 0; }
            .messaging-layout { flex-direction: column; }
            .conversation-sidebar {
                width: 100%;
                height: auto; 
                border-right: none;
                border-bottom: 1px solid #e0e6ed;
                display: <?php echo $selected_conversation_id ? 'none' : 'flex'; ?>; 
            }
            .message-view {
                display: <?php echo $selected_conversation_id ? 'flex' : 'none'; ?>;
                height: 100%;
            }
            .message-header {
                padding-left: 16px;
                padding-right: 16px;
            }
            .message-header a.back-button {
                display: inline-block;
                color: var(--text-dark);
                text-decoration: none;
                font-size: 1.2rem;
                margin-right: 10px;
            }
            /* (NEW) Adjust emoji picker for mobile */
            emoji-picker {
                right: 16px;
                bottom: 80px; 
            }
            .message-input-area {
                padding: 16px;
            }
            #read-status-indicator {
                padding-right: 58px; /* Mobile alignment */
            }
        }
        
        @media (min-width: 1024px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: 260px; }
            .lg-hidden { display: none; }
            #sidebar-overlay { display: none !important; }
            .message-header a.back-button { display: none; }
        }
        
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #ccc; border-radius: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #aaa; }

    </style>
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="students.php" class="sidebar-header-logo">
            <i class="fa-solid fa-graduation-cap"></i>
            <h2>SmartGrade<span>Student Portal</span></h2>
        </a>
        <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
            <i class="fa-solid fa-times" style="color: var(--white);"></i>
        </button>
    </div>
    <nav class="sidebar-nav">
        <ul>
            <li><a href="students.php" class="nav-link"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="messaging_center.php" class="nav-link active"><i class="fa-solid fa-comments"></i><span>Messaging</span></a></li>
            <li><a href="student_profile.php" class="nav-link"><i class="fa-solid fa-user"></i><span>Profile</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Log Out</a>
    </div>
</div>

<div id="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="main-content" id="main-content">
    
    <header class="header">
        <div class="header-left">
            <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <h1 class="header-title">Messaging Center</h1>
        </div>
        
        <div class="user-profile">
            <div class="user-profile-info">
                <strong><?php echo htmlspecialchars($student_name); ?></strong>
                <span><?php echo htmlspecialchars(ucfirst($current_user_role)); ?></span>
            </div>
        </div>
    </header>

    <main class="page-view">
        
        <div class="messaging-layout">
            <aside class="conversation-sidebar">
                <div class="conversation-header">
                    <h2>Conversations</h2>
                    <button id="new-conversation-btn" class="new-conv-btn"><i class="fas fa-plus mr-1"></i> New Conversation</button>
                    <input type="text" id="conversation-search" placeholder="Search conversations...">
                </div>
                
                <nav class="conversation-list custom-scrollbar">
                    <?php if (!empty($conversations)): ?>
                        <?php foreach ($conversations as $conv): ?>
                        <a href="messaging_center.php?conversation_id=<?= $conv['conversation_id']; ?>" class="conversation-item <?= ($selected_conversation_id === (int)$conv['conversation_id']) ? 'active' : ''; ?>">
                            <div class="conversation-item-details">
                                <i class="fa-regular fa-user-circle"></i>
                                <span><?= htmlspecialchars($conv['other_full_name'] ?? 'Unknown User'); ?></span>
                            </div>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <span class="unread-badge"><?= $conv['unread_count']; ?></span>
                            <?php endif; ?>
                            <button class="delete-conversation-btn" data-conv-id="<?= $conv['conversation_id']; ?>" title="Hide conversation">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="no-data" style="padding: 15px;">No conversations yet.</p>
                    <?php endif; ?>
                </nav>
            </aside>

            <div class="message-view">
                <header class="message-header">
                    <a href="messaging_center.php" class="back-button"><i class="fas fa-arrow-left"></i></a>
                    <i class="fa-solid fa-user"></i>
                    <h3><?= $other_user_name; ?></h3>
                </header>
                
                <div id="message-area" class="custom-scrollbar">
                    <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
                        <?php if (!empty($messages)): ?>
                            <?php foreach ($messages as $msg): ?>
                                <div class="message-bubble-container <?= ($msg['sender_id'] == $current_user_id) ? 'sent' : 'received'; ?>" data-message-id="<?= $msg['message_id']; ?>">
                                    <div class="message-bubble <?= ($msg['sender_id'] == $current_user_id) ? 'sent' : 'received'; ?>">
                                        <p><?= nl2br(htmlspecialchars($msg['body'])); ?></p>
                                        <p class="timestamp"><?= date('g:i A', strtotime($msg['sent_at'])); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-data">No messages yet. Say hello!</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="select-conversation-prompt">Select a conversation or start a new one.</p>
                    <?php endif; ?>
                </div>

                <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
                
                <emoji-picker id="emoji-picker" class="light"></emoji-picker>
                
                <footer class="message-input-area">
                    <div id="send-error-container"></div>
                    
                    <form id="send-message-form">
                        <input type="hidden" name="receiver_id" id="receiver_id_input" value="<?= htmlspecialchars($current_conversation_receiver_id); ?>">
                        <input type="hidden" name="conversation_id" id="conversation_id_input" value="<?= $selected_conversation_id; ?>">
                        
                        <button type="button" id="emoji-toggle-btn" title="Toggle Emojis"><i class="fas fa-smile"></i></button>
                        
                        <textarea name="body" id="message-body-input" placeholder="Type your message..." rows="1" required></textarea>
                        <button type="submit" name="send_message" title="Send"><i class="fas fa-paper-plane"></i></button>
                    </form>
                    <div id="read-status-indicator"></div>
                </footer>
                <?php endif; ?>
            </div>
        </div>
        
    </main>
</div>

<div id="new-conversation-modal" class="modal">
    <div class="modal-content">
        <button id="modal-close-x-btn" class="modal-close-btn">&times;</button>
        <h3>Start New Conversation</h3>
        <?php if ($start_error): ?>
            <p class="message error" style="margin-bottom: 10px;"><?= htmlspecialchars($start_error); ?></p>
        <?php endif; ?>
        
        <form action="messaging_center.php" method="POST">
            <label for="modal-recipient-search" style="text-align: left;">Search by Name:</label>
            <input type="text" id="modal-recipient-search" placeholder="Type a name to filter...">

            <label for="recipient_id" style="text-align: left; margin-top: 10px;">Select a Recipient:</label>
            <select name="recipient_id" id="recipient_id" required size="5" style="height: 150px;">
                <option value="" disabled selected>-- Choose a Student or Professor --</option>
                <?php foreach ($recipient_list as $recipient): ?>
                    <option value="<?= $recipient['sid']; ?>">
                        <?= htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name'] . ' (' . ucfirst($recipient['role']) . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="start_conversation"><i class="fas fa-paper-plane"></i> Start Conversation</button>
            <button type="button" id="modal-cancel-btn" class="modal-cancel-btn">Cancel</button>
        </form>
    </div>
</div>

<script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        sidebar.classList.toggle('sidebar-visible');
        overlay.style.display = sidebar.classList.contains('sidebar-visible') ? 'block' : 'none';
    }

    function handleResize() {
        if (window.innerWidth >= 1024) {
            document.getElementById('sidebar').classList.add('sidebar-visible');
            document.getElementById('sidebar-overlay').style.display = 'none';
        } else {
            if (!document.getElementById('sidebar').classList.contains('sidebar-visible')) {
                 document.getElementById('sidebar-overlay').style.display = 'none';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (window.innerWidth >= 1024) {
            document.getElementById('sidebar').classList.add('sidebar-visible');
        }
    });
    window.addEventListener('resize', handleResize);
</script>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const messageArea = document.getElementById('message-area');
        const selectedConversationId = <?= json_encode($selected_conversation_id); ?>;
        const startError = <?= json_encode($start_error); ?>;
        
        if (startError) {
            document.getElementById('new-conversation-modal').style.display = 'flex';
        }

        // 1. Initial Scroll to Bottom
        const scrollToBottom = () => {
            if (messageArea) {
                messageArea.scrollTop = messageArea.scrollHeight;
            }
        };
        if (selectedConversationId) {
            scrollToBottom();
        }

        // 2. (REAL-TIME) AJAX Polling
        const readStatusIndicator = document.getElementById('read-status-indicator');
        const fetchMessages = () => {
            if (!messageArea || !selectedConversationId) return;

            const messageContainers = messageArea.querySelectorAll('.message-bubble-container');
            const lastMessageElement = messageContainers.length > 0 ? messageContainers[messageContainers.length - 1] : null;
            const lastMessageId = lastMessageElement ? (lastMessageElement.dataset.messageId || 0) : 0;
            
            const url = `fetch_messages.php?conversation_id=${selectedConversationId}&last_id=${lastMessageId}`;

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data && data.messages_html) {
                        const newContent = data.messages_html.trim();
                        if (newContent.length > 0) {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = newContent;
                            const isScrolledToBottom = messageArea.scrollHeight - messageArea.clientHeight <= messageArea.scrollTop + 50; 
                            while (tempDiv.firstChild) {
                                messageArea.appendChild(tempDiv.firstChild);
                            }
                            if (isScrolledToBottom) {
                                scrollToBottom();
                            }
                        }
                    }
                    if (data && data.sidebar_html) {
                        document.querySelector('.conversation-list').innerHTML = data.sidebar_html;
                        // (NEW) Re-attach delete listeners after sidebar refresh
                        attachDeleteListeners();
                    }
                    
                    // (NEW) Update Read Receipt
                    if (readStatusIndicator) {
                        if (data.read_status === 'seen') {
                            readStatusIndicator.innerHTML = '<i class="fas fa-check-double" style="color: var(--accent-light);"></i> Seen';
                        } else if (data.read_status === 'delivered') {
                            readStatusIndicator.innerHTML = '<i class="fas fa-check"></i> Delivered';
                        } else {
                            readStatusIndicator.innerHTML = ''; // No status (e.g., no messages sent)
                        }
                    }
                })
                .catch(e => {
                    console.error("Error fetching messages:", e);
                    clearInterval(pollingInterval); 
                });
        };

        let pollingInterval;
        if (selectedConversationId) {
            pollingInterval = setInterval(fetchMessages, 3000);
            fetchMessages(); // Run once immediately to get initial read status
        }

        // 3. (REAL-TIME) AJAX Send Message
        const sendMessageForm = document.getElementById('send-message-form');
        const messageInput = document.getElementById('message-body-input');
        const errorContainer = document.getElementById('send-error-container');
        const emojiPicker = document.getElementById('emoji-picker');

        if (sendMessageForm) {
            sendMessageForm.addEventListener('submit', function(e) {
                e.preventDefault();
                errorContainer.innerHTML = ''; 
                
                const formData = new FormData(this);
                if (messageInput.value.trim() === '') return; // Don't send empty messages

                fetch('send_message_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageInput.value = '';
                        adjustHeight(); 
                        emojiPicker.classList.remove('visible'); // Hide picker
                        fetchMessages(); 
                        scrollToBottom();
                    } else {
                        errorContainer.innerHTML = `<div class="message error">${data.error}</div>`;
                    }
                })
                .catch(err => {
                    console.error('Send Error:', err);
                    errorContainer.innerHTML = `<div class="message error">A network error occurred. Please try again.</div>`;
                });
            });
        }

        // 4. Textarea Auto-Resize
        const adjustHeight = () => {
            if(messageInput) {
                messageInput.style.height = 'auto';
                messageInput.style.height = (messageInput.scrollHeight) + 'px';
            }
        };
        if(messageInput) {
            messageInput.addEventListener('input', adjustHeight);
        }

        // 5. Modal Handling
        const modal = document.getElementById('new-conversation-modal');
        const newConversationBtn = document.getElementById('new-conversation-btn');
        const closeModalXBtn = document.getElementById('modal-close-x-btn');
        const modalCancelBtn = document.getElementById('modal-cancel-btn');

        const closeModal = () => { if(modal) modal.style.display = 'none'; };

        if(newConversationBtn) { newConversationBtn.onclick = function() { if(modal) modal.style.display = 'flex'; } }
        if(closeModalXBtn) { closeModalXBtn.onclick = closeModal; }
        if(modalCancelBtn) { modalCancelBtn.onclick = closeModal; }
        window.onclick = function(event) { if (event.target == modal) { closeModal(); } }

        // 6. (NEW) Conversation Search Bar Logic
        const searchInput = document.getElementById('conversation-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const filter = searchInput.value.toLowerCase();
                const conversations = document.querySelectorAll('.conversation-list .conversation-item');
                
                conversations.forEach(conv => {
                    const name = conv.querySelector('span').textContent.toLowerCase();
                    if (name.includes(filter)) {
                        conv.style.display = 'flex';
                    } else {
                        conv.style.display = 'none';
                    }
                });
            });
        }
        
        // 7. (NEW) Modal Recipient Search Logic
        const modalSearch = document.getElementById('modal-recipient-search');
        const recipientSelect = document.getElementById('recipient_id');
        if (modalSearch && recipientSelect) {
            modalSearch.addEventListener('input', function() {
                const filter = modalSearch.value.toLowerCase();
                const options = recipientSelect.options;
                
                for (let i = 0; i < options.length; i++) {
                    const option = options[i];
                    if (option.value === "") continue; // Skip the "-- Choose --" option
                    
                    const name = option.textContent.toLowerCase();
                    if (name.includes(filter)) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                }
            });
        }
        
        // 8. (NEW) Delete Conversation Logic
        function attachDeleteListeners() {
            document.querySelectorAll('.delete-conversation-btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault(); // Stop navigation
                    e.stopPropagation(); // Stop parent link from firing
                    
                    const convId = this.dataset.convId;
                    if (confirm('Are you sure you want to hide this conversation?\nThe other user will still be able to see it.')) {
                        fetch('delete_conversation.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `conversation_id=${convId}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Remove from view immediately
                                this.closest('.conversation-item').remove();
                                // If this was the active chat, redirect to main page
                                if (convId == selectedConversationId) {
                                    window.location.href = 'messaging_center.php';
                                }
                            } else {
                                alert(`Error: ${data.error}`);
                            }
                        })
                        .catch(err => alert('An error occurred.'));
                    }
                });
            });
        }
        attachDeleteListeners(); // Run on initial load
        
        // 9. (NEW) Emoji Picker Logic
        const emojiToggle = document.getElementById('emoji-toggle-btn');
        if (emojiPicker && emojiToggle && messageInput) {
            emojiToggle.addEventListener('click', () => {
                emojiPicker.classList.toggle('visible');
            });
            
            emojiPicker.addEventListener('emoji-click', event => {
                messageInput.value += event.detail.unicode;
                adjustHeight();
                messageInput.focus();
            });
            
            // Close picker if you click outside
            document.addEventListener('click', function(event) {
                if (messageInput && emojiToggle && emojiPicker) {
                    if (!messageInput.contains(event.target) && !emojiToggle.contains(event.target) && !emojiPicker.contains(event.target)) {
                        emojiPicker.classList.remove('visible');
                    }
                }
            });
        }

    });
</script>
</body>
</html>