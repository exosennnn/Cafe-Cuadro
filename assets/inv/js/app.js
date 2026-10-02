document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');

    function isDesktop() { return window.innerWidth >= 768; }

    function closeMobileSidebar() {
        if (sidebar) sidebar.classList.remove('show');
        if (overlay) overlay.classList.remove('show');
    }

    // Restore the user's preferred desktop sidebar state (collapsed / expanded)
    if (sidebar && isDesktop()) {
        const savedState = window.localStorage ? window.localStorage.getItem('cafe_sidebar_collapsed') : null;
        if (savedState === '1') sidebar.classList.add('collapsed');
    }

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function () {
            if (isDesktop()) {
                sidebar.classList.toggle('collapsed');
                if (window.localStorage) {
                    window.localStorage.setItem('cafe_sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
                }
            } else {
                const willShow = !sidebar.classList.contains('show');
                sidebar.classList.toggle('show', willShow);
                if (overlay) overlay.classList.toggle('show', willShow);
            }
        });
    }

    // Tapping the dimmed overlay closes the mobile sidebar
    if (overlay) {
        overlay.addEventListener('click', closeMobileSidebar);
    }

    // Close the mobile sidebar automatically when a nav link is tapped
    if (sidebar) {
        sidebar.querySelectorAll('.sidebar-nav a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (!isDesktop()) closeMobileSidebar();
            });
        });
    }

    // Keep sidebar state sane when resizing across the mobile/desktop breakpoint
    window.addEventListener('resize', function () {
        if (isDesktop()) closeMobileSidebar();
    });

    // Close sidebar when clicking outside on mobile (outside the sidebar/overlay/toggle)
    document.addEventListener('click', function (e) {
        if (!isDesktop() && sidebar && sidebar.classList.contains('show')) {
            if (!sidebar.contains(e.target) && e.target !== toggleBtn) {
                closeMobileSidebar();
            }
        }
    });

    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function (alertEl) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // Client-side confirm for delete actions
    document.querySelectorAll('.confirm-delete').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    });

    // Enable Bootstrap tooltips wherever data-bs-toggle="tooltip" is used
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });

    // Show a spinner + disable the button on form submit to prevent double-submits
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            const submitBtn = form.querySelector('button[type="submit"]:not(.no-spinner)');
            if (submitBtn && !submitBtn.disabled) {
                submitBtn.dataset.originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-inline"></span>' + submitBtn.textContent.trim();
                submitBtn.disabled = true;
                // Re-enable if the page doesn't navigate away (e.g. validation error re-render)
                setTimeout(function () {
                    if (submitBtn.disabled) {
                        submitBtn.disabled = false;
                        if (submitBtn.dataset.originalText) submitBtn.innerHTML = submitBtn.dataset.originalText;
                    }
                }, 8000);
            }
        });
    });
});
