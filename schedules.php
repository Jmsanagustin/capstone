<?php
session_start();
include 'db.php';

// --- FIX: Set current page for sidebar highlighting ---
$currentPage = 'schedules.php';

// 1. Check if user is logged in
if (!isset($_SESSION['sid'])) {
    header("Location: index.php");
    exit();
}

// 2. Fetch schedule data
$schedules = [];
$today_schedule = [];
$db_error = '';

try {
    $query = "SELECT * FROM schedules WHERE sid = :sid ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':sid', $_SESSION['sid']);
    $stmt->execute();
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = date('l');
    $today_schedule = array_filter($schedules, fn($s) => $s['day_of_week'] === $today);

} catch (PDOException $e) {
    $db_error = "Error fetching schedule: " . $e->getMessage();
    // In a real app, log this error
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Class Schedule</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" xintegrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Link to your existing student.css for header/sidebar styles -->
    <link rel="stylesheet" href="students.css"> 
    
    <style>
        /* --- Root Variables --- */
        :root {
            --nav-dark: #0f4a73; --panel-blue: #1f6ea3; --bg-light: #f2f5f8;
            --white: #ffffff; --text-dark: #102a43; --text-light: #627d98;
            --accent-light: #4db8ff; --shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            --critical: #cc3333; --success-green: #28a745;
        }
        /* --- Base styles --- */
        body { display: flex; height: 100vh; margin: 0; font-family: 'Inter', sans-serif; background-color: #f2f5f8; }
        .main-content { flex-grow: 1; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        main { flex-grow: 1; overflow-y: auto; padding: 24px; }
        /* Custom scrollbar */
        main::-webkit-scrollbar { width: 8px; }
        main::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        main::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        main::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* --- Content Styles --- */
        .content-section {
            background: var(--white);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow);
            margin-bottom: 32px;
        }
        .content-section h1 {
            font-size: 1.875rem; /* 30px */
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #e0e6ed;
            padding-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .content-section h2 {
            font-size: 1.25rem; /* 20px */
            font-weight: 700;
            color: var(--panel-blue);
            margin-bottom: 1rem;
        }
        /* Table styles from student dashboard */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            background: var(--white);
        }
        table th {
            background: var(--bg-light);
            color: var(--text-light);
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
        }
        table td {
            border-bottom: 1px solid #e0e6ed;
            padding: 15px;
            vertical-align: middle;
        }
        table tbody tr:last-child td {
            border-bottom: none;
        }
        .no-data {
            text-align: center;
            padding: 30px;
            color: var(--text-light);
        }
        .message.error { 
            padding: 15px 20px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; border: 1px solid;
            background: #fde8e8; color: var(--critical); border-color: var(--critical); 
        }
    </style>
</head>
<body>

    <!-- Include Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- FIX: This wrapper must match student_dashboard.php -->
    <div id="mainContent" class="main-content">
        <!-- Include Header -->
        <?php include 'header.php'; ?>

        <!-- Main Page View (Scrollable) -->
        <main class="p-8 max-w-7xl mx-auto"> 

            <div class="content-section">
                <h1><i class="fa-solid fa-calendar-alt"></i> Class Schedule</h1>

                <?php if ($db_error): ?>
                    <div class="message error"><?php echo htmlspecialchars($db_error); ?></div>
                <?php endif; ?>

                <section>
                    <h2>Today's Schedule (<?php echo $today; ?>)</h2>
                    <div style="overflow-x: auto;"> <!-- Table container for responsiveness -->
                        <?php if (!empty($today_schedule)): ?>
                            <table>
                                <thead><tr><th>Subject</th><th>Start</th><th>End</th><th>Room</th></tr></thead>
                                <tbody>
                                <?php foreach ($today_schedule as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['subject']); ?></td>
                                        <td><?= htmlspecialchars(date('g:i A', strtotime($row['start_time']))); ?></td>
                                        <td><?= htmlspecialchars(date('g:i A', strtotime($row['end_time']))); ?></td>
                                        <td><?= htmlspecialchars($row['room']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?><p class="no-data">No classes scheduled for today.</p><?php endif; ?>
                    </div>
                </section>
            </div>

            <div class="content-section">
                <section>
                    <h2>Full Weekly Schedule</h2>
                    <div style="overflow-x: auto;"> <!-- Table container for responsiveness -->
                        <?php if (!empty($schedules)): ?>
                            <table>
                                <thead><tr><th>Day</th><th>Subject</th><th>Start</th><th>End</th><th>Room</th></tr></thead>
                                <tbody>
                                <?php foreach ($schedules as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['day_of_week']); ?></td>
                                        <td><?= htmlspecialchars($row['subject']); ?></td>
                                        <td><?= htmlspecialchars(date('g:i A', strtotime($row['start_time']))); ?></td>
                                        <td><?= htmlspecialchars(date('g:i A', strtotime($row['end_time']))); ?></td>
                                        <td><?= htmlspecialchars($row['room']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                             <p class="no-data">No schedules found.</p>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </main>
    </div>
    
    <!-- Active link script (if not already in header.php) -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
             try {
                 const currentPath = window.location.pathname.split('/').pop();
                 // Find the link that matches the current file name
                 const activeLink = document.querySelector(`.sidebar a[href='${currentPath}']`); 
                 if (activeLink) { 
                      // Remove 'active' from all links
                      document.querySelectorAll('.sidebar a.active').forEach(link => link.classList.remove('active'));
                      // Add 'active' to the current page's link
                      activeLink.classList.add('active'); 
                 }
             } catch(e) { console.error("Could not set active link:", e); }
        });
    </script>

</body>
</html>