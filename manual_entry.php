<?php
// FILE: manual_entry.php
// PURPOSE: Asks the professor to select a term (Midterm/Final) to manage.

session_start();
include 'db.php';

// --- PROFESSOR AUTHENTICATION ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    header("Location: index.php");
    exit();
}
$professor_sid = $_SESSION['sid'];
// --- END: PROFESSOR AUTHENTICATION ---

// --- Get Class ID from URL ---
$class_id = $_GET['class_id'] ?? null;
if (!$class_id) {
    die("Error: No class ID provided. Please go back to your dashboard.");
}

$page_title = "Select Term";

try {
    // --- SECURITY CHECK: Verify this professor teaches this class ---
    $stmt_check = $conn->prepare("
        SELECT s.subject_name, c.section, s.subject_code
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        WHERE c.class_id = :cid AND c.professor_sid = :psid
    ");
    $stmt_check->execute([':cid' => $class_id, ':psid' => $professor_sid]);
    $class_info = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$class_info) {
        die("Access Denied: You are not authorized to manage this class.");
    }
    
    $page_title = htmlspecialchars($class_info['subject_name'] . ' (' . $class_info['subject_code'] . ') - ' . $class_info['section']);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Term to Manage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        .term-button {
            background: #1f6ea3; /* --panel-blue */
            color: white;
            padding: 24px;
            border-radius: 12px;
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
            transition: all 0.2s ease;
            display: block;
            text-decoration: none;
        }
        .term-button:hover {
            background: #0f4a73; /* --nav-dark */
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .term-button.final {
            background: #c92a2a; /* --critical-dark */
        }
        .term-button.final:hover {
            background: #991b1b;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen font-sans">

    <header class="bg-blue-800 text-white p-4 shadow-lg flex justify-between items-center">
        <div class="text-xl font-bold">Professor Panel</div>
        <button onclick="window.location.href='teacher_dashboard.php'" class="bg-white text-blue-800 hover:bg-gray-200 transition duration-150 font-semibold py-2 px-4 rounded-lg shadow-md">
            Back to Dashboard
        </button>
    </header>

    <main class="container mx-auto p-4 md:p-8">

        <section class="bg-white p-6 rounded-xl shadow-lg mb-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-blue-800 mb-1">
                Manage Class: <?php echo $page_title; ?>
            </h2>
        </section>

        <section class="bg-white p-6 rounded-xl shadow-lg">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Please select a term to manage:</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <a href="professor_manage_class.php?class_id=<?php echo $class_id; ?>&term=Midterm" class="term-button">
                    <i class="fa-solid fa-clipboard"></i> Manage Midterm
                </a>
                <a href="professor_manage_class.php?class_id=<?php echo $class_id; ?>&term=Final" class="term-button final">
                    <i class="fa-solid fa-flag-checkered"></i> Manage Final
                </a>
            </div>
        </section>

    </main>
</body>
</html>