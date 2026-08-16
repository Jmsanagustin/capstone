<?php
// cron_finalize_grades.php
//
// This script is NOT run by a user.
// You must set this up as a "Cron Job" on your web server to run once per day.
// 
// Example Cron Job command (runs every day at 1:00 AM):
// 0 1 * * * /usr/bin/php /path/to/your/project/cron_finalize_grades.php

// Include your database connection file
include 'db.php'; 

echo "Cron Job Started: " . date('Y-m-d H:i:s') . "\n";

try {
    // 1. Find and LOCK all expired MIDTERM windows
    // This finds classes where submission has started, is not locked,
    // and is 7 days or more in the past.
    $stmt_mid = $conn->prepare("
        UPDATE classes 
        SET midterm_finalized_at = NOW() 
        WHERE midterm_submission_start IS NOT NULL 
          AND midterm_finalized_at IS NULL 
          AND NOW() >= DATE_ADD(midterm_submission_start, INTERVAL 7 DAY)
    ");
    $stmt_mid->execute();
    $mid_locked_count = $stmt_mid->rowCount();
    
    echo "Locked $mid_locked_count Midterm class(es).\n";

    // 2. Find and LOCK all expired FINAL windows
    // This also sets your original 'is_finalized' flag for the whole class
    $stmt_fin = $conn->prepare("
        UPDATE classes 
        SET 
            final_finalized_at = NOW(), 
            is_finalized = 1,              -- Your original column
            finalization_date = NOW()      -- Your original column
        WHERE final_submission_start IS NOT NULL 
          AND final_finalized_at IS NULL 
          AND NOW() >= DATE_ADD(final_submission_start, INTERVAL 7 DAY)
    ");
    $stmt_fin->execute();
    $fin_locked_count = $stmt_fin->rowCount();

    echo "Locked $fin_locked_count Final class(es).\n";

    // 3. (Optional) Trigger reports/emails to Program Head here
    // You would fetch the class_ids that were just locked and
    // generate the reports/send notifications.

    echo "Cron Job Finished.\n";

} catch (PDOException $e) {
    // Log error to a file
    file_put_contents('cron_error_log.txt', date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n", FILE_APPEND);
    echo "Error: " . $e->getMessage() . "\n";
}
?>