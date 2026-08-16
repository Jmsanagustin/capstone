<?php
// FILE: download_cor_report.php
// PURPOSE: Generates a CSV for student lists OR a multi-page HTML COR report.
// UPDATED: Added logic to handle "section=all" for both CSV and HTML reports.
// UPDATED: Branching logic. If 'student_names_section' is chosen, generate a CSV.
//          Otherwise, generate the multi-page HTML COR report.
// FIX:     Re-wrote grade query to use `term` and `term_grade` columns from the DB.
// FIX:     Re-built HTML/CSS to match the new template (image_ae3705.jpg).
// FIX:     Added Excel formatting for Student ID in CSV export to prevent scientific notation.
// UPDATE:  Added dynamic section formatting (e.g., "IS1-1-01") to HTML report.

session_start();
include 'db.php'; // Include your database connection

// 1. ROLE-BASED ACCESS CONTROL
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Registrar') {
    die("Access Denied. You must be logged in as a Registrar to view this page.");
}

// 2. GET DATA FROM FORM
$report_type = $_POST['report_type'] ?? null;
$section = $_POST['section_filter'] ?? null;

if (!$report_type || !$section) {
    die("Error: Report Type and Section are required. Please go back and select both.");
}

try {
    // 3. FETCH ALL STUDENTS BASED ON SECTION (or ALL)
    $sql = "
        SELECT 
            u.sid, 
            u.username, 
            up.student_id, 
            up.first_name, 
            up.middle_name, 
            up.last_name, 
            up.program, 
            up.section,
            up.academic_year -- Added academic_year for section formatting
        FROM users u 
        JOIN user_profiles up ON u.sid = up.sid 
        WHERE u.role = 'student'
    ";
    
    $params = [];
    $report_title_section = "Section " . htmlspecialchars($section);

    if ($section !== 'all') {
        $sql .= " AND up.section = :section";
        $params[':section'] = $section;
    } else {
        $report_title_section = "All Sections";
    }
    
    $sql .= " ORDER BY up.program, up.section, up.last_name, up.first_name";
    
    $stmt_students = $conn->prepare($sql);
    $stmt_students->execute($params);
    $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

    if (empty($students)) {
        die("No students found for the selected criteria.");
    }

    // --- 4. LOGIC BRANCH: CSV or HTML? ---

    if ($report_type == 'student_names_section') {
        // --- GENERATE A CSV FILE ---
        
        $filename = "student_list_" . ($section === 'all' ? 'all' : $section) . "_" . date('Y-m-d') . ".csv";

        // Set headers to force download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // Write the CSV header
        fputcsv($output, ['Student ID', 'Last Name', 'First Name', 'Middle Name', 'Program', 'Section']);

        // Write student data
        foreach ($students as $student) {
            
            // --- START OF FIX: Format Student ID for Excel ---
            $student_id_formatted = '="' . $student['student_id'] . '"';
            // --- END OF FIX ---

            fputcsv($output, [
                $student_id_formatted, // Use the formatted ID
                $student['last_name'],
                $student['first_name'],
                $student['middle_name'],
                $student['program'],
                $student['section']
            ]);
        }

        fclose($output);
        exit; // Stop execution after sending the file

    } else {
        // --- GENERATE HTML COR REPORT ---

        // 5. SET DYNAMIC REPORT VARIABLES FOR COR
        $term_name = "UNKNOWN";
        $term_filter = null; // To be used in the SQL query

        switch ($report_type) {
            case 'midterm_grades_section':
                $term_name = "MIDTERM TERM";
                $term_filter = "Midterm";
                break;
            case 'final_grades_section':
                $term_name = "FINAL TERM";
                $term_filter = "Final";
                break;
            default:
                die("Invalid report type for COR.");
        }

        // Hardcoded for template consistency.
        $current_semester = "SECOND SEMESTER";
        $current_ay = "2024-2025";
        $page_subtitle = "$term_name, $current_semester AY $current_ay";

        // 6. PREPARE THE GRADES QUERY (to be used inside the loop)
        // FIX: This query now correctly uses `term` and `term_grade`
        // NOTE: The 'subject' table in the SQL dump has NO 'units' column.
        // I am hardcoding '3' units.
        // You must add a 'units' column to your 'subject' table to fix this.
        $stmt_grades = $conn->prepare("
            SELECT 
                s.subject_name, 
                s.subject_code, 
                g.term_grade
                -- '3' AS units -- REMOVE THIS LINE
                -- ADD s.units -- ONCE YOU ADD IT TO YOUR 'subject' TABLE
            FROM grades g 
            JOIN subject s ON g.subject_id = s.subject_id 
            WHERE g.student_id_string = :username
            AND g.term = :term
        ");

    } // End of HTML logic branch

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// If we are here, it means we are generating the HTML report.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scholastic Record - <?php echo $report_title_section; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Arial:wght@400;700&family=Times+New+Roman:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #e0e0e0;
            font-family: 'Times New Roman', Times, serif;
            color: #000;
        }

        /* --- Print Control --- */
        .no-print {
            position: fixed;
            top: 10px;
            left: 10px;
            background: #0f4a73;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-family: Arial, sans-serif;
            cursor: pointer;
            z-index: 10001;
        }

        /* --- Page Layout --- */
        .page {
            background: white;
            width: 8.5in;
            height: 11in;
            margin: 20px auto;
            padding: 0.5in;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
            box-sizing: border-box;
            position: relative; /* For footer */
        }
        
        .page-content {
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        /* --- NEW HEADER --- */
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid black;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }
        .header-logo {
            width: 100px; /* Adjust as needed */
        }
        .header-logo img {
            width: 100%;
        }
        .header-text {
            text-align: center;
            font-family: Arial, sans-serif;
            flex-grow: 1;
            padding: 0 15px;
        }
        .header-text p {
            margin: 0;
            font-size: 9px;
            font-weight: bold;
        }
        .header-text h1 {
            margin: 2px 0;
            font-size: 14px;
            font-weight: bold;
            color: #000;
        }
        
        .page-title {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin: 5px 0;
            font-family: Arial, sans-serif;
        }
        .page-subtitle {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 15px;
            font-family: Arial, sans-serif;
        }

        /* --- Student Info (New Layout) --- */
        .student-info {
            display: flex;
            justify-content: space-between;
            font-family: Arial, sans-serif;
            font-size: 11px;
            margin-bottom: 10px;
            padding: 0 10px;
        }
        .student-info-left, .student-info-right {
            flex-basis: 50%;
        }
        .info-row {
            margin-bottom: 3px;
        }
        .info-row span {
            display: inline-block;
            font-weight: normal;
            width: 80px; /* Label width */
        }
        .info-row b {
            font-weight: bold;
        }

        /* --- Grades Table --- */
        .grades-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            font-family: Arial, sans-serif;
            margin-bottom: 10px;
        }
        .grades-table th, .grades-table td {
            border: 1px solid black;
            padding: 5px;
            text-align: center;
            font-weight: bold;
        }
        .grades-table th {
            font-weight: bold;
        }
        .grades-table td.align-left {
            text-align: left;
            padding-left: 10px;
        }
        .grades-table .col-desc { width: 50%; }
        .grades-table .col-code { width: 15%; }
        .grades-table .col-grade { width: 10%; }
        .grades-table .col-units { width: 10%; }
        .grades-table .col-remarks { width: 15%; }
        
        .nothing-follows td {
            text-align: center;
            font-weight: bold;
            color: #000;
            border-bottom-width: 2px;
        }
        
        .total-units {
            text-align: right;
            font-family: Arial, sans-serif;
            font-size: 11px;
            font-weight: bold;
            padding-right: 5px;
            margin: 10px 0;
        }

        /* --- Footer --- */
        .footer-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            font-family: Arial, sans-serif;
            font-size: 9px;
            line-height: 1.3;
            padding-top: 10px;
            margin-top: auto; /* Pushes footer to bottom of flex container */
            border-top: 1px solid #ccc;
        }
        .footer-col {
            flex-basis: 25%;
        }
        .footer-col.legend {
            flex-basis: 20%;
        }
        .footer-col.signatures {
            flex-basis: 30%;
            text-align: center;
            line-height: 1.4;
        }
        .footer-col h4 {
            font-size: 10px;
            font-weight: bold;
            margin: 0 0 5px 0;
        }
        .legend-table, .grading-table {
            width: 100%;
            font-size: 9px;
        }
        .grading-table {
            border-collapse: collapse;
        }
        .grading-table td {
            border: 1px solid #000;
            padding: 1px 4px;
            text-align: center;
            font-weight: bold;
        }
        .legend-table td {
            font-weight: bold;
        }
        .signature-line {
            border-bottom: 1px solid black;
            margin: 25px auto 5px auto;
            width: 200px;
        }
        .signature-name {
            font-weight: bold;
            font-size: 10px;
        }
        .signature-title {
            font-size: 9px;
        }
        .to-students {
            border: 1px solid #000;
            padding: 5px;
        }

        /* --- Print Styles --- */
        @media print {
            body {
                background: none;
            }
            .no-print {
                display: none;
            }
            .page {
                margin: 0;
                box-shadow: none;
                page-break-after: always;
            }
            .page:last-child {
                page-break-after: auto;
            }
        }
    </style>
</head>
<body>

    <button class="no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print / Download PDF
    </button>

    <?php foreach ($students as $student): ?>
    <?php
        // --- START OF NEW SECTION LOGIC ---
        // 1. Program Prefix (e.g., "IS" from "BSIS")
        $program_prefix = 'UNKN';
        if (strpos($student['program'], 'BSIS') !== false) {
            $program_prefix = 'IS';
        } elseif (strpos($student['program'], 'BSCPE') !== false) {
            $program_prefix = 'CPE';
        } // Add more else/if for other programs
        
        // 2. Year Level (e.g., "1" from "1st Year")
        $year_num = substr($student['academic_year'], 0, 1);
        
        // 3. Semester (e.g., "2" from "SECOND SEMESTER")
        $sem_num = (strpos($current_semester, "FIRST") !== false) ? '1' : '2';
        
        // 4. Section Number (e.g., "01" from "1")
        $section_num = str_pad($student['section'], 2, '0', STR_PAD_LEFT);
        
        // 5. Combine
        $formatted_section = $program_prefix . $year_num . '-' . $sem_num . '-' . $section_num;
        // --- END OF NEW SECTION LOGIC ---
    ?>
    <div class="page">
        <div class="page-content">
            
            <div class="header-container">
                <div class="header-logo">
                    <img src="logo.png" alt="Logo 1">
                </div>
                <div class="header-text">
                    <p>REPUBLIC OF THE PHILIPPINES</p>
                    <p>CITY OF LAS PIÑAS</p>
                    <h1>DR. FILEMON C. AGUILAR MEMORIAL COLLEGE OF LAS PIÑAS</h1>
                    <p>IT CAMPUS: Dandelion St., Doña Manuela Subdivision, Pamplona Tres, Las Piñas City</p>
                    <p>ooc.satellite@dfcamclp.edu.ph / ooc.registrar@gmail.com 📞 8332-9913</p>
                </div>
                <div class="header-logo">
                    <img src="logo.png" alt="Logo 2">
                </div>
            </div>

            <h3 class="page-title">COLLEGIATE SCHOLASTIC RECORD</h3>
            <h4 class="page-subtitle"><?php echo htmlspecialchars($page_subtitle); ?></h4>

            <div class="student-info">
                <div class="student-info-left">
                    <div class="info-row">
                        <span>Student No.:</span>
                        <b><?php echo htmlspecialchars($student['student_id']); ?></b>
                    </div>
                    <div class="info-row">
                        <span>Name:</span>
                        <b><?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . $student['middle_name']); ?></b>
                    </div>
                </div>
                <div class="student-info-right">
                    <div class="info-row">
                        <span>Program:</span>
                        <b><?php echo htmlspecialchars($student['program']); ?></b>
                    </div>
                    <div class="info-row">
                        <span>Section:</span>
                        <b><?php echo htmlspecialchars($formatted_section); ?></b>
                    </div>
                </div>
            </div>

            <table class="grades-table">
                <thead>
                    <tr>
                        <th class="col-desc">Course Description</th>
                        <th class="col-code">Course Code</th>
                        <th class="col-grade">Grade</th>
                        <th class="col-units">Units</th>
                        <th class="col-remarks">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_units = 0;
                    $has_grades = false;
                    
                    // Use the student's username (which is their student ID string)
                    $stmt_grades->execute([
                        ':username' => $student['username'],
                        ':term' => $term_filter
                    ]);
                    $grades = $stmt_grades->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($grades)):
                        $has_grades = true;
                        foreach ($grades as $grade):
                            $grade_value = $grade['term_grade'];
                            $grade_to_display = "";
                            $remarks = "";

                            if ($grade_value === null) {
                                $grade_to_display = "N/G"; // No Grade
                                $remarks = "N/G";
                            } elseif ($grade_value == 5.0) {
                                $grade_to_display = "5.00";
                                $remarks = "FAILED";
                            } elseif ($grade_value >= 1.0 && $grade_value <= 3.0) {
                                $grade_to_display = number_format($grade_value, 2);
                                $remarks = "PASSED";
                            } else {
                                $grade_to_display = is_numeric($grade_value) ? number_format($grade_value, 2) : $grade_value;
                                $remarks = "---";
                            }
                            
                            // TEMPORARY: Using 3 units. Remove this when 'units' is in 'subject' table
                            $current_units = 3; 
                            // $current_units = (int)$grade['units']; // UNCOMMENT THIS
                            $total_units += $current_units;
                    ?>
                    <tr>
                        <td class="align-left"><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                        <td><?php echo htmlspecialchars($grade['subject_code']); ?></td>
                        <td><?php echo $grade_to_display; ?></td>
                        <td><?php echo $current_units; ?></td>
                        <td><?php echo $remarks; ?></td>
                    </tr>
                    <?php 
                        endforeach; 
                    endif; 
                    ?>
                    <tr class="nothing-follows">
                        <td colspan="5">
                            <?php echo !$has_grades ? '*** NO SUBJECTS/GRADES FOUND FOR THIS TERM ***' : '*** NOTHING FOLLOWS ***'; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="total-units">
                Total Units: <?php echo $total_units; ?>
            </div>

            <div class="footer-container">
                <div class="footer-col to-students">
                    <h4>TO THE STUDENTS:</h4>
                    <p>This report shows your academic progress. This is issued every end of the semester. May we request you to check it carefully and show this to your parents and / or guardian. Should you have any questions or concerns on your schooling, you are welcome to make an appointment with the College Registrar and / or the College Dean.</p>
                </div>
                <div class="footer-col legend">
                    <h4>LEGEND:</h4>
                    <table class="legend-table">
                        <tr><td>OD</td><td>- Officially Dropped</td></tr>
                        <tr><td>UW</td><td>- Unauthorized Withdrawal</td></tr>
                        <tr><td>INC</td><td>- Incomplete</td></tr>
                        <tr><td>FA</td><td>- Failure due to Absences</td></tr>
                    </table>
                </div>
                <div class="footer-col grading">
                    <h4>GRADING SYSTEM:</h4>
                    <table class="grading-table">
                        <tr><td>97-100</td><td>1.00</td><td>82-84</td><td>2.25</td></tr>
                        <tr><td>94-96</td><td>1.25</td><td>79-81</td><td>2.50</td></tr>
                        <tr><td>91-93</td><td>1.50</td><td>76-78</td><td>2.75</td></tr>
                        <tr><td>88-90</td><td>1.75</td><td>75</td><td>3.00</td></tr>
                        <tr><td>85-87</td><td>2.00</td><td>74-Below</td><td>5.00</td></tr>
                    </table>
                </div>
                <div class="footer-col signatures">
                    <div class="signature-line" style="margin-top: 10px;"></div>
                    <span class="signature-name">MA. ROMANNE MOLO</span><br>
                    <span class="signature-title">Registrar Officer</span>

                    <div class="signature-line"></div>
                    <span class="signature-name">ANNABEL T. VALLE</span><br>
                    <span class="signature-title">College Registrar</span>
                </div>
            </div>

        </div> </div> <?php endforeach; ?>

</body>
</html>