/* registrar_scripts.js */

document.addEventListener("DOMContentLoaded", function() {
    // --- Set Active Sidebar Link ---
    const urlParams = new URLSearchParams(window.location.search);
    let currentView = urlParams.get('view') || 'dashboard';
    const activeLink = document.querySelector(`.sidebar-nav a[data-view="${currentView}"]`);
    if (activeLink) { activeLink.classList.add('active'); }

    // --- Sidebar Toggle ---
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');

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

    // --- Auto-scroll message area (Removed the old logic that caused the 404/error) ---
    // The auto-scroll/auto-resize for the chat view is now correctly in registrar_messages.php's script block.
    
    // --- Modal Toggle Logic ---
    const modal = document.getElementById('new-conversation-modal');
    const newConvBtn = document.getElementById('new-conversation-btn');
    const closeModalBtn = document.getElementById('modal-close-x-btn');
    const cancelModalBtn = document.getElementById('modal-cancel-btn');
    const searchInput = document.getElementById('recipient-search');

    const showModal = () => {
        if (modal) {
            modal.classList.add('show');
            if(searchInput) searchInput.focus();
        }
    };
    const closeModal = () => {
        if(modal) modal.classList.remove('show');
    };

    if (newConvBtn) newConvBtn.addEventListener('click', showModal);
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

    if(modal) {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    }

    // --- AJAX User Search ---
    const suggestionsBox = document.getElementById('search-suggestions');
    const recipientInput = document.getElementById('recipient-sid-input');
    const startConvBtn = document.getElementById('start-conv-submit-btn');

    if(searchInput) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim();
            
            if(recipientInput) recipientInput.value = '';
            if(startConvBtn) startConvBtn.disabled = true;

            if (query.length < 2) {
                if(suggestionsBox) suggestionsBox.innerHTML = '';
                return;
            }

            // Note: The AJAX handler is registrar_ajax_search_users.php, which must exist.
            fetch(`registrar_ajax_search_users.php?query=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if(!suggestionsBox) return;
                    suggestionsBox.innerHTML = '';
                    if (data.success && data.users.length > 0) {
                        data.users.forEach(user => {
                            const div = document.createElement('div');
                            div.className = 'suggestion-item';
                            div.innerHTML = `<strong>${user.name}</strong> (${user.role} - ${user.username})`;
                            div.dataset.sid = user.sid;
                            
                            div.addEventListener('click', () => {
                                searchInput.value = `${user.name} (${user.role})`;
                                if(recipientInput) recipientInput.value = user.sid;
                                if(startConvBtn) startConvBtn.disabled = false;
                                suggestionsBox.innerHTML = '';
                            });
                            suggestionsBox.appendChild(div);
                        });
                    } else if (data.users.length === 0) {
                        suggestionsBox.innerHTML = '<p style="padding: 10px; color: var(--text-light);">No users found.</p>';
                    }
                })
                .catch(err => {
                    if(suggestionsBox) suggestionsBox.innerHTML = '<p style="padding: 10px; color: var(--critical);">Search failed.</p>';
                    console.error(err);
                });
        });
    }
});

// Helper for Dashboard (must be globally accessible)
function toggleSection(id) {
    var x = document.getElementById(id);
    if (x.style.display === "none") {
        x.style.display = "block";
    } else {
        x.style.display = "none";
    }
}