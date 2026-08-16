<?php
// FILE: registrar_reports.php (Reports View)
// VERSION 6: ADDED DETAILED STUDENT ACADEMIC HISTORY REPORT

// --- CSV EXPORT LOGIC ---
// This block checks for an 'export' flag. If found, it generates a CSV and stops.
if (isset($_GET['export']) && $_GET['export'] == '1') {
    
    // We must re-run session/db connection here as this file will be executed via a direct link (for the download)
    session_start();
    include 'db.php'; // Assumes db.php is available

    // --- Authentication & Permission Check ---
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Registrar') {
        die('Authentication failed.');
    }
    $registrar_sid = $_SESSION['sid'] ?? 0;
    if (empty($registrar_sid)) {
        die('User ID not found in session.');
    }

    // --- Get Filters ---
    $report_id = $_GET['report_id'] ?? null;
    $filter_acad_year = $_GET['academic_year'] ?? '';
    $filter_semester = $_GET['semester'] ?? '';
    $filter_prof_sid = $_GET['professor_sid'] ?? '';
    $filter_section_key = $_GET['section_key'] ?? ''; 
    $filter_program = $_GET['program'] ?? ''; // New filter for academic history

    $report_title = 'Report';
    $report_data = [];

    // --- Run the exact same query as the HTML page (but without PH filters) ---
    try {
        if ($report_id && !empty($filter_acad_year) && !empty($filter_semester)) {
            
            switch ($report_id) {
                
                // --- CASE 1: AT-RISK STUDENTS (SYSTEM-WIDE) ---
                case 'at_risk':
                    $report_title = "At-Risk_Students_{$filter_acad_year}_{$filter_semester}";
                    $sql = "
                        SELECT 
                            up.first_name, up.last_name, up.student_id, up.program,
                            s.subject_code, s.subject_name,
                            g.final_grade,
                            CONCAT(prof_up.first_name, ' ', prof_up.last_name) AS prof_name
                        FROM grades g
                        JOIN users u ON g.student_id_string = u.username
                        JOIN user_profiles up ON u.sid = up.sid
                        JOIN subject s ON g.subject_id = s.subject_id
                        JOIN enrollment e ON u.sid = e.student_sid
                        JOIN classes c ON e.class_id = c.class_id AND c.subject_id = s.subject_id
                        LEFT JOIN users prof_u ON c.professor_sid = prof_u.sid
                        LEFT JOIN user_profiles prof_up ON prof_u.sid = prof_up.sid
                        WHERE 
                            c.academic_year = :acad_year
                            AND c.semester = :semester
                            AND g.final_grade < 75
                            AND g.final_grade IS NOT NULL
                            AND g.term = 'Final'
                        ORDER BY
                            up.program, up.last_name, s.subject_code
                    ";
                    $stmt_report = $conn->prepare($sql);
                    $stmt_report->execute([
                        ':acad_year' => $filter_acad_year,
                        ':semester' => $filter_semester
                    ]);
                    $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                    break;

                // --- CASE 2: SUBJECT PERFORMANCE (SYSTEM-WIDE) ---
                case 'subject_performance':
                    $report_title = "Subject_Performance_{$filter_acad_year}_{$filter_semester}";
                    $sql = "
                        SELECT 
                            s.subject_code, s.subject_name,
                            up_subj.program,
                            COUNT(g.grade_id) AS total_students,
                            AVG(g.final_grade) AS avg_grade,
                            SUM(CASE WHEN g.final_grade >= 75 THEN 1 ELSE 0 END) AS pass_count,
                            SUM(CASE WHEN g.final_grade < 75 THEN 1 ELSE 0 END) AS fail_count,
                            (SUM(CASE WHEN g.final_grade >= 75 THEN 1 ELSE 0 END) / COUNT(g.grade_id)) * 100 AS pass_rate
                        FROM grades g
                        JOIN subject s ON g.subject_id = s.subject_id
                        JOIN users u ON g.student_id_string = u.username
                        JOIN enrollment e ON u.sid = e.student_sid
                        JOIN classes c ON e.class_id = c.class_id AND c.subject_id = s.subject_id
                        LEFT JOIN users ph_u ON s.program_head_id = ph_u.sid
                        LEFT JOIN user_profiles up_subj ON ph_u.sid = up_subj.sid
                        WHERE
                            c.academic_year = :acad_year
                            AND c.semester = :semester
                            AND g.final_grade IS NOT NULL
                            AND g.term = 'Final'
                        GROUP BY
                            s.subject_code, s.subject_name, up_subj.program
                        ORDER BY
                            up_subj.program, pass_rate ASC, s.subject_code
                    ";
                    $stmt_report = $conn->prepare($sql);
                    $stmt_report->execute([
                        ':acad_year' => $filter_acad_year,
                        ':semester' => $filter_semester
                    ]);
                    $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                    break;

                // --- CASE 3: FACULTY GRADE DISTRIBUTION (SYSTEM-WIDE) ---
                case 'faculty_performance':
                    if (!empty($filter_prof_sid)) {
                        $stmt_prof_name = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM user_profiles WHERE sid = :sid");
                        $stmt_prof_name->execute([':sid' => $filter_prof_sid]);
                        $prof_name = $stmt_prof_name->fetchColumn();
                        $prof_name_safe = preg_replace('/[^A-Za-z0-9_]/', '', $prof_name);

                        $report_title = "Faculty_Distribution_{$prof_name_safe}_{$filter_acad_year}";
                        $sql = "
                            SELECT 
                                s.subject_code, s.subject_name,
                                c.section, up.program,
                                COUNT(g.grade_id) AS total_students,
                                AVG(g.final_grade) AS avg_grade,
                                MAX(g.final_grade) AS high_grade,
                                MIN(g.final_grade) AS low_grade,
                                (SUM(CASE WHEN g.final_grade >= 75 THEN 1 ELSE 0 END) / COUNT(g.grade_id)) * 100 AS pass_rate
                            FROM grades g
                            JOIN subject s ON g.subject_id = s.subject_id
                            JOIN users u ON g.student_id_string = u.username
                            JOIN enrollment e ON u.sid = e.student_sid
                            JOIN classes c ON e.class_id = c.class_id AND c.subject_id = s.subject_id
                            JOIN user_profiles up ON u.sid = up.sid
                            WHERE
                                c.academic_year = :acad_year
                                AND c.semester = :semester
                                AND c.professor_sid = :prof_sid
                                AND g.final_grade IS NOT NULL
                                AND g.term = 'Final'
                            GROUP BY
                                s.subject_code, s.subject_name, c.section, up.program
                            ORDER BY
                                up.program, s.subject_code, c.section
                        ";
                        $stmt_report = $conn->prepare($sql);
                        $stmt_report->execute([
                            ':acad_year' => $filter_acad_year,
                            ':semester' => $filter_semester,
                            ':prof_sid' => $filter_prof_sid
                        ]);
                        $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                    }
                    break;
                
                // --- CASE 4: ENROLLED STUDENTS BY SECTION (SYSTEM-WIDE) ---
                case 'enrolled_by_section':
                    if (!empty($filter_section_key)) {
                        $report_title = "Enrolled_Students_{$filter_section_key}_{$filter_acad_year}";
                        $sql = "
                            SELECT
                                DISTINCT up.student_id,
                                up.last_name,
                                up.first_name,
                                up.program
                            FROM enrollment e
                            JOIN classes c ON e.class_id = c.class_id
                            JOIN users u ON e.student_sid = u.sid
                            JOIN user_profiles up ON u.sid = up.sid
                            WHERE
                                c.academic_year = :acad_year
                                AND c.semester = :semester
                                AND c.section = :section_key
                            ORDER BY
                                up.program, up.last_name, up.first_name
                        ";
                        $stmt_report = $conn->prepare($sql);
                        $stmt_report->execute([
                            ':acad_year' => $filter_acad_year,
                            ':semester' => $filter_semester,
                            ':section_key' => $filter_section_key
                        ]);
                        $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                    }
                    break;

                // --- CASE 5: DETAILED STUDENT ACADEMIC HISTORY ---
                case 'academic_history':
                    if (!empty($filter_program)) {
                        $report_title = "Academic_History_{$filter_program}_{$filter_acad_year}_{$filter_semester}";
                        $sql = "
                            SELECT
                                CONCAT(up.last_name, ', ', up.first_name) AS student_name,
                                up.student_id,
                                up.program,
                                sah.academic_year AS student_acad_year,
                                sah.semester AS student_semester,
                                sah.cumulative_gwa,
                                sah.fail_count
                            FROM student_academic_history sah
                            JOIN users u ON sah.student_sid = u.sid
                            JOIN user_profiles up ON u.sid = up.sid
                            WHERE
                                up.program = :program
                                AND sah.academic_year = :acad_year
                                AND sah.semester = :semester
                            ORDER BY
                                up.last_name, up.first_name, sah.academic_year, sah.semester
                        ";
                        $stmt_report = $conn->prepare($sql);
                        $stmt_report->execute([
                            ':program' => $filter_program,
                            ':acad_year' => $filter_acad_year,
                            ':semester' => $filter_semester
                        ]);
                        $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                    }
                    break;
            }
        }
    } catch (PDOException $e) {
        die("Database error generating report: " . $e->getMessage());
    }

    // --- Start CSV Generation ---
    
    // Clean filename
    $filename = preg_replace('/[^A-Za-z0-9_]/', '', $report_title) . ".csv";

    // Set browser headers to force download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Open the output stream
    $output = fopen('php://output', 'w');
    
    // Write data based on which report was run
    if (!empty($report_data)) {
        switch ($report_id) {
            case 'at_risk':
                fputcsv($output, ['Student Name', 'Student ID', 'Program', 'Subject Code', 'Subject Name', 'Professor', 'Final Grade']);
                foreach ($report_data as $row) {
                    fputcsv($output, [
                        $row['last_name'] . ', ' . $row['first_name'],
                        $row['student_id'],
                        $row['program'] ?? 'N/A',
                        $row['subject_code'],
                        $row['subject_name'],
                        $row['prof_name'],
                        number_format($row['final_grade'], 2)
                    ]);
                }
                break;

            case 'subject_performance':
                fputcsv($output, ['Subject Code', 'Subject Name', 'Program (PH)', 'Total Students', 'Pass Rate (%)', 'Avg. Grade', 'Pass Count', 'Fail Count']);
                foreach ($report_data as $row) {
                    fputcsv($output, [
                        $row['subject_code'],
                        $row['subject_name'],
                        $row['program'] ?? 'N/A',
                        $row['total_students'],
                        number_format($row['pass_rate'], 2),
                        number_format($row['avg_grade'], 2),
                        $row['pass_count'],
                        $row['fail_count']
                    ]);
                }
                break;
            
            case 'faculty_performance':
                fputcsv($output, ['Program', 'Subject Code', 'Subject Name', 'Section', 'Total Students', 'Pass Rate (%)', 'Avg. Grade', 'Highest Grade', 'Lowest Grade']);
                foreach ($report_data as $row) {
                    fputcsv($output, [
                        $row['program'] ?? 'N/A',
                        $row['subject_code'],
                        $row['subject_name'],
                        $row['section'],
                        $row['total_students'],
                        number_format($row['pass_rate'], 2),
                        number_format($row['avg_grade'], 2),
                        number_format($row['high_grade'], 2),
                        number_format($row['low_grade'], 2)
                    ]);
                }
                break;

            case 'enrolled_by_section':
                fputcsv($output, ['Program', 'Student ID', 'Last Name', 'First Name']);
                foreach ($report_data as $row) {
                    fputcsv($output, [
                        $row['program'] ?? 'N/A',
                        $row['student_id'],
                        $row['last_name'],
                        $row['first_name']
                    ]);
                }
                break;

            case 'academic_history':
                fputcsv($output, ['Student Name', 'Student ID', 'Program', 'Academic Year', 'Semester', 'Cumulative GWA', 'Fail Count']);
                foreach ($report_data as $row) {
                    fputcsv($output, [
                        $row['student_name'],
                        $row['student_id'],
                        $row['program'],
                        $row['student_acad_year'],
                        $row['student_semester'],
                        number_format($row['cumulative_gwa'] ?? 0, 2),
                        $row['fail_count']
                    ]);
                }
                break;
        }
    } else {
        // Write a "no data" row if the report was empty
        fputcsv($output, ['No data found matching your selected filters.']);
    }
    
    fclose($output);
    exit(); // Stop script execution
}
// --- END CSV EXPORT LOGIC ---


// --- Regular HTML Page Load (INCLUDED BY ROUTER) ---

// We assume $conn, $view, $report_id, $filter_acad_year, $filter_semester, $filter_prof_sid, $filter_section_key are set by the router.

$report_title = '';
$report_description = '';
$report_data = [];
$filter_data_local = [
    'academic_years' => [],
    'professors' => [],
    'sections' => [],
    'programs' => [] // Added programs filter
];
$report_availability_local = [
    'grades' => false,
    'students' => false,
    'profs' => false
];

try {
    // --- 1. Load Data for Filters ---
    $stmt_years = $conn->prepare("SELECT DISTINCT academic_year FROM classes ORDER BY academic_year DESC");
    $stmt_years->execute();
    $filter_data_local['academic_years'] = $stmt_years->fetchAll(PDO::FETCH_ASSOC);

    $stmt_profs = $conn->prepare("
        SELECT u.sid, CONCAT(up.first_name, ' ', up.last_name) AS full_name 
        FROM users u
        JOIN user_profiles up ON u.sid = up.sid
        WHERE u.role = 'professor'
        ORDER BY up.last_name ASC
    ");
    $stmt_profs->execute();
    $filter_data_local['professors'] = $stmt_profs->fetchAll(PDO::FETCH_ASSOC);
    $report_availability_local['profs'] = (count($filter_data_local['professors']) > 0);

    // Fetch all programs for the Academic History report
    $stmt_programs = $conn->prepare("SELECT DISTINCT program FROM user_profiles WHERE program IS NOT NULL ORDER BY program ASC");
    $stmt_programs->execute();
    $filter_data_local['programs'] = $stmt_programs->fetchAll(PDO::FETCH_ASSOC);


    if ($report_id == 'enrolled_by_section' && !empty($filter_acad_year) && !empty($filter_semester)) {
        $stmt_sections = $conn->prepare("
            SELECT DISTINCT section 
            FROM classes
            WHERE 
                academic_year = :acad_year
                AND semester = :semester
            ORDER BY section
        ");
        $stmt_sections->execute([
            ':acad_year' => $filter_acad_year,
            ':semester' => $filter_semester
        ]);
        $filter_data_local['sections'] = $stmt_sections->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt_check_grades = $conn->prepare("SELECT 1 FROM grades WHERE final_grade IS NOT NULL LIMIT 1");
    $stmt_check_grades->execute();
    $report_availability_local['grades'] = $stmt_check_grades->fetchColumn() > 0;

    $stmt_check_students = $conn->prepare("SELECT 1 FROM user_profiles WHERE student_id IS NOT NULL LIMIT 1");
    $stmt_check_students->execute();
    $report_availability_local['students'] = $stmt_check_students->fetchColumn() > 0;
    
    // --- 2. Process Report Generation ---
    if ($report_id && !empty($filter_acad_year) && !empty($filter_semester)) {
        
        switch ($report_id) {
            
            case 'at_risk':
                $report_title = "System-Wide At-Risk Students Report";
                $report_description = "Students with a final grade below 75 across all programs for $filter_acad_year / $filter_semester.";
                
                $sql = "
                    SELECT 
                        up.first_name, up.last_name, up.student_id, up.program,
                        s.subject_code, s.subject_name,
                        g.final_grade,
                        CONCAT(prof_up.first_name, ' ', prof_up.last_name) AS prof_name
                    FROM grades g
                    JOIN users u ON g.student_id_string = u.username
                    JOIN user_profiles up ON u.sid = up.sid
                    JOIN subject s ON g.subject_id = s.subject_id
                    JOIN enrollment e ON u.sid = e.student_sid
                    JOIN classes c ON e.class_id = c.class_id AND c.subject_id = s.subject_id
                    LEFT JOIN users prof_u ON c.professor_sid = prof_u.sid
                    LEFT JOIN user_profiles prof_up ON prof_u.sid = prof_up.sid
                    WHERE 
                        c.academic_year = :acad_year
                        AND c.semester = :semester
                        AND g.final_grade < 75
                        AND g.final_grade IS NOT NULL
                        AND g.term = 'Final'
                    ORDER BY
                        up.program, up.last_name, s.subject_code
                ";
                $stmt_report = $conn->prepare($sql);
                $stmt_report->execute([':acad_year' => $filter_acad_year, ':semester' => $filter_semester]);
                $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'subject_performance':
                $report_title = "System-Wide Subject Performance Report";
                $report_description = "Analysis of all subjects across all programs for $filter_acad_year / $filter_semester.";
                
                $sql = "
                    SELECT 
                        s.subject_code, s.subject_name, up_subj.program,
                        COUNT(g.grade_id) AS total_students,
                        AVG(g.final_grade) AS avg_grade,
                        SUM(CASE WHEN g.final_grade >= 75 THEN 1 ELSE 0 END) AS pass_count,
                        SUM(CASE WHEN g.final_grade < 75 THEN 1 ELSE 0 END) AS fail_count,
                        (SUM(CASE WHEN g.final_grade >= 75 THEN 1 ELSE 0 END) / COUNT(g.grade_id)) * 100 AS pass_rate
                    FROM grades g
                    JOIN subject s ON g.subject_id = s.subject_id
                    JOIN users u ON g.student_id_string = u.username
                    JOIN enrollment e ON u.sid = e.student_sid
                    JOIN classes c ON e.class_id = c.class_id AND c.subject_id = s.subject_id
                    LEFT JOIN users ph_u ON s.program_head_id = ph_u.sid
                    LEFT JOIN user_profiles up_subj ON ph_u.sid = up_subj.sid
                    WHERE
                        c.academic_year = :acad_year
                        AND c.semester = :semester
                        AND g.final_grade IS NOT NULL
                        AND g.term = 'Final'
                    GROUP BY
                        s.subject_code, s.subject_name, up_subj.program
                    ORDER BY
                        up_subj.program, pass_rate ASC, s.subject_code
                ";
                $stmt_report = $conn->prepare($sql);
                $stmt_report->execute([':acad_year' => $filter_acad_year, ':semester' => $filter_semester]);
                $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'faculty_performance':
                if (!empty($filter_prof_sid)) {
                    $report_title = "Faculty Grade Distribution Report";
                    $prof_name = 'N/A';
                    foreach($filter_data_local['professors'] as $prof) {
                        if ($prof['sid'] == $filter_prof_sid) {
                            $prof_name = $prof['full_name'];
                            break;
                        }
                    }
                    $report_description = "Grading summary for all classes taught by $prof_name during $filter_acad_year / $filter_semester.";

                    $sql = "
                        SELECT 
                            s.subject_code, s.subject_name,
                            c.section, up.program,
                            COUNT(g.grade_id) AS total_students,
                            AVG(g.final_grade) AS avg_grade,
                            MAX(g.final_grade) AS high_grade,
                            MIN(g.final_grade) AS low_grade,
                            (SUM(CASE WHEN g.final_grade >= 75 THEN 1 ELSE 0 END) / COUNT(g.grade_id)) * 100 AS pass_rate
                        FROM grades g
                        JOIN subject s ON g.subject_id = s.subject_id
                        JOIN users u ON g.student_id_string = u.username
                        JOIN enrollment e ON u.sid = e.student_sid
                        JOIN classes c ON e.class_id = c.class_id AND c.subject_id = s.subject_id
                        JOIN user_profiles up ON u.sid = up.sid
                        WHERE
                            c.academic_year = :acad_year
                            AND c.semester = :semester
                            AND c.professor_sid = :prof_sid
                            AND g.final_grade IS NOT NULL
                            AND g.term = 'Final'
                        GROUP BY
                            s.subject_code, s.subject_name, c.section, up.program
                        ORDER BY
                            up.program, s.subject_code, c.section
                    ";
                    $stmt_report = $conn->prepare($sql);
                    $stmt_report->execute([
                        ':acad_year' => $filter_acad_year,
                        ':semester' => $filter_semester,
                        ':prof_sid' => $filter_prof_sid
                    ]);
                    $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $report_title = "Faculty Grade Distribution Report";
                    $report_description = "Please select a professor to generate this report.";
                }
                break;
            
            case 'enrolled_by_section':
                if (!empty($filter_section_key)) {
                    $report_title = "Enrolled Students Report";
                    $report_description = "Class roster for section $filter_section_key during $filter_acad_year / $filter_semester.";
                    $sql = "
                        SELECT
                            DISTINCT up.student_id,
                            up.last_name,
                            up.first_name,
                            up.program
                        FROM enrollment e
                        JOIN classes c ON e.class_id = c.class_id
                        JOIN users u ON e.student_sid = u.sid
                        JOIN user_profiles up ON u.sid = up.sid
                        WHERE
                            c.academic_year = :acad_year
                            AND c.semester = :semester
                            AND c.section = :section_key
                        ORDER BY
                            up.program, up.last_name, up.first_name
                    ";
                    $stmt_report = $conn->prepare($sql);
                    $stmt_report->execute([
                        ':acad_year' => $filter_acad_year,
                        ':semester' => $filter_semester,
                        ':section_key' => $filter_section_key
                    ]);
                    $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $report_title = "Enrolled Students Report";
                    $report_description = "Please select an Academic Year, Semester, and Section to generate a roster.";
                }
                break;

            // --- CASE 5: DETAILED STUDENT ACADEMIC HISTORY ---
            case 'academic_history':
                if (!empty($filter_program)) {
                    $report_title = "Detailed Student Academic History";
                    $report_description = "Cumulative GWA and academic progression for students in {$filter_program} during {$filter_acad_year} / {$filter_semester}.";
                    $sql = "
                        SELECT
                            CONCAT(up.last_name, ', ', up.first_name) AS student_name,
                            up.student_id,
                            up.program,
                            sah.academic_year AS student_acad_year,
                            sah.semester AS student_semester,
                            sah.cumulative_gwa,
                            sah.fail_count
                        FROM student_academic_history sah
                        JOIN users u ON sah.student_sid = u.sid
                        JOIN user_profiles up ON u.sid = up.sid
                        WHERE
                            up.program = :program
                            AND sah.academic_year = :acad_year
                            AND sah.semester = :semester
                        ORDER BY
                            up.last_name, up.first_name, sah.academic_year, sah.semester
                    ";
                    $stmt_report = $conn->prepare($sql);
                    $stmt_report->execute([
                        ':program' => $filter_program,
                        ':acad_year' => $filter_acad_year,
                        ':semester' => $filter_semester
                    ]);
                    $report_data = $stmt_report->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $report_title = "Detailed Student Academic History";
                    $report_description = "Please select a Program, Academic Year, and Semester to generate the academic history.";
                }
                break;
        }
    }
} catch (PDOException $e) {
    $db_error_report = "Database error: " . $e->getMessage();
}
?>

<style>
    /* Inlined CSS for the Reports View */
    .report-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        align-items: flex-end;
    }
    .form-group {
        display: flex;
        flex-direction: column;
    }
    .form-group label {
        font-weight: 600; 
        display: block; 
        margin-bottom: 5px;
    }
    .form-group select,
    .form-group input {
        width: 100%; 
        padding: 10px; 
        border: 1px solid #ccc; 
        border-radius: 6px;
    }
    .form-group select:disabled {
        background-color: #eee;
        cursor: not-allowed;
    }
    .report-results {
        margin-top: 2rem;
    }
    .report-actions {
        display: flex;
        gap: 10px;
        margin-bottom: 1rem;
    }
    select option:disabled {
        color: #aaa;
        background: #eee;
    }
    @media print {
        body * {
            visibility: hidden;
        }
        .report-results, .report-results * {
            visibility: visible;
        }
        .report-results {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }
        .review-table th, .review-table td {
            font-size: 10pt;
        }
        .no-print {
            display: none;
        }
    }
</style>

<?php if (isset($db_error_report)): ?>
    <div class="message error"><?php echo $db_error_report; ?></div>
<?php endif; ?>

<section class="content-section mb-8 no-print">
    <h2><i class="fa-solid fa-chart-pie"></i> Generate Reports</h2>
    <p class="description">Select a report to generate, then apply filters to view the data.</p>
    
    <form action="registrar_dashboard.php?view=reports" method="GET">
        <input type="hidden" name="view" value="reports">
        <div class="report-form-grid">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="report_id">1. Select a Report</label>
                <select name="report_id" id="report_id" onchange="this.form.submit()">
                    <option value="">Select a report type...</option>
                    
                    <option value="at_risk" 
                        <?php echo ($report_id == 'at_risk') ? 'selected' : ''; ?>
                        <?php echo (!$report_availability_local['grades']) ? 'disabled' : ''; ?>>
                        At-Risk Students <?php echo (!$report_availability_local['grades']) ? '(No grade data found)' : ''; ?>
                    </option>
                    
                    <option value="subject_performance" 
                        <?php echo ($report_id == 'subject_performance') ? 'selected' : ''; ?>
                        <?php echo (!$report_availability_local['grades']) ? 'disabled' : ''; ?>>
                        Subject Performance <?php echo (!$report_availability_local['grades']) ? '(No grade data found)' : ''; ?>
                    </option>
                    
                    <option value="faculty_performance" 
                        <?php echo ($report_id == 'faculty_performance') ? 'selected' : ''; ?>
                        <?php echo (!$report_availability_local['profs']) ? 'disabled' : ''; ?>>
                        Faculty Grade Distribution <?php echo (!$report_availability_local['profs']) ? '(No professors found)' : ''; ?>
                    </option>
                    
                    <option value="enrolled_by_section" 
                        <?php echo ($report_id == 'enrolled_by_section') ? 'selected' : ''; ?>
                        <?php echo (!$report_availability_local['students']) ? 'disabled' : ''; ?>>
                        Enrolled Students by Section <?php echo (!$report_availability_local['students']) ? '(No students found)' : ''; ?>
                    </option>

                    <option value="academic_history" 
                        <?php echo ($report_id == 'academic_history') ? 'selected' : ''; ?>
                        <?php echo (!$report_availability_local['students']) ? 'disabled' : ''; ?>>
                        Detailed Student Academic History <?php echo (!$report_availability_local['students']) ? '(No student data found)' : ''; ?>
                    </option>

                </select>
            </div>
        </div>
    </form>
</section>

<?php if ($report_id): ?>
<section class="content-section mb-8 no-print">
    <h2><i class="fa-solid fa-filter"></i> 2. Apply Filters</h2>
    <form action="registrar_dashboard.php?view=reports" method="GET">
        <input type="hidden" name="view" value="reports">
        <input type="hidden" name="report_id" value="<?php echo htmlspecialchars($report_id); ?>">
        <div class="report-form-grid">
            
            <?php if ($report_id == 'academic_history'): ?>
                <div class="form-group">
                    <label for="program">Program</label>
                    <select name="program" id="program" required>
                        <option value="">Select program...</option>
                        <?php foreach ($filter_data_local['programs'] as $prog): ?>
                            <option value="<?php echo htmlspecialchars($prog['program']); ?>" <?php echo ($filter_program == $prog['program']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prog['program']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="academic_year">Academic Year</label>
                <select name="academic_year" id="academic_year" required>
                    <option value="">Select year...</option>
                    <?php foreach ($filter_data_local['academic_years'] as $year): ?>
                        <option value="<?php echo $year['academic_year']; ?>" <?php echo ($filter_acad_year == $year['academic_year']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year['academic_year']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="semester">Semester</label>
                <select name="semester" id="semester" required>
                    <option value="">Select semester...</option>
                    <option value="1st Semester" <?php echo ($filter_semester == '1st Semester') ? 'selected' : ''; ?>>1st Semester</option>
                    <option value="2nd Semester" <?php echo ($filter_semester == '2nd Semester') ? 'selected' : ''; ?>>2nd Semester</option>
                </select>
            </div>

            <?php if ($report_id == 'faculty_performance'): ?>
                <div class="form-group">
                    <label for="professor_sid">Professor</label>
                    <select name="professor_sid" id="professor_sid" required>
                        <option value="">Select professor...</option>
                        <?php foreach ($filter_data_local['professors'] as $prof): ?>
                            <option value="<?php echo $prof['sid']; ?>" <?php echo ($filter_prof_sid == $prof['sid']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prof['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($report_id == 'enrolled_by_section'): ?>
                <div class="form-group">
                    <label for="section_key">Section</label>
                    <select name="section_key" id="section_key" required <?php echo (empty($filter_data_local['sections'])) ? 'disabled' : ''; ?>>
                        
                        <?php if (empty($filter_data_local['sections'])): ?>
                            
                            <?php if (empty($filter_acad_year) || empty($filter_semester)): ?>
                                <option value="">Select Year & Semester first...</option>
                            <?php else: ?>
                                <option value="">No sections found for <?php echo htmlspecialchars($filter_acad_year); ?> / <?php echo htmlspecialchars($filter_semester); ?></option>
                            <?php endif; ?>

                        <?php else: ?>
                            <option value="">Select a section...</option>
                            <?php foreach ($filter_data_local['sections'] as $section): ?>
                                <option value="<?php echo htmlspecialchars($section['section']); ?>" <?php echo ($filter_section_key == $section['section']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section['section']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <button type="submit" class="approve-btn" style="background-color: var(--panel-blue); width: 100%;">
                    <i class="fa-solid fa-play"></i> Generate Report
                </button>
            </div>

        </div>
    </form>
</section>
<?php endif; ?>


<?php if (!empty($report_data)): ?>
    <section class="content-section report-results">
        <h2><i class="fa-solid fa-file-alt"></i> <?php echo htmlspecialchars($report_title); ?></h2>
        <p class="description"><?php echo htmlspecialchars($report_description); ?></p>
        
        <div class="report-actions no-print">
            <button onclick="window.print()" class="approve-btn" style="background-color: var(--success-green);">
                <i class="fa-solid fa-print"></i> Print Report
            </button>
            <?php
                // Create the query string for the export button
                $export_params = $_GET;
                $export_params['export'] = '1';
                $export_query_string = http_build_query($export_params);
            ?>
            <a href="registrar_reports.php?<?php echo $export_query_string; ?>" class="approve-btn" style="background-color: #1e6091; text-decoration: none;">
                <i class="fa-solid fa-file-excel"></i> Export to Excel (CSV)
            </a>
        </div>

        <div class="table-container">
            <table class="review-table">
                <?php if ($report_id == 'at_risk'): ?>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Student ID</th>
                            <th>Program</th>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Professor</th>
                            <th>Final Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['program'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['subject_code']); ?></td>
                                <td><?php echo htmlspecialchars($row['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['prof_name']); ?></td>
                                <td><strong style="color: var(--critical);"><?php echo number_format($row['final_grade'], 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                
                <?php elseif ($report_id == 'subject_performance'): ?>
                    <thead>
                        <tr>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Program (PH)</th>
                            <th>Total Students</th>
                            <th>Pass Rate</th>
                            <th>Avg. Grade</th>
                            <th>Pass</th>
                            <th>Fail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['subject_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['program'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['total_students']); ?></td>
                                <td><strong><?php echo number_format($row['pass_rate'], 2); ?>%</strong></td>
                                <td><?php echo number_format($row['avg_grade'], 2); ?></td>
                                <td style="color: var(--success-green);"><?php echo htmlspecialchars($row['pass_count']); ?></td>
                                <td style="color: var(--critical);"><?php echo htmlspecialchars($row['fail_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                <?php elseif ($report_id == 'faculty_performance'): ?>
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Subject Code</th>
                            <th>Subject Name</th>
                            <th>Section</th>
                            <th>Total Students</th>
                            <th>Pass Rate</th>
                            <th>Avg. Grade</th>
                            <th>Highest Grade</th>
                            <th>Lowest Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['program'] ?? 'N/A'); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['subject_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['section']); ?></td>
                                <td><?php echo htmlspecialchars($row['total_students']); ?></td>
                                <td><strong><?php echo number_format($row['pass_rate'], 2); ?>%</strong></td>
                                <td><?php echo number_format($row['avg_grade'], 2); ?></td>
                                <td style="color: var(--success-green);"><?php echo number_format($row['high_grade'], 2); ?></td>
                                <td style="color: var(--critical);"><?php echo number_format($row['low_grade'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                <?php elseif ($report_id == 'enrolled_by_section'): ?>
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Student ID</th>
                            <th>Last Name</th>
                            <th>First Name</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['program'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['last_name']); ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($row['first_name']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                
                <?php elseif ($report_id == 'academic_history'): ?>
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Student ID</th>
                            <th>Program</th>
                            <th>Academic Year</th>
                            <th>Semester</th>
                            <th>Cumulative GWA</th>
                            <th>Fail Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['student_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['program']); ?></td>
                                <td><?php echo htmlspecialchars($row['student_acad_year']); ?></td>
                                <td><?php echo htmlspecialchars($row['student_semester']); ?></td>
                                <td><strong><?php echo number_format($row['cumulative_gwa'] ?? 0, 2); ?></strong></td>
                                <td style="color: <?php echo ($row['fail_count'] > 0 ? 'var(--critical)' : 'var(--success-green)'); ?>"><?php echo htmlspecialchars($row['fail_count']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                <?php endif; ?>
            </table>
        </div>
    </section>

<?php elseif ($report_id && !empty($filter_acad_year)): ?>
    <section class="content-section report-results">
        <h2><i class="fa-solid fa-file-alt"></i> <?php echo htmlspecialchars($report_title); ?></h2>
        <p class="description"><?php echo htmlspecialchars($report_description); ?></p>
        <?php if ($report_id == 'faculty_performance' && empty($filter_prof_sid)): ?>
             <p class="message warning">Please select a professor and generate the report.</p>
        <?php elseif ($report_id == 'enrolled_by_section' && empty($filter_section_key)): ?>
             <p class="message warning">Please select a section and generate the report.</p>
        <?php elseif ($report_id == 'academic_history' && empty($filter_program)): ?>
             <p class="message warning">Please select a program and generate the report.</p>
        <?php else: ?>
             <p class="no-data">No data found matching your selected filters.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>