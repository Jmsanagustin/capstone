<?php
// FILE: registrar_footer.php
// PURPOSE: Centralized footer and required JavaScript for the Registrar Portal.
// VERSION 7.13: Cleaned up markdown and stabilized layout.

// Ensures the session is started if not already handled by a header or body file.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

    </main>
</div>

<div id="edit-modal" class="modal">
    <div class="modal-content">
        <button class="modal-close-btn" onclick="closeEditModal()">&times;</button>
        <h3>Edit Student Record Verification</h3>
        <p id="e_csv_guide" class="description" style="color: var(--text-dark); background: var(--bg-light); padding: 10px; border-radius: 6px; margin-bottom: 20px;">
            <strong>CSV Input:</strong> <strong id="e_original_full_name">Loading...</strong> 
        </p>
        <p class="description" style="color: var(--critical);"><i class="fa-solid fa-triangle-exclamation"></i> <strong>Manual Verification.</strong> Please correct the parsed fields below to match the student's official records.</p>
        <form action="registrar_student_management.php?view=students&tab=upload" method="POST">
            <input type="hidden" name="update_review_data" value="1">
            <input type="hidden" name="index" id="e_index">
            
            <div class="modal-body">
                <div class="form-group">
                    <label>Student ID<span class="required-asterisk">*</span></label>
                    <input type="text" name="e_student_id" id="e_student_id" required>
                </div>
                <div class="form-group">
                    <label>Email<span class="required-asterisk">*</span></label>
                    <input type="email" name="e_email" id="e_email" required>
                </div>
                <div class="form-group">
                    <label>First Name<span class="required-asterisk">*</span></label>
                    <input type="text" name="e_first_name" id="e_first_name" required>
                </div>
                <div class="form-group">
                    <label>Middle Name (Enter FULL name, or leave blank)</label>
                    <input type="text" name="e_middle_name" id="e_middle_name">
                </div>
                <div class="form-group">
                    <label>Last Name<span class="required-asterisk">*</span> (Include Jr./Sr./III if applicable)</label>
                    <input type="text" name="e_last_name" id="e_last_name" required>
                </div>
            </div>

            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="reject-btn" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="approve-btn">Save & Verify</button>
            </div>
        </form>
    </div>
</div>

<script>
    /**
     * toggleTooltip (For registrar_header tooltips)
     */
    function toggleTooltip(element) {
        element.classList.toggle('tooltip-active');
        if (element.classList.contains('tooltip-active')) {
            document.addEventListener('click', closeTooltipOutside, true);
        } else {
            document.removeEventListener('click', closeTooltipOutside, true);
        }
    }

    /**
     * closeTooltipOutside
     */
    function closeTooltipOutside(event) {
        const activeTooltip = document.querySelector('.info-tooltip.tooltip-active');
        if (activeTooltip && !activeTooltip.contains(event.target)) {
            activeTooltip.classList.remove('tooltip-active');
            document.removeEventListener('click', closeTooltipOutside, true);
        }
    }


    // Global utility functions remain the same:
    function openEditModal(index, studentDataJson) {
        const studentData = studentDataJson.replace(/&quot;/g, '"'); 
        try {
            const student = JSON.parse(studentData);
            document.getElementById('e_original_full_name').textContent = student.full_student_name;
            document.getElementById('e_index').value = index;
            document.getElementById('e_student_id').value = student.student_id;
            document.getElementById('e_email').value = student.email;
            document.getElementById('e_first_name').value = student.first_name;
            document.getElementById('e_middle_name').value = student.middle_name || '';
            document.getElementById('e_last_name').value = student.last_name;
            document.getElementById('edit-modal').classList.add('show');
        } catch (e) {
            console.error("Error parsing student data for modal:", e);
            alert("Error loading student data. Check console for details.");
        }
    }

    function closeEditModal() {
        document.getElementById('edit-modal').classList.remove('show');
    }

    function toggleDirectory(contentId) {
        const content = document.getElementById(contentId);
        const iconId = contentId + '-icon';
        const icon = document.getElementById(iconId);
        
        if (!content) return;

        const isHidden = (content.style.display === 'none' || content.style.display === '');
        content.style.display = isHidden ? 'block' : 'none';
        
        if (icon) {
            icon.classList.toggle('fa-chevron-right', !isHidden);
            icon.classList.toggle('fa-chevron-down', isHidden);
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        // --- Sidebar Toggle (for mobile responsiveness) ---
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        
        // This is necessary because the messaging JS block in registrar_messages.php uses the messagingLayout variable.
        const messagingLayout = document.querySelector('.messaging-layout'); 

        if (sidebarToggle && sidebar && mainContent) {
            sidebarToggle.addEventListener('click', (e) => {
                e.stopPropagation(); 
                sidebar.classList.toggle('mobile-open');
            });
            mainContent.addEventListener('click', () => {
                if (sidebar.classList.contains('mobile-open')) {
                    sidebar.classList.remove('mobile-open');
                }
            });
        }
        
        // --- Activate click functionality for all tooltips ---
        document.querySelectorAll('.info-tooltip').forEach(tooltip => {
            const icon = tooltip.querySelector('i');
            if(icon) {
                icon.addEventListener('click', (e) => {
                    e.stopPropagation(); 
                    toggleTooltip(tooltip);
                });
            }
        });
        
        // --- Critical Message Dismissal Logic ---
        document.getElementById('critical-warning')?.addEventListener('click', (e) => { e.currentTarget.style.display = 'none'; });

        // =======================================================
        // MESSAGING JS INTEGRATION AND FIXES (Applies only to messages page)
        // =======================================================
        if (window.location.pathname.includes('registrar_messages.php')) {
            const newConvModal = document.querySelector('#new-conversation-modal');
            const newConvBtn = document.getElementById('new-conversation-btn');
            const closeModalXBtn = document.getElementById('modal-close-x-btn-messages');
            const cancelModalBtn = document.getElementById('modal-cancel-btn-messages');
            const messageArea = document.getElementById('message-area');
            const startConvSubmitBtn = document.querySelector('#new-conversation-modal button[name="start_conversation"]');
            
            // FIX: Search inputs/containers
            const searchInput = document.querySelector('#new-conversation-modal input[name="recipient_username"]');
            const suggestionsBox = document.getElementById('search-suggestions');
            
            // --- Mobile View Navigation Logic ---
            
            // Mobile: Clicking the back button triggers the list view transition
            document.querySelector('.message-header-conv .back-button')?.addEventListener('click', (e) => {
                e.preventDefault(); 
                if (window.innerWidth <= 768 && messagingLayout) {
                    messagingLayout.classList.add('show-list');
                    messagingLayout.classList.remove('show-chat');
                    
                    const newUrl = new URL(window.location.href);
                    newUrl.searchParams.delete('conversation_id');
                    window.history.replaceState(null, null, newUrl.toString());
                }
            });


            // --- Modal Open/Close Logic ---
            const openMessageModal = () => {
                newConvModal?.classList.add('show');
                
                // Clear state on open
                if (searchInput) searchInput.value = '';
                if (suggestionsBox) suggestionsBox.innerHTML = '';
                if (startConvSubmitBtn) startConvSubmitBtn.disabled = true;
            };

            const closeMessageModal = () => {
                newConvModal?.classList.remove('show');
                
                // Clear URL error state (This is a clean-up function)
                const newUrl = new URL(window.location.href);
                newUrl.searchParams.delete('start_conversation_error'); 
                window.history.replaceState(null, null, newUrl.toString());
            };

            newConvBtn?.addEventListener('click', openMessageModal);
            closeModalXBtn?.addEventListener('click', closeMessageModal);
            cancelModalBtn?.addEventListener('click', closeMessageModal);
            window.onclick = function(event) { 
                if (event.target == newConvModal) { closeMessageModal(); } 
            }

            // --- Scroll and Textarea Logic ---
            if (messageArea) { messageArea.scrollTop = messageArea.scrollHeight; }

            const textarea = document.querySelector('.message-input-area textarea');
            if (textarea) {
                const setHeight = () => { textarea.style.height = 'auto'; textarea.style.height = (textarea.scrollHeight) + 'px'; };
                textarea.addEventListener('input', setHeight);
                setHeight(); 
            }
            
            // --- CORE AJAX Search Integration ---
            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    const query = searchInput.value.trim();
                    
                    // Reset internal state and button
                    startConvSubmitBtn.disabled = true;

                    if (query.length < 2) {
                        if (suggestionsBox) suggestionsBox.innerHTML = '';
                        return;
                    }
                    
                    fetch(`registrar_ajax_search_users.php?query=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (!suggestionsBox) return;
                            suggestionsBox.innerHTML = '';
                            
                            if (data.success && data.users.length > 0) {
                                data.users.forEach(user => {
                                    const div = document.createElement('div');
                                    div.className = 'suggestion-item';
                                    div.innerHTML = `<strong>${user.name}</strong> (${user.role} - @${user.username})`; 
                                    
                                    div.addEventListener('click', () => {
                                        // CRITICAL: Fill the search input with the username for PHP to verify
                                        searchInput.value = user.username; 
                                        startConvSubmitBtn.disabled = false; // Enable submission
                                        suggestionsBox.innerHTML = ''; 
                                    });
                                    suggestionsBox.appendChild(div);
                                });
                            } else {
                                suggestionsBox.innerHTML = '<p style="padding: 10px; color: var(--text-light);">No users found.</p>';
                            }
                        })
                        .catch(err => {
                            if (suggestionsBox) suggestionsBox.innerHTML = '<p style="padding: 10px; color: var(--critical);">Search failed. Check network.</p>';
                            console.error("AJAX Search Error:", err);
                        });
                });
            }

            // Check if there was an error after a POST request that should keep the modal open
            const conversationError = "<?php echo !empty($start_conversation_error) ? 'true' : 'false'; ?>";
            if (conversationError === 'true') {
                 openMessageModal(); 
            }
        } // End if (registrar_messages.php) block
    }); 
</script>

</body>
</html>