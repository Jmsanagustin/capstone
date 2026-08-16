<?php
// FILE: registrar_messages.php (Messages View)
// PURPOSE: Centralized messaging system for the Registrar.
// VERSION 5.2: Logic confirmed for Registrar initiation control.

// --- 1. SESSION & INCLUDES ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db.php'; 

// --- 2. AUTHORIZATION ---
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Registrar')) {
    header("Location: index.php");
    exit();
}
$current_user_id = $_SESSION['sid'];
$current_view = 'messages'; 
$start_conversation_error = '';
$send_error = '';
$db_error = '';


// -----------------------------------------------------------------
// --- 3. CORE MANAGEMENT LOGIC: Hide, Unhide, Delete Full ---
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['hide_conversation', 'unhide_conversation', 'delete_full_conversation'])) {
    $conversation_id = filter_input(INPUT_POST, 'conversation_id', FILTER_VALIDATE_INT);
    $redirect_url = 'registrar_messages.php';

    if ($conversation_id) {
        try {
            $stmt_check = $conn->prepare("SELECT user_1_id FROM conversations WHERE conversation_id = :conv_id AND (user_1_id = :user_id OR user_2_id = :user_id)");
            $stmt_check->execute([':conv_id' => $conversation_id, ':user_id' => $current_user_id]);
            $convo = $stmt_check->fetch();
            
            if ($convo) {
                $is_user_1 = ($convo['user_1_id'] == $current_user_id);
                $field_to_update = $is_user_1 ? 'user_1_hidden' : 'user_2_hidden';
                
                $conn->beginTransaction();

                if ($_POST['action'] === 'hide_conversation') {
                    $sql = "UPDATE conversations SET {$field_to_update} = 1, updated_at = NOW() WHERE conversation_id = :conv_id";
                    $conn->prepare($sql)->execute([':conv_id' => $conversation_id]);
                    
                } elseif ($_POST['action'] === 'unhide_conversation') {
                    $sql = "UPDATE conversations SET {$field_to_update} = 0, updated_at = NOW() WHERE conversation_id = :conv_id";
                    $conn->prepare($sql)->execute([':conv_id' => $conversation_id]);
                    
                } elseif ($_POST['action'] === 'delete_full_conversation') {
                    // Foreign key constraint on messages will cascade deletion if needed, but explicit deletion is safer.
                    $sql_delete_messages = "DELETE FROM messages WHERE conversation_id = :conv_id";
                    $conn->prepare($sql_delete_messages)->execute([':conv_id' => $conversation_id]);
                    
                    $sql_delete_conv = "DELETE FROM conversations WHERE conversation_id = :conv_id";
                    $conn->prepare($sql_delete_conv)->execute([':conv_id' => $conversation_id]);
                    $redirect_url = 'registrar_messages.php';
                }
                
                $conn->commit();
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $db_error = "Error managing conversation: " . $e->getMessage();
        }
    }
    header("Location: " . $redirect_url);
    exit();
}

// -----------------------------------------------------------------
// --- 4. SINGLE MESSAGE DELETE LOGIC ---
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_message') {
    $message_id = filter_input(INPUT_POST, 'message_id', FILTER_VALIDATE_INT);
    $conversation_id = filter_input(INPUT_POST, 'conversation_id', FILTER_VALIDATE_INT);

    if ($message_id && $conversation_id) {
        try {
            $stmt_delete = $conn->prepare("DELETE FROM messages WHERE message_id = :msg_id AND (sender_id = :user_id OR receiver_id = :user_id)");
            $stmt_delete->execute([':msg_id' => $message_id, ':user_id' => $current_user_id]);

        } catch (PDOException $e) {
            $db_error = "Error deleting message: " . $e->getMessage();
        }
    }
    header("Location: registrar_messages.php?conversation_id=" . $conversation_id);
    exit();
}


// -----------------------------------------------------------------
// --- 5. NEW CONVERSATION LOGIC ---
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_conversation'])) {
    $recipient_id = (int)($_POST['recipient_sid'] ?? 0);
    
    if ($recipient_id > 0) {
        if ($recipient_id == $current_user_id) {
            $start_conversation_error = "You cannot start a conversation with yourself.";
        } else {
            try {
                $stmt_check_conv = $conn->prepare("
                    SELECT conversation_id FROM conversations
                    WHERE (user_1_id = :user1 AND user_2_id = :user2) OR (user_1_id = :user2 AND user_2_id = :user1)
                ");
                $stmt_check_conv->execute([':user1' => $current_user_id, ':user2' => $recipient_id]);
                $existing_conversation = $stmt_check_conv->fetch(PDO::FETCH_ASSOC);

                if ($existing_conversation) {
                    header("Location: registrar_messages.php?conversation_id=" . $existing_conversation['conversation_id']);
                    exit();
                } else {
                    $stmt_create_conv = $conn->prepare("INSERT INTO conversations (user_1_id, user_2_id, updated_at) VALUES (:user1, :user2, NOW())");
                    $user1 = min($current_user_id, $recipient_id);
                    $user2 = max($current_user_id, $recipient_id);
                    $stmt_create_conv->execute([':user1' => $user1, ':user2' => $user2]);
                    $new_conversation_id = $conn->lastInsertId();
                    
                    header("Location: registrar_messages.php?conversation_id=" . $new_conversation_id);
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


// -----------------------------------------------------------------
// --- 6. MESSAGE SENDING LOGIC ---
// -----------------------------------------------------------------
$selected_conversation_id = $_GET['conversation_id'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && $selected_conversation_id) {
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
            
            header("Location: registrar_messages.php?conversation_id=" . $selected_conversation_id);
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


// --- 7. DATA FETCHING FOR VIEW ---
$selected_conversation_id = $_GET['conversation_id'] ?? null;
$messages = [];
$other_user_name = 'Select Conversation';
$other_user_role = '';
$current_conversation_receiver_id = null;
$is_part_of_conversation = false;

if ($selected_conversation_id) {
    
    // Fetch conversation details
    $stmt_conv_details = $conn->prepare("
        SELECT 
            c.user_1_id, c.user_2_id, c.user_1_hidden, c.user_2_hidden,
            u1.role as user_1_role,
            u2.role as user_2_role,
            COALESCE(NULLIF(CONCAT(up1.first_name, ' ', up1.last_name), ' '), u1.username) as user_1_name,
            COALESCE(NULLIF(CONCAT(up2.first_name, ' ', up2.last_name), ' '), u2.username) as user_2_name
        FROM conversations c
        JOIN users u1 ON c.user_1_id = u1.sid
        JOIN users u2 ON c.user_2_id = u2.sid
        LEFT JOIN user_profiles up1 ON u1.sid = up1.sid
        LEFT JOIN user_profiles up2 ON u2.sid = up2.sid
        WHERE c.conversation_id = :conv_id AND (c.user_1_id = :current_user OR c.user_2_id = :current_user)
    ");
    $stmt_conv_details->execute([
        ':conv_id' => $selected_conversation_id,
        ':current_user' => $current_user_id
    ]);
    $conv_details = $stmt_conv_details->fetch(PDO::FETCH_ASSOC);

    if ($conv_details) {
        $is_part_of_conversation = true;
        
        // Determine the other user
        if ($conv_details['user_1_id'] == $current_user_id) {
            $current_conversation_receiver_id = (int)$conv_details['user_2_id'];
            $other_user_name = $conv_details['user_2_name'];
            $other_user_role = $conv_details['user_2_role'] ?? 'User';
        } else {
            $current_conversation_receiver_id = (int)$conv_details['user_1_id'];
            $other_user_name = $conv_details['user_1_name'];
            $other_user_role = $conv_details['user_1_role'] ?? 'User';
        }

        // Fetch messages
        $stmt_msgs = $conn->prepare("
            SELECT 
                m.*, 
                COALESCE(NULLIF(CONCAT(up.first_name, ' ', up.last_name), ' '), u.username) AS sender_full_name
            FROM messages m
            JOIN users u ON m.sender_id = u.sid
            LEFT JOIN user_profiles up ON u.sid = up.sid
            WHERE m.conversation_id = :conv_id ORDER BY m.sent_at ASC
        ");
        $stmt_msgs->bindParam(':conv_id', $selected_conversation_id, PDO::PARAM_INT);
        $stmt_msgs->execute();
        $messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

        // Mark messages as read
        $stmt_read = $conn->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = :conv_id AND receiver_id = :current_user_id AND is_read = 0");
        $stmt_read->bindParam(':conv_id', $selected_conversation_id, PDO::PARAM_INT);
        $stmt_read->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
        $stmt_read->execute();

    } else {
        $selected_conversation_id = null;
        $other_user_name = "Invalid Conversation";
        if (empty($db_error)) {
            $db_error = "You do not have permission to view this conversation or it does not exist.";
        }
    }
}

// --- Fetch last message for the sidebar (Assumed dependency on registrar_header.php data) ---
if (!empty($conversations)) {
    try {
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

    } catch (PDOException $e) {
        $db_error = "Error fetching last messages: " . $e->getMessage();
    }
}
?>

<?php include_once 'registrar_header.php'; ?>

<style>
    /*
    * INTERNAL STYLES FOR: registrar_messages.php
    */

    /* --- Page Layout Override --- */
    .page-view {
        padding: 0 !important; /* Override header padding */
        display: flex;
        flex-direction: column;
        overflow: hidden; /* Disable the main page scrollbar */
    }

    /* --- Main Chat Layout --- */
    .messaging-layout {
        display: flex;
        width: 100%;
        flex-grow: 1; 
        height: 100%; 
        overflow: hidden;
        background: var(--white);
        position: relative; 
    }

    /* --- 1. Conversation Sidebar (Left) --- */
    .conversation-sidebar {
        width: 320px;
        flex-shrink: 0;
        background: var(--white);
        border-right: 1px solid #e0e6ed;
        display: flex;
        flex-direction: column;
        transition: transform 0.3s ease;
    }

    .conversation-header {
        padding: 24px;
        border-bottom: 1px solid #e0e6ed;
        flex-shrink: 0;
    }
    .conversation-header h2 {
        font-size: 1.25rem;
        color: var(--text-dark);
        margin-bottom: 4px;
    }
    .conversation-header p {
        font-size: 0.875rem;
        color: var(--text-light);
        margin-bottom: 16px;
        line-height: 1.4;
    }
    .new-conv-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 12px;
        background: var(--panel-blue);
        color: var(--white);
        border: none;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }
    .new-conv-btn:hover {
        background: var(--nav-dark);
    }
    .new-conv-btn .mr-1 {
        margin-right: 8px;
    }

    .conversation-list {
        flex-grow: 1;
        overflow-y: auto;
        padding: 8px 0;
    }
    .conversation-list .no-data {
        color: var(--text-light);
        font-size: 0.9rem;
    }
    
    .conversation-item {
        display: flex;
        align-items: center;
        padding: 12px 24px;
        cursor: pointer;
        transition: background-color 0.2s ease;
        text-decoration: none;
        color: var(--text-dark);
        position: relative; 
    }
    .conversation-item:hover {
        background: var(--bg-light);
    }
    .conversation-item.active {
        background: var(--accent-light);
        color: var(--nav-dark);
        font-weight: 600;
    }
    .conversation-item.active .conversation-timestamp,
    .conversation-item.active .conversation-last-message {
        color: var(--nav-dark);
        opacity: 0.9;
    }
    
    .conversation-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: var(--panel-blue);
        color: var(--white);
        display: grid;
        place-items: center;
        font-weight: 600;
        font-size: 1.1rem;
        margin-right: 12px;
        flex-shrink: 0;
    }
    .conversation-item.active .conversation-avatar {
        background: var(--nav-dark);
    }
    
    .conversation-details {
        flex-grow: 1;
        overflow: hidden;
    }
    .conversation-name {
        font-weight: 600;
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 0.95rem;
        margin-bottom: 2px;
    }
    .conversation-last-message {
        font-size: 0.875rem;
        color: var(--text-light);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .conversation-meta {
        flex-shrink: 0;
        text-align: right;
        margin-left: 8px;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        align-self: flex-start;
        padding-top: 2px;
    }
    .conversation-timestamp {
        font-size: 0.75rem;
        color: var(--text-light);
        margin-bottom: 5px;
    }
    .unread-badge {
        background: var(--panel-blue);
        color: var(--white);
        font-size: 0.7rem;
        font-weight: 700;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        line-height: 1;
    }

    /* --- Delete/Hide Button Styles --- */
    .delete-conversation-btn {
        position: absolute;
        top: 4px;
        right: 4px;
        background: none;
        border: none;
        color: var(--text-light);
        cursor: pointer;
        padding: 5px;
        border-radius: 50%;
        font-size: 0.8rem;
        line-height: 1;
        opacity: 0; 
        transition: opacity 0.2s ease, background 0.2s ease;
    }
    .conversation-item:hover .delete-conversation-btn {
        opacity: 1; 
    }
    .delete-conversation-btn:hover {
        background: #e0e6ed;
        color: var(--critical);
    }
    .delete-conversation-btn-wrapper {
        display: contents;
    }


    /* --- 2. Message View (Right) --- */
    .message-view {
        flex-grow: 1;
        background: var(--bg-light); 
        display: flex;
        flex-direction: column;
        transition: transform 0.3s ease;
    }

    .message-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 24px;
        background: var(--white);
        border-bottom: 1px solid #e0e6ed;
        flex-shrink: 0;
        z-index: 10;
        min-height: 85px; 
    }
    .message-header .back-button {
        display: none; 
        font-size: 1.1rem;
        color: var(--text-dark);
        cursor: pointer;
        margin-right: 8px;
        text-decoration: none;
    }
    .message-header .fa-user {
        font-size: 1.25rem;
        padding: 10px;
        width: 44px;
        height: 44px;
        box-sizing: border-box;
        border-radius: 50%;
        background: var(--bg-light);
        color: var(--text-light);
        text-align: center;
    }
    .message-header h3 {
        font-size: 1.1rem;
        font-weight: 600;
        line-height: 1.2;
        margin: 0;
        color: var(--text-dark);
    }
    .message-header span {
        font-size: 0.8rem;
        color: var(--text-light);
        line-height: 1.2;
    }

    /* --- 3. Message Area (Chat Bubbles) --- */
    .message-bubble-container {
        position: relative; 
        display: flex;
        margin-bottom: 4px;
        max-width: 100%;
    }
    #message-area {
        flex-grow: 1;
        overflow-y: auto;
        padding: 24px;
        display: flex;
        flex-direction: column;
    }
    .select-conversation-prompt {
        margin: auto;
        text-align: center;
        color: var(--text-light);
        font-size: 1.1rem;
    }
    #message-area .no-data {
        color: var(--text-light);
        text-align: center;
        margin: auto;
        font-size: 1rem;
    }
    .message-bubble-container.sent {
        justify-content: flex-end;
    }
    .message-bubble-container.received {
        justify-content: flex-start;
    }

    .message-bubble {
        max-width: 70%;
        padding: 12px 16px;
        border-radius: 18px;
        font-size: 0.95rem;
        line-height: 1.4;
        margin-bottom: 4px;
        word-wrap: break-word;
    }
    .message-bubble p {
        margin: 0; 
    }
    .message-bubble .sender-name {
        font-weight: 600;
        font-size: 0.8rem;
        margin-bottom: 4px;
        color: var(--panel-blue);
    }
    .message-bubble .timestamp {
        font-size: 0.75rem;
        text-align: right;
        margin-top: 8px;
        opacity: 0.8;
    }

    .message-bubble.sent {
        background: var(--panel-blue);
        color: var(--white);
        border-bottom-right-radius: 5px;
    }
    .message-bubble.sent .timestamp {
        opacity: 0.9;
    }
    .message-bubble.received {
        background: var(--white);
        color: var(--text-dark);
        border: 1px solid #e0e6ed;
        border-bottom-left-radius: 5px;
    }

    /* --- 4. Message Input Area (Footer) --- */
    .message-input-area {
        padding: 16px 24px;
        background: var(--white);
        border-top: 1px solid #e0e6ed;
        flex-shrink: 0;
    }
    .message-input-area form {
        display: flex;
        align-items: flex-end;
        gap: 12px;
    }
    .message-input-area textarea {
        flex-grow: 1;
        border: 1px solid #e0e6ed;
        border-radius: 20px;
        padding: 10px 16px;
        resize: none;
        font-size: 0.95rem;
        font-family: 'Inter', sans-serif;
        max-height: 100px;
        line-height: 1.4;
        background: var(--bg-light);
    }
    .message-input-area textarea:focus {
        outline: none;
        border-color: var(--panel-blue);
        background: var(--white);
        box-shadow: 0 0 0 2px rgba(31, 110, 163, 0.2);
    }
    .message-input-area button[type="submit"] {
        flex-shrink: 0;
        background: var(--panel-blue);
        color: white;
        border: none;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        font-size: 1rem;
        cursor: pointer;
        transition: background-color 0.2s ease;
        display: grid;
        place-items: center;
    }
    .message-input-area button[type="submit"]:hover {
        background: var(--nav-dark);
    }

    /* --- 5. New Conversation Modal --- */
    .modal {
        display: none; 
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.5); 
        align-items: center;
        justify-content: center;
    }
    .modal.show {
        display: flex;
    }
    .modal-content {
        background-color: var(--white);
        margin: auto;
        padding: 24px;
        border: none;
        width: 90%;
        max-width: 500px;
        border-radius: 12px;
        box-shadow: var(--shadow-md);
        position: relative;
    }
    .modal-close-btn {
        color: var(--text-light);
        float: right;
        font-size: 28px;
        font-weight: bold;
        position: absolute;
        top: 10px;
        right: 20px;
        border: none;
        background: none;
        cursor: pointer;
    }
    .modal-close-btn:hover,
    .modal-close-btn:focus {
        color: var(--text-dark);
    }
    .modal h3 {
        margin-bottom: 24px;
        color: var(--text-dark);
        font-size: 1.25rem;
    }
    .search-form {
        margin-bottom: 24px;
        text-align: left !important; 
    }
    .search-form label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--text-light);
        font-size: 0.9rem;
    }
    .search-form input[type="search"] {
        width: 100%;
        padding: 12px;
        font-size: 1rem;
        border: 1px solid #e0e6ed;
        border-radius: 8px;
        background: var(--bg-light);
    }
    .search-form input[type="search"]:focus {
        outline: none;
        border-color: var(--panel-blue);
        background: var(--white);
    }

    #search-suggestions {
        max-height: 150px;
        overflow-y: auto;
        border: 1px solid #e0e6ed;
        border-top: none;
        border-radius: 0 0 8px 8px;
        background: var(--white);
    }
    .suggestion-item {
        padding: 12px;
        cursor: pointer;
        font-size: 0.9rem;
    }
    .suggestion-item:hover {
        background: var(--bg-light);
    }
    .suggestion-item strong {
        color: var(--text-dark);
    }
    .suggestion-item span {
        color: var(--text-light);
    }

    #new-conv-form {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        text-align: right !important; 
    }
    #start-conv-submit-btn,
    .modal-cancel-btn {
        padding: 12px 20px;
        font-size: 0.9rem;
        font-weight: 600;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color 0.2s ease, opacity 0.2s ease;
    }
    #start-conv-submit-btn {
        background: var(--panel-blue);
        color: var(--white);
    }
    #start-conv-submit-btn:hover {
        background: var(--nav-dark);
    }
    #start-conv-submit-btn:disabled {
        background: #ccc;
        cursor: not-allowed;
        opacity: 0.7;
    }
    .modal-cancel-btn {
        background: var(--bg-light);
        color: var(--text-dark);
        border: 1px solid #e0e6ed;
    }
    .modal-cancel-btn:hover {
        background: #e0e6ed;
    }


    /* --- 6. (BUILT-IN) Responsive Styles --- */
    @media (max-width: 768px) {
        .messaging-layout {
            overflow-x: hidden; 
        }
        
        .conversation-sidebar {
            position: absolute;
            width: 100%;
            height: 100%;
            z-index: 20;
            transform: translateX(0);
            border-right: none;
        }

        .message-view {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 30;
            transform: translateX(100%);
        }
        
        /* PHP class logic will now work */
        .messaging-layout.show-list .conversation-sidebar {
            transform: translateX(0);
        }
        .messaging-layout.show-list .message-view {
            transform: translateX(100%);
        }

        .messaging-layout.show-chat .conversation-sidebar {
            transform: translateX(-100%);
        }
        .messaging-layout.show-chat .message-view {
            transform: translateX(0);
        }
        
        .message-header .back-button {
            display: block; 
        }
        
        /* Adjust padding for smaller screens */
        .conversation-header,
        .message-header,
        .message-input-area {
            padding-left: 16px;
            padding-right: 16px;
        }
        #message-area {
            padding: 16px;
        }
        .conversation-item {
            padding: 12px 16px;
        }
    }
    
    /* ----------------------------------------------------------------- */
    /* ⬇️ NEW/MODIFIED STYLES FOR CORE FUNCTIONALITY */
    /* ----------------------------------------------------------------- */
    .conversation-item-hidden {
        opacity: 0.6;
        font-style: italic;
        background: #f7f7f7 !important;
    }

    .unhide-conversation-btn {
        position: absolute;
        top: 4px;
        right: 4px;
        background: var(--panel-blue); 
        color: var(--white);
        border: none;
        border-radius: 50%;
        cursor: pointer;
        padding: 5px;
        font-size: 0.8rem;
        line-height: 1;
        opacity: 1;
        transition: background 0.2s ease;
    }
    .unhide-conversation-btn:hover {
        background: var(--nav-dark);
    }

    .chat-actions {
        margin-left: auto;
    }
    .delete-full-conv-btn {
        background: var(--critical); 
        color: var(--white);
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 0.8rem;
        cursor: pointer;
        transition: background 0.2s ease;
    }
    .delete-full-conv-btn:hover {
        background: darkred;
    }

    /* Delete Message Button within Bubble */
    .delete-message-btn {
        display: block;
        float: right;
        margin-left: 10px;
        background: none;
        border: none;
        color: #e0e0e0;
        cursor: pointer;
        font-size: 0.8em;
        padding: 0 2px;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    /* Show delete button on bubble hover */
    .message-bubble:hover .delete-message-btn {
        opacity: 1;
    }

    .message-bubble.sent .delete-message-btn {
        color: rgba(255, 255, 255, 0.7); 
    }
    .message-bubble.received .delete-message-btn {
        color: #a0a0a0; 
    }
    .delete-message-btn:hover {
        color: var(--critical);
    }
</style>

<div class="messaging-layout <?php echo $selected_conversation_id ? 'show-chat' : 'show-list'; ?>">
    <aside class="conversation-sidebar">
        <div class="conversation-header">
            <h2>Conversations</h2>
            <p>Logged in as: <?= htmlspecialchars($current_user_name ?? 'Registrar'); ?></p>
            <button id="new-conversation-btn" class="new-conv-btn"><i class="fas fa-plus mr-1"></i> New Conversation</button>
        </div>
        
        <nav class="conversation-list custom-scrollbar" id="conversation-list-area">
            <?php if (empty($conversations)): ?>
                <p class="no-data" style="padding: 20px;">No conversations yet.</p>
            <?php else: ?>
                <?php foreach ($conversations as $conv): ?>
                    <?php
                        $is_active = ($selected_conversation_id == $conv['conversation_id']);
                        $is_hidden = ($conv['user_1_id'] == $current_user_id) ? $conv['user_1_hidden'] : $conv['user_2_hidden'];
                        
                        // Generate initials for avatar
                        $name_parts = explode(' ', $conv['other_full_name']);
                        $initials = '';
                        if (count($name_parts) > 0 && !empty($name_parts[0])) {
                            $initials .= strtoupper(substr($name_parts[0], 0, 1));
                        }
                        if (count($name_parts) > 1) {
                            $initials .= strtoupper(substr(end($name_parts), 0, 1));
                        }
                        if (empty($initials)) {
                            $initials = 'U';
                        }
                    ?>
                    <a href="registrar_messages.php?conversation_id=<?= $conv['conversation_id'] ?>" 
                        class="conversation-item <?= $is_active ? 'active' : '' ?> <?= $is_hidden ? 'conversation-item-hidden' : '' ?>">
                        
                        <?php if (!$is_hidden): ?>
                        <form method="POST" action="registrar_messages.php" class="delete-conversation-btn-wrapper" onsubmit="return confirm('Are you sure you want to hide this conversation? This will not delete messages but remove it from your list.');">
                            <input type="hidden" name="action" value="hide_conversation">
                            <input type="hidden" name="conversation_id" value="<?= $conv['conversation_id'] ?>">
                            <button type="submit" class="delete-conversation-btn" title="Hide Conversation">
                                <i class="fa-solid fa-times"></i>
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($is_hidden): ?>
                        <form method="POST" action="registrar_messages.php" class="delete-conversation-btn-wrapper" onsubmit="return confirm('Do you want to restore this conversation to your active list?');">
                            <input type="hidden" name="action" value="unhide_conversation">
                            <input type="hidden" name="conversation_id" value="<?= $conv['conversation_id'] ?>">
                            <button type="submit" class="unhide-conversation-btn" title="Unhide Conversation (Restore)">
                                <i class="fa-solid fa-undo"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <div class="conversation-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div class="conversation-details">
                            <span class="conversation-name"><?= htmlspecialchars($conv['other_full_name']) ?></span>
                            <span class="conversation-last-message">
                                <?= $is_hidden ? 'Conversation Hidden' : htmlspecialchars($conv['last_message'] ?? '...'); ?>
                            </span>
                        </div>
                        <div class="conversation-meta">
                            <span class="conversation-timestamp">
                                <?= date('M j', strtotime($conv['updated_at'])) ?>
                            </span>
                            <?php if (!$is_hidden && ($conv['unread_count'] ?? 0) > 0): ?> 
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
            <a href="registrar_messages.php" class="back-button">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <i class="fa-solid fa-user"></i>
            <div>
                <h3><?= htmlspecialchars($other_user_name); ?></h3>
                <span><?= htmlspecialchars($other_user_role); ?></span>
            </div>
            
            <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
            <div class="chat-actions">
                <form method="POST" action="registrar_messages.php" onsubmit="return confirm('WARNING: Are you sure you want to permanently DELETE this entire conversation and all messages for BOTH users? This action cannot be undone.');">
                    <input type="hidden" name="action" value="delete_full_conversation">
                    <input type="hidden" name="conversation_id" value="<?= $selected_conversation_id ?>">
                    <button type="submit" class="delete-full-conv-btn" title="Permanently Delete Conversation">
                        <i class="fas fa-trash"></i> Delete Chat
                    </button>
                </form>
            </div>
            <?php endif; ?>
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
                            <p>
                                <?= nl2br(htmlspecialchars($msg['body'])); ?>
                                
                                <form method="POST" action="registrar_messages.php?conversation_id=<?= $selected_conversation_id; ?>" style="display:inline;" onsubmit="return confirm('Delete this specific message? This action is permanent.');">
                                    <input type="hidden" name="action" value="delete_message">
                                    <input type="hidden" name="message_id" value="<?= $msg['message_id'] ?>">
                                    <input type="hidden" name="conversation_id" value="<?= $selected_conversation_id ?>">
                                    <button type="submit" class="delete-message-btn" title="Delete Message">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </p>
                            <p class="timestamp">
                                <?= date('M j, g:i A', strtotime($msg['sent_at'])); ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="no-data">No messages yet. Say hello!</p>
                <?php endif; ?>
            <?php elseif ($db_error): ?>
                    <p class="message error" style="margin: 20px;"><?= htmlspecialchars($db_error) ?></p>
            <?php else: ?>
                <p class="select-conversation-prompt">Select a conversation or start a new one.</p>
            <?php endif; ?>
        </div>
        <?php if ($selected_conversation_id && $is_part_of_conversation): ?>
        <footer class="message-input-area">
            <?php if ($send_error): ?><p class="message error" style="text-align: left; margin-bottom: 10px;"><?= htmlspecialchars($send_error); ?></p><?php endif; ?>
            <form action="registrar_messages.php?conversation_id=<?= $selected_conversation_id; ?>" method="POST">
                <input type="hidden" name="receiver_id" value="<?= htmlspecialchars($current_conversation_receiver_id); ?>">
                <textarea name="body" placeholder="Type your message..." rows="1" required></textarea>
                <button type="submit" name="send_message" title="Send"><i class="fas fa-paper-plane"></i></button>
            </form>
        </footer>
        <?php endif; ?>
    </div>
</div>

<div id="new-conversation-modal" class="modal <?php if(!empty($start_conversation_error)) echo 'show'; ?>">
    <div class="modal-content">
        <button id="modal-close-x-btn" class="modal-close-btn">&times;</button>
        <h3>Start New Conversation</h3>
        
        <div class="search-form">
            <label for="recipient-search">Search by Name:</label>
            <input type="search" id="recipient-search" placeholder="Type a name to search..." autocomplete="off">
            <div id="search-suggestions" class="custom-scrollbar">
                </div>
        </div>

        <form action="registrar_messages.php" method="POST" id="new-conv-form">
            <?php if ($start_conversation_error): ?>
                <p class="message error" style="text-align: left; margin-bottom: 10px;"><?= htmlspecialchars($start_conversation_error); ?></p>
            <?php endif; ?>
            
            <input type="hidden" name="recipient_sid" id="recipient-sid-input" value="">
            
            <button type="button" id="modal-cancel-btn" class="modal-cancel-btn">Cancel</button>
            <button type="submit" name="start_conversation" id="start-conv-submit-btn" disabled>
                <i class="fas fa-plus"></i> Start Conversation
            </button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    
    // --- Auto-scroll message area to bottom ---
    const messageArea = document.getElementById('message-area');
    if (messageArea) {
        messageArea.scrollTop = messageArea.scrollHeight;
    }

    // --- Auto-resize text area ---
    const textarea = document.querySelector('.message-input-area textarea');
    if (textarea) {
        const setHeight = () => {
            textarea.style.height = 'auto'; // Reset height
            textarea.style.height = (textarea.scrollHeight) + 'px'; // Set to scroll height
        };
        textarea.addEventListener('input', setHeight);
        setHeight(); // Set initial height on load
    }

    // --- Modal Toggle Logic ---
    const modal = document.getElementById('new-conversation-modal');
    const newConvBtn = document.getElementById('new-conversation-btn');
    const closeModalBtn = document.getElementById('modal-close-x-btn');
    const cancelModalBtn = document.getElementById('modal-cancel-btn');
    const searchInput = document.getElementById('recipient-search');

    const showModal = () => {
        if(modal) modal.classList.add('show');
        if(searchInput) searchInput.focus();
    };
    const closeModal = () => {
        if(modal) modal.classList.remove('show');
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

    // --- AJAX User Search ---
    const suggestionsBox = document.getElementById('search-suggestions');
    const recipientInput = document.getElementById('recipient-sid-input');
    const startConvBtn = document.getElementById('start-conv-submit-btn');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim();
            
            // Reset state upon typing
            if (recipientInput) recipientInput.value = '';
            if (startConvBtn) startConvBtn.disabled = true;

            if (query.length < 2) {
                if (suggestionsBox) suggestionsBox.innerHTML = '';
                return;
            }

            // Calls a separate PHP file to handle the user search logic
            fetch(`registrar_ajax_search_users.php?query=${encodeURIComponent(query)}`) 
                .then(response => response.json())
                .then(data => {
                    if (!suggestionsBox) return;
                    suggestionsBox.innerHTML = '';
                    if (data.success && data.users.length > 0) {
                        data.users.forEach(user => {
                            const div = document.createElement('div');
                            div.className = 'suggestion-item';
                            div.innerHTML = `<strong>${user.name}</strong> (${user.role})`;
                            div.dataset.sid = user.sid;
                            
                            // Handle selection of a user
                            div.addEventListener('click', () => {
                                searchInput.value = `${user.name} (${user.role})`;
                                recipientInput.value = user.sid;
                                startConvBtn.disabled = false;
                                suggestionsBox.innerHTML = '';
                            });
                            suggestionsBox.appendChild(div);
                        });
                    } else if (data.users.length === 0) {
                        suggestionsBox.innerHTML = '<p style="padding: 10px; color: var(--text-light);">No users found.</p>';
                    }
                })
                .catch(err => {
                    suggestionsBox.innerHTML = '<p style="padding: 10px; color: var(--critical);">Search failed.</p>';
                    console.error(err);
                });
        });
    }

    // --- Mobile Responsive Toggle (Back Button Logic) ---
    const backButton = document.querySelector('.message-header .back-button');
    const layout = document.querySelector('.messaging-layout');

    if (layout && backButton) {
        if (window.innerWidth <= 768) {
            // Check current display state and use back button to navigate
            backButton.addEventListener('click', (e) => {
                e.preventDefault();
                layout.classList.remove('show-chat');
                layout.classList.add('show-list');
            });

            // Set initial mobile view on load
            if (layout.classList.contains('show-list')) {
                 // Check if a conversation ID is present in the URL, but the class state defaulted to show-list
                 const urlParams = new URLSearchParams(window.location.search);
                 if (urlParams.get('conversation_id')) {
                    // Force chat view if ID is present (will be removed by click listener above)
                    layout.classList.remove('show-list');
                    layout.classList.add('show-chat');
                 } else {
                    layout.classList.add('show-list');
                    layout.classList.remove('show-chat');
                 }
            }
        }
    }
});
</script>

<?php include 'registrar_footer.php'; ?>