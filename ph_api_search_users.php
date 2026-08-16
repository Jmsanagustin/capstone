<?php
// FILE: ph_api_search_users.php
// PURPOSE: API endpoint for live-searching users by full name (for Program Head).

session_start();
include 'db.php';

header('Content-Type: application/json');

// Access Control
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$current_user_id = $_SESSION['sid'] ?? $_SESSION['user_id'] ?? 0;
$query = $_GET['query'] ?? '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit();
}

$search_term = '%' . $query . '%';

try {
    // Search by first name, last name, or "first last" or "last, first"
    $stmt = $conn->prepare("
        SELECT u.sid, up.first_name, up.last_name, u.role
        FROM users u
        JOIN user_profiles up ON u.sid = up.sid
        WHERE 
            (
                up.first_name LIKE :query OR 
                up.last_name LIKE :query OR
                CONCAT(up.first_name, ' ', up.last_name) LIKE :query OR
                CONCAT(up.last_name, ' ', up.first_name) LIKE :query
            )
            AND u.sid != :current_user_id
        LIMIT 10
    ");
    
    $stmt->execute([
        ':query' => $search_term,
        ':current_user_id' => $current_user_id
    ]);
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach ($users as $user) {
        $results[] = [
            'sid' => $user['sid'],
            'full_name' => $user['first_name'] . ' ' . $user['last_name'],
            'role' => $user['role']
        ];
    }
    
    echo json_encode($results);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database query failed.']);
}
?>