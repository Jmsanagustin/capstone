<?php
// FILE: ph_footer.php
// PURPOSE: Reusable footer and script block for all Program Head pages.
?>
            </main>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const currentView = '<?php echo $current_view ?? 'dashboard'; ?>';
            
            // --- Sidebar Toggle Logic ---
            const sidebar = document.getElementById("sidebar");
            const headerToggleBtn = document.getElementById("headerToggleBtn");
            const header = document.getElementById("header");
            const mainContent = document.getElementById("mainContent"); 

            if (headerToggleBtn) {
                headerToggleBtn.addEventListener("click", () => {
                    if (sidebar) sidebar.classList.toggle("minimized");
                    if (header) header.classList.toggle("expanded");
                    if (mainContent) mainContent.classList.toggle("expanded");
                });
            }

            // --- Messaging Specific JS ---
            if (currentView === 'messages') {
                const messageArea = document.getElementById('message-area');
                if (messageArea) { messageArea.scrollTop = messageArea.scrollHeight; }

                const textarea = document.querySelector('.message-input-area textarea');
                if(textarea) {
                    const adjustHeight = () => {
                        textarea.style.height = 'auto';
                        textarea.style.height = textarea.scrollHeight + 'px';
                    };
                    textarea.addEventListener('input', adjustHeight);
                    adjustHeight();
                }

                const modal = document.getElementById('new-conversation-modal');
                const newConversationBtn = document.getElementById('new-conversation-btn');
                const closeModalXBtn = document.getElementById('modal-close-x-btn');
                const modalCancelBtn = document.getElementById('modal-cancel-btn');
                const conversationError = "<?php echo !empty($start_conversation_error) ? 'true' : 'false'; ?>"; 

                const closeModal = () => { modal.style.display = 'none'; };

                if(newConversationBtn) newConversationBtn.onclick = () => { modal.style.display = 'flex'; }
                if(closeModalXBtn) closeModalXBtn.onclick = closeModal;
                if(modalCancelBtn) modalCancelBtn.onclick = closeModal;

                window.onclick = (event) => {
                    if (event.target == modal) { closeModal(); }
                }

                if (conversationError === 'true') {
                     modal.style.display = 'flex';
                }
            }
        }); // End DOMContentLoaded

        // --- Grade Approval Specific JS (Global for Dashboard) ---
        function confirmReject(form) {
             const reason = prompt("Reason for rejecting grade:");
             if (reason === null || reason.trim() === "") {
               alert("Rejection cancelled. Reason required.");
               return false;
             }
             form.reject_reason.value = reason;
             return true;
        }
    </script>

</body>
</html>