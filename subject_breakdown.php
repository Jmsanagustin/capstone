<?php
// FILE: subject_breakdown.php
// FINAL VERSION: Implements 40/60 Midterm/Final weighted average for projection logic,
// displays item-specific *TRANSMUTED GRADE* goals for the Final term,
// and removes discouraging Lowest/Projected Grade metrics from the summary.

// --- (MODIFIED) ---
// Adds a "Warning" status for Midterm grades between 75-80.
// INTEGRATED: Laboratory/Lecture component splitting within the existing Term tabs.
// ---

// --- (NEW REVISION) ---
// 1. (FIX) Implemented "Smarter Status" logic. Status will show "In-Progress" and not "Failing"
//    if less than 50% of the Midterm is graded and Finals have not started.
// 2. Made Lecture/Lab component sections collapsible (<details> tag).
// 3. Moved GWA info icon into the projections card (on-click, opens down).
// 4. Added "Passing Threshold" line and enhanced tooltips on the performance graph.
// 5. Removed unnecessary asterisks (*) from UI text.
// 6. Rotated X-axis labels on the graph to prevent overlapping.
// 7. Re-instated graph title and score trend text in a new, more suitable header layout above the chart.
// 8. GWA logic cutoff corrected to 75 (for 3.0) and 74.99 (for 5.0), matching the table.
// 9. GWA table "Passing" status changed to "Minimum Passing" (in yellow) for clarity.
// ---

// --- (ADVANCED PREDICTIVE ANALYTICS REVISION V3) ---
// 1. OBJECTIVE: "Integrate predictive analytics for early identification and intervention."
// 2. MODEL: Implemented Exponentially Weighted Moving Average (EWMA) for trend analysis.
//    This model gives more weight to recent scores, making it highly responsive.
// 3. INTERVENTION FLAG: The system now calculates "Momentum" (EWMA) vs. "Overall Average".
//    If Momentum < Overall, this indicates a "declining" trend, triggering an early warning.
// 4. PREDICTION: The "Predicted Final Grade" is now based on this forward-looking EWMA,
//    not the static historical average.
// 5. DATA INTEGRITY:
//    - PHP: Added 'date_recorded' to the JSON data.
//    - PHP: Added 'ORDER BY date_recorded ASC' to the scores query.
//    - JS: Re-architected score processing to be fully chronological.
// ---

// Add these lines temporarily to see the real error message
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
// Assuming db.php handles the database connection ($conn)
include 'db.php'; 

// Define string matching constants for the hack logic
const MIDTERM_COMPONENT_NAMES = ['Quizzes', 'Class Participation', 'Performance Task', 'Major Exam'];

// --- STUDENT AUTHENTICATION ---
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php"); 
    exit();
}
$current_student_sid = $_SESSION['sid'];
// --- END: STUDENT AUTHENTICATION ---

// --- Get Subject Code from URL ---
$subject_code = $_GET['subject_code'] ?? null;
if (!$subject_code) {
    die("Error: No subject code provided. Please go back to the dashboard and select a subject.");
}

$raw_data_php = null; // This will hold the data for JS
$db_error = "";
$student_name = $_SESSION['username'] ?? 'Student';
$subject_title = 'Subject';
$subject_instructor = 'Professor'; // Default

try {
    // 1. Get student name (for header)
    $stmt_student = $conn->prepare("SELECT first_name, last_name, middle_name FROM user_profiles WHERE sid = :sid");
    $stmt_student->execute([':sid' => $current_student_sid]);
    $profile = $stmt_student->fetch(PDO::FETCH_ASSOC);
    if ($profile) {
        $middle_initial = !empty($profile['middle_name']) ? ' ' . mb_substr($profile['middle_name'], 0, 1) . '.' : '';
        $student_name = htmlspecialchars($profile['first_name'] . $middle_initial . ' ' . $profile['last_name']);
    }

    // 2. Find the student's enrollment and class details for this subject code
    $stmt_class = $conn->prepare("
        SELECT 
            e.enrollment_id, 
            c.class_id,
            s.subject_id,
            s.subject_name,
            up.first_name AS prof_first,
            up.middle_name AS prof_middle,
            up.last_name AS prof_last
        FROM enrollment e
        JOIN classes c ON e.class_id = c.class_id
        JOIN subject s ON c.subject_id = s.subject_id
        JOIN users u ON c.professor_sid = u.sid
        LEFT JOIN user_profiles up ON u.sid = up.sid
        WHERE e.student_sid = :sid AND s.subject_code = :subject_code
        LIMIT 1
    ");
    $stmt_class->execute([
        ':sid' => $current_student_sid,
        ':subject_code' => $subject_code
    ]);
    $class_info = $stmt_class->fetch(PDO::FETCH_ASSOC);

    if (!$class_info) {
        throw new Exception("You are not enrolled in this subject ($subject_code) or class data is missing.");
    }

    // --- Set header data (Professor name is now assembled) ---
    $enrollment_id = $class_info['enrollment_id'];
    $class_id = $class_info['class_id'];
    $subject_id = $class_info['subject_id'];
    $subject_title = htmlspecialchars($class_info['subject_name']);
    
    // Build professor's full name
    $prof_first = htmlspecialchars($class_info['prof_first'] ?? 'Prof.');
    $prof_middle = htmlspecialchars($class_info['prof_middle'] ?? '');
    $prof_last = htmlspecialchars($class_info['prof_last'] ?? '');
    $prof_middle_initial = !empty($prof_middle) ? ' ' . mb_substr($prof_middle, 0, 1) . '.' : '';
    $subject_instructor = trim($prof_first . $prof_middle_initial . ' ' . $prof_last);
    // --- END: Professor name assembly ---


    // 3. Fetch all grade components for this class
    $stmt_components = $conn->prepare("
        SELECT component_id, term, component_name, weight, component_type
        FROM grade_components 
        WHERE class_id = :class_id
        ORDER BY term DESC, component_type, component_id 
    ");
    $stmt_components->execute([':class_id' => $class_id]);
    $components = $stmt_components->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch all raw scores for this student's enrollment
    // --- (MODIFIED V3) Added 'ORDER BY date_recorded' for chronological analysis ---
    $stmt_scores = $conn->prepare("
        SELECT component_id, item_name, score, max_score, date_recorded 
        FROM raw_scores 
        WHERE enrollment_id = :enrollment_id
        ORDER BY date_recorded ASC, score_id ASC
    ");
    $stmt_scores->execute([':enrollment_id' => $enrollment_id]);
    $scores = $stmt_scores->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Build the $raw_data_php object in the structure the JS expects
    $gradeComponents = [];
    $hasFinalComponents = false;
    foreach ($components as $comp) {
        if ($comp['term'] === 'Final') {
            $hasFinalComponents = true;
        }

        $comp_id = $comp['component_id'];
        // **FIX**: Safely define comp_type with a fallback
        $comp_type = $comp['component_type'] ?? 'General';
        $comp_name_key = $comp['term'] . '_' . $comp_type . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $comp['component_name']); 
        
        $gradeComponents[$comp_name_key] = [
            'term' => $comp['term'],
            'type' => $comp_type, // <-- ADDED: Component Type
            'name' => $comp['component_name'],
            'weight' => ($comp['weight'] * 100) . '%',
            'decimal_weight' => (float)$comp['weight'],
            'scores' => []
        ];
        
        foreach ($scores as $score) {
            if ($score['component_id'] == $comp_id) {
                // --- (MODIFIED) ---
                // Restore original logic: A 0 is "Missing"
                $is_missing = ((float)$score['score'] == 0 && (float)$score['max_score'] > 0);
                // --- (END MODIFIED) ---
                
                $gradeComponents[$comp_name_key]['scores'][] = [
                    'name' => htmlspecialchars($score['item_name']),
                    'score' => (float)$score['score'],
                    'max' => (float)$score['max_score'],
                    'date_recorded' => $score['date_recorded'], // <-- (MODIFIED V3) Pass date to JS
                    'status' => $is_missing ? 'Missing' : 'Graded',
                    // --- (MODIFIED) Enhanced feedback text ---
                    'feedback' => $is_missing ? 'Not submitted or zero score recorded.' : 'Recorded on ' . $score['date_recorded']
                ];
            }
        }
        
        // --- (MODIFIED) ---
        // Removed the `stripos($comp['component_name'], 'Major Exam') === false` condition.
        // Now, *any* component with no scores (including Major Exam) will get an "Upcoming" placeholder.
        $has_graded_scores = !empty($gradeComponents[$comp_name_key]['scores']);
        
        if (!$has_graded_scores) {
             // Add a single "Upcoming" placeholder score item
             $gradeComponents[$comp_name_key]['scores'][] = [
                 'name' => $comp['term'] . ' ' . $comp['component_name'],
                 'score' => 0,
                 'max' => 100,
                 'date_recorded' => null, // <-- (MODIFIED V3)
                 'status' => 'Upcoming',
                 'feedback' => 'This item is not yet graded.'
             ];
        }
        // --- (END MODIFIED) ---

    }

    // 6. Assemble the final PHP object
    $raw_data_php = [
        'subjectTitle' => $subject_title,
        'subjectCode' => $subject_code,
        'instructor' => $subject_instructor,
        'finalExamMaxScore' => 100,
        'passingGWA' => 3.00,
        'hasFinalComponents' => $hasFinalComponents,
        'gradeComponents' => $gradeComponents
    ];

} catch (PDOException $e) {
    // If the 500 error was due to the missing 'component_type' column, this will catch it and display a proper error.
    $db_error = "Database Error: " . $e.getMessage() . ". If 'component_type' is missing, please update your 'grade_components' table schema.";
} catch (Exception $e) {
    $db_error = "Error: " . $e.getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Performance Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
    <style>
        :root {
            --primary-color: #0056b3;
            --red-alert: #cc0000;
            --yellow-warning: #ffa500; /* <-- NEW */
            --green-status: #10b981;
        }
        .status-failing { color: var(--red-alert); font-weight: 600; }
        .status-passing { color: var(--green-status); font-weight: 600; }
        .status-warning { color: var(--yellow-warning); font-weight: 600; } /* <-- NEW */

        .goal-impossible { color: var(--red-alert); font-weight: 600; }
        .goal-achievable { color: var(--green-status); font-weight: 600; }
        
        /* (NEW V3) Momentum/Intervention Colors */
        .momentum-improving { color: var(--green-status); }
        .momentum-declining { color: var(--red-alert); }
        .momentum-stable { color: var(--primary-color); }

        .message.error {
            padding: 15px 20px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; border: 1px solid;
            background: #fde8e8; color: var(--red-alert); border-color: var(--red-alert); 
        }
        
        /* --- UPDATED Grade Color Logic (per user request) --- */
        .grade-high { color: var(--green-status); font-weight: 700; } /* 80%+ Transmuted */
        .grade-failing { color: var(--red-alert); font-weight: 700; } /* 79 and below Transmuted */
        .grade-pending { color: var(--text-light); font-weight: 500; }

        /* --- (NEW) AT-RISK/WARNING COLOR --- */
        .text-warning { color: var(--yellow-warning); }
        .text-success { color: var(--green-status); }
        .text-alert { color: var(--red-alert); }
        /* --- END NEW --- */


        /* NEW: Styles for the term tabs */
        .tab-button {
            padding: 10px 15px;
            font-weight: bold;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        /* --- (MODIFIED) Styles for GWA Info Popover (Click logic) --- */
        .gwa-info-container {
            position: absolute; 
            top: 20px; 
            right: 20px; 
            z-index: 100;
        }
        .gwa-info-icon {
            width: 40px;
            height: 40px;
            background-color: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            font-family: 'Times New Roman', Times, serif; /* Makes it a classic 'i' */
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            transition: all 0.2s;
        }
        .gwa-info-icon:hover {
            background-color: #004494; /* Darker shade */
            transform: scale(1.1);
        }
        .gwa-table-content {
            display: none; /* Hide by default */
            position: absolute;
            top: 100%; /* (FIX) Position below the icon */
            margin-top: 10px; /* (FIX) Space from icon */
            right: 0;
            width: 350px; 
            background: white;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
            border: 1px solid #ddd;
            overflow: hidden; 
        }
        /* (NEW) This class is toggled by JS to show the pop-up */
        .gwa-info-container.active .gwa-table-content {
            display: block; 
        }
        /* --- END MODIFIED --- */

        /* --- NEW: Styles for Collapsible Sections --- */
        details > summary {
            list-style: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        details > summary::-webkit-details-marker {
            display: none;
        }
        details > summary::before {
            content: '►';
            font-size: 0.8em;
            margin-right: 10px;
            transition: transform 0.2s;
        }
        details[open] > summary::before {
            transform: rotate(90deg);
        }
        details[open] > summary {
            background-color: #e0e6ed; /* Slightly darker when open */
        }
        /* --- END NEW --- */
    </style>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    'primary': '#0056b3',
                    'alert': '#cc0000',
                    'warning': '#ffa500', // <-- NEW
                    'success': '#10b981',
                },
            }
        }
    }

    // --- Core Grade Logic ---

    // --- (FIXED) GWA Logic to match table (75 = 3.00, 74.99 = 5.00) ---
    const getGWAEquivalent = (numericalGrade) => {
        if (numericalGrade >= 97) return 1.00;
        if (numericalGrade >= 94) return 1.25;
        if (numericalGrade >= 91) return 1.50;
        if (numericalGrade >= 88) return 1.75;
        if (numericalGrade >= 85) return 2.00;
        if (numericalGrade >= 82) return 2.25;
        if (numericalGrade >= 79) return 2.50;
        if (numericalGrade >= 76) return 2.75;
        if (numericalGrade >= 75) return 3.00; // Cutoff is exactly 75 
        return 5.00; // Anything below 75 is 5.00
    };
    // --- (END FIX) ---

    /**
     * (NEW) Helper function to determine CSS class based on grade
     * 80+      = text-success (Green)
     * 75-79.99 = text-warning (Yellow)
     * < 75     = text-alert (Red)
     */
    const getGradeClass = (grade) => {
        if (grade >= 80) return 'text-success';
        if (grade >= 75) return 'text-warning';
        return 'text-alert';
    };


    const transmuteGrade = (rawPercentage) => {
        let raw = Math.min(parseFloat(rawPercentage), 100);
        if (isNaN(raw) || raw < 0) raw = 0;
        const transmuted = (raw * 0.5) + 50;
        return transmuted;
    };
    
    // --- Data Processing Functions ---

    /**
    * Processes raw data to calculate projections and contributions using the 40/60 rule.
    * (MODIFIED to include ADVANCED PREDICTIVE ANALYTICS - EWMA)
    */
    const processRawData = (rawData) => {
        
        // --- (NEW V3) Chronological Score Processing ---
        // 1. Flatten all graded scores and sort them chronologically
        const allGradedScores = [];
        for (const key in rawData.gradeComponents) {
            const component = rawData.gradeComponents[key];
            component.scores.forEach(item => {
                // Only include items that are graded (or missing, which counts as a grade)
                if ((item.status === 'Graded' || item.status === 'Missing') && item.date_recorded) {
                    const rawPercent = (item.max > 0 ? (item.score / item.max) : 0) * 100;
                    const descriptiveName = `${component.type} ${component.name} - ${item.name}`;
                    allGradedScores.push({
                        name: descriptiveName,
                        percentage: rawPercent,
                        date: new Date(item.date_recorded)
                    });
                }
            });
        }
        
        // Sort the flat array by date. This is crucial.
        allGradedScores.sort((a, b) => a.date - b.date);

        // 2. Build progression data from the sorted array
        const progressionLabels = allGradedScores.map(item => item.name);
        const progressionScores = allGradedScores.map(item => item.percentage);
        // --- (END NEW V3) ---


        // --- (NEW V3) ADVANCED PREDICTIVE ANALYTICS ---
        let averageRawPerformance = 50.0; // Historical Simple Average
        let ewmaRawPerformance = 50.0;    // Predictive EWMA
        let momentum = 'stable';
        
        if (progressionScores.length > 0) {
            // 1. Simple Average (Historical Benchmark)
            averageRawPerformance = progressionScores.reduce((a, b) => a + b, 0) / progressionScores.length;

            // 2. EWMA (Predictive "Momentum" Score)
            // Alpha (smoothing factor) = 0.3. Gives 30% weight to the newest score.
            const alpha = 0.3; 
            let ewma = progressionScores[0]; // Seed with the first score
            for (let i = 1; i < progressionScores.length; i++) {
                ewma = (progressionScores[i] * alpha) + (ewma * (1 - alpha));
            }
            ewmaRawPerformance = ewma;

            // 3. Momentum Check (for intervention)
            // Compare momentum (EWMA) to the simple average
            // A 2-point difference flags a trend.
            if (ewmaRawPerformance > averageRawPerformance + 2) {
                momentum = 'improving';
            } else if (ewmaRawPerformance < averageRawPerformance - 2) {
                momentum = 'declining'; // <-- INTERVENTION FLAG
            }
        }
        
        // This is the *predicted* transmuted grade based on recent momentum
        const predictedTransmutedPerformance = transmuteGrade(ewmaRawPerformance);
        // --- END: ADVANCED PREDICTIVE ANALYTICS ---


        let midtermGrade = 0;
        let midtermWeightSum = 0; 
        let midtermGradedWeight = 0;
        let finalWeightSum = 0; 

        let finalTermLowestPossibleGradeSum = 0; 
        let finalTermHighestPossibleGradeSum = 0;
        let finalTermGradedWeight = 0;
        let finalTermUpcomingWeight = 0;

        // 1. Calculate Midterm Grade (Fixed 40% component) and initialize Final Term Weights
        for (const key in rawData.gradeComponents) {
            const component = rawData.gradeComponents[key];
            const componentWeight = component.decimal_weight;
            
            let individualTransmutedGrades = [];
            let hasGradedItem = false;
            let upcomingItemsCount = 0; 
            
            component.scores.forEach(item => {
                if (item.status === 'Graded' || item.status === 'Missing') {
                    const rawPercent = (item.max > 0 ? (item.score / item.max) : 0) * 100;
                    const transmuted = transmuteGrade(rawPercent);
                    individualTransmutedGrades.push(transmuted);
                    hasGradedItem = true;
                    // Note: Graph data is already built chronologically above (V3)
                } else if (item.status === 'Upcoming') {
                    upcomingItemsCount++;
                }
            });

            let currentComponentTransmutedAvg = 0;
            if (hasGradedItem) {
                currentComponentTransmutedAvg = individualTransmutedGrades.reduce((a, b) => a + b, 0) / individualTransmutedGrades.length;
            }
            
            // Store for table display
            component.display_transmuted_grade = hasGradedItem ? currentComponentTransmutedAvg.toFixed(2) : "N/A";

            // Determine the weight distribution within the component for the TERM
            const totalItems = individualTransmutedGrades.length + upcomingItemsCount;
            let gradedPartWeightTerm = (totalItems > 0) ? (individualTransmutedGrades.length / totalItems) * componentWeight : (hasGradedItem ? componentWeight : 0);
            let upcomingPartWeightTerm = (totalItems > 0) ? (upcomingItemsCount / totalItems) * componentWeight : (hasGradedItem ? 0 : componentWeight);
            
            // Store contribution (Weighted contribution to the TERM Grade)
            // This is the Lowest Possible Term Grade contribution from this component
            component.current_contribution_term = (currentComponentTransmutedAvg * gradedPartWeightTerm) + (50 * upcomingPartWeightTerm);

            if (component.term === 'Midterm') {
                midtermGrade += (currentComponentTransmutedAvg * gradedPartWeightTerm);
                midtermGrade += (50 * upcomingPartWeightTerm); // Assume 50 (0% raw) for lowest possible in Midterm
                midtermWeightSum += componentWeight;
                midtermGradedWeight += gradedPartWeightTerm; // Track graded weight for status checks
                component.midterm_term_weight = componentWeight; // Store total term weight
            } else if (component.term === 'Final') {
                finalWeightSum += componentWeight;
                finalTermGradedWeight += gradedPartWeightTerm;
                finalTermUpcomingWeight += upcomingPartWeightTerm;
                
                // Calculate Final TERM Projections
                // Lowest Possible Final Term Contribution from this component
                finalTermLowestPossibleGradeSum += (currentComponentTransmutedAvg * gradedPartWeightTerm);
                finalTermLowestPossibleGradeSum += (50 * upcomingPartWeightTerm);
                
                // Highest Possible Final Term Contribution from this component
                finalTermHighestPossibleGradeSum += (currentComponentTransmutedAvg * gradedPartWeightTerm);
                finalTermHighestPossibleGradeSum += (100 * upcomingPartWeightTerm);
                
                component.final_term_weight = componentWeight; // Store total term weight
            }
            
            // Contribution to overall FINAL COURSE GRADE (For display - using Lowest Possible Term Grade)
            component.final_course_contribution = component.term === 'Midterm' 
                ? component.current_contribution_term * 0.40 
                : component.current_contribution_term * 0.60;
        } // End component loop

        // Finalize Midterm Term Grade (The base 40% component)
        const actualMidtermTermGrade = midtermWeightSum > 0 ? midtermGrade / midtermWeightSum : 0;
        
        // --- (MODIFIED V3) Use EWMA for Prediction ---
        // Get the total weighted *graded* part of the Final term
        const finalTermGradedPartSum = finalTermLowestPossibleGradeSum - (finalTermUpcomingWeight * 50);

        // The new predicted sum = (graded part) + (predicted EWMA score * upcoming weight)
        const finalTermPredictedGradeSum = finalTermGradedPartSum + (predictedTransmutedPerformance * finalTermUpcomingWeight);
        
        // Normalize it
        const finalTermGradePredicted = finalWeightSum > 0 ? finalTermPredictedGradeSum / finalWeightSum : 0;
        // --- END: MODIFIED V3 ---

        // Finalize Final Term Projections
        const finalTermGradeLowest = finalWeightSum > 0 ? finalTermLowestPossibleGradeSum / finalWeightSum : 0;
        const finalTermGradeHighest = finalWeightSum > 0 ? finalTermHighestPossibleGradeSum / finalWeightSum : 0;
        
        // --- (MODIFIED) More nuanced checks ---
        const midtermGradedPercent = midtermGradedWeight / (midtermWeightSum || 1);
        const isMidtermCompleted = midtermGradedPercent > 0.99; // Midterm grades are locked
        const hasGradedFinalItems = finalTermGradedWeight > 0.0001;
        const isFinalCompleted = (finalTermGradedWeight / (finalWeightSum || 1)) > 0.99; // Final term grades are locked


        // 2. Calculate Final Course Projections (Using 40/60 Split)

        // Midterm Contribution (Fixed 40% component)
        const fixedMidtermContribution = actualMidtermTermGrade * 0.40;
        
        // Final Term Contribution Projections (60% component)
        const finalTermLowestContribution = finalTermGradeLowest * 0.60;
        const finalTermHighestContribution = finalTermGradeHighest * 0.60;
        
        // --- (NEW) Predicted Final Term Contribution ---
        const finalTermPredictedContribution = finalTermGradePredicted * 0.60;

        // Final Course Grades
        const lowestPossibleFinalCourseGrade = fixedMidtermContribution + finalTermLowestContribution;
        const highestPossibleFinalCourseGrade = fixedMidtermContribution + finalTermHighestContribution;
        
        // --- (NEW) Predicted Final Course Grade ---
        const predictedFinalCourseGrade = fixedMidtermContribution + finalTermPredictedContribution;

        // --- (REVISED) SMARTER STATUS LOGIC ---
        let gradeForStatusCheck = 0; 
        let statusBaseLabel = "Pending";
        let isGWA_Pending = false; // <-- NEW FLAG

        if (isMidtermCompleted && isFinalCompleted) {
            // State 4: Course Complete
            gradeForStatusCheck = lowestPossibleFinalCourseGrade;
            statusBaseLabel = "Final Course Status";
        } else if (isMidtermCompleted && hasGradedFinalItems) {
            // State 3: Final In-Progress (Graded Items Exist)
            // --- (MODIFIED) Status check should be based on the *PREDICTED* grade, not the lowest.
            gradeForStatusCheck = predictedFinalCourseGrade;
            statusBaseLabel = "Projected Final Status";
        } else if (isMidtermCompleted && !hasGradedFinalItems) {
            // State 2: Midterm Complete, Final Not Started
            gradeForStatusCheck = actualMidtermTermGrade;
            statusBaseLabel = "Midterm Finalized";
        } else if (midtermGradedPercent < 0.50 && !hasGradedFinalItems) {
            // --- (NEW) State 1.A: Midterm is < 50% graded. ---
            // This is the fix for the user's scenario.
            statusBaseLabel = "Midterm (In-Progress)";
            isGWA_Pending = true; // Flag to show "Pending" instead of a GWA
        } else {
            // State 1.B: Midterm is > 50% graded, but not complete.
            gradeForStatusCheck = actualMidtermTermGrade;
            statusBaseLabel = "Midterm Status";
        }
        // --- END REVISED STATUS LOGIC ---
        
        // Final GWA is based on the determined status check grade
        const currentGWA = getGWAEquivalent(gradeForStatusCheck);
        
        // --- (MODIFIED V3) Score Trend now uses Momentum ---
        // (This is redundant, 'momentum' var is used directly now)
        // --- (END MODIFIED) ---

        return {
            ...rawData, 
            gradeComponents: rawData.gradeComponents,
            
            // New Midterm Term Grade
            midtermTermGrade: parseFloat(actualMidtermTermGrade.toFixed(2)),
            finalTermGradeLowest: parseFloat(finalTermGradeLowest.toFixed(2)), 
            hasGradedFinalItems: hasGradedFinalItems, 

            // Final Course Projections
            lowestPossibleGrade: parseFloat(lowestPossibleFinalCourseGrade.toFixed(2)),
            highestPossibleGrade: Math.min(highestPossibleFinalCourseGrade, 100),
            
            // --- (NEW V3) Advanced Predictive Analytics ---
            predictedFinalCourseGrade: parseFloat(predictedFinalCourseGrade.toFixed(2)),
            predictedTransmutedPerformance: parseFloat(predictedTransmutedPerformance.toFixed(2)),
            averageRawPerformance: parseFloat(averageRawPerformance.toFixed(2)),
            ewmaRawPerformance: parseFloat(ewmaRawPerformance.toFixed(2)),
            momentum: momentum, // 'improving', 'declining', 'stable'
            allGradedScoresCount: allGradedScores.length,
            // --- END NEW V3 ---

            projectedFinalGrade: finalTermGradeHighest, // (Legacy) Kept for 'isTermOver' display
            currentGWA: parseFloat(currentGWA.toFixed(2)), 
            statusBaseLabel: statusBaseLabel, 
            isGWA_Pending: isGWA_Pending, // <-- NEW

            isMidtermCompleted: isMidtermCompleted,
            isFinalCompleted: isFinalCompleted,
            finalTermUpcomingWeight: finalTermUpcomingWeight,
            
            // Goal Seeker calculation needs the weighted sum of *graded* Final Term components 
            goal_finalTermGradedWeightedAvg: finalWeightSum > 0 ? (finalTermLowestPossibleGradeSum - (finalTermUpcomingWeight * 50)) / finalWeightSum : 0,
            goal_totalUpcomingWeight: finalTermUpcomingWeight,
            
            goal_finalWeightSum: finalWeightSum, 
            
            scoreTrend: momentum, // (Legacy) Use momentum for old graph text
            progressionLabels: progressionLabels, // Chronologically sorted
            progressionScores: progressionScores, // Chronologically sorted
        };
    };

    /**
     * Generates the HTML table content for a specific term (Midterm or Final).
     * (MODIFIED to include <details> and <summary> for collapsibles)
     */
    const generateTableHTML = (gradeData, targetTerm, goalClass, requiredRawScore, goalGWA, goalGrade, isTermOver, currentMidtermGrade) => {
        let tableHTML = '';
        let hasContent = false;
        
        // 1. Group components by Type and calculate total weights (NEW LOGIC)
        // Ensure 'General' is a fallback type
        const componentsByType = { 'Lecture': [], 'Laboratory': [], 'General': [] };
        let totalWeightByType = { 'Lecture': 0, 'Laboratory': 0, 'General': 0 };
        let termTotalWeight = 0;

        for (const componentNameKey in gradeData.gradeComponents) {
            const data = gradeData.gradeComponents[componentNameKey];
            if (data.term === targetTerm) {
                const type = data.type || 'General';
                // Only push if the type is known or is the 'General' fallback
                if (componentsByType.hasOwnProperty(type)) { 
                    componentsByType[type].push(data);
                    totalWeightByType[type] += data.decimal_weight;
                    termTotalWeight += data.decimal_weight;
                    hasContent = true;
                }
            }
        }

        // 2. Term Summary Header (Line 466)
        tableHTML += `
            <div class="mb-4 p-3 bg-gray-100 rounded-lg">
                <p class="font-bold text-gray-700">Term Weight (to Final Course Grade): 
                    <span class="text-primary">${targetTerm === 'Midterm' ? '40%' : '60%'}</span> 
                    | Term Components Total: <span class="text-primary">${(termTotalWeight * 100).toFixed(0)}%</span>
                </p>
                ${targetTerm === 'Midterm' ? 
                    // --- (MODIFIED) Use new getGradeClass function ---
                    `<p class="font-bold text-gray-700">Calculated Midterm Term Grade: 
                        <span class="${getGradeClass(gradeData.midtermTermGrade)}">
                            ${gradeData.midtermTermGrade.toFixed(2)}%
                        </span>
                    </p>` : ''
                }
            </div>
        `;

        // 3. Iterate through Component Types (Lecture, Laboratory, General)
        const typeOrder = ['Lecture', 'Laboratory', 'General'];
        let isFirstType = true; // To make the first section open by default

        for (const type of typeOrder) {
            const components = componentsByType[type];
            const totalWeight = totalWeightByType[type];

            if (components.length === 0) continue;

            // --- (NEW) Create <details> wrapper ---
            // Add 'open' attribute if it's the first type
            tableHTML += `<details class="mb-4" ${isFirstType ? 'open' : ''}>`;
            isFirstType = false; // Unset flag

            // --- (NEW) Header for the Component Type Group (is now a <summary>) ---
            tableHTML += `
                <summary class="mt-8 mb-4 bg-gray-200 p-3 rounded-lg flex justify-between items-center border-l-4 border-primary hover:bg-gray-300">
                    <h5 class="text-xl font-extrabold text-primary">${type} Components</h5>
                    <p class="font-bold text-gray-700">Weight in Term: <span class="text-primary">${(totalWeight * 100).toFixed(0)}%</span></p>
                </summary>
            `;
            
            // This div will contain all the component tables *within* this type
            tableHTML += `<div class="pl-4 border-l-2 border-gray-200">`; 

            // Loop through components within this type group
            components.forEach(data => {
                const cleanName = data.name; 
                const isStillGrading = data.scores.length === 0 || data.scores.some(item => item.status === 'Upcoming');
                let suggestionHTML = '';

                // Only show suggestion if term is not over and goal is achievable AND it's the Final Term
                if (isStillGrading && !isTermOver && goalClass === 'goal-achievable' && targetTerm === 'Final') {
                    // This requiredRawScore value is derived from the overall calculation to hit the GWA goal
                    const requiredRawAvgToMeetGoal = Math.max(0, requiredRawScore); 
                    const requiredTransmutedAvg = transmuteGrade(requiredRawAvgToMeetGoal);

                    // --- (REMOVED *) ---
                    suggestionHTML = `
                        <p class="text-sm mb-3 p-3 bg-blue-50 border-l-4 border-primary text-gray-800 rounded-r-md">
                            ✨ <strong>Goal Suggestion:</strong> To help achieve your target Final GWA (${goalGWA.toFixed(2)}), aim for an average <strong>transmuted score</strong> of at least <strong>${requiredTransmutedAvg.toFixed(2)}%</strong> on all remaining Final Term items.
                        </p>
                    `;
                }

                // --- (MODIFIED) Show simplified weighted grade based on CURRENT average ---
                let weightedGradeDisplay = "N/A";
                const avgGrade = parseFloat(data.display_transmuted_grade);
                if (!isNaN(avgGrade)) {
                    // This calculates the component's weighted grade based *only* on items already graded
                    const weightedGrade = avgGrade * data.decimal_weight;
                    weightedGradeDisplay = weightedGrade.toFixed(2);
                }
                
                // --- (REMOVED *) ---
                tableHTML += `
                    <h4 class="mt-4 text-lg font-semibold text-primary border-b pb-1 mb-2">
                        ${cleanName}
                        <span class="float-right font-normal text-success text-sm ml-2">(${data.weight})</span>
                    </h4>
                    
                    <p class="text-sm mb-2 text-gray-600">
                        Current Weighted Grade Contribution to Term: <strong class="text-primary">${weightedGradeDisplay}</strong>
                    </p>
                    
                    ${suggestionHTML} 

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 bg-white rounded-lg shadow-sm mb-4">
                `;
                // --- (END MODIFIED) ---

                tableHTML += `
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Transmuted %</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status/Feedback</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                `;

                if (data.scores.length === 0) {
                    tableHTML += `
                        <tr class="hover:bg-gray-50 transition duration-150">
                            <td colspan="4" class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">No scores recorded for this component yet.</td>
                        </tr>
                    `;
                } else {
                    data.scores.forEach(item => {
                        const isMissing = item.status === 'Missing';
                        const isUpcoming = item.status === 'Upcoming';
                        
                        const percentage = (item.max > 0) ? (item.score / item.max) * 100 : 0;
                        const transmutedPercent = transmuteGrade(percentage);
                        
                        let gradeClass = 'grade-pending'; 
                        let itemScoreDisplay = isUpcoming ? 'Upcoming' : `${item.score} / ${item.max}`;
                        let feedbackText = item.feedback || item.status;
                        let percentText = isUpcoming ? 'N/A' : `${transmutedPercent.toFixed(0)}%`;
                        
                        // --- (MODIFIED) Logic for Final Term Upcoming Goal & At-Risk Alerts ---
                        if (isUpcoming && targetTerm === 'Final' && goalClass === 'goal-achievable') {
                            // Goal Seeker Calculation for individual item goal (simplified to GWA 3.00 target)
                            const requiredRawAvgToMeetGoal = 
                                (75 - currentMidtermGrade * 0.4 - gradeData.goal_finalTermGradedWeightedAvg * 0.6) / (gradeData.goal_totalUpcomingWeight * 0.6) || 100;
                            
                            const requiredTransmuted = transmuteGrade(requiredRawAvgToMeetGoal);
                            const displayTransmutedGoal = Math.min(100, requiredTransmuted);

                            itemScoreDisplay = "Goal Score";
                            percentText = `<span class="font-bold goal-achievable">${displayTransmutedGoal.toFixed(2)}%</span>`;
                            feedbackText = "Transmuted Goal for Passing Course (GWA 3.00)";
                            gradeClass = 'grade-pending'; // Keep it neutral
                        
                        } else if (!isUpcoming) { // This is for Graded or Missing items
                            
                            if (isMissing) {
                                gradeClass = 'grade-failing';
                                // **(REMOVED *)**
                                feedbackText = `⚠️ MISSING! ${item.feedback}`; 
                            } else {
                                // Item is GRADED and NOT Missing. Now apply warnings.
                                if (transmutedPercent >= 80) {
                                    gradeClass = 'grade-high'; 
                                } else if (transmutedPercent >= 75) { // 75-79.99
                                    gradeClass = 'text-warning font-bold'; // Use the warning class
                                    // **(REMOVED *)**
                                    feedbackText = `⚠️ At-Risk. ${item.feedback}`; 
                                } else { // < 75
                                    gradeClass = 'grade-failing';
                                    // **(REMOVED *)**
                                    feedbackText = `⚠️ LOW SCORE! ${item.feedback}`; 
                                }
                            }
                        }
                        // --- END MODIFIED ---

                        tableHTML += `
                            <tr class="hover:bg-gray-50 transition duration-150">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${item.name}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center ${gradeClass}">${itemScoreDisplay}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center ${gradeClass}">${percentText}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">${feedbackText}</td>
                            </tr>
                        `;
                    });
                }

                tableHTML += `
                            </tbody>
                        </table>
                        </div>
                    `;
            });
            // --- (NEW) Close the component type div and the <details> tag ---
            tableHTML += `</div></details>`;
        } // End type loop
        
        if (!hasContent) {
            return `<p class="no-data p-4 text-center text-gray-500">No grading components defined for the ${targetTerm} term yet.</p>`;
        }
        return tableHTML;
    };
    
    /**
    * Toggles between Midterm and Final content display.
    * @param {string} term - 'Midterm' or 'Final'
    */
    window.showTermContent = (term) => {
        document.getElementById('midterm-content').style.display = (term === 'Midterm' ? 'block' : 'none');
        document.getElementById('final-content').style.display = (term === 'Final' ? 'block' : 'none');
        
        document.getElementById('midterm-button').classList.toggle('active', term === 'Midterm');
        document.getElementById('final-button').classList.toggle('active', term === 'Final');
    };

    // --- DOM Manipulation and Chart Generation ---
    document.addEventListener('DOMContentLoaded', () => {
        
        const rawDataFromPHP = <?php echo json_encode($raw_data_php); ?>;
        const dbError = <?php echo json_encode($db_error); ?>;
        
        const errorContainer = document.getElementById('error-container');
        if (dbError) {
            errorContainer.innerHTML = `<div class="message error">${dbError}</div>`;
            document.getElementById('subject-header').innerHTML = "Error Loading Data";
            return; 
        }
        
        if (!rawDataFromPHP || Object.keys(rawDataFromPHP.gradeComponents).length === 0) {
            errorContainer.innerHTML = `<div class="message error">Grade breakdown data is not available for this subject yet. Your professor has not set up the grading components.</div>`;
            document.getElementById('subject-header').innerHTML = `<?php echo $subject_title; ?> (<?php echo $subject_code; ?>)`;
            document.getElementById('instructor-info').innerHTML = `Instructor: <strong><?php echo $subject_instructor; ?></strong>`;
            document.getElementById('main-content-area').style.display = 'none';
            return;
        }
        
        const gradeData = processRawData(rawDataFromPHP);
        
        if (!gradeData) {
            document.getElementById('subject-header').innerHTML = "Error: Could not process data.";
            return;
        }
        
        const setText = (id, text) => {
            const el = document.getElementById(id);
            if (el) el.innerHTML = text;
        };

        // 1. Update Summary Section
        setText('subject-header', `<?php echo $subject_title; ?> (<?php echo $subject_code; ?>)`);
        
        
        // --- (REVISED) Smarter Status Logic ---
        let statusHtml = '';
        const currentGWA = gradeData.currentGWA;
        
        if (gradeData.isGWA_Pending) {
             // --- (NEW) Handle "In-Progress" state ---
            statusHtml = `<span class="font-bold text-gray-600">In-Progress</span>`;
            setText('current-gwa', `<strong class="text-gray-500">Pending...</strong>`);
            setText('current-gwa-label', `Current GWA:`);
        } else {
            // --- Original Logic for At-Risk, Passing, Failing ---
            
            // --- (MODIFIED) Base status check on the *predicted* GWA if finals are in progress ---
            let statusCheckGWA = currentGWA; // Use the GWA calculated during processing
            let statusCheckGrade = gradeData.midtermTermGrade; // Fallback for early midterm
            
            if (gradeData.statusBaseLabel.includes("Midterm")) {
                 statusCheckGWA = getGWAEquivalent(gradeData.midtermTermGrade);
                 statusCheckGrade = gradeData.midtermTermGrade;
            } else {
                 // Use the predicted grade for status checking
                 statusCheckGrade = gradeData.predictedFinalCourseGrade;
            }

            if (statusCheckGrade >= 75 && statusCheckGrade < 80) {
                 // Special Case: Midterm/Projected is "At-Risk" (75-79)
                 statusHtml = `<span class="status-warning font-bold">⚠️ At-Risk!</span>`;
            
            } else {
                 // Default check based on the calculated GWA (which could be Midterm or Final)
                 const statusClass = statusCheckGWA > gradeData.passingGWA ? 'status-failing' : 'status-passing';
                 const statusText = statusCheckGWA > gradeData.passingGWA ? 'Failing' : 'Passing';
                 statusHtml = `<span class="${statusClass}"><strong>${statusText}</strong></span>`;
            }
            
            setText('current-gwa', `<strong>${gradeData.currentGWA.toFixed(2)}</strong>`);
        }
        
        setText('instructor-info', `Instructor: <strong><?php echo $subject_instructor; ?></strong> | Current Status (${gradeData.statusBaseLabel}): ${statusHtml}`);
        // --- (END REVISED) ---
        

        // --- *** GRADE PROJECTION LOGIC (UI CHANGE 2) *** ---
        const totalUpcomingWeight = gradeData.finalTermUpcomingWeight; // Only consider Final term upcoming weight
        const isTermOver = (gradeData.isFinalCompleted && gradeData.isMidtermCompleted);

        // 1. Populate Grade Projections Box
        if (isTermOver) {
            // --- TERM IS OVER (Final Course Grade is locked) ---
            setText('current-gwa-label', `<strong>Final GWA:</strong>`);
            // GWA is already set above
            
            // Hide the new "final term" box and "highest" box
            document.getElementById('final-term-grade-box').style.display = 'none';
            document.getElementById('highest-grade-box').style.display = 'none';

            // Change Midterm Term Grade field to display FINAL COURSE GRADE
            setText('midterm-grade-label', `<strong>Final Course Grade:</strong>`); 
            setText('midterm-grade', `<span class="text-xl font-bold text-primary float-right">${gradeData.lowestPossibleGrade.toFixed(2)}%</span>`); 
            setText('midterm-grade-desc', `(All coursework is complete: Midterm ${gradeData.midtermTermGrade.toFixed(2)}% * 40% + Final Term ${gradeData.projectedFinalGrade.toFixed(2)}% * 60%)`); 
            
        } else {
            // --- TERM IS ONGOING (Projection based on fixed Midterm + forecasted Final Term) ---
            if (!gradeData.isGWA_Pending) { // Only set label if not pending
                setText('current-gwa-label', `Current GWA:`);
            }

            // --- (MODIFIED) Midterm Grade (Standalone) ---
            const midtermGradeClass = getGradeClass(gradeData.midtermTermGrade);
            setText('midterm-grade-label', `<strong>Midterm Term Grade (Standalone):</strong>`);
            setText('midterm-grade', `<span class="text-xl font-bold ${midtermGradeClass} float-right">${gradeData.midtermTermGrade.toFixed(2)}%</span>`);
            setText('midterm-grade-desc', `(Determined based on Midterm Term performance)`);

            // --- NEW: Populate Standalone Final Term Grade (UI CHANGE 2) ---
            if (gradeData.hasFinalComponents) {
                // --- (MODIFIED LOGIC PER USER REQUEST) ---
                if (gradeData.hasGradedFinalItems) {
                    const finalTermGrade = gradeData.finalTermGradeLowest;
                    const finalTermGradeClass = getGradeClass(finalTermGrade); // <-- Use new class
                    setText('final-term-grade-label', `<strong>Current Final Term Grade (Standalone):</strong>`);
                    setText('final-term-grade', `<span class="text-xl font-bold ${finalTermGradeClass} float-right">${finalTermGrade.toFixed(2)}%</span>`);
                    setText('final-term-grade-desc', `(Assumes 50% on all remaining Final Term work)`);
                } else {
                    setText('final-term-grade-label', `<strong>Current Final Term Grade (Standalone):</strong>`);
                    setText('final-term-grade', `<span class="text-xl font-bold text-gray-400 float-right">N/A</span>`);
                    setText('final-term-grade-desc', `(No Final Term scores recorded yet)`);
                }
                // --- (END MODIFIED LOGIC) ---
                document.getElementById('final-term-grade-box').style.display = 'block';
            }
            // --- END NEW ---

            // Highest Possible Grade (Combined)
            setText('highest-grade-label', `<strong>Highest Possible Final Course Grade (Combined):</strong>`);
            setText('highest-grade', `<strong>${gradeData.highestPossibleGrade.toFixed(2)}%</strong>`);
            setText('highest-grade-desc', `(Assumes 100% raw score on all remaining Final Term work)`);
            document.getElementById('highest-grade-box').style.display = 'block';
        }

        // --- (NEW V3) ADVANCED PREDICTIVE ANALYTICS (Replaces old "AI Goal Forecast") ---
        let goalGWA = 1.00;
        let goalGrade = 97; 
        const gwaLevels = {"1.00": 97, "1.25": 94, "1.50": 91, "1.75": 88, "2.00": 85, "2.25": 82, "2.50": 79, "2.75": 76, "3.00": 75};
        
        let requiredRawScore = 0; // Raw score needed for all upcoming items
        let goalText = "";
        let goalClass = "goal-achievable";
        let foundAchievableGoal = false; 

        const currentMidtermGrade = gradeData.midtermTermGrade;
        
        if (!isTermOver) { 
            
            if (gradeData.highestPossibleGrade < 75) { 
                // Case 1: Cannot pass
                goalText = `<strong>⚠️ URGENT:</strong> Even with 100% on all future work, your highest possible Final Course Grade is <strong>${gradeData.highestPossibleGrade.toFixed(2)}%</strong>. You cannot pass. Please contact your professor immediately.`;
                goalClass = "goal-impossible";
                requiredRawScore = 101; // Set to impossible for table
                goalGWA = 5.00;
                goalGrade = 74;

            } else {
                // Case 2: Can pass. Show the NEW Trend-Based Prediction.
                
                const predictedGWA = getGWAEquivalent(gradeData.predictedFinalCourseGrade);
                const predictedGradeClass = getGradeClass(gradeData.predictedFinalCourseGrade);
                
                // --- 1. The Intervention/Momentum Message ---
                let momentumIcon = '📊';
                let momentumClass = 'momentum-stable';
                let momentumText = `Your performance is <strong>Stable</strong>.`;
                
                if (gradeData.momentum === 'improving') {
                    momentumIcon = '🚀';
                    momentumClass = 'momentum-improving';
                    momentumText = `Your recent performance is <strong>Improving</strong>. Keep up the momentum!`;
                } else if (gradeData.momentum === 'declining') {
                    momentumIcon = '⚠️';
                    momentumClass = 'momentum-declining';
                    momentumText = `<strong>Early Warning:</strong> Your recent scores are <strong>Declining</strong>. This is an early opportunity to seek help or review your study habits.`;
                }

                // --- 2. The Prediction Message ---
                let predictionIntro = `This model analyzes your <strong>Performance Momentum</strong> (recent scores) to predict your final grade.`;
                
                if (gradeData.allGradedScoresCount < 3) { 
                     predictionIntro = `Once more scores are recorded, this model will analyze your performance momentum to predict your final grade.`;
                }

                // --- 3. Assemble the Card ---
                goalText = `
                    <div class="mb-3 text-lg font-bold ${momentumClass}">
                        ${momentumIcon} ${momentumText}
                    </div>
                    <div class="text-sm text-gray-700 mb-3">
                        ${predictionIntro}
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 text-center mb-3">
                        <div>
                            <span class="text-xs text-gray-500 uppercase">Recent Momentum</span>
                            <div class="text-xl font-bold ${momentumClass}">${gradeData.ewmaRawPerformance.toFixed(2)}%</div>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 uppercase">Overall Average</span>
                            <div class="text-xl font-bold text-gray-600">${gradeData.averageRawPerformance.toFixed(2)}%</div>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    
                    <div class="text-base mb-1">
                        Based on your <strong>Recent Momentum</strong>, your predicted Final Course Grade is:
                    </div>
                    <div class="text-center mb-2">
                        <strong class="${predictedGradeClass} text-3xl font-extrabold">${gradeData.predictedFinalCourseGrade.toFixed(2)}%</strong>
                        (GWA: <strong class="text-primary">${predictedGWA.toFixed(2)}</strong>)
                    </div>
                    
                    <div classtext-xs text-gray-600">
                        This is a prediction. Your highest possible grade is <strong>${gradeData.highestPossibleGrade.toFixed(2)}%</strong> and your lowest is <strong>${gradeData.lowestPossibleGrade.toFixed(2)}%</strong>.
                    </div>
                `;
                
                // Set table goals to the *minimum passing* goal (GWA 3.00)
                // Goal Seeker: Find raw % to get 75
                const requiredTransmutedToPass = (75 - (currentMidtermGrade * 0.4) - (gradeData.goal_finalTermGradedWeightedAvg * 0.6)) / (gradeData.goal_totalUpcomingWeight * 0.6);
                requiredRawScore = (requiredTransmutedToPass - 50) * 2;
                
                goalGWA = 3.00;
                goalGrade = 75;
                goalClass = "goal-achievable"; // Box styling
            }
            
        } else {
             goalText = `<span>All coursework is complete. Your final grade is <strong>${gradeData.lowestPossibleGrade.toFixed(2)}%</strong>.</span>`;
        }

        // Note: Changed span to div to allow for block-level elements (like <hr>)
        setText('goal-forecast-status', `<div class='${goalClass}'>${goalText}</div>`);
        // --- END: ADVANCED PREDICTIVE ANALYTICS ---


        // Feature 4: Score Trend (Now uses Momentum)
        let trendText = "";
        if (gradeData.progressionScores.length > 2) {
            if (gradeData.momentum === 'improving') {
                trendText = `<span class="goal-achievable">📈 Your scores are trending <strong>UP</strong>. Keep it up!</span>`;
            } else if (gradeData.momentum === 'declining') {
                // --- (CHANGE 1 APPLIED) ---
                trendText = `<span class="goal-impossible">📉 **Warning:** Your recent scores are trending <strong>Down</strong>. This is an early intervention opportunity.</span>`;
            } else {
                trendText = `<span>📊 Your scores are <strong>STABLE</strong>.</span>`;
            }
        } else {
            trendText = 'Not enough data yet to show a trend.';
        }
        setText('score-trend-text', trendText);


        // 2. Render Component Scores Table (Feature 2)
        const componentTableContainer = document.getElementById('component-table-container');
        
        // Generate content for both Midterm and Final
        const midtermHTML = generateTableHTML(gradeData, 'Midterm', goalClass, requiredRawScore, goalGWA, goalGrade, isTermOver, currentMidtermGrade);
        const finalHTML = generateTableHTML(gradeData, 'Final', goalClass, requiredRawScore, goalGWA, goalGrade, isTermOver, currentMidtermGrade);

        componentTableContainer.innerHTML = `
            <div class="flex border-b border-gray-200 mb-4">
                <button id="midterm-button" class="tab-button active" onclick="showTermContent('Midterm')">Midterm Details</button>
                <button id="final-button" class="tab-button ${gradeData.hasFinalComponents ? '' : 'text-gray-400 cursor-not-allowed'}" 
                        onclick="showTermContent('Final')" ${gradeData.hasFinalComponents ? '' : 'disabled'}>Final Details</button>
            </div>

            <div id="midterm-content" class="term-content">${midtermHTML}</div>
            <div id="final-content" class="term-content" style="display: none;">${finalHTML}</div>
        `;
        
        // Default view: Midterm (handled by JS above)
        // If no midterm components exist, default to Final if available.
        if (midtermHTML.includes('No grading components defined') && gradeData.hasFinalComponents) {
             window.showTermContent('Final');
        } else {
             window.showTermContent('Midterm'); // Ensure initial view is set
        }


        // 3. Generate Chart 1: Performance Progression
        const ctxPerformance = document.getElementById('performanceChart');
        if (ctxPerformance && gradeData.progressionScores.length > 0) {
            
            // --- (GRAPH ENHANCEMENT) Create Gradient ---
            const ctx = ctxPerformance.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(0, 86, 179, 0.5)'); // Darker at top
            gradient.addColorStop(1, 'rgba(0, 86, 179, 0.05)'); // Fading to transparent

            new Chart(ctxPerformance, {
                type: 'line',
                data: {
                    labels: gradeData.progressionLabels, // (V3) Now chronological
                    datasets: [{
                        label: 'Student Score (Raw %)',
                        data: gradeData.progressionScores, // (V3) Now chronological
                        borderColor: 'rgb(0, 86, 179)',
                        backgroundColor: gradient, // Use the gradient
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 5,
                        pointBackgroundColor: 'rgb(0, 86, 179)',
                        pointHoverRadius: 7, // Make hover more obvious
                        pointHoverBorderColor: 'white',
                        pointHoverBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        // --- (GRAPH ENHANCEMENT) Hide Legend ---
                        legend: { 
                            display: false 
                        },
                        // --- (MODIFICATION) Title is now an HTML element, not part of the canvas ---
                        title: { 
                            display: false 
                        },
                        // --- (GRAPH ENHANCEMENT) Custom Tooltips ---
                        tooltip: {
                            backgroundColor: '#000',
                            titleFont: { size: 14 },
                            bodyFont: { size: 12 },
                            padding: 10,
                            boxPadding: 4,
                            callbacks: {
                                label: function(context) {
                                    const rawPercent = context.parsed.y;
                                    const transmuted = transmuteGrade(rawPercent); // Use the existing helper function
                                    return [
                                        `Raw Score: ${rawPercent.toFixed(2)}%`,
                                        `Transmuted: ${transmuted.toFixed(2)}%`
                                    ];
                                }
                            }
                        },
                        // --- (NEW) Added Annotation Line ---
                        annotation: {
                            annotations: {
                                line1: {
                                    type: 'line',
                                    yMin: 50, // 50% Raw = 75% Transmuted
                                    yMax: 50,
                                    borderColor: 'rgb(204, 0, 0)',
                                    borderWidth: 2,
                                    borderDash: [6, 6],
                                    label: {
                                        content: 'Passing Threshold (50% Raw)',
                                        position: 'start',
                                        backgroundColor: 'rgba(204, 0, 0, 0.7)',
                                        font: {
                                            weight: 'bold'
                                        },
                                        color: 'white',
                                        padding: 6
                                    }
                                }
                            }
                        }
                        // --- (END NEW) ---
                    },
                    scales: {
                        y: {
                            min: 0,
                            max: 100,
                            title: { display: true, text: 'Raw Score Percentage (%)' },
                            grid: {
                                color: '#ddd' // Make Y-axis gridlines slightly more visible
                            }
                        },
                        x: {
                            title: { display: true, text: 'Graded Item (in chronological order)' }, // (V3) Updated title
                            grid: {
                                display: false // Hide X-axis gridlines
                            },
                            // --- (NEW FIX for Overlap) ---
                            ticks: {
                                maxRotation: 45, // Rotate labels up to 45 degrees
                                minRotation: 30, // Rotate labels at least 30 degrees
                                autoSkip: false, // Do not skip labels
                                font: {
                                    size: 10 // Slightly smaller font to help fit
                                }
                            }
                            // --- (END FIX) ---
                        }
                    }
                }
            });
        } else if (ctxPerformance) {
             // (MODIFICATION) Hide the whole card if no data
            ctxPerformance.closest('.bg-white').style.display = 'none';
        }

        // --- (NEW) GWA Info Pop-up Click Logic ---
        const gwaContainer = document.getElementById('gwa-info-container');
        const gwaIcon = document.getElementById('gwa-info-icon');

        if (gwaContainer && gwaIcon) {
            gwaIcon.addEventListener('click', (event) => {
                event.stopPropagation(); // Prevent click from bubbling up to the window
                gwaContainer.classList.toggle('active');
            });

            // Add click listener to the whole window to close the pop-up
            window.addEventListener('click', (event) => {
                // If the click is NOT on the icon AND the container is active
                if (!gwaContainer.contains(event.target) && gwaContainer.classList.contains('active')) {
                    gwaContainer.classList.remove('active');
                }
            });
        }
        // --- (END NEW) ---
    });
</script>
</head>
<body class="bg-gray-100 min-h-screen font-sans antialiased">

    <header class="bg-primary text-white p-4 shadow-lg flex justify-between items-center">
        <div class="text-xl font-bold">SmartGrade Monitoring System</div>
        <button onclick="window.location.href='students.php'" class="bg-white text-primary hover:bg-gray-200 transition duration-150 font-semibold py-2 px-4 rounded-lg shadow-md">
            Back to Dashboard
        </button>
    </header>

    <main class="container mx-auto p-4 md:p-8">
        
        <div id="error-container"></div>
        
        <section class="bg-white p-6 rounded-xl shadow-lg mb-6">
            <h2 id="subject-header" class="text-2xl md:text-3xl font-extrabold text-primary mb-1">Loading...</h2>
            <p id="instructor-info" class="text-gray-700 text-sm md:text-base"></p>
        </section>

        <div id="main-content-area">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div class="col-span-1 space-y-6">
                    <div class="bg-white p-6 rounded-xl shadow-lg border-t-4 border-primary relative">
                        <h3 class="text-xl font-bold mb-3 text-gray-800 flex items-center">
                            📊 Final Course Grade Projections
                        </h3>
                        <p class="text-sm text-gray-600 mb-4">Final Course Grade = (Midterm Term Grade * 40%) + (Final Term Grade * 60%).</p>
                        
                        <div class="gwa-info-container" id="gwa-info-container">
                            <span class="gwa-info-icon" id="gwa-info-icon">i</span>
                            <div class="gwa-table-content">
                                <div class="bg-white p-4">
                                    <h3 class="text-lg font-bold mb-3 text-gray-800">
                                        GWA Grading System Reference
                                    </h3>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Numerical Grade (%)</th>
                                                    <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">GWA Equivalent</th>
                                                    <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 text-xs">
                                                <tr class="bg-green-50/50"><td class="px-3 py-2">97 - 100</td><td class="px-3 py-2 font-bold">1.00</td><td class="px-3 py-2 text-success">Excellent</td></tr>
                                                <tr class="bg-green-50/20"><td class="px-3 py-2">94 - 96</td><td class="px-3 py-2">1.25</td><td class="px-3 py-2 text-success">Very Good</td></tr>
                                                <tr class="bg-green-50/50"><td class="px-3 py-2">91 - 93</td><td class="px-3 py-2">1.50</td><td class="px-3 py-2 text-success">Very Good</td></tr>
                                                <tr class="bg-green-50/20"><td class="px-3 py-2">88 - 90</td><td class="px-3 py-2">1.75</td><td class="px-3 py-2 text-success">Good</td></tr>
                                                <tr class="bg-green-50/50"><td class="px-3 py-2">85 - 87</td><td class="px-3 py-2">2.00</td><td class="px-3 py-2 text-success">Good</td></tr>
                                                <tr class="bg-green-50/20"><td class="px-3 py-2">82 - 84</td><td class="px-3 py-2">2.25</td><td class="px-3 py-2 text-success">Satisfactory</td></tr>
                                                <tr class="bg-yellow-50/50"><td class="px-3 py-2">79 - 81</td><td class="px-3 py-2">2.50</td><td class="px-3 py-2 text-yellow-600">Fair</td></tr>
                                                <tr class="bg-yellow-50/20"><td class="px-3 py-2">76 - 78</td><td class="px-3 py-2">2.75</td><td class="px-3 py-2 text-yellow-600">Fair (At Risk)</td></tr>
                                                <tr class="bg-yellow-50/50"><td class="px-3 py-2 font-bold">75 (Passing Mark)</td><td class="px-3 py-2 font-bold">3.00</td><td class="px-3 py-2 text-yellow-600">Minimum Passing</td></tr>
                                                <tr class="bg-red-50/20"><td class="px-3 py-2 font-bold">74 and below</td><td class="px-3 py-2 font-bold">5.00</td><td class="px-3 py-2 text-alert">Failing</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p id="current-gwa-label" class="text-lg text-primary font-bold mb-4">Current GWA: <span id="current-gwa" class="text-3xl">...</span></p>
                        
                        <hr class="my-4">
                        
                        <p id="midterm-grade-box" class="text-sm mb-2 text-gray-700">
                            <strong id="midterm-grade-label">Midterm Term Grade (Standalone):</strong>
                            <span id="midterm-grade" class="text-xl font-bold text-success float-right">...</span><br>
                            <em id="midterm-grade-desc" class="text-xs">(Determined based on Midterm Term performance)</em>
                        </p>

                        <p id="final-term-grade-box" class="text-sm mb-2 text-gray-700" style="display: none;"> <strong id="final-term-grade-label">Current Final Term Grade (Standalone):</strong>
                            <span id="final-term-grade" class="text-xl font-bold text-success float-right">...</span><br>
                            <em id="final-term-grade-desc" class="text-xs">(Assumes 50% on all remaining Final Term work)</em>
                        </p>

                        <hr class="my-3 border-gray-300">

                        <p id="highest-grade-box" class="text-sm mb-2 text-gray-700">
                            <strong id="highest-grade-label">Highest Possible Final Course Grade (Combined):</strong>
                            <span id="highest-grade" class="text-xl font-bold text-success float-right">...</span><br>
                            <em id="highest-grade-desc" class="text-xs">(Assumes 100% raw score on all remaining Final Term work)</em>
                        </p>
                        <hr class="my-4">

                        <h3 class="text-xl font-bold mb-3 text-gray-800 flex items-center">
                            ✨ Advanced Predictive Forecast
                        </h3>
                        
                        <div id="goal-forecast-status" class="text-base mb-2">Calculating...</div>
                        
                    </div>
                </div>

                <div class="col-span-1 space-y-6">
                    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="text-lg font-bold text-gray-800">Score Progression (Chronological)</h3>
                            <p id="score-trend-text" class="text-base mt-1">Loading trend...</p>
                        </div>
                        <div class="p-4 h-80">
                            <canvas id="performanceChart" class="w-full h-full"></canvas>
                        </div>
                    </div>
                </div>
                </div>
            
            <section class="mt-6">
                <div class="bg-white p-6 rounded-xl shadow-lg border-t-4 border-success">
                    <h3 class="text-2xl font-bold mb-4 text-gray-800 flex items-center">
                    📝 Detailed Component Scores
                    </h3>
                    <div id="component-table-container">
                    </div>
                </div>
            </section>
        </div> 
        
        </main></body>
</html>