<?php
// FILE: api_get_messages.php
// PURPOSE: API endpoint for real-time polling of messages and conversation list.

session_start();
include 'db.php';

header('Content-Type: application/json');

// Access Control
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$current_user_id = $_SESSION['sid'] ?? $_SESSION['user_id'] ?? 0;
$action = $_GET['action'] ?? 'get_messages';

try {

    // --- ACTION 1: Get all conversations for the sidebar ---
    if ($action === 'get_conversations') {
        
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

        echo json_encode(['status' => 'success', 'conversations' => $conversations]);
        exit();
    }


    // --- ACTION 2: Get messages for a specific chat window ---
    $conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : null;

    if (!$conversation_id) {
        echo json_encode(['status' => 'error', 'message' => 'No conversation ID provided.']);
        exit();
    }

    // Security Check: Verify user is part of this conversation
    $stmt_conv_check = $conn->prepare("SELECT user_1_id, user_2_id FROM conversations WHERE conversation_id = :conv_id");
    $stmt_conv_check->execute([':conv_id' => $conversation_id]);
    $conv_info = $stmt_conv_check->fetch(PDO::FETCH_ASSOC);

    if (!$conv_info || ($conv_info['user_1_id'] != $current_user_id && $conv_info['user_2_id'] != $current_user_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
        exit();
    }

    // Mark messages as read *before* fetching
    $stmt_mark_read = $conn->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = :conv_id AND receiver_id = :current_user_id AND is_read = 0");
    $stmt_mark_read->execute([':conv_id' => $conversation_id, ':current_user_id' => $current_user_id]);

    // Fetch all messages for the conversation
    $stmt_messages = $conn->prepare("
        SELECT m.sender_id, m.body, m.sent_at, CONCAT(up.first_name, ' ', up.last_name) AS sender_full_name
        FROM messages m
        JOIN users u ON m.sender_id = u.sid
        LEFT JOIN user_profiles up ON u.sid = up.sid
        WHERE m.conversation_id = :conv_id
        ORDER BY m.sent_at ASC
    ");
    $stmt_messages->execute([':conv_id' => $conversation_id]);
    $messages = $stmt_messages->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'messages' => $messages]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database query failed: ' . $e->getMessage()]);
}
?>