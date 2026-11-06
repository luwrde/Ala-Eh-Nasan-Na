(() => {
  'use strict';

  const input = document.getElementById('profile-photo-upload');
  const preview = document.querySelector('.profile-photo-preview');
  const status = document.getElementById('profile-photo-preview-status');
  if (!(input instanceof HTMLInputElement) || !(preview instanceof HTMLElement) || !(status instanceof HTMLElement)) return;

  const savedPreview = preview.innerHTML;
  let previewUrl = null;

  input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = null;
    }

    if (!file) {
      preview.innerHTML = savedPreview;
      status.textContent = '';
      return;
    }

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
      preview.innerHTML = savedPreview;
      status.textContent = file.size > 5 * 1024 * 1024
        ? 'The photo must be 5 MB or smaller.'
        : 'Choose a JPEG, PNG, or WebP photo to preview.';
      input.setCustomValidity(status.textContent);
      return;
    }

    input.setCustomValidity('');
    previewUrl = URL.createObjectURL(file);
    const selectedUrl = previewUrl;
    const image = new Image();
    image.alt = '';
    image.onload = () => {
      if (previewUrl !== selectedUrl) return;
      if (image.naturalWidth > 6000 || image.naturalHeight > 6000
        || image.naturalWidth * image.naturalHeight > 16000000) {
        URL.revokeObjectURL(selectedUrl);
        previewUrl = null;
        preview.innerHTML = savedPreview;
        status.textContent = 'Choose a photo with dimensions no larger than 6000 by 6000 pixels.';
        input.setCustomValidity(status.textContent);
        return;
      }

      preview.replaceChildren(image);
      status.textContent = `Previewing ${file.name}. Upload photo to save it to your profile.`;
    };
    image.onerror = () => {
      if (previewUrl !== selectedUrl) return;
      URL.revokeObjectURL(selectedUrl);
      previewUrl = null;
      preview.innerHTML = savedPreview;
      status.textContent = 'The selected file could not be previewed as an image.';
      input.setCustomValidity(status.textContent);
    };
    image.src = previewUrl;
  });

  window.addEventListener('pagehide', () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  });
})();
