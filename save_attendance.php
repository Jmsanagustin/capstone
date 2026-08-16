<?php
// FILE: save_attendance.php
// API: Saves attendance data submitted from the form.
// Uses ON DUPLICATE KEY UPDATE to handle both new and existing records.

session_start();
include 'db.php';

header('Content-Type: application/json');

// --- 1. Security and Validation ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$professor_sid = $_SESSION['sid'];
$class_id = $_POST['class_id'] ?? null;
$attendance_date = $_POST['attendance_date'] ?? null;

// These will be arrays: e.g., $_POST['student_sid'][0], $_POST['status'][0], etc.
$student_sids = $_POST['student_sid'] ?? [];
$statuses = $_POST['status'] ?? [];
$remarks = $_POST['remarks'] ?? [];

if (!$class_id || !$attendance_date || empty($student_sids)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required data.']);
    exit();
}

try {
    // --- 2. Verify professor teaches this class (Security) ---
    $stmt_verify = $conn->prepare("SELECT class_id FROM classes WHERE class_id = :cid AND professor_sid = :psid");
    $stmt_verify->execute([':cid' => $class_id, ':psid' => $professor_sid]);
    if ($stmt_verify->rowCount() == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Access denied to this class.']);
        exit();
    }

    // --- 3. Get Enrollment IDs for all students in this class ---
    // We need to map the student_sid from the form to the enrollment_id for the DB
    $enrollment_map = [];
    $stmt_enroll = $conn->prepare("SELECT student_sid, enrollment_id FROM enrollment WHERE class_id = :class_id");
    $stmt_enroll->execute([':class_id' => $class_id]);
    while ($row = $stmt_enroll->fetch(PDO::FETCH_ASSOC)) {
        $enrollment_map[$row['student_sid']] = $row['enrollment_id'];
    }

    // --- 4. Prepare the UPSERT query ---
    // This single query will INSERT a new record, or UPDATE an existing one
    // thanks to the UNIQUE KEY on (enrollment_id, attendance_date).
    $sql_upsert = "
        INSERT INTO attendance (enrollment_id, attendance_date, status, remarks)
        VALUES (:enroll_id, :att_date, :status, :remarks)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            remarks = VALUES(remarks)
    ";
    $stmt_upsert = $conn->prepare($sql_upsert);

    // --- 5. Loop and Save Data in a Transaction ---
    $conn->beginTransaction();
    
    $records_processed = 0;
    foreach ($student_sids as $index => $sid) {
        // Find the enrollment_id that matches the student_sid
        if (!isset($enrollment_map[$sid])) {
            // This student isn't in this class's enrollment map, skip
            continue;
        }
        
        $enrollment_id = $enrollment_map[$sid];
        $status = $statuses[$index] ?? 'Present';
        $remark = $remarks[$index] ?? '';

        $stmt_upsert->execute([
            ':enroll_id' => $enrollment_id,
            ':att_date' => $attendance_date,
            ':status' => $status,
            ':remarks' => $remark
        ]);
        $records_processed++;
    }

    $conn->commit();

    echo json_encode(['status' => 'success', 'message' => "Attendance for $records_processed student(s) has been saved."]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Database save failed: ' . $e->getMessage()]);
}
?>