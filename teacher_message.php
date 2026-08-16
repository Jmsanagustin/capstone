<?php
// FILE: teacher_message.php
// ... (previous changelogs) ...
// ---
// ENHANCEMENTS:
// 1. (Previous enhancements...)
// 2. NEW: Added full mobile responsiveness.
// 3. NEW: Added "Back" button for mobile chat.
// 4. NEW: Added hamburger menu for mobile sidebar.
// 5. REMOVED: "Manage Students" link from sidebar.
// 6. FIX: Corrected Sidebar Logo positioning and alignment.

session_start();
include 'db.php'; 

// =========================================================================
// 1. GLOBAL SETUP & ACCESS CONTROL
// =========================================================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    header("Location: index.php");
    exit();
}

$current_user_id = $_SESSION['sid'] ?? $_SESSION['user_id'] ?? 0;
$current_user_name = $_SESSION['username'] ?? 'Professor';
$teacher_name = $_SESSION['name'] ?? $current_user_name;
$active_page = basename($_SERVER['PHP_SELF']); // For sidebar highlighting

$error_message = "";
$success_message = "";
$db_error = "";

$selected_conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : null;

// Initialize data containers
$conversations = [];
$messages = [];
$current_conversation_receiver_id = null;
$other_user_name = 'Select Conversation';
$other_user_role = '';
$is_part_of_conversation = false;

try {
    if (!$conn) {
        throw new PDOException("Database connection object (\$conn) is not initialized.");
    }
    
    // --- Always fetch conversations for sidebar ---
    $stmt_conv_fetch = $conn->prepare("
        SELECT 
            c.conversation_id, c.updated_at,
            CASE 
                WHEN c.user_1_id = :current_user_id THEN c.user_2_id 
                ELSE c.user_1_id 
            END AS other_user_id,
            CASE 
                WHEN c.user_1_id = :current_user_id THEN CONCAT(up2.first_name, ' ', up2.last_name)
                ELSE CONCAT(up1.first_name, ' ', up1.last_name)
            END AS other_full_name,
            CASE 
                WHEN c.user_1_id = :current_user_id THEN u2.role
                ELSE u1.role
            END AS other_role,
            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.conversation_id AND m.receiver_id = :current_user_id AND m.is_read = 0) AS unread_count
        FROM conversations c
        JOIN users u1 ON c.user_1_id = u1.sid
        JOIN users u2 ON c.user_2_id = u2.sid
        LEFT JOIN user_profiles up1 ON u1.sid = up1.sid
        LEFT JOIN user_profiles up2 ON u2.sid = up2.sid
        WHERE c.user_1_id = :current_user_id OR c.user_2_id = :current_user_id
        ORDER BY c.updated_at DESC
    ");
    $stmt_conv_fetch->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
    $stmt_conv_fetch->execute();
    $conversations = $stmt_conv_fetch->fetchAll(PDO::FETCH_ASSOC);

    // =========================================================================
    // A. HANDLE POST REQUESTS
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // --- Message Sending ---
        if (isset($_POST['send_message']) && isset($_GET['conversation_id'])) {
            $selected_conversation_id = (int)$_GET['conversation_id'];
            $body = trim($_POST['body'] ?? '');
            $receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : null;
            if (!empty($body) && $receiver_id) {
                try {
                    $conn->beginTransaction();
                    $stmt_msg = $conn->prepare("INSERT INTO messages (conversation_id, sender_id, receiver_id, body, sent_at) VALUES (:conv_id, :sender_id, :receiver_id, :body, NOW())");
                    $stmt_msg->execute([':conv_id' => $selected_conversation_id, ':sender_id' => $current_user_id, ':receiver_id' => $receiver_id, ':body' => $body]);
                    $stmt_conv = $conn->prepare("UPDATE conversations SET updated_at = NOW() WHERE conversation_id = :conv_id");
                    $stmt_conv->execute([':conv_id' => $selected_conversation_id]);
                    $conn->commit();
                    header("Location: teacher_message.php?conversation_id=" . $selected_conversation_id);
                    exit();
                } catch (PDOException $e) { $conn->rollBack(); $error_message = "Database error: " . $e->getMessage(); }
            } else { $error_message = "Message body cannot be empty."; }
        }

        // --- Start New Conversation ---
        elseif (isset($_POST['start_conversation'])) {
            $recipient_id = (int)($_POST['recipient_sid'] ?? 0);
            
            if ($recipient_id > 0) {
                if ($recipient_id == $current_user_id) {
                    $error_message = "You cannot start a conversation with yourself.";
                } else {
                    $stmt_check_conv = $conn->prepare("SELECT conversation_id FROM conversations WHERE (user_1_id = :user1 AND user_2_id = :user2) OR (user_1_id = :user2 AND user_2_id = :user1)");
                    $stmt_check_conv->execute([':user1' => $current_user_id, ':user2' => $recipient_id]);
                    $existing_conversation = $stmt_check_conv->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing_conversation) {
                        header("Location: teacher_message.php?conversation_id=" . $existing_conversation['conversation_id']);
                        exit();
                    } else {
                        $stmt_create_conv = $conn->prepare("INSERT INTO conversations (user_1_id, user_2_id, updated_at) VALUES (:user1, :user2, NOW())");
                        $user1 = min($current_user_id, $recipient_id);
                        $user2 = max($current_user_id, $recipient_id);
                        $stmt_create_conv->execute([':user1' => $user1, ':user2' => $user2]);
                        $new_conversation_id = $conn->lastInsertId();
                        header("Location: teacher_message.php?conversation_id=" . $new_conversation_id);
                        exit();
                    }
                }
            } else {
                $error_message = "Please select a valid user from the search suggestions.";
            }
        }
    }

    // =========================================================================
    // B. FETCH DATA FOR THIS VIEW
    // =========================================================================
    
    if ($selected_conversation_id) {
        $stmt_conv_check = $conn->prepare("SELECT user_1_id, user_2_id FROM conversations WHERE conversation_id = :conv_id");
        $stmt_conv_check->execute([':conv_id' => $selected_conversation_id]);
        $conv_info = $stmt_conv_check->fetch(PDO::FETCH_ASSOC);

        if ($conv_info && ($conv_info['user_1_id'] == $current_user_id || $conv_info['user_2_id'] == $current_user_id)) {
            $is_part_of_conversation = true;
            $current_conversation_receiver_id = ($conv_info['user_1_id'] == $current_user_id) ? $conv_info['user_2_id'] : $conv_info['user_1_id'];
            
            foreach ($conversations as $conv) {
                if ($conv['conversation_id'] == $selected_conversation_id) {
                    $other_user_name = $conv['other_full_name'];
                    $other_user_role = $conv['other_role'];
                    break;
                }
            }
            
            $stmt_messages = $conn->prepare("
                SELECT m.sender_id, m.body, m.sent_at, CONCAT(up.first_name, ' ', up.last_name) AS sender_full_name
                FROM messages m
                JOIN users u ON m.sender_id = u.sid
                LEFT JOIN user_profiles up ON u.sid = up.sid
                WHERE m.conversation_id = :conv_id
                ORDER BY m.sent_at ASC
            ");
            $stmt_messages->execute([':conv_id' => $selected_conversation_id]);
            $messages = $stmt_messages->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt_mark_read = $conn->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = :conv_id AND receiver_id = :current_user_id AND is_read = 0");
            $stmt_mark_read->execute([':conv_id' => $selected_conversation_id, ':current_user_id' => $current_user_id]);
        }
    }

} catch (PDOException $e) {
    if (str_contains($e->getMessage(), '1267')) {
         $db_error = "❌ **Database Collation Error:** Your tables have conflicting text settings. Please run the `ALTER TABLE ... CONVERT TO ...` queries provided to fix this.";
    } else {
         $db_error = "Database error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instructor Portal - Messages</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
    --nav-dark:#0f4a73; --panel-blue:#1f6ea3; --bg-light:#f4f7fa;
    --white:#fff; --text-dark:#102a43; --text-light:#627d98;
    --accent-light:#4db8ff; --shadow: 0 4px 12px rgba(0,0,0,0.05);
    --shadow-md: 0 6px 15px rgba(0,0,0,0.08); --critical:#b91c1c;
    --warning:#a16207; --muted:#888; --success-green: #166534;
    --border-color: #e5e7eb;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100vh; overflow: hidden; }
body{font-family: 'Inter', 'Segoe UI', sans-serif;margin:0;background:var(--bg-light);color:var(--text-dark); display: flex;}

/* --- Sidebar Styles (Updated for correct logo alignment) --- */
.sidebar { 
    width: 260px; background: var(--nav-dark); color: var(--white); 
    display: flex; flex-direction: column; flex-shrink: 0; 
    height: 100vh; transition: transform 0.3s ease-in-out; 
    z-index: 2000;
}
/* Fixed Header Layout */
.sidebar-header { 
    padding: 24px 20px; 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    gap: 15px; 
    border-bottom: 1px solid rgba(255, 255, 255, 0.1); 
}
/* Logo Wrapper Layout */
.sidebar-header-logo { 
    text-decoration: none; 
    color: white; 
    display: flex; 
    align-items: center; 
    gap: 10px; 
}
/* Logo Image Sizing */
.sidebar-header img { 
    width: 32px; 
    height: 32px; 
    border-radius: 4px; 
}
/* Text Layout */
.sidebar-header h2 { 
    font-size: 1.15rem; 
    font-weight: 600; 
    line-height: 1.3; 
    margin: 0; 
}
.sidebar-header h2 span { 
    font-size: 0.8rem; 
    font-weight: 400; 
    color: #9ac8ee; 
    display: block; 
}

/* Nav */
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

/* --- Main Content & Header --- */
.main-content { 
    flex-grow: 1; display: flex; flex-direction: column; 
    height: 100vh; overflow: hidden; /* Keep this */
    margin-left: 0; /* Default for mobile/flex logic handled by media queries below */
    transition: margin-left 0.3s ease;
    width: 100%;
}
.header { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; background: var(--white); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
.menu-toggle {
    display: none; /* Hidden on desktop */
    font-size: 1.5rem;
    color: var(--text-dark);
    background: none;
    border: none;
    cursor: pointer;
    padding: 5px;
}
.toggle-button {
    background: none; border: none; cursor: pointer; color: var(--text-dark); padding: 4px;
}
.header-title { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); }
.user-profile { display: flex; align-items: center; gap: 12px; }
.user-profile .icon-button { font-size: 1.2rem; color: var(--text-light); text-decoration: none; padding: 8px; border-radius: 50%; transition: background 0.2s ease; position: relative; }
.user-profile .icon-button:hover { background: var(--bg-light); }
.user-profile .unread-badge {
    background: var(--critical); color: var(--white); position: absolute; top: 0; right: 0;
    border-radius: 50%; padding: 2px 6px; font-size: 0.7rem; font-weight: 700;
}
.user-profile-info { text-align: right; }
.user-profile-info strong { display: block; font-weight: 600; color: var(--text-dark); }
.user-profile-info span { font-size: 0.85rem; color: var(--text-light); }
.page-view { flex-grow: 1; overflow-y: auto; padding: 0; } /* Full-bleed for messages */
.message-box{padding:10px 15px; border-radius:8px; margin-bottom:15px; font-weight:bold;}
.error-message{background: #fef2f2; color: var(--critical); border: 1px solid #fecaca;}
.success-message{background: #f0fdf4; color: var(--success-green); border: 1px solid #bbf7d0;}
.no-data { text-align: center; padding: 30px; color: var(--text-light); }
input[type="text"], input[type="search"], select, textarea {
    width: 100%;
    padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 14px;
    background: #f9fafb;
}
input[type="text"]:focus, input[type="search"]:focus, textarea:focus {
    outline: none;
    border-color: var(--panel-blue);
    background: var(--white);
    box-shadow: 0 0 0 2px rgba(31, 110, 163, 0.2);
}
/* --- Messaging Specific Styles --- */
.messaging-layout { display: flex; height: 100%; position: relative; overflow: hidden; }
.conversation-sidebar { width: 320px; background: var(--white); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; flex-shrink: 0; transition: transform 0.3s ease; }
.conversation-header { padding: 16px; border-bottom: 1px solid var(--border-color); }
.conversation-header h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 4px;}
.conversation-header p { font-size: 0.85rem; color: var(--text-light); }
.new-conv-btn { 
    background: var(--panel-blue); color: var(--white); padding: 8px 12px; border-radius: 6px; 
    font-size: 0.85rem; font-weight: 500; border: none; cursor: pointer; 
    transition: background 0.2s ease; margin-top: 10px; width: 100%;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.new-conv-btn:hover { background: var(--nav-dark); }
.conversation-list { flex-grow: 1; overflow-y: auto; padding: 8px; }
.conversation-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-radius: 8px; text-decoration: none; color: var(--text-dark); margin-bottom: 4px; transition: background 0.2s ease; }
.conversation-item:hover { background: var(--bg-light); }
.conversation-item.active { background: #eef2ff; color: #312e81; font-weight: 600; }
.conversation-item-details { display: flex; align-items: center; gap: 12px; }
.conversation-item-details i { font-size: 1.5rem; color: var(--text-light); }
.conversation-item.active i { color: var(--panel-blue); }
.conversation-item-details div { font-size: 0.9rem; }
.conversation-item-details div span.name { font-weight: 600; color: var(--text-dark); }
.conversation-item.active div span.name { color: #312e81; }
.conversation-item-details div span.role { font-size: 0.8rem; color: var(--text-light); text-transform: capitalize; }
.unread-badge { background: var(--critical); color: var(--white); font-size: 0.7rem; font-weight: 700; border-radius: 50%; padding: 2px 6px; line-height: 1; }
.message-view { flex-grow: 1; display: flex; flex-direction: column; background: var(--bg-light); }
.message-header { display: flex; align-items: center; gap: 12px; padding: 16px 20px; background: var(--white); border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
/* NEW: Mobile Back Button */
.back-button {
    display: none; /* Hidden on desktop */
    font-size: 1.2rem;
    color: var(--text-dark);
    text-decoration: none;
    margin-right: 10px;
    padding: 5px;
}
.message-header i { font-size: 1.3rem; color: var(--text-light); }
.message-header div h3 { font-size: 1.1rem; font-weight: 600; }
.message-header div span { font-size: 0.85rem; color: var(--text-light); text-transform: capitalize; }
#message-area { flex-grow: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; }
.message-bubble-container { display: flex; }
.message-bubble-container.sent { justify-content: flex-end; }
.message-bubble-container.received { justify-content: flex-start; }
.message-bubble { max-width: 70%; padding: 10px 15px; border-radius: 18px; line-height: 1.5; font-size: 0.9rem; }
.message-bubble.sent { background: var(--panel-blue); color: var(--white); border-bottom-right-radius: 4px; }
.message-bubble.received { background: var(--white); color: var(--text-dark); border: 1px solid #e0e6ed; border-bottom-left-radius: 4px; }
.message-bubble .sender-name { font-size: 0.75rem; font-weight: 600; margin-bottom: 4px; color: var(--text-light); }
.message-bubble .timestamp { font-size: 0.7rem; margin-top: 5px; text-align: right; }
.message-bubble.sent .timestamp { color: rgba(255, 255, 255, 0.7); }
.message-bubble.received .timestamp { color: var(--text-light); }
.message-input-area { padding: 16px; background: var(--white); border-top: 1px solid var(--border-color); flex-shrink: 0; }
.message-input-area form { display: flex; gap: 10px; }
.message-input-area textarea { flex-grow: 1; border: 1px solid var(--border-color); border-radius: 20px; padding: 10px 15px; font-size: 0.9rem; resize: none; min-height: 40px; max-height: 120px; overflow-y: auto; }
.message-input-area button { background: var(--panel-blue); color: var(--white); border: none; border-radius: 50%; width: 40px; height: 40px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1rem; cursor: pointer; transition: background 0.2s ease; }
.message-input-area button:hover { background: var(--nav-dark); }
.select-conversation-prompt { text-align: center; padding: 40px; color: var(--text-light); }
/* Modal Styles */
.modal { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { position: relative; background: var(--white); padding: 25px; border-radius: 8px; width: 90%; max-width: 450px; box-shadow: var(--shadow-md); }
.modal-content h3 { font-size: 1.2rem; margin-bottom: 15px; text-align: left; }
.modal-content form { text-align: left; }
.modal-content form label { font-weight: 600; font-size: 0.9rem; margin-bottom: 5px; display: block; }
.modal-content form input[type="search"] { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; margin-bottom: 5px; }
.modal-content form button { width: 100%; padding: 10px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; margin-top: 15px; }
.modal-content form button[type="submit"] { background: var(--panel-blue); color: var(--white); }
.modal-content form button[type="submit"]:disabled { background: #9ca3af; cursor: not-allowed; }
.modal-close-btn { position: absolute; top: 10px; right: 10px; border: none; background: none; font-size: 1.5rem; cursor: pointer; color: var(--text-light); line-height: 1; }
.modal-cancel-btn { margin-top: 10px; background: #eee; color: var(--text-dark); }
/* NEW: Search Suggestions */
#search-suggestions {
    border: 1px solid var(--border-color);
    border-radius: 6px;
    max-height: 200px;
    overflow-y: auto;
    background: var(--white);
    display: none; /* Hidden by default */
}
.suggestion-item {
    padding: 10px;
    cursor: pointer;
    border-bottom: 1px solid var(--border-color);
}
.suggestion-item:last-child { border-bottom: none; }
.suggestion-item:hover { background: var(--bg-light); }
.suggestion-item strong { color: var(--text-dark); }
.suggestion-item span { font-size: 0.85rem; color: var(--text-light); text-transform: capitalize; }
/* Custom Scrollbar */
.custom-scrollbar::-webkit-scrollbar { width: 8px; }
.custom-scrollbar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

/* NEW: Overlay for when sidebar is open */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1999;
    display: none; /* Hidden by default */
}
.sidebar-overlay.is-open {
    display: block;
}

/* Helper class for responsive hiding */
.lg-hidden { display: none; }

/* ====================================================== */
/* DESKTOP (Default > 1024px) */
/* ====================================================== */
@media (min-width: 1024px) {
    .sidebar { transform: translateX(0); position: fixed; }
    .main-content { margin-left: 260px; width: calc(100% - 260px); }
    .lg-hidden { display: none !important; }
}

/* ====================================================== */
/* NEW: Mobile Responsiveness (< 1024px) */
/* ====================================================== */
@media (max-width: 1023px) {
    body {
        overflow-y: auto; /* Allow body scroll only if needed, but layout is 100vh */
    }
    
    .sidebar {
        transform: translateX(-260px); /* Hidden by default */
    }
    
    .sidebar.is-open {
        transform: translateX(0);
    }

    .main-content {
        width: 100%;
        margin-left: 0;
    }
    
    .header {
        padding: 12px 16px;
    }

    .header-title {
        display: none; /* Hide desktop title */
    }
    
    .lg-hidden { display: block; } /* Show hamburger */

    .menu-toggle {
        display: block; /* Show hamburger */
        margin-right: 0;
        margin-left: -5px;
    }
    
    /* Make user profile info smaller */
    .user-profile { gap: 8px; }
    .user-profile-info strong { font-size: 0.9rem; }
    .user-profile-info span { display: none; } /* Hide role on mobile */
    .user-profile .icon-button { margin-left: auto; } /* Push envelope icon to the right */

    /* --- Mobile Chat Layout --- */
    .conversation-sidebar {
        width: 100%;
        height: 100%;
        position: absolute;
        left: 0;
        top: 0;
        z-index: 100;
        transition: transform 0.3s ease;
        transform: translateX(0);
        border-right: none;
    }
    .message-view {
        width: 100%;
        height: 100%;
    }
    
    /* This class is added by PHP based on URL */
    .messaging-layout.show-chat .conversation-sidebar {
        transform: translateX(-100%); /* Slide list out */
    }
    .messaging-layout.show-list .message-view {
        display: none; /* Hide message view entirely */
    }
    
    .back-button {
        display: block; /* Show back button in chat header */
    }
    .message-header i.fa-user {
        display: none; /* Hide default user icon to make space */
    }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="teacher_dashboard.php" class="sidebar-header-logo">
            <img src="dfcam-logo.png" alt="DFCAMCLP Logo"> 
            <h2>DFCAMCLP<span>Instructor Portal</span></h2>
        </a>
        <button class="toggle-button lg-hidden" onclick="toggleSidebar()">
            <i class="fa-solid fa-times" style="color: var(--white);"></i>
        </button>
    </div>
    <nav class="sidebar-nav custom-scrollbar">
        <ul>
            <li><a href="teacher_dashboard.php" class="nav-link" data-view="dashboard"><i class="fa-solid fa-tachometer-alt"></i><span>Dashboard</span></a></li>
            <li><a href="input_grades.php" class="nav-link" data-view="grade_input"><i class="fa-solid fa-file-upload"></i><span>Grade Input</span></a></li>
            <li><a href="grade_management.php" class="nav-link" data-view="grade_management"><i class="fa-solid fa-archive"></i><span>Grade Management</span></a></li>
            <li><a href="teacher_message.php" class="nav-link" data-view="messages"><i class="fa-solid fa-comments"></i><span>Messages</span></a></li>
            <li><a href="teacher_manage_students.php" class="nav-link" data-view="manage_students"><i class="fa-solid fa-users-cog"></i><span>Manage Students</span></a></li>
            <li><a href="attendance.php" class="nav-link" data-view="attendance"><i class="fa-solid fa-clipboard-user"></i><span>Attendance</span></a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="main-content" id="main-content">

    <header class="header">
        <button class="menu-toggle" id="menu-toggle">
            <i class="fa-solid fa-bars"></i>
        </button>
        
        <h1 class="header-title">Message Center</h1>
        
        <div class="user-profile">
            <a href="teacher_message.php" class="icon-button" title="Messages">
                <i class="fa-solid fa-envelope"></i>
                <?php 
                    $total_unread = array_sum(array_column($conversations, 'unread_count'));
                    if ($total_unread > 0): 
                ?>
                    <span class="unread-badge" id="global-unread-badge"><?= $total_unread ?></span>
                <?php endif; ?>
            </a>
            <div class="user-profile-info">
                <strong><?php echo htmlspecialchars($teacher_name); ?></strong>
                <span><?php echo htmlspecialchars($_SESSION['role']); ?></span>
            </div>
        </div>
    </header>

    <main class="page-view custom-scrollbar messages-view">
        
        <?php if ($db_error): ?>
            <div class="message-box error-message"><?php echo $db_error; ?></div>
        <?php endif; ?>
    
        <div class="messaging-layout <?php echo $selected_conversation_id ? 'show-chat' : 'show-list'; ?>">
            <aside class="conversation-sidebar">
                <div class="conversation-header">
                    <h2>Conversations</h2>
                    <p>Logged in as: <?= htmlspecialchars($current_user_name); ?></p>
                    <button id="new-conversation-btn" class="new-conv-btn">
                        <i class="fas fa-plus"></i> New Conversation
                    </button>
                </div>
                <nav class="conversation-list custom-scrollbar" id="conversation-list-area">
                    <?php if (empty($conversations)): ?>
                        <p class="no-data" style="padding: 15px;">No conversations yet.</p>
                    <?php endif; ?>
                    </nav>
            </aside>
            <div class="message-view">
                <header class="message-header">
                    <a href="teacher_message.php" class="back-button">
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
                    <?php else: ?>
                        <p class="select-conversation-prompt">Select a conversation or start a new one.</p>
                    <?php endif; ?>
                </div>
                <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
                <footer class="message-input-area">
                        <?php if ($error_message): ?><p style="color:red; font-size: 0.8rem; margin-bottom: 5px;"><?= htmlspecialchars($error_message); ?></p><?php endif; ?>
                    <form action="teacher_message.php?conversation_id=<?= $selected_conversation_id; ?>" method="POST">
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
                <button id="modal-close-x-btn" class="modal-close-btn">&times;</button>
                <h3>Start New Conversation</h3>
                
                <div class="search-form" style="text-align: left;">
                    <label for="recipient-search">Search by Name:</label>
                    <input type="search" id="recipient-search" placeholder="Type a name to search..." autocomplete="off">
                    <div id="search-suggestions" class="custom-scrollbar">
                        </div>
                </div>

                <form action="teacher_message.php" method="POST" id="new-conv-form" style="text-align: center;">
                    <?php if (!empty($error_message) && isset($_POST['start_conversation'])): ?>
                        <p class="message-box error-message" style="text-align: left; margin-bottom: 10px;"><?= htmlspecialchars($error_message); ?></p>
                    <?php endif; ?>
                    
                    <input type="hidden" name="recipient_sid" id="recipient-sid-input" value="">
                    
                    <button type="submit" name="start_conversation" id="start-conv-submit-btn" disabled>
                        <i class="fas fa-plus"></i> Start Conversation
                    </button>
                    <button type="button" id="modal-cancel-btn" class="modal-cancel-btn">Cancel</button>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
// --- START: Full JavaScript Logic ---

// --- Global variables for real-time polling ---
const currentUserId = <?php echo json_encode($current_user_id); ?>;
const selectedConvId = <?php echo json_encode($selected_conversation_id); ?>;
let messagePollInterval;
let conversationPollInterval;

/**
 * Scrolls the message area to the bottom.
 */
function scrollMessageAreaToBottom() {
    const messageArea = document.getElementById('message-area');
    if (messageArea) {
        messageArea.scrollTop = messageArea.scrollHeight;
    }
}

/**
 * Renders a single message bubble in the chat window.
 */
function renderMessage(msg) {
    const messageArea = document.getElementById('message-area');
    if (!messageArea) return;

    const bubbleContainer = document.createElement('div');
    const isSent = msg.sender_id == currentUserId;
    bubbleContainer.className = 'message-bubble-container ' + (isSent ? 'sent' : 'received');
    
    // Format timestamp
    const date = new Date(msg.sent_at);
    const timestamp = date.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });

    let senderNameHtml = '';
    if (!isSent) {
        senderNameHtml = `<p class="sender-name">${escapeHTML(msg.sender_full_name || 'User')}</p>`;
    }

    bubbleContainer.innerHTML = `
        <div class="message-bubble ${isSent ? 'sent' : 'received'}">
            ${senderNameHtml}
            <p>${escapeHTML(msg.body).replace(/\n/g, '<br>')}</p>
            <p class="timestamp">${timestamp}</p>
        </div>
    `;
    messageArea.appendChild(bubbleContainer);
}

/**
 * Fetches new messages from the server and updates the chat.
 */
async function fetchNewMessages() {
    if (!selectedConvId) return;

    try {
        const response = await fetch(`api_get_messages.php?conversation_id=${selectedConvId}`);
        if (!response.ok) return;

        const result = await response.json();
        if (result.status === 'success' && result.messages) {
            const messageArea = document.getElementById('message-area');
            const shouldScroll = messageArea.scrollTop + messageArea.clientHeight >= messageArea.scrollHeight - 50; // Check if user is at the bottom

            messageArea.innerHTML = ''; // Clear all messages
            
            if (result.messages.length === 0) {
                 messageArea.innerHTML = '<p class="no-data">No messages yet. Say hello!</p>';
            } else {
                result.messages.forEach(msg => renderMessage(msg));
            }
            
            if (shouldScroll) {
                scrollMessageAreaToBottom();
            }
        }
    } catch (error) {
        console.error("Error fetching messages:", error);
    }
}

/**
 * Renders a single conversation item in the sidebar.
 */
function renderConversation(conv) {
    const listArea = document.getElementById('conversation-list-area');
    if (!listArea) return;

    const link = document.createElement('a');
    link.href = `teacher_message.php?conversation_id=${conv.conversation_id}`;
    link.className = 'conversation-item ' + (selectedConvId == conv.conversation_id ? 'active' : '');

    let unreadBadge = '';
    if (conv.unread_count > 0) {
        unreadBadge = `<span class="unread-badge">${conv.unread_count}</span>`;
    }

    link.innerHTML = `
        <div class="conversation-item-details">
            <i class="fa-regular fa-user-circle"></i>
            <div>
                <span class="name">${escapeHTML(conv.other_full_name || 'Unknown User')}</span><br>
                <span class="role">${escapeHTML(conv.other_role || 'User')}</span>
            </div>
        </div>
        ${unreadBadge}
    `;
    listArea.appendChild(link);
}

/**
 * Fetches the conversation list and updates the sidebar.
 */
async function fetchConversations() {
    try {
        const response = await fetch(`api_get_messages.php?action=get_conversations`);
        if (!response.ok) return;

        const result = await response.json();
        if (result.status === 'success' && result.conversations) {
            const listArea = document.getElementById('conversation-list-area');
            const globalUnreadBadge = document.getElementById('global-unread-badge');
            
            listArea.innerHTML = ''; // Clear list
            let totalUnread = 0;

            if (result.conversations.length === 0) {
                listArea.innerHTML = '<p class="no-data" style="padding: 15px;">No conversations yet.</p>';
            } else {
                result.conversations.forEach(conv => {
                    renderConversation(conv);
                    totalUnread += parseInt(conv.unread_count, 10);
                });
            }

            // Update global unread badge in header
            if (globalUnreadBadge) {
                if (totalUnread > 0) {
                    globalUnreadBadge.textContent = totalUnread;
                    globalUnreadBadge.style.display = 'inline-block';
                } else {
                    globalUnreadBadge.style.display = 'none';
                }
            }
        }
    } catch (error) {
        console.error("Error fetching conversations:", error);
    }
}

/**
 * Helper to escape HTML to prevent XSS.
 */
function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, function(m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[m];
    });
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    sidebar.classList.toggle('is-open');
    overlay.classList.toggle('is-open');
}

document.addEventListener("DOMContentLoaded", function() {
    
    // --- NEW: Mobile Sidebar Toggle ---
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menu-toggle');
    const overlay = document.getElementById('sidebar-overlay');

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('is-open');
            overlay.classList.toggle('is-open');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('is-open');
            overlay.classList.remove('is-open');
        });
    }
    
    // Initial scroll to bottom
    scrollMessageAreaToBottom();
    
    // Start real-time polling
    if (selectedConvId) {
        messagePollInterval = setInterval(fetchNewMessages, 5000); // Poll messages every 5s
    }
    conversationPollInterval = setInterval(fetchConversations, 5000); // Poll conversation list every 5s
    fetchConversations(); // Initial load of conversation list

    // --- Modal Logic for New Conversation ---
    const newConvBtn = document.getElementById('new-conversation-btn');
    const modal = document.getElementById('new-conversation-modal');
    const modalCloseXBtn = document.getElementById('modal-close-x-btn');
    const modalCancelBtn = document.getElementById('modal-cancel-btn');
    
    const searchInput = document.getElementById('recipient-search');
    const suggestionsBox = document.getElementById('search-suggestions');
    const recipientSidInput = document.getElementById('recipient-sid-input');
    const startConvBtn = document.getElementById('start-conv-submit-btn');

    if (newConvBtn) {
        newConvBtn.addEventListener('click', () => {
            if (modal) modal.style.display = 'flex';
        });
    }

    const closeModal = () => {
        if (modal) modal.style.display = 'none';
        // Clear search
        searchInput.value = '';
        suggestionsBox.innerHTML = '';
        suggestionsBox.style.display = 'none';
        recipientSidInput.value = '';
        startConvBtn.disabled = true;
    };

    if (modalCloseXBtn) modalCloseXBtn.addEventListener('click', closeModal);
    if (modalCancelBtn) modalCancelBtn.addEventListener('click', closeModal);
    
    // Auto-show modal if PHP error exists
    <?php if (!empty($error_message) && isset($_POST['start_conversation'])): ?>
    if (modal) modal.style.display = 'flex';
    <?php endif; ?>

    // --- Live Search Logic ---
    if (searchInput) {
        searchInput.addEventListener('input', async function() {
            const query = searchInput.value;
            
            // Clear selection
            recipientSidInput.value = '';
            startConvBtn.disabled = true;

            if (query.length < 2) {
                suggestionsBox.innerHTML = '';
                suggestionsBox.style.display = 'none';
                return;
            }

            try {
                const response = await fetch(`api_search_users.php?query=${encodeURIComponent(query)}`);
                const users = await response.json();

                suggestionsBox.innerHTML = '';
                if (users.length > 0) {
                    users.forEach(user => {
                        const item = document.createElement('div');
                        item.className = 'suggestion-item';
                        item.innerHTML = `<strong>${escapeHTML(user.full_name)}</strong><br><span>${escapeHTML(user.role)}</span>`;
                        item.onclick = () => {
                            // User selected
                            searchInput.value = user.full_name;
                            recipientSidInput.value = user.sid;
                            startConvBtn.disabled = false;
                            suggestionsBox.style.display = 'none';
                        };
                        suggestionsBox.appendChild(item);
                    });
                    suggestionsBox.style.display = 'block';
                } else {
                    suggestionsBox.innerHTML = '<div class="suggestion-item"><span>No users found.</span></div>';
                    suggestionsBox.style.display = 'block';
                }
            } catch (error) {
                console.error("Search error:", error);
                suggestionsBox.innerHTML = '<div class="suggestion-item"><span>Error loading results.</span></div>';
                suggestionsBox.style.display = 'block';
            }
        });
    }
});
</script>
</body>
</html>