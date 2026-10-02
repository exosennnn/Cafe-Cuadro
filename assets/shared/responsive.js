/* Responsive helpers shared by every shell (main app + POS + kiosk). */
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var nav = document.querySelector('.app-navbar');
    var sidebar = document.getElementById('appSidebar');

    // 1) Keep --nav-h equal to the real navbar height so the drawer, overlay
    //    and dropdown sheets line up under it on every screen size.
    function setNavH() {
      if (nav) document.documentElement.style.setProperty('--nav-h', nav.offsetHeight + 'px');
    }
    setNavH();
    window.addEventListener('resize', setNavH);
    window.addEventListener('orientationchange', setNavH);

    // 2) Off-canvas sidebar: make sure an overlay exists (POS shell has none),
    //    keep it in sync with the drawer, lock page scroll, allow Esc to close.
    if (sidebar) {
      var overlay = document.getElementById('sidebarOverlay');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'sidebarOverlay';
        overlay.className = 'sidebar-overlay';
        sidebar.parentNode.insertBefore(overlay, sidebar);
        overlay.addEventListener('click', function () { sidebar.classList.remove('show'); });
      }
      var sync = function () {
        var open = sidebar.classList.contains('show') && window.innerWidth < 992;
        overlay.classList.toggle('show', open);
        document.body.classList.toggle('sidebar-open', open);
      };
      new MutationObserver(sync).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
      window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) sidebar.classList.remove('show');
        sync();
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') sidebar.classList.remove('show');
      });
      // Swipe left on the open drawer to close it
      var startX = null;
      sidebar.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
      sidebar.addEventListener('touchend', function (e) {
        if (startX !== null && startX - e.changedTouches[0].clientX > 60) sidebar.classList.remove('show');
        startX = null;
      }, { passive: true });
    }

    // 3) Any table that isn't already inside a scroll container gets one, so
    //    wide tables scroll sideways on phones instead of breaking the layout.
    document.querySelectorAll('table').forEach(function (t) {
      if (t.closest('.table-responsive, .table-wrap, .dataTables_wrapper, .no-auto-wrap, .swal2-container, .modal-header')) return;
      if (t.classList.contains('dataTable')) return;
      var wrap = document.createElement('div');
      wrap.className = 'table-responsive';
      t.parentNode.insertBefore(wrap, t);
      wrap.appendChild(t);
    });
  });
})();
