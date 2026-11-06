(() => {
  'use strict';
  const sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  const desktopToggle = document.getElementById('sidebar-toggle');
  const accountToggle = document.getElementById('account-menu-toggle');
  const accountMenu = document.getElementById('account-menu');
  const mobile = matchMedia('(max-width: 768px)');
  let collapsed = false;
  try { collapsed = localStorage.getItem('sidebarCollapsed') === 'true'; } catch (_) { /* Storage may be disabled. */ }

  function syncLayout() {
    sidebar.classList.toggle('collapsed', !mobile.matches && collapsed);
    desktopToggle.setAttribute('aria-expanded', String(!collapsed));
    sidebar.inert = false;
  }
  desktopToggle.addEventListener('click', () => {
    collapsed = !collapsed;
    try { localStorage.setItem('sidebarCollapsed', String(collapsed)); } catch (_) { /* Keep the in-memory preference. */ }
    syncLayout();
  });
  mobile.addEventListener('change', syncLayout);
  const currentPath = location.pathname.split('/').pop();
  sidebar.querySelectorAll('.sidebar-link').forEach(link => {
    if (link.getAttribute('href') === currentPath) {
      link.classList.add('active');
      link.setAttribute('aria-current', 'page');
    }
  });
  if (accountToggle && accountMenu) {
    const setAccountMenu = (open, restoreFocus = false) => {
      accountMenu.hidden = !open;
      accountToggle.setAttribute('aria-expanded', String(open));
      accountToggle.setAttribute('aria-label', open ? 'Close account menu' : 'Open account menu');
      if (open) accountMenu.querySelector('a, button')?.focus();
      else if (restoreFocus) accountToggle.focus();
    };
    accountToggle.addEventListener('click', () => setAccountMenu(accountMenu.hidden));
    document.addEventListener('click', (event) => {
      if (!accountMenu.hidden && !event.target.closest('.header-account')) setAccountMenu(false);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !accountMenu.hidden) {
        event.preventDefault();
        setAccountMenu(false, true);
      }
    });
  }
  syncLayout();
})();
