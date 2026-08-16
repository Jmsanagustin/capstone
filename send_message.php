<?php
// FILE: send_message.php
session_start();
include 'db.php';

// Role-based access
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Registrar'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied']);
    exit();
}

header('Content-Type: application/json');

// Get data from POST
$current_user_id = $_SESSION['sid'];
$conversation_id = filter_input(INPUT_POST, 'conversation_id', FILTER_VALIDATE_INT);
$receiver_id = filter_input(INPUT_POST, 'receiver_id', FILTER_VALIDATE_INT);
$body = trim($_POST['body'] ?? '');

if (empty($body) || !$receiver_id || !$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid input.']);
    exit();
}

try {
    $conn->beginTransaction();
    
    // --- START OF MODIFICATION ---
    // Added the 'subject' column and bound it as an empty string
    $stmt_msg = $conn->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, subject, body, sent_at) 
        VALUES (:conv_id, :sender_id, :receiver_id, :subject, :body, NOW())
    ");
    
    $stmt_msg->execute([
        ':conv_id' => $conversation_id,
        ':sender_id' => $current_user_id,
        ':receiver_id' => $receiver_id,
        ':subject' => '', // Bind an empty string for the subject
        ':body' => $body
    ]);
    // --- END OF MODIFICATION ---
    
    // Update the conversation timestamp
    $stmt_conv = $conn->prepare("UPDATE conversations SET updated_at = NOW() WHERE conversation_id = :conv_id");
    $stmt_conv->execute([':conv_id' => $conversation_id]);
    
    $conn->commit();
    
    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    $conn->rollBack();
    // Send a specific error message back to the browser console
    http_response_code(500); 
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>