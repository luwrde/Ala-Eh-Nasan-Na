(() => {
  'use strict';
  const form = document.getElementById('trip-form');
  if (!form) return;
  const journeyStorageKey = form.dataset.journeyStorageKey || 'alaEhJourney:public';
  const initial = form.querySelector('input[name="trip_status"]:checked')?.value;
  const status = document.getElementById('trip-save-status');
  const original = status.textContent;
  if (form.dataset.tripStatus === 'out') {
    try { localStorage.removeItem(journeyStorageKey); } catch (_) { /* Storage may be disabled. */ }
  }
  form.addEventListener('change', () => {
    const selected = form.querySelector('input[name="trip_status"]:checked');
    status.textContent = selected?.value === initial ? original : 'Unsaved change — select Save trip status to update your status.';
    document.querySelector('.trip-save-message')?.remove();
  });
})();
