document.addEventListener('DOMContentLoaded', function () {
  const toggle = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('appSidebar');
  const overlay = document.getElementById('sidebarOverlay');

  function isDesktop() { return window.innerWidth >= 992; }

  function closeMobileSidebar() {
    if (sidebar) sidebar.classList.remove('show');
    if (overlay) overlay.classList.remove('show');
  }

  // Restore the user's preferred desktop sidebar state (collapsed / expanded)
  if (sidebar && isDesktop()) {
    const savedState = window.localStorage ? window.localStorage.getItem('hrms_sidebar_collapsed') : null;
    if (savedState === '1') sidebar.classList.add('collapsed');
  }

  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      if (isDesktop()) {
        sidebar.classList.toggle('collapsed');
        if (window.localStorage) {
          window.localStorage.setItem('hrms_sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
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
    sidebar.querySelectorAll('.nav-link').forEach(function (link) {
      link.addEventListener('click', function () {
        if (!isDesktop()) closeMobileSidebar();
      });
    });
  }

  // Keep sidebar state sane when resizing across the mobile/desktop breakpoint
  window.addEventListener('resize', function () {
    if (isDesktop()) closeMobileSidebar();
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
      e.preventDefault();
      const form = el.closest('form') || el;
      Swal.fire({
        title: 'Are you sure?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it'
      }).then((result) => {
        if (result.isConfirmed) {
          if (form.tagName === 'FORM') form.submit();
          else window.location.href = el.href;
        }
      });
    });
  });

  // Intercept forms with inline onsubmit="return confirm(...)"
  document.querySelectorAll('form[onsubmit*="return confirm"]').forEach(function(form) {
    const inlineScript = form.getAttribute('onsubmit');
    const match = inlineScript.match(/confirm\(['"](.*?)['"]\)/);
    const message = match ? match[1] : 'Are you sure you want to proceed?';
    
    // Remove inline to prevent native popup
    form.removeAttribute('onsubmit');
    
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      Swal.fire({
        title: 'Are you sure?',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, proceed'
      }).then((result) => {
        if (result.isConfirmed) {
          form.submit();
        }
      });
    });
  });

  // Enable Bootstrap tooltips wherever data-bs-toggle="tooltip" is used
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el);
  });

  // Re-adjust any DataTables column widths once the page (and web fonts)
  // have fully settled. See comment above this block's insertion point.
  function adjustAllDataTables() {
    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
      window.jQuery('table.dataTable').each(function () {
        window.jQuery.fn.dataTable.Api(this).columns.adjust();
      });
    }
  }
  window.addEventListener('load', function () {
    adjustAllDataTables();
    setTimeout(adjustAllDataTables, 300);
  });
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(adjustAllDataTables);
  }

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
