<?php
// FILE: search_students_ajax.php
// PURPOSE: Securely search for students to add to a class.

session_start();
include 'db.php';

// 1. Authorization
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'Programhead')) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// 2. Get Inputs
$query = trim($_GET['q'] ?? '');
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);

if (empty($query) || empty($class_id)) {
    echo json_encode([]); // Return empty on no query
    exit();
}

// 3. Search Database
try {
    // Find students matching the query who are NOT already in this class
    $stmt = $conn->prepare("
        SELECT 
            up.sid, 
            up.student_id, 
            up.first_name, 
            up.last_name
        FROM user_profiles up
        JOIN users u ON up.sid = u.sid
        WHERE 
            u.role = 'Student'
            AND (up.student_id LIKE ? OR CONCAT(up.first_name, ' ', up.last_name) LIKE ?)
            AND up.sid NOT IN (
                SELECT student_sid FROM enrollment WHERE class_id = ?
            )
        LIMIT 10
    ");
    
    $search_term = "%" . $query . "%";
    $stmt->execute([$search_term, $search_term, $class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Return JSON
    header('Content-Type: application/json');
    echo json_encode($students);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>