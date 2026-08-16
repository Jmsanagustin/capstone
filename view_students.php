<style>
.info-tooltip { position: relative; display: inline-block; margin-left: 8px; cursor: pointer; font-size: 1rem; vertical-align: middle; }
.info-tooltip .tooltip-text { visibility: hidden; width: 380px; background-color: #333; color: #fff; text-align: left; border-radius: 6px; padding: 10px 15px; position: absolute; z-index: 10; top: 150%; left: 50%; margin-left: -190px; opacity: 0; transition: opacity 0.3s; font-size: 0.85rem; line-height: 1.5; font-weight: 400; }
.info-tooltip:hover .tooltip-text { visibility: visible; opacity: 1; }
.info-tooltip .tooltip-text::after { content: ""; position: absolute; bottom: 100%; left: 50%; margin-left: -5px; border-width: 5px; border-style: solid; border-color: transparent transparent #333 transparent; }
</style>

<section class="content-section mb-8">
    <h2>
        <i class="fa-solid fa-cloud-upload-alt"></i> Bulk Student Registration & Enrollment
        <span class="info-tooltip">
            <i class="fa-solid fa-circle-info"></i>
            <span class="tooltip-text">
                <p>Upload a CSV file containing student details.</p>
                <a href="registrar_dashboard.php?download_template=1" style="margin-top: 10px; display: inline-block; color: #4db8ff;">Download Template</a>
            </span>
        </span>
    </h2>
    
    <?php if (!isset($_SESSION['student_import_data'])): ?>
        <form action="registrar_dashboard.php?view=students" method="POST" enctype="multipart/form-data" class="upload-form" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; background: var(--bg-light); padding: 20px; border-radius: 8px; margin-top: 1rem;">
            <div style="flex: 1; min-width: 150px;">
                <label for="academic_year_select" style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 5px;">Academic Year:</label>
                <select name="current_academic_year" id="academic_year_select" required style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
                    <option value="">Select Year...</option>
                    <option value="2025-2026" <?php echo (date('Y') == 2025) ? 'selected' : ''; ?>>2025-2026</option>
                    <option value="2026-2027">2026-2027</option>
                </select>
            </div>
            <div style="flex: 1; min-width: 150px;">
                <label for="semester_select" style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 5px;">Semester:</label>
                <select name="current_semester" id="semester_select" required style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
                    <option value="1st Semester">1st Semester</option>
                    <option value="2nd Semester">2nd Semester</option>
                </select>
            </div>
            <div style="flex: 2; min-width: 200px;">
                <label for="student_file_input" style="font-size: 0.8rem; font-weight: 600; display: block; margin-bottom: 5px;">CSV File:</label>
                <input type="file" name="student_file" id="student_file_input" accept=".csv" required style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: 100%;">
            </div>
            <button type="submit" name="upload_students" class="approve-btn" style="background-color: var(--success-green); padding: 10px 20px; height: 40px;"><i class="fa-solid fa-upload"></i> Upload & Parse</button>
        </form>
    <?php else: 
        $review_data = $_SESSION['student_import_data'];
    ?>
    <div class="review-panel" style="margin-top: 20px;">
        <p class="description" style="color: var(--critical); font-weight: 600;">ATTENTION: Reviewing <strong><?= count($review_data); ?></strong> records.</p>
        <div class="table-container" style="max-height: 400px; overflow-y: auto; margin-bottom: 20px;">
            <table class="review-table">
                <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Program</th><th>Year</th><th>Section</th></tr></thead>
                <tbody>
                    <?php foreach ($review_data as $student): ?>
                        <tr>
                            <td><?= htmlspecialchars($student['student_id']); ?></td>
                            <td><?= htmlspecialchars($student['full_student_name']); ?></td>
                            <td><?= htmlspecialchars($student['email']); ?></td>
                            <td><strong><?= htmlspecialchars($student['program']); ?></strong></td>
                            <td><?= htmlspecialchars($student['academic_year']); ?></td>
                            <td><?= htmlspecialchars($student['section']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form action="registrar_dashboard.php?view=students" method="POST" style="display: flex; gap: 15px; justify-content: flex-end;">
            <input type="hidden" name="confirm_upload" value="1">
            <button type="submit" class="approve-btn"><i class="fa-solid fa-check"></i> Confirm</button>
            <button type="button" onclick="window.location.href='registrar_dashboard.php?view=students&clear=1'" class="reject-btn"><i class="fa-solid fa-times"></i> Cancel</button>
        </form>
    </div>
    <?php endif; ?>
</section>

<section class="content-section"> 
    <h2><i class="fa-solid fa-users"></i> Student Directory</h2>
    <div class="table-container">
        <?php if (!empty($student_list)): ?>
        <table class="review-table">
            <thead><tr><th>ID</th><th>Name</th><th>Program</th><th>Year</th><th>Section</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($student_list as $student): ?>
                <tr>
                    <td><?= htmlspecialchars($student['id']); ?></td>
                    <td><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                    <td><?= htmlspecialchars($student['program']); ?></td>
                    <td><?= htmlspecialchars($student['year']); ?></td>
                    <td><?= htmlspecialchars($student['section']); ?></td>
                    <td><a href="re_student_details.php?id=<?= $student['id']; ?>" class="action-link" style="padding: 6px 10px;"><i class="fa-solid fa-eye"></i> View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><p class="no-data">No student records found.</p><?php endif; ?>
    </div>
</section>