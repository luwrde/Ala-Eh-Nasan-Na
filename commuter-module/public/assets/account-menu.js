/* Account controls shared by every signed-in commuter page. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('.top-header');
    const profile = document.querySelector('.passenger-profile');
    const edit = profile?.querySelector('.profile-edit');
    const logout = document.getElementById('passenger-logout');
    if (!header || !edit || !logout) return;
    const icon = paths => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    const account = document.createElement('div');
    account.className = 'commuter-account';
    account.innerHTML = `
      <button type="button" id="commuter-account-toggle" class="commuter-account-toggle" aria-expanded="false" aria-controls="commuter-account-panel" aria-label="Open account menu">
        <span class="passenger-profile-avatar" aria-hidden="true">P</span>
        <span class="commuter-account-info"><strong class="passenger-profile-name">Commuter</strong><span>Commuter</span></span>
        ${icon('<path d="m7 10 5 5 5-5"/>')}
      </button>
      <nav id="commuter-account-panel" class="commuter-account-panel" aria-label="Account" hidden>
        <button type="button" id="commuter-account-profile" aria-haspopup="dialog" aria-controls="profile-settings">${icon('<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>')}<span>Profile</span></button>
        <a href="user-settings.html">${icon('<path d="m9 3 .6-1h4.8l.6 3 2 .9 2.8-1 2.4 4.2-2.2 2v2l2.2 2-2.4 4.2-2.8-1-2 .9-.6 2.8H9.6L9 19l-2-.9-2.8 1-2.4-4.2 2.2-2v-2l-2.2-2 2.4-4.2 2.8 1 2-.9Z"/><circle cx="12" cy="12" r="3"/>')}<span>Settings</span></a>
        <button type="button" id="commuter-account-logout">${icon('<path d="M10 3H4v18h6M9 12h12m-5-5 5 5-5 5"/>')}<span>Logout</span></button>
      </nav>`;
    const oldHeaderProfile = header.querySelector('.passenger-profile');
    if (oldHeaderProfile) oldHeaderProfile.hidden = true;
    header.append(account);
    const toggle = document.getElementById('commuter-account-toggle');
    const panel = document.getElementById('commuter-account-panel');
    const profileButton = document.getElementById('commuter-account-profile');
    const items = [...panel.querySelectorAll('button, a')];
    function setOpen(open, focusTrigger = false) {
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close account menu' : 'Open account menu');
      if (focusTrigger) toggle.focus();
    }
    toggle.addEventListener('click', () => setOpen(panel.hidden));
    toggle.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown') { event.preventDefault(); setOpen(true); items[0].focus(); }
    });
    panel.addEventListener('keydown', event => {
      const index = items.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
      } else if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault(); items[event.key === 'Home' ? 0 : items.length - 1].focus();
      }
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); setOpen(false, true); }
    });
    document.addEventListener('click', event => { if (!account.contains(event.target)) setOpen(false); });
    document.addEventListener('focusin', event => { if (!account.contains(event.target)) setOpen(false); });
    document.getElementById('passenger-menu')?.addEventListener('click', () => setOpen(false));
    window.addEventListener('pageshow', () => setOpen(false));
    profileButton.addEventListener('click', () => {
      setOpen(false, true);
      window.PassengerUI.openProfile(toggle);
    });
    document.getElementById('commuter-account-logout').addEventListener('click', () => {
      setOpen(false);
      logout.click();
    });
    if (location.pathname.endsWith('/user-settings.html')) panel.querySelector('a').setAttribute('aria-current', 'page');
    function updateAccount() {
      const preferences = window.PassengerUI.getPreferences();
      const name = account.querySelector('.passenger-profile-name');
      name.textContent = preferences.name || 'Commuter';
      name.title = name.textContent;
      const avatar = account.querySelector('.passenger-profile-avatar');
      const sourceAvatar = profile.querySelector('.passenger-profile-avatar');
      avatar.replaceChildren(...[...sourceAvatar.childNodes].map(node => node.cloneNode(true)));
    }
    document.addEventListener('passenger-preferences-changed', updateAccount);
    updateAccount();
  });
})();
