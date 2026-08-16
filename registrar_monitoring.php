<?php
session_start();
include 'db.php';  // Database connection

// Assuming you are storing the user's section in session or passing it via GET/POST
// If the section is stored in session (e.g., $_SESSION['section']), use that.
$section = isset($_SESSION['section']) ? $_SESSION['section'] : '';

// Fetch students from the database
try {
    $stmt = $conn->prepare("SELECT * FROM students WHERE section = :section");
    $stmt->bindParam(':section', $section);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Error fetching students: " . $e->getMessage();
}

// Fetch attendance records filtered by section (and possibly student)
try {
    $stmt = $conn->prepare("SELECT a.sid, s.lname, a.attendance_date, a.status 
                            FROM attendance a 
                            JOIN students s ON a.sid = s.sid 
                            WHERE s.section = :section      
                            ORDER BY a.attendance_date DESC");
    $stmt->bindParam(':section', $section);
    $stmt->execute();
    $attendances_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Error fetching attendance: " . $e->getMessage();
}

// Fetch grades records for students in the section
try {
    $stmt = $conn->prepare("SELECT g.sid, s.lname, g.subject_code, g.grade_id
                            FROM grades g
                            JOIN students s ON g.sid = s.sid
                            WHERE s.section = :section");
    $stmt->bindParam(':section', $section);
    $stmt->execute();
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Error fetching grades: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Monitoring Page</title>
    <link rel="stylesheet" href="registrar.css">
</head>
<body>

<div class="navbar">
    <a href="registrar_monitoring.php">Home</a>
    <a href="logout.php">Logout</a>
</div>

<h1>Registrar Monitoring Dashboard</h1>

<h2>Students Overview (Section: <?= htmlspecialchars($section) ?>)</h2>
<table border="1" cellpadding="10">
    <thead>
        <tr>
            <th>Student ID</th>
            <th>Name</th>
            <th>Program</th>
            <th>Year</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($students as $student): ?>
            <tr>
                <td><?= htmlspecialchars($student['sid']); ?></td>
                <td><?= htmlspecialchars($student['lname']); ?></td>
                <td><?= htmlspecialchars($student['program']); ?></td>
                <td><?= htmlspecialchars($student['year']); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>Attendance Records for Section <?= htmlspecialchars($section) ?></h2>
<table border="1" cellpadding="10">
    <thead>
        <tr>
            <th>Student ID</th>
            <th>Student Name</th>
            <th>Date</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (count($attendances_records) > 0): ?>
            <?php foreach ($attendances_records as $record): ?>
                <tr>
                    <td><?= htmlspecialchars($record['sid']); ?></td>
                    <td><?= htmlspecialchars($record['lname']); ?></td>
                    <td><?= htmlspecialchars($record['attendance_date']); ?></td>
                    <td><?= htmlspecialchars($record['status']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">No attendance records found for this section.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<h2>Grades Overview</h2>
<table border="1" cellpadding="10">
    <thead>
        <tr>
            <th>Student ID</th>
            <th>Student Name</th>
            <th>Subject</th>
            <th>Grade</th>
        </tr>
    </thead>
    <tbody>
        <?php if (isset($grades) && count($grades) > 0): ?>
            <?php foreach ($grades as $grade): ?>
                <tr>
                    <td><?= htmlspecialchars($grade['sid']); ?></td>
                    <td><?= htmlspecialchars($grade['lname']); ?></td>
                    <td><?= htmlspecialchars($grade['subject_name']); ?></td>
                    <td><?= htmlspecialchars($grade['grade']); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">No grades available for this section.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

</body>
</html>