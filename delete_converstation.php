<?php
// delete_conversation.php

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

if (empty($conversation_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation ID.']);
    exit();
}

try {
    // 1. Find the conversation to see if user is user_1 or user_2
    $stmt_find = $conn->prepare("
        SELECT user_1_id, user_2_id 
        FROM conversations 
        WHERE conversation_id = :conv_id 
          AND (user_1_id = :user_id OR user_2_id = :user_id)
    ");
    $stmt_find->execute([':conv_id' => $conversation_id, ':user_id' => $current_user_id]);
    $conv = $stmt_find->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        echo json_encode(['success' => false, 'error' => 'Conversation not found or permission denied.']);
        exit();
    }

    // 2. Set the correct 'hidden' flag
    if ($conv['user_1_id'] == $current_user_id) {
        // User is user_1
        $sql = "UPDATE conversations SET user_1_hidden = 1 WHERE conversation_id = :conv_id";
    } else {
        // User is user_2
        $sql = "UPDATE conversations SET user_2_hidden = 1 WHERE conversation_id = :conv_id";
    }

    $stmt_update = $conn->prepare($sql);
    $stmt_update->execute([':conv_id' => $conversation_id]);

    echo json_encode(['success' => true]);
    exit();

} catch (PDOException $e) {
    error_log("Delete Conversation Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
    exit();
}
?>