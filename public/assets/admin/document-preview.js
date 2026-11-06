(() => {
  'use strict';
  const dialog = document.getElementById('document-preview-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const canvas = dialog.querySelector('.dp-canvas');
  const loading = dialog.querySelector('.dp-loading');
  const error = dialog.querySelector('.dp-error');
  let source, controller, objectUrl, generation = 0, previousOverflow = '';
  function clearPreview() {
    generation++;
    controller?.abort();
    canvas.replaceChildren();
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = null;
  }
  async function loadPreview() {
    clearPreview();
    const current = generation;
    controller = new AbortController();
    const requestController = controller;
    loading.hidden = false;
    error.hidden = true;
    const url = new URL(source.href);
    url.searchParams.set('preview', '1');
    const timeout = setTimeout(() => requestController.abort(), 30000);
    try {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      if (response.redirected || response.status === 401 || response.status === 403) throw new Error('Your session may have expired. Sign in again to preview this document.');
      if (!response.ok) throw new Error('This document could not be loaded. Please try again or download the file.');
      const mime = (response.headers.get('content-type') || '').split(';')[0].trim();
      if (!['image/jpeg', 'image/png', 'application/pdf'].includes(mime)) throw new Error('This file cannot be previewed. Please download it to view.');
      const blob = await response.blob();
      if (current !== generation || !dialog.open) return;
      objectUrl = URL.createObjectURL(blob);
      const preview = document.createElement(mime === 'application/pdf' ? 'iframe' : 'img');
      if (mime === 'application/pdf') preview.title = 'PDF preview: ' + source.dataset.name;
      else {
        preview.alt = source.dataset.name;
        preview.addEventListener('error', () => {
          if (current !== generation) return;
          canvas.replaceChildren();
          error.querySelector('.dp-error-message').textContent = 'The image could not be displayed. Download the original file to view it.';
          error.hidden = false;
        }, { once: true });
      }
      preview.src = objectUrl;
      canvas.append(preview);
    } catch (failure) {
      if (current !== generation || !dialog.open) return;
      error.querySelector('.dp-error-message').textContent = failure.name === 'AbortError' ? 'The preview took too long to load. Please try again.' : failure.message;
      error.hidden = false;
    } finally {
      clearTimeout(timeout);
      if (current === generation) loading.hidden = true;
    }
  }
  document.querySelectorAll('[data-document-preview]').forEach(link => link.addEventListener('click', event => {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (dialog.open) return;
    source = link;
    dialog.querySelector('#document-preview-title').textContent = link.dataset.name;
    ['driver', 'type', 'uploaded'].forEach(key => { dialog.querySelector('#dp-' + key).textContent = link.dataset[key]; });
    const status = dialog.querySelector('#dp-status');
    const state = ['approved', 'rejected', 'pending'].includes(link.dataset.status) ? link.dataset.status : 'pending';
    status.className = 'dp-status ' + state;
    status.textContent = { approved: 'Approved', rejected: 'Rejected', pending: 'Pending review' }[state];
    const note = dialog.querySelector('.dp-note');
    note.hidden = !link.dataset.note;
    note.querySelector('p').textContent = link.dataset.note || '';
    dialog.querySelector('.dp-download').href = link.href;
    const review = dialog.querySelector('.dp-review');
    review.replaceChildren();
    const original = link.closest('tr').querySelector('form');
    if (original) {
      const heading = document.createElement('h3');
      heading.textContent = 'Review document';
      const form = original.cloneNode(true);
      form.className = 'dp-review-form';
      const input = form.querySelector('[name="note"]');
      input.id = 'dp-review-note';
      input.value = original.querySelector('[name="note"]').value;
      const label = document.createElement('label');
      label.htmlFor = input.id;
      label.textContent = 'Review note (optional)';
      input.before(label);
      input.addEventListener('input', () => { original.querySelector('[name="note"]').value = input.value; });
      review.append(heading, form);
    } else {
      review.textContent = state === 'pending'
        ? 'Your account has view-only access.'
        : 'Review completed. This document has been ' + state + '.';
    }
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    dialog.showModal();
    loadPreview();
  }));
  dialog.querySelector('.dp-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('keydown', event => {
    if (event.key === 'Escape') { event.preventDefault(); dialog.close(); }
  });
  dialog.querySelector('.dp-retry').addEventListener('click', loadPreview);
  dialog.addEventListener('click', event => {
    const bounds = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
  });
  dialog.addEventListener('close', () => {
    clearPreview();
    document.body.style.overflow = previousOverflow;
    source?.focus();
  });
})();
