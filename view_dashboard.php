<div class="dashboard-grid">
    <div class="dashboard-cards">
        <section class="quick-stats-grid">
            <div class="stat-card"><h3>Total Students</h3><p><?= $total_students; ?></p></div>
            <div class="stat-card"><h3>Programs Offered</h3><p><?= $programs_count; ?></p></div>
            <div class="stat-card urgent"><h3>Grades for Acknowledgement</h3><p><?= $pending_final_acknowledgements; ?></p></div>
        </section>
    </div>

    <div class="dashboard-main">
        <section class="content-section" style="min-width: 0; background: transparent; box-shadow: none; padding: 0;">
            <div style="margin-bottom: 20px;">
                <h2 style="color: var(--panel-blue); margin-bottom: 5px;"><i class="fa-solid fa-stamp"></i> Grade Acknowledgement</h2>
                <p class="description">Review and acknowledge final grades (Grouped by Section).</p>
            </div>
            
            <?php 
            $grouped_data = [];
            if (!empty($pending_grades_for_registrar)) {
                foreach ($pending_grades_for_registrar as $row) {
                    $sec = $row['section'] ?? 'Unassigned';
                    $subj_key = $row['subject_code'] . '||' . $row['subject_name'] . '||' . $row['teacher_name'];
                    $grouped_data[$sec][$subj_key][] = $row;
                }
            }
            ?>

            <?php if (!empty($grouped_data)): ?>
                <div class="sections-list">
                    <?php foreach ($grouped_data as $section_name => $subjects): ?>
                        <?php $sec_id = md5($section_name); ?>
                        <div class="section-group">
                            <div class="section-header">
                                <div class="section-title">
                                    Section: <?= htmlspecialchars($section_name); ?>
                                    <small>Contains <?= count($subjects); ?> Subject(s)</small>
                                </div>
                                <div style="display: flex; gap: 12px; align-items: center;">
                                    <form action="registrar_dashboard.php?view=dashboard" method="POST">
                                        <input type="hidden" name="section_name" value="<?= htmlspecialchars($section_name); ?>">
                                        <button type="submit" name="action" value="acknowledge_section" class="approve-btn" onclick="return confirm('Acknowledge ALL grades for Section <?= htmlspecialchars($section_name); ?>?');">
                                            <i class="fas fa-check-circle"></i> Acknowledge Whole Section
                                        </button>
                                    </form>
                                    <button onclick="toggleSection('sec-<?= $sec_id; ?>')" class="toggle-btn">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="sec-<?= $sec_id; ?>" style="display: block;"> 
                                <?php foreach ($subjects as $subj_key => $rows): 
                                    list($s_code, $s_name, $t_name) = explode('||', $subj_key); ?>
                                    <div class="subject-wrapper">
                                        <div class="subject-header">
                                            <div class="subject-info"><span class="subject-badge"><?= htmlspecialchars($s_code); ?></span> <?= htmlspecialchars($s_name); ?></div>
                                            <div class="teacher-info"><i class="fa-solid fa-chalkboard-user"></i> <?= htmlspecialchars($t_name); ?></div>
                                        </div>
                                        <table class="grade-table">
                                            <tbody>
                                                <?php foreach ($rows as $grade): ?>
                                                    <tr>
                                                        <td style="font-weight: 500; width:50%;"><i class="fa-regular fa-user" style="color: #94a3b8; margin-right: 8px;"></i> <?= htmlspecialchars($grade['student_full_name']); ?></td>
                                                        <td style="width:25%;"><?= htmlspecialchars($grade['term']); ?></td>
                                                        <td style="width:25%;"><span class="grade-pill"><?= number_format($grade['grade_value'] ?? 0, 2); ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-data" style="background:white; border-radius:12px; padding:40px; border:1px solid #e2e8f0;">
                    <i class="fa-solid fa-check-circle" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; display:block;"></i>
                    No grades pending acknowledgement.
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>