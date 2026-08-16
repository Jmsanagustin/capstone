<?php
// send_message_ajax.php

session_start();
include 'db.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['sid']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit();
}

$current_user_id = $_SESSION['sid'];
$conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : null;
$receiver_id = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : null;
$body = isset($_POST['body']) ? trim($_POST['body']) : '';

if (empty($body)) {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty.']);
    exit();
}

if (empty($conversation_id) || empty($receiver_id)) {
    echo json_encode(['success' => false, 'error' => 'Missing conversation data.']);
    exit();
}

try {
    // 1. Verify user is part of this conversation
    $stmt_check = $conn->prepare("
        SELECT conversation_id FROM conversations 
        WHERE conversation_id = :conv_id AND (user_1_id = :user_id OR user_2_id = :user_id)
    ");
    $stmt_check->execute([':conv_id' => $conversation_id, ':user_id' => $current_user_id]);
    
    if (!$stmt_check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to send this message.']);
        exit();
    }

    // 2. Update the conversation's updated_at timestamp
    $conn->prepare("UPDATE conversations SET updated_at = NOW() WHERE conversation_id = :conv_id")
         ->execute([':conv_id' => $conversation_id]);
    
    // 3. Insert the message
    $stmt = $conn->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, body)
        VALUES (:conv_id, :sender_id, :receiver_id, :body)
    ");
    $stmt->execute([
        ':conv_id' => $conversation_id,
        ':sender_id' => $current_user_id,
        ':receiver_id' => $receiver_id,
        ':body' => $body
    ]);

    // 4. Send success response
    echo json_encode(['success' => true]);
    exit();

} catch (PDOException $e) {
    error_log("Send Message AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
    exit();
}
?>