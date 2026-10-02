</main>
  </div><!-- /app-wrapper -->

  <footer class="text-center text-muted small py-3 border-top" style="background: var(--cream, #f7f2ea);">
    &copy; <?= date('Y') ?> <?= APP_NAME ?>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="<?= BASE_URL ?>assets/hrms/js/script.js"></script>
  <script src="<?= BASE_URL ?>assets/shared/responsive.js?v=<?= ASSET_VER ?>"></script>

  <script>
  // Global fetch interceptor for 401 Session Expired
  const originalFetch = window.fetch;
  window.fetch = async function(...args) {
    const response = await originalFetch.apply(this, args);
    if (response.status === 401) {
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: 'warning',
          title: 'Session Expired',
          text: 'Your session has expired or you need to log in. Please log in again.',
          confirmButtonText: 'Go to Login',
          allowOutsideClick: false
        }).then(() => {
          window.location.href = '<?= BASE_URL ?>?error=session_expired';
        });
      } else {
        alert('Your session has expired. Please log in again.');
        window.location.href = '<?= BASE_URL ?>?error=session_expired';
      }
    }
    return response;
  };

  document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('appSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    // Hover-to-expand only makes sense on desktop with a real pointer
    if (!window.matchMedia('(min-width: 992px) and (hover: hover)').matches) return;
    if (toggleBtn) {
      toggleBtn.addEventListener('mouseenter', function () {
        document.body.classList.remove('sidebar-collapsed');
      });
    }
    if (sidebar) {
      sidebar.addEventListener('mouseleave', function () {
        document.body.classList.add('sidebar-collapsed');
      });
    }
  });
  </script>
</body>
</html>
