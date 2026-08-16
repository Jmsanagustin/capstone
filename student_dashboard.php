<?php

session_start();

// Ensure the user is logged in and is a student
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: index.php");
    exit();
}

// Assume $userName and $role are set from session
$userName = $_SESSION['username'] ?? 'Student';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Performance Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>

</head>
<body class="bg-gray-50 min-h-screen">
    <?php include 'sidebar.php'; ?>
    <?php include 'header.php'; ?>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        primary: '#1d4ed8', /* Blue 700 */
                        secondary: '#ff9900', /* Orange accent */
                    }
                }
            }
        }
    </script>

    <div id="mainContent" class="main-content">
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

            <button id="back-button" class="hidden mb-6 flex items-center text-primary hover:text-blue-800 transition duration-150" onclick="showDashboardIndex()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
                Back to Subject List
            </button>

            <section id="dashboard-index">
                <h2 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-2">My Subject Grades Overview</h2>

                <div id="risk-warning-box" class="hidden mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg" role="alert">
                    <p class="font-bold flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.34c.53-1.054 2.144-1.054 2.673 0l6.234 12.467A1.5 1.5 0 0116.326 17H3.673a1.5 1.5 0 01-1.238-2.193l6.234-12.467zM10 13a1 1 0 100 2 1 1 0 000-2zm-1-7a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd" />
                        </svg>
                        ACADEMIC ALERT:
                    </p>
                    <p class="ml-7 text-sm">You have subjects currently categorized as high-risk. Please review your detailed performance immediately.</p>
                </div>

                <div id="subject-summary" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                </div>
            </section>

            <section id="subject-detail" class="hidden">
                <h2 id="detail-subject-title" class="text-3xl font-extrabold text-gray-900 mb-2"></h2>
                <p id="detail-subject-instructor" class="text-lg text-gray-600 mb-6"></p>

                <div id="risk-detail-warning" class="hidden mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg" role="alert">
                    <p class="font-bold flex items-center">
                         <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                             <path fill-rule="evenodd" d="M8.257 3.34c.53-1.054 2.144-1.054 2.673 0l6.234 12.467A1.5 1.5 0 0116.326 17H3.673a1.5 1.5 0 01-1.238-2.193l6.234-12.467zM10 13a1 1 0 100 2 1 1 0 000-2zm-1-7a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd" />
                         </svg>
                         ACADEMIC INTERVENTION NEEDED
                    </p>
                    <p class="ml-7 text-sm" id="risk-detail-message">Your current grade predicts a high risk of failure. Consult your instructor immediately.</p>
                </div>

                <div id="grade-summary-box" class="mb-8 p-6 bg-white border-l-4 border-primary rounded-lg shadow-lg">
                    <p class="text-sm font-medium text-gray-500">Current Weighted Average (MOTHER WTD AVE)</p>
                    <p id="detail-mother-wtd-ave" class="text-5xl font-extrabold text-primary mt-1">--</p>
                    <p id="detail-final-status" class="text-xl font-semibold mt-2"></p>
                </div>

                <div class="mt-8">
                    <h3 class="text-2xl font-bold text-gray-900 mb-4 border-b pb-2">Predictive Grade Analysis (Forecast)</h3>
                    <div id="forecast-analysis" class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 space-y-3 text-gray-700">
                    </div>
                </div>
                        
                <div id="component-grades" class="space-y-6">
                </div>
            </section>

        </main>
    </div>

    <script>
        // --- (MODIFIED) ---
        // Removed the redundant getAcademicRisk() function.
        // Removed the redundant getGwaEquivalent() function.
        // ---

        // Global variable to hold our data
        let STUDENT_DATA = {};

        // --- View Handlers ---

        // Function to show the main dashboard (Index)
        function showDashboardIndex() {
            document.getElementById('dashboard-index').classList.remove('hidden');
            document.getElementById('subject-detail').classList.add('hidden');
            document.getElementById('back-button').classList.add('hidden');
        }

        // Helper function for prediction output styling
        function getPredictionClass(isGood) {
            return isGood ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold';
        }

        // Function to show the detailed view for a specific subject
        function showSubjectDetail(subjectCode) {
            const subject = STUDENT_DATA.subjects.find(s => s.code === subjectCode);
            if (!subject) return;

            // Update Header and Status
            document.getElementById('detail-subject-title').textContent = subject.name + ' (' + subject.code + ')';
            document.getElementById('detail-subject-instructor').textContent = 'Instructor: ' + subject.instructor;

            const aveElement = document.getElementById('detail-mother-wtd-ave');
            aveElement.textContent = subject.current_grade.toFixed(2);
            
            const statusElement = document.getElementById('detail-final-status');
            statusElement.textContent = 'Status: ' + subject.status;

            // Apply color coding for Status and Average (existing logic)
            aveElement.classList.remove('text-red-600', 'text-orange-600', 'text-green-600', 'text-primary');
            statusElement.classList.remove('text-red-600', 'text-orange-600', 'text-green-600', 'text-primary');

            // --- (MODIFIED) ---
            // The 'risk' object now comes directly from the backend.
            const statusClass = subject.risk.color === 'red' ? 'text-red-600' :
                                subject.risk.color === 'orange' ? 'text-orange-600' :
                                'text-green-600';
            // --- (END MODIFIED) ---
            
            aveElement.classList.add(statusClass);
            statusElement.classList.add(statusClass);

            // --- Predictive Analytics Detail View (Risk Warning) ---
            const riskDetailWarning = document.getElementById('risk-detail-warning');
            const riskDetailMessage = document.getElementById('risk-detail-message');
            
            if (subject.risk.level !== 'LOW') {
                riskDetailMessage.textContent = subject.risk.message;
                riskDetailWarning.classList.remove('hidden');
            } else {
                riskDetailWarning.classList.add('hidden');
            }

            // --- Forecasting Output (New Logic) ---
            const forecast = subject.forecast;
            const forecastContainer = document.getElementById('forecast-analysis');

            // --- (MODIFIED) ---
            // Replaced the JS getGwaEquivalent() call with the 'subject.gwa_equivalent' property from the backend.
            let forecastHtml = `
                <p><strong>Current Grade GWA:</strong> ${subject.current_grade < 75 ? `<span class="text-red-600 font-semibold">${subject.gwa_equivalent.toFixed(2)} (Failing)</span>` : subject.gwa_equivalent.toFixed(2)}</p>
                <hr class="my-2 border-gray-100">

                <p>1. 🎯 **Highest Possible Final Grade (100% on Future):** <span class="${getPredictionClass(forecast.max_gwa <= 3.00)}">
                        ${forecast.max_grade.toFixed(2)}% (GWA: ${forecast.max_gwa.toFixed(2)})
                    </span>
                </p>

                <p>2. 📉 **Final Grade (Assuming Same Performance):** <span class="${getPredictionClass(forecast.unchanged_gwa <= 3.00)}">
                        ${forecast.unchanged_grade.toFixed(2)}% (GWA: ${forecast.unchanged_gwa.toFixed(2)})
                    </span>
                </p>

                <p>5. ⬇️ **Lowest Possible Grade (50% on Future):** <span class="${getPredictionClass(forecast.min_gwa <= 3.00)}">
                        ${forecast.min_grade.toFixed(2)}% (GWA: ${forecast.min_gwa.toFixed(2)})
                    </span>
                </p>

                <hr class="my-2 border-gray-100">

                <p>3. **Can you still fail with 100%?** <span class="${getPredictionClass(!forecast.is_failed_even_with_perfect)}">
                        ${forecast.is_failed_even_with_perfect ? '❌ YES (Requires more than 100% to pass)' : '✅ NO (Passing is guaranteed)'}
                    </span>
                </p>

                <p>4. **Eligible for Honors (Max GWA $\le 1.75$)?** <span class="${getPredictionClass(forecast.max_gwa_for_honors)}">
                        ${forecast.max_gwa_for_honors ? '🎉 YES' : '⛔ NO'}
                    </span>
                </p>
                
                <p>6. **Academic Advisory Status:** <span class="${getPredictionClass(!forecast.needs_help)}">
                        ${forecast.needs_help ? '🚨 INTERVENTION NEEDED' : '👍 On Track'}
                    </span>
                </p>
                
                <p>7. **Submitted to Program Head?** (Based on Risk/Incomplete Status) 
                    <span class="${getPredictionClass(!forecast.for_program_head)}">
                        ${forecast.for_program_head ? '⚠️ YES (High risk or Incomplete)' : 'No'}
                    </span>
                </p>

                <p>⚠️ **Incomplete Status Warning:** <span class="${getPredictionClass(!forecast.incomplete_warn)}">
                        ${forecast.incomplete_warn ? `DANGER: Status is 'Incomplete'. Submit requirements now.` : 'None.'}
                    </span>
                </p>
            `;
            // --- (END MODIFIED) ---
            
            forecastContainer.innerHTML = forecastHtml;

            // Build Detailed Component Grades
            const componentGradesContainer = document.getElementById('component-grades');
            componentGradesContainer.innerHTML = subject.components.map(comp => {
                const maxPossibleScore = 100;
                // Handle case where raw_average might be 0 to avoid division by zero
                const progress = maxPossibleScore > 0 ? (comp.raw_average / maxPossibleScore) * 100 : 0;
                const progressColor = progress > 90 ? 'bg-green-500' : progress > 75 ? 'bg-yellow-500' : 'bg-red-500';

                // Display individual scores, or "N/A" if no scores yet
                let scoresDisplay = "N/A";
                if (comp.scores.length > 0) {
                     scoresDisplay = comp.scores.map(s => s.toFixed(0)).join(', ');
                }

                return `
                    <div class="p-4 bg-white rounded-xl shadow-md border border-gray-200">
                        <div class="flex justify-between items-center mb-2">
                            <h3 class="text-xl font-semibold text-gray-800">${comp.name}</h3>
                            <span class="text-xl font-bold text-secondary">${(comp.weight * 100).toFixed(0)}%</span>
                        </div>
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center border-t pt-4">
                            <div class="flex flex-col">
                                <span class="text-xs font-medium text-gray-500">Raw Scores</span>
                                <span class="text-sm font-semibold text-gray-700">${scoresDisplay}</span>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-xs font-medium text-gray-500">Category Average</span>
                                <span class="text-lg font-bold">${comp.raw_average.toFixed(2)}</span>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-xs font-medium text-gray-500">Weighted Score</span>
                                <span class="text-lg font-bold text-blue-700">${comp.weighted_score.toFixed(2)}</span>
                            </div>
                            <div class="flex flex-col">
                                <span class="text-xs font-medium text-gray-500">Performance</span>
                                <div class="w-full bg-gray-200 rounded-full h-2.5 mt-1">
                                    <div class="h-2.5 rounded-full ${progressColor}" style="width: ${progress.toFixed(0)}%"></div>
                                </div>
                                <span class="text-xs text-gray-500">${progress.toFixed(0)}% of Max Score</span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');


            // Switch views
            document.getElementById('dashboard-index').classList.add('hidden');
            document.getElementById('subject-detail').classList.remove('hidden');
            document.getElementById('back-button').classList.remove('hidden');

        }

        // --- Initialization ---

        /**
         * Fetches data from the server and initializes the dashboard.
         */
        async function fetchDataAndInitialize() {
            try {
                // Fetch data from our new PHP endpoint
                const response = await fetch('get_student_data.php');
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error('Failed to fetch grade data: ' + errorText);
                }
                
                const data = await response.json();

                if (data.error) {
                     throw new Error(data.error);
                }

                // --- (MODIFIED) ALIGNMENT FIX ---
                // The data is now pre-processed. No need to re-calculate 'risk'.
                STUDENT_DATA = data;
                // --- (END MODIFIED) ---
                
                // Now that data is loaded, render the dashboard
                renderDashboard();

            } catch (error) {
                console.error('Error loading dashboard:', error);
                document.getElementById('subject-summary').innerHTML = 
                    `<div class="p-4 bg-red-100 text-red-700 rounded col-span-full">
                        <strong>Error:</strong> Could not load grade data. <br>
                        <pre class="text-sm" style="white-space: pre-wrap;">${error.message}</pre>
                    </div>`;
            }
        }

        /**
         * Renders the main dashboard cards using the global STUDENT_DATA.
         */
        function renderDashboard() {
            const container = document.getElementById('subject-summary');
            
            if (!STUDENT_DATA.subjects || STUDENT_DATA.subjects.length === 0) {
                container.innerHTML = `<p class="text-gray-600 col-span-full">You are not currently enrolled in any subjects with grades.</p>`;
                return;
            }

            let hasHighRisk = false;

            container.innerHTML = STUDENT_DATA.subjects.map(subject => {
                // --- (MODIFIED) ---
                // 'risk' is now guaranteed to be on the subject object from the backend.
                const risk = subject.risk;
                // --- (END MODIFIED) ---

                const cardColor = risk.color === 'red' ? 'bg-red-100 border-red-500 text-red-700' :
                                risk.color === 'orange' ? 'bg-yellow-100 border-yellow-500 text-yellow-700' :
                                'bg-green-100 border-green-500 text-green-700';
                
                if (risk.level === 'HIGH') {
                    hasHighRisk = true;
                }

                return `
                    <div class="grade-card bg-white p-6 rounded-xl shadow-lg border-l-8 border-primary flex flex-col justify-between">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 mb-1">${subject.name}</h3>
                            <p class="text-sm text-gray-500 mb-4">Instructor: ${subject.instructor}</p>
                            
                            <div class="flex items-end justify-between">
                                <div>
                                    <p class="text-xs font-medium text-gray-500 uppercase">Current Wtd. Ave.</p>
                                    <p class="text-4xl font-extrabold text-gray-800">${subject.current_grade.toFixed(2)}</p>
                                </div>
                                
                                <span class="text-xs font-semibold px-3 py-1 rounded-full ${cardColor.replace('100', '200').replace('text', 'border').replace('border', 'text')} border">
                                    RISK: ${risk.level}
                                </span>
                            </div>
                            
                            ${risk.level !== 'LOW' ? 
                                `<p class="mt-3 text-sm font-semibold text-red-600 flex items-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-9a1 1 0 000 2h2a1 1 0 100-2h-2zm-1 3a1 1 0 102 0 1 1 0 00-2 0z" clip-rule="evenodd" />
                                    </svg>
                                    ${risk.message}
                                </p>` : ''
                            }
                        </div>

                        <button class="mt-4 w-full bg-primary text-white py-2 rounded-lg font-semibold hover:bg-blue-800 transition duration-150"
                                onclick="showSubjectDetail('${subject.code}')">
                            View Detailed Grades
                        </button>
                    </div>
                `;
            }).join('');
            
            // Show the overall academic alert if any high-risk subjects exist
            if (hasHighRisk) {
                document.getElementById('risk-warning-box').classList.remove('hidden');
            } else {
                document.getElementById('risk-warning-box').classList.add('hidden');
            }
        }

        // Run initialization on page load
        window.onload = fetchDataAndInitialize;

    </script>
</body>
</html>