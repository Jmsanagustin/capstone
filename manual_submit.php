<?php
// FILE: manual_submit.php
// PURPOSE: Handles the PROFESSOR'S manual submission of grades.
// This script runs the *same* logic as the 7-day auto-lock cron job,
// but does so immediately upon the professor's request.

session_start();
include 'db.php'; 

// --- Load PHPMailer (Paths must be correct) ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
// --- End PHPMailer ---

// --- CONFIGURATION (Copied from cron job) ---
date_default_timezone_set('Asia/Manila');
define('ACADEMIC_TERM', 'AY 2025-2026 1st Semester');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'johnmichaelsanagustin2@gmail.com');
define('SMTP_PASS', 'qgfqehpswgbmuwwn'); 
define('SMTP_PORT', 465);
define('SMTP_FROM_EMAIL', 'noreply@dfcamclp.edu.ph');
define('SMTP_FROM_NAME', 'Smart Grade System (Manual Submission)');
$PASSING_GRADE = 75.00;
// --- END CONFIGURATION ---


// --- HELPER FUNCTIONS (Copied from cron job) ---
function transmuteGrade($rawPercentage) {
    $raw = min(floatval($rawPercentage), 100);
    if (is_nan($raw) || $raw < 0) $raw = 0;
    return ($raw * 0.5) + 50;
}
function getGWAEquivalent($numericalGrade) {
    if ($numericalGrade >= 97) return 1.00;
    if ($numericalGrade >= 94) return 1.25;
    if ($numericalGrade >= 91) return 1.50;
    if ($numericalGrade >= 88) return 1.75;
    if ($numericalGrade >= 85) return 2.00;
    if ($numericalGrade >= 82) return 2.25;
    if ($numericalGrade >= 79) return 2.50;
    if ($numericalGrade >= 76) return 2.75;
    if ($numericalGrade >= 74.99) return 3.00; 
    return 5.00;
}
function sendReportEmail($to_email, $subject, $html_body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to_email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body . "<p style='margin-top:20px; font-size:12px; color:#888;'>This report was submitted manually by the professor for your review.</p>";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error (Manual Submit): {$mail->ErrorInfo}");
        return false;
    }
}
function autoSubmitGradesAndGenerateReport($conn, $class_id, $subject_id, $subject_code, $subject_name, $submission_term, $professor_sid) {
    // This function is identical to the one in run_nightly_tasks.php
    // It calculates grades from raw_scores, inserts into 'grades', and returns an HTML report.
    
    $PASSING_GRADE_LOCAL = 75.00;
    $html_report = "
        <h2 style='color:#0f4a73;'>[MANUAL SUBMISSION] $subject_code: $subject_name ($submission_term)</h2>
        <p style='font-weight:bold;'>The professor has finalized and submitted these grades for your approval.</p>
    ";
    
    $comp_stmt = $conn->prepare("SELECT component_id, component_name, weight FROM grade_components WHERE class_id = ? AND term = ?");
    $comp_stmt->execute([$class_id, $submission_term]);
    $components = $comp_stmt->fetchAll(PDO::FETCH_ASSOC);

    $stud_stmt = $conn->prepare("SELECT e.enrollment_id, u.sid, u.username, up.first_name, up.last_name FROM enrollment e JOIN users u ON e.student_sid = u.sid JOIN user_profiles up ON u.sid = up.sid WHERE e.class_id = ?");
    $stud_stmt->execute([$class_id]);
    $students = $stud_stmt->fetchAll(PDO::FETCH_ASSOC);

    $archive_stmt = $conn->prepare("INSERT INTO grade_archives (student_sid, class_id, subject_code, subject_name, final_grade, gwa, academic_term) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insert_grade_stmt = $conn->prepare("
        INSERT INTO grades 
            (student_id_string, subject_id, term, midterm_grade, final_grade, status, teacher_id, remarks, submission_date)
        VALUES 
            (:username, :subject_id, :term, :midterm_grade, :final_grade, 'Pending PH Approval', :teacher_id, :remarks, NOW())
        ON DUPLICATE KEY UPDATE
            status = 'Pending PH Approval', remarks = VALUES(remarks), 
            midterm_grade = VALUES(midterm_grade), final_grade = VALUES(final_grade), 
            submission_date = NOW(), teacher_id = VALUES(teacher_id)
    ");

    $html_report .= "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width:100%; font-size:12px;'>";
    $html_report .= "<thead style='background-color: #0f4a73; color:white;'><tr><th>Student</th><th>Term Grade</th>";
    foreach ($components as $comp) {
        $html_report .= "<th>" . htmlspecialchars($comp['component_name']) . " (" . ($comp['weight'] * 100) . "%)</th>";
    }
    $html_report .= "<th>Final Status</th></tr></thead><tbody>";

    foreach ($students as $student) {
        $grade_summary = [];
        $term_grade_sum = 0;
        $total_weight_decimal = 0;

        foreach ($components as $component) {
            $comp_weight = $component['weight'];
            $total_weight_decimal += $comp_weight;

            $item_stmt = $conn->prepare("SELECT score, max_score FROM raw_scores WHERE enrollment_id = ? AND component_id = ?");
            $item_stmt->execute([$student['enrollment_id'], $component['component_id']]);
            $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

            $transmuted_grades = [];
            $total_items = count($items);
            if ($total_items > 0) {
                foreach ($items as $item) {
                    $raw_percent = ($item['max_score'] > 0) ? ($item['score'] / $item['max_score']) * 100 : 0;
                    $transmuted_grades[] = transmuteGrade($raw_percent);
                }
                $component_avg = array_sum($transmuted_grades) / $total_items;
            } else {
                $component_avg = 50; 
            }

            $component_weighted_grade = $component_avg * $comp_weight;
            $term_grade_sum += $component_weighted_grade;
            $grade_summary[] = number_format($component_avg, 2) . '%';
        }

        $term_grade = ($total_weight_decimal > 0) ? ($term_grade_sum / $total_weight_decimal) : 50.00;
        $remarks = ($term_grade >= $PASSING_GRADE_LOCAL) ? 'PASSED' : 'FAILED';
        $style = ($remarks === 'FAILED') ? 'color: #cc3333; font-weight:bold;' : 'color: #28a745; font-weight:bold;';

        $midterm_grade_to_save = ($submission_term === 'Midterm') ? $term_grade : null;
        $final_grade_to_save = ($submission_term === 'Final') ? $term_grade : null;
        
        $insert_grade_stmt->execute([
            ':username' => $student['username'], 
            ':subject_id' => $subject_id, 
            ':term' => $submission_term, 
            ':midterm_grade' => $midterm_grade_to_save, 
            ':final_grade' => $final_grade_to_save, 
            ':teacher_id' => $professor_sid, 
            ':remarks' => $remarks
        ]);

        $html_report .= "<tr>
            <td>{$student['last_name']}, {$student['first_name']} ({$student['username']})</td>
            <td><span style='{$style}'>" . number_format($term_grade, 2) . "%</span></td>";
        foreach ($grade_summary as $summary) { $html_report .= "<td>$summary</td>"; }
        $html_report .= "<td><span style='{$style}'>$remarks</span></td></tr>";

        if ($submission_term === 'Final') {
             $final_gwa = getGWAEquivalent($term_grade);
             $archive_stmt->execute([
                $student['sid'], $class_id, $subject_code, $subject_name,
                $term_grade, $final_gwa, ACADEMIC_TERM
            ]);
        }
    }
    $html_report .= "</tbody></table>";
    return $html_report;
}
// --- END HELPER FUNCTIONS ---


// =========================================================================
// 1. SCRIPT EXECUTION
// =========================================================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'professor') {
    $_SESSION['dashboard_message'] = ['type' => 'error', 'body' => 'Authentication failed.'];
    header("Location: teacher_dashboard.php");
    exit();
}

$professor_sid = $_SESSION['sid'];
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$term = filter_input(INPUT_GET, 'term', FILTER_SANITIZE_STRING);

if (!$class_id || !$term || !in_array($term, ['Midterm', 'Final'])) {
    $_SESSION['dashboard_message'] = ['type' => 'error', 'body' => 'Invalid or missing class/term for submission.'];
    header("Location: teacher_dashboard.php");
    exit();
}

// Determine which fields to lock
$finalized_field = ($term === 'Midterm') ? 'midterm_finalized_at' : 'final_finalized_at';
$timer_start_field = ($term === 'Midterm') ? 'midterm_submission_start' : 'final_submission_start';

try {
    // 1. Get all class/PH info
    $stmt_info = $conn->prepare("
        SELECT 
            c.subject_id, s.subject_code, s.subject_name, phu.email AS program_head_email
        FROM classes c
        JOIN subject s ON c.subject_id = s.subject_id
        JOIN users phu ON s.program_head_id = phu.sid
        WHERE c.class_id = :class_id AND c.professor_sid = :professor_sid
    ");
    $stmt_info->execute([':class_id' => $class_id, ':professor_sid' => $professor_sid]);
    $class_info = $stmt_info->fetch(PDO::FETCH_ASSOC);

    if (!$class_info || empty($class_info['program_head_email'])) {
        throw new Exception("Class not found, or Program Head email is missing.");
    }

    $conn->beginTransaction();

    // 2. Run the calculation, grade insertion, and report generation
    $report_html = autoSubmitGradesAndGenerateReport(
        $conn, $class_id, $class_info['subject_id'], $class_info['subject_code'],
        $class_info['subject_name'], $term, $professor_sid
    );

    // 3. Email the Program Head
    $subject = "[MANUAL SUBMISSION] Grades for Review: {$class_info['subject_code']} ($term)";
    $email_success = sendReportEmail($class_info['program_head_email'], $subject, $report_html);
    
    if (!$email_success) {
        throw new Exception("Failed to send email to Program Head.");
    }
   
    // 4. Immediately lock the class and start the timer (if not started)
    $stmt_lock = $conn->prepare("
        UPDATE classes 
        SET 
            {$finalized_field} = NOW(),
            {$timer_start_field} = COALESCE({$timer_start_field}, NOW())
        WHERE class_id = ?
    ");
    $stmt_lock->execute([$class_id]);

    // 5. If all steps succeeded, commit
    $conn->commit();
    $_SESSION['dashboard_message'] = ['type' => 'success', 'body' => "Success! Grades for {$class_info['subject_code']} ($term) have been calculated, locked, and submitted to the Program Head."];

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $_SESSION['dashboard_message'] = ['type' => 'error', 'body' => "Submission Failed: " . $e->getMessage()];
}

// Redirect back to the dashboard
header("Location: teacher_dashboard.php");
exit();
?>