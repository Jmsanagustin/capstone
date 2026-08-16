<?php
/**
 * FILE: intervention_logic.php
 *
 * This file contains the core logic for predictive analytics.
 * It must be included in any script that saves or updates a student's score.
 */

/**
 * Calculates the transmuted grade from a raw percentage (0-100).
 *
 * @param float $rawPercentage The raw score percentage.
 * @return float The transmuted grade (50-100).
 */
function transmuteGrade($rawPercentage) {
    $raw = min(floatval($rawPercentage), 100);
    if (is_nan($raw) || $raw < 0) $raw = 0;
    return ($raw * 0.5) + 50;
}

/**
 * Checks a student's performance momentum and logs an intervention if they are declining.
 * This is the "Early Identification and Intervention" system.
 *
 * @param PDO $conn The active database connection.
 * @param int $student_sid The student's SID to analyze.
 * @param int $class_id The class_id to analyze.
 * @return void
 */
function checkAndLogIntervention($conn, $student_sid, $class_id) {
    try {
        // 1. Get all chronological scores for this student in this class
        $stmt_scores = $conn->prepare("
            SELECT r.score, r.max_score
            FROM raw_scores r
            JOIN enrollment e ON r.enrollment_id = e.enrollment_id
            JOIN grade_components c ON r.component_id = c.component_id
            WHERE e.student_sid = :sid AND e.class_id = :cid
            AND (r.score_status = 'Graded') -- Only check graded items
            AND r.max_score > 0
            ORDER BY r.date_recorded ASC, r.score_id ASC
        ");
        $stmt_scores->execute([':sid' => $student_sid, ':cid' => $class_id]);
        $scores = $stmt_scores->fetchAll(PDO::FETCH_ASSOC);

        // We need at least 3 data points to identify a trend.
        if (count($scores) < 3) {
            return; // Not enough data
        }

        // 2. Calculate simple average and EWMA (in raw percentage)
        $raw_percentages = [];
        foreach ($scores as $score) {
            $raw_percentages[] = ($score['score'] / $score['max_score']) * 100;
        }

        $simple_average = array_sum($raw_percentages) / count($raw_percentages);
        
        // Calculate EWMA (Exponentially Weighted Moving Average)
        $alpha = 0.3; // Smoothing factor, gives 30% weight to the newest score
        $ewma = $raw_percentages[0]; // Seed with the first score
        for ($i = 1; $i < count($raw_percentages); $i++) {
            $ewma = ($raw_percentages[$i] * $alpha) + ($ewma * (1 - $alpha));
        }

        // 3. Compare and check for intervention (Intervention Flag)
        // We flag if momentum (ewma) is more than 2 points below the overall average
        $is_declining = $ewma < ($simple_average - 2.0);

        if ($is_declining) {
            // 4. Log the intervention
            // Use INSERT IGNORE to prevent spamming duplicate 'Open' alerts
            $stmt_log = $conn->prepare("
                INSERT IGNORE INTO interventions 
                    (student_sid, class_id, type, task_description, status, commit_date)
                VALUES
                    (:sid, :cid, :type, :desc, 'Open', CURDATE())
            ");
            
            $description = sprintf(
                "System-generated alert: Student's performance momentum is declining. Overall Average (Raw): %.2f%%. Recent Momentum (Raw): %.2f%%. Early intervention is recommended.",
                $simple_average,
                $ewma
            );

            $stmt_log->execute([
                ':sid' => $student_sid,
                ':cid' => $class_id,
                ':type' => 'Academic Momentum (Declining)',
                ':desc' => $description
            ]);
        }

    } catch (Exception $e) {
        // Silently fail or log to a file. We don't want to break the
        // professor's grading workflow if the prediction logic fails.
        error_log("Intervention Check Failed for SID " . $student_sid . ": " . $e->getMessage());
    }
}
?>