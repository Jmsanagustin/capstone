<?php
// FILE: registrar_ajax_search_users.php
// PURPOSE: Provides user data for the "New Conversation" modal search for Registrars.
// FIX: Allows Registrar to search and initiate chats with ALL user roles (including students).

// Start output buffering to protect JSON output
ob_start();

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

$response = ['success' => false, 'users' => []];
$query = $_GET['query'] ?? '';
$current_user_id = $_SESSION['sid'] ?? 0;

// Prevent warnings from breaking JSON output
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Validation
if (strlen($query) < 2 || $current_user_id === 0) {
    ob_clean();
    echo json_encode($response);
    if (ob_get_level()) ob_end_flush();
    exit();
}

try {
    $sql_query = "%$query%";

    $stmt = $conn->prepare("
        SELECT u.sid, u.username, up.first_name, up.last_name, u.role
        FROM users u
        LEFT JOIN user_profiles up ON u.sid = up.sid
        WHERE 
            -- REMOVED ROLE FILTER: Registrar can search ALL users.
            -- (The restriction on students starting chats is handled by access control on the student's end of the application.)
            (
                CONCAT(up.first_name, ' ', up.last_name) LIKE :query_name
                OR u.username LIKE :query_username
            )
            AND u.sid != :current_user_id
        LIMIT 10
    ");

    $stmt->execute([
        ':query_name' => $sql_query,
        ':query_username' => $sql_query,
        ':current_user_id' => $current_user_id
    ]);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($users as $user) {
        $display_name = trim($user['first_name'] . ' ' . $user['last_name']);
        
        // If profile name is empty (e.g., system user or incomplete profile), use username
        if ($display_name === '') {
            $display_name = $user['username'];
        }

        $response['users'][] = [
            'sid' => $user['sid'],
            'name' => $display_name,
            'username' => $user['username'],
            'role' => $user['role']
        ];
    }

    $response['success'] = true;

    ob_clean();
    echo json_encode($response);

} catch (PDOException $e) {

    error_log('AJAX Search DB Error: ' . $e->getMessage());
    $response['error'] = 'A system error occurred during search.';

    ob_clean();
    http_response_code(500);
    echo json_encode($response);

} finally {
    if (ob_get_level()) ob_end_flush();
}