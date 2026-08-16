import json
import sys
from typing import Dict, Any, Union

# --- Grading System Constants ---
GWA_MAPPING = {
    (97, 100): 1.00, (94, 96): 1.25, (91, 93): 1.50, (88, 90): 1.75,
    (85, 87): 2.00, (82, 84): 2.25, (79, 81): 2.50, (76, 78): 2.75,
    (75, 75): 3.00,
}
PASSING_GRADE = 75.0
HONORS_GWA_THRESHOLD = 1.75
AT_RISK_GRADE = 78.0


class GradeAnalyzer:
    """Performs grade forecasting and advisory checks for a single subject."""

    def __init__(self, subject: Dict[str, Any]):
        self.code = subject['code']
        self.status = subject.get('status', 'In Progress')
        self.current_grade = subject.get('current_grade', 0.0)
        
        # Fixed model assumption: 60% completed, 40% remaining
        self.completed_weight = 0.60
        self.remaining_weight = 0.40
        self.results: Dict[str, Any] = {}

    def get_gwa_equivalent(self, numerical_grade: Union[int, float]) -> float:
        """Converts numerical grade to GWA based on defined mapping."""
        grade = round(numerical_grade)
        if grade <= 74:
            return 5.00
            
        for (low, high), gwa in GWA_MAPPING.items():
            if low <= grade <= high:
                return gwa
        return 1.00

    def calculate_final_grade_forecast(self, assumption_score: float) -> float:
        """Calculates the final numerical grade based on an assumed future score (0.50 to 1.00)."""
        current_contribution = self.current_grade * self.completed_weight
        future_contribution = (100 * assumption_score) * self.remaining_weight
        final_grade = current_contribution + future_contribution
        return round(final_grade, 2)

    def run_analysis(self) -> Dict[str, Any]:
        """Runs all forecasting algorithms and advisory checks."""
        
        # 1. Highest Possible Grade
        max_grade = self.calculate_final_grade_forecast(1.00)
        max_gwa = self.get_gwa_equivalent(max_grade)
        
        # 2. Final grade assuming performance doesn't change
        unchanged_grade = self.current_grade 
        unchanged_gwa = self.get_gwa_equivalent(unchanged_grade)
        
        # 5. Lowest Possible Grade (using 50% lowest score assumption)
        min_grade = self.calculate_final_grade_forecast(0.50)
        min_gwa = self.get_gwa_equivalent(min_grade)
        
        # --- Advisory Checks ---
        is_failed_even_with_perfect = max_grade < PASSING_GRADE # 3. Can still fail?
        can_reach_honors = max_gwa <= HONORS_GWA_THRESHOLD # 4. For honors?
        needs_intervention = unchanged_grade <= AT_RISK_GRADE # 6. Needs help?
        is_incomplete = self.status == 'Incomplete' # Incomplete warning
        for_program_head = (unchanged_gwa >= 3.00) or is_incomplete # 7. Program head list?
        
        self.results = {
            # Forecasts
            'max_grade': max_grade, 'max_gwa': max_gwa,
            'unchanged_grade': unchanged_grade, 'unchanged_gwa': unchanged_gwa,
            'min_grade': min_grade, 'min_gwa': min_gwa,
            # Advisory
            'is_failed_even_with_perfect': is_failed_even_with_perfect,
            'max_gwa_for_honors': can_reach_honors, // Renamed for JS consistency
            'needs_help': needs_intervention,       // Renamed for JS consistency
            'for_program_head': for_program_head,
            'incomplete_warn': is_incomplete
        }
        return self.results


def main():
    """Receives JSON data via command line, processes it, and prints JSON output."""
    if len(sys.argv) < 2:
        # Return error if no arguments are passed
        print(json.dumps({'error': 'No data provided to Python script.'}))
        return

    try:
        # Input is a JSON array containing all subjects
        input_data = json.loads(sys.argv[1])
    except json.JSONDecodeError:
        print(json.dumps({'error': 'Invalid JSON input received by Python.'}))
        return

    processed_subjects = []
    
    # Analyze each subject
    for subject in input_data:
        analyzer = GradeAnalyzer(subject)
        forecast = analyzer.run_analysis()
        
        # Combine subject data with the forecast results
        subject['forecast'] = forecast
        processed_subjects.append(subject)

    # Output the full processed data back to PHP as JSON
    print(json.dumps(processed_subjects))


if __name__ == "__main__":
    main()