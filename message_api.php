<?php
header('Content-Type: application/json');
include 'db.php'; // Changed to 'db.php'

// Check if session variables are set
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    // In a real app, you'd probably have your login script set these.
    // For testing, we can set defaults, but it's better to ensure login works.
     // MOCKUP: Set a logged-in user for testing.
    // In your real system, this $_SESSION['user_id'] would be set at login.
    // if (!isset($_SESSION['user_id'])) {
    //     $_SESSION['user_id'] = 1; // Default to user 1 for testing.
    //     $_SESSION['user_role'] = 'Student'; // Example role
    // }
    
    // If not testing, it's better to stop.
    echo json_encode(['error' => 'User not authenticated. Please log in.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];

$action = $_GET['action'] ?? '';

switch ($action) {
    // Fetches all users (for starting a new message)
    case 'get_contacts':
        if ($current_user_role === 'Student') {
            // Students only see non-student roles
            $stmt = $conn->prepare("SELECT sid, full_name, role FROM users WHERE role != 'Student'");
            $stmt->execute(); // No parameters needed
        } else {
            // Instructors, Program Heads, Registrars can message anyone else
            $stmt = $conn->prepare("SELECT sid, full_name, role FROM users WHERE sid != ?");
            $stmt->execute([$current_user_id]);
        }
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($contacts);
        break;

    // Fetches all active conversations for the user
    case 'get_conversations':
        $stmt = $conn->prepare("
            SELECT 
                c.conversation_id, 
                u.full_name AS other_user_name,
                u.sid AS other_user_id, -- Changed from user_id
                (SELECT body FROM messages m WHERE m.conversation_id = c.conversation_id ORDER BY m.sent_at DESC LIMIT 1) as last_message,
                (SELECT sent_at FROM messages m WHERE m.conversation_id = c.conversation_id ORDER BY m.sent_at DESC LIMIT 1) as last_message_time,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.conversation_id AND m.receiver_id = ? AND m.is_read = 0) as unread_count
            FROM conversations c
            JOIN users u ON u.sid = IF(c.user_1_id = ?, c.user_2_id, c.user_1_id) -- Changed from user_id
            WHERE c.user_1_id = ? OR c.user_2_id = ?
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute([$current_user_id, $current_user_id, $current_user_id, $current_user_id]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($conversations);
        break;

    // Fetches all messages for a specific conversation
    case 'get_messages':
        $convo_id = $_GET['convo_id'] ?? 0;
        
        // Mark messages as read
        $update_stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND receiver_id = ?");
        $update_stmt->execute([$convo_id, $current_user_id]);

        // Fetch messages
        $stmt = $conn->prepare("
            SELECT m.*, u.full_name AS sender_name 
            FROM messages m
            JOIN users u ON m.sender_id = u.sid -- Changed from user_id
            WHERE m.conversation_id = ?
            ORDER BY m.sent_at ASC
        ");
        $stmt->execute([$convo_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($messages);
        break;

    // Sends a new message
    case 'send_message':
        $data = json_decode(file_get_contents('php://input'), true);
        $receiver_id = $data['receiver_id'];
        // $subject = $data['subject']; // Removed per instruction
        $body = $data['body'];

        // Updated validation: removed subject check
        if (empty($receiver_id) || empty($body)) {
            echo json_encode(['success' => false, 'message' => 'Receiver and body are required.']);
            exit;
        }

        $conn->beginTransaction();
        try {
            // Find or create conversation
            $user_1 = min($current_user_id, $receiver_id);
            $user_2 = max($current_user_id, $receiver_id);

            $stmt = $conn->prepare("SELECT conversation_id FROM conversations WHERE user_1_id = ? AND user_2_id = ?");
            $stmt->execute([$user_1, $user_2]);
            $convo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$convo) {
                $stmt = $conn->prepare("INSERT INTO conversations (user_1_id, user_2_id) VALUES (?, ?)");
                $stmt->execute([$user_1, $user_2]);
                $convo_id = $conn->lastInsertId();
            } else {
                $convo_id = $convo['conversation_id'];
                // Update conversation timestamp
                $stmt = $conn->prepare("UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE conversation_id = ?");
                $stmt->execute([$convo_id]);
            }

            // Insert the message
            // --- MODIFIED BLOCK ---
            $stmt = $conn->prepare(
                "INSERT INTO messages (conversation_id, sender_id, receiver_id, subject, body) 
                 VALUES (:convo_id, :sender_id, :receiver_id, :subject, :body)"
            );
            $stmt->execute([
                ':convo_id' => $convo_id,
                ':sender_id' => $current_user_id,
                ':receiver_id' => $receiver_id,
                ':subject' => "", // Hardcoded as empty string per your instruction
                ':body' => $body
            ]);
            // --- END MODIFIED BLOCK ---

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Message sent!']);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>