<?php
// FILE: get_messages.php
session_start();
include 'db.php';

// Role-based access
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Registrar'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied']);
    exit();
}

$current_user_id = $_SESSION['sid'];
$conversation_id = filter_input(INPUT_GET, 'conversation_id', FILTER_VALIDATE_INT);

if (!$conversation_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid Conversation ID']);
    exit();
}

try {
    // Check if user is part of the conversation (basic security)
    $stmt_check = $conn->prepare("SELECT 1 FROM conversations WHERE conversation_id = :cid AND (user_1_id = :uid OR user_2_id = :uid)");
    $stmt_check->execute([':cid' => $conversation_id, ':uid' => $current_user_id]);
    if ($stmt_check->fetch() === false) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Not part of conversation']);
        exit();
    }

    // Fetch messages
    $stmt_msgs = $conn->prepare("
        SELECT m.*, u.username AS sender_username FROM messages m
        JOIN users u ON m.sender_id = u.sid
        WHERE m.conversation_id = :conv_id ORDER BY m.sent_at ASC
    ");
    $stmt_msgs->execute([':conv_id' => $conversation_id]);
    $messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

    // Mark as read
    $stmt_read = $conn->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = :conv_id AND receiver_id = :current_user_id AND is_read = 0");
    $stmt_read->execute([':conv_id' => $conversation_id, ':current_user_id' => $current_user_id]);

    // Send messages as JSON
    header('Content-Type: application/json');
    echo json_encode($messages);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>