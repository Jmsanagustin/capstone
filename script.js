document.getElementById('filterForm').addEventListener('submit', function(e) {
    e.preventDefault();

    // Get the selected year and section
    const year = document.getElementById('year').value;
    const section = document.getElementById('section').value;

    // Fetch students and grades based on the year and section
    fetch(`teacher_dashboard.php?year=${year}&section=${section}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const tableBody = document.getElementById('grades-table-body');
                tableBody.innerHTML = ''; // Clear the table

                data.students.forEach(student => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${student.stud_fname} ${student.stud_lname}</td>
                        <td><input type="number" value="${student.quiz_score}" data-student-id="${student.stud_id}" data-type="quiz"></td>
                        <td><input type="number" value="${student.performance_task_score}" data-student-id="${student.stud_id}" data-type="performance_task"></td>
                        <td><input type="number" value="${student.class_participation_score}" data-student-id="${student.stud_id}" data-type="class_participation"></td>
                        <td><input type="number" value="${student.major_exam_score}" data-student-id="${student.stud_id}" data-type="major_exam"></td>
                        <td><input type="number" value="${student.midterm_score}" data-student-id="${student.stud_id}" data-type="midterm"></td>
                        <td><input type="number" value="${student.final_exam_score}" data-student-id="${student.stud_id}" data-type="final_exam"></td>
                        <td>${student.final_grade}</td>
                        <td><button class="save-btn" data-student-id="${student.stud_id}">Save</button></td>
                    `;
                    tableBody.appendChild(row);
                });
            }
        });
});

document.addEventListener('click', function(e) {
    if (e.target.classList.contains('save-btn')) {
        const studentId = e.target.getAttribute('data-student-id');
        const inputs = document.querySelectorAll(`[data-student-id="${studentId}"]`);
        let updatedData = {};

        inputs.forEach(input => {
            updatedData[input.getAttribute('data-type')] = parseFloat(input.value);
        });

        // Send the updated grades via POST
        fetch(`teacher_dashboard.php?studentId=${studentId}`, {
            method: 'POST',
            body: JSON.stringify(updatedData),
            headers: {
                'Content-Type': 'application/json'
            }
        }).then(response => response.json())
          .then(data => {
            if (data.success) {
                alert('Grades updated successfully!');
            }
        });
    }
});


// script.js
document.getElementById('year').addEventListener('change', loadStudents);
document.getElementById('section').addEventListener('change', loadStudents);

// Fetch students based on the selected year and section
function loadStudents() {
    const year = document.getElementById('year').value;
    const section = document.getElementById('section').value;

    // Make API request to the server
    fetch(`http://localhost:5000/students?year=${year}&section=${section}`)
        .then(response => response.json())
        .then(data => {
            const studentList = document.getElementById('studentList');
            studentList.innerHTML = ''; // Clear existing list

            data.forEach(student => {
                const row = document.createElement('tr');
                
                const nameCell = document.createElement('td');
                nameCell.textContent = student.name;

                const attendanceCell = document.createElement('td');
                const presentRadio = document.createElement('input');
                presentRadio.type = 'radio';
                presentRadio.name = `attendance-${student.name}`;
                presentRadio.value = 'Present';
                presentRadio.id = `present-${student.name}`;
                presentRadio.onclick = function() { markAttendance(student.name, 'Present'); };

                const absentRadio = document.createElement('input');
                absentRadio.type = 'radio';
                absentRadio.name = `attendance-${student.name}`;
                absentRadio.value = 'Absent';
                absentRadio.id = `absent-${student.name}`;
                absentRadio.onclick = function() { markAttendance(student.name, 'Absent'); };

                attendanceCell.appendChild(presentRadio);
                attendanceCell.appendChild(document.createTextNode(' Present '));
                attendanceCell.appendChild(absentRadio);
                attendanceCell.appendChild(document.createTextNode(' Absent '));

                const remarksCell = document.createElement('td');
                const remarksTextarea = document.createElement('textarea');
                remarksTextarea.id = `remarks-${student.name}`;
                remarksTextarea.rows = 2;
                remarksTextarea.placeholder = "Enter remarks (optional)";
                remarksCell.appendChild(remarksTextarea);

                row.appendChild(nameCell);
                row.appendChild(attendanceCell);
                row.appendChild(remarksCell);
                studentList.appendChild(row);
            });
        })
        .catch(error => {
            console.error('Error fetching student data:', error);
        });
}

// Mark attendance as Present or Absent
function markAttendance(studentName, status) {
    console.log(`${studentName} is marked as ${status}`);

    // You can store this in an object or array, or send it to a back-end server
    // Example of storing the status temporarily:
    const remarksField = document.getElementById(`remarks-${studentName}`);
    const studentAttendance = {
        name: studentName,
        status: status,
        remarks: remarksField.value || "No remarks"
    };

    // Store the status (this can be saved or processed as needed)
    console.log(studentAttendance);
}

// Load students initially based on the default year and section
loadStudents();
