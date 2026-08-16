<?php
// fetch_messages.php
// (MODIFIED: Adds read receipt status, filters sidebar by 'hidden' flag)

session_start();
include 'db.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['sid']) || !isset($_SESSION['role'])) {
    echo json_encode(['messages_html' => '', 'sidebar_html' => '', 'read_status' => '']);
    exit();
}

$current_user_id = $_SESSION['sid'];
$conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
$last_message_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if ($conversation_id === 0) {
    echo json_encode(['messages_html' => '', 'sidebar_html' => '', 'read_status' => '']);
    exit();
}

$messages_html = '';
$sidebar_html = '';
$read_status = ''; // (NEW)

try {
    // 1. Fetch NEW messages for the chat window
    $stmt_msgs = $conn->prepare("
        SELECT m.message_id, m.sender_id, m.body, m.sent_at
        FROM messages m
        WHERE m.conversation_id = :conv_id 
          AND m.message_id > :last_id
          AND (m.sender_id = :user_id OR m.receiver_id = :user_id)
        ORDER BY m.sent_at ASC
    ");
    $stmt_msgs->execute([
        ':conv_id' => $conversation_id,
        ':last_id' => $last_message_id,
        ':user_id' => $current_user_id
    ]);
    
    $new_messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);
    
    if ($new_messages) {
        $unread_message_ids = [];
        foreach ($new_messages as $msg) {
            $is_sent = ($msg['sender_id'] == $current_user_id);
            $bubble_class = $is_sent ? 'sent' : 'received';
            
            $messages_html .= '
                <div class="message-bubble-container '. $bubble_class .'" data-message-id="'. $msg['message_id'] .'">
                    <div class="message-bubble '. $bubble_class .'">
                        <p>'. nl2br(htmlspecialchars($msg['body'])) .'</p>
                        <p class="timestamp">'. date('g:i A', strtotime($msg['sent_at'])) .'</p>
                    </div>
                </div>';
            
            // Collect unread messages to mark as read
            if (!$is_sent) {
                $unread_message_ids[] = $msg['message_id'];
            }
        }
        
        if (!empty($unread_message_ids)) {
            $in_query = implode(',', array_fill(0, count($unread_message_ids), '?'));
            $stmt_read = $conn->prepare("UPDATE messages SET is_read = 1 WHERE message_id IN ($in_query) AND receiver_id = ?");
            $params = array_merge($unread_message_ids, [$current_user_id]);
            $stmt_read->execute($params);
        }
    }

    // 2. (MODIFIED) Fetch UPDATED conversation list for the sidebar (respects 'hidden' flag)
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
    $conversations = $stmt_conv->fetchAll(PDO::FETCH_ASSOC);

    if ($conversations) {
        foreach ($conversations as $conv) {
            $is_active = ($selected_conversation_id === (int)$conv['conversation_id']);
            $active_class = $is_active ? 'active' : '';
            
            $sidebar_html .= '
                <a href="messaging_center.php?conversation_id='. $conv['conversation_id'] .'" class="conversation-item '. $active_class .'">
                    <div class="conversation-item-details">
                        <i class="fa-regular fa-user-circle"></i>
                        <span>'. htmlspecialchars($conv['other_full_name'] ?? 'Unknown User') .'</span>
                    </div>';
            
            if ($conv['unread_count'] > 0) {
                $sidebar_html .= '<span class="unread-badge">'. $conv['unread_count'] .'</span>';
            }
            
            // Add delete button
            $sidebar_html .= '
                    <button class="delete-conversation-btn" data-conv-id="'. $conv['conversation_id'] .'" title="Hide conversation">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </a>';
        }
    } else {
        $sidebar_html = '<p class="no-data" style="padding: 15px;">No conversations yet.</p>';
    }

    // 3. (NEW) Check Read Status for the last message SENT by the current user
    $stmt_read_status = $conn->prepare("
        SELECT is_read 
        FROM messages 
        WHERE conversation_id = :conv_id AND sender_id = :user_id
        ORDER BY sent_at DESC
        LIMIT 1
    ");
    $stmt_read_status->execute([':conv_id' => $conversation_id, ':user_id' => $current_user_id]);
    $last_sent_msg = $stmt_read_status->fetch(PDO::FETCH_ASSOC);
    
    if ($last_sent_msg) {
        $read_status = $last_sent_msg['is_read'] ? 'seen' : 'delivered';
    } else {
        $read_status = 'none'; // User hasn't sent any messages in this chat
    }


    // 4. Send final JSON response
    echo json_encode([
        'messages_html' => $messages_html, 
        'sidebar_html' => $sidebar_html,
        'read_status' => $read_status // (NEW)
    ]);
    exit();

} catch (PDOException $e) {
    error_log("Fetch Messages AJAX Error: " . $e->getMessage());
    echo json_encode(['messages_html' => '', 'sidebar_html' => '', 'read_status' => '']);
    exit();
}
?>