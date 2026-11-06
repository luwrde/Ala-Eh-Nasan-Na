(() => {
  'use strict';
  const container = document.getElementById('location-editor-map');
  const status = document.getElementById('location-editor-status');
  if (!container) return;
  const latitude = document.getElementById('location-lat');
  const longitude = document.getElementById('location-lng');
  const form = document.getElementById('location-editor-form');
  const center = document.getElementById('location-center');
  const unpin = document.getElementById('location-unpin');
  let map = null;
  let marker = null;
  function validatePair() {
    latitude.setCustomValidity(''); longitude.setCustomValidity('');
    if ((latitude.value === '') !== (longitude.value === '')) {
      (latitude.value === '' ? latitude : longitude).setCustomValidity('Enter both coordinates, or leave both blank.');
    }
    center.disabled = !map || latitude.value === '' || longitude.value === '' || !latitude.validity.valid || !longitude.validity.valid;
    unpin.disabled = latitude.value === '' && longitude.value === '';
  }
  function showPin(lat, lng, zoom = false) {
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || lat < 13.45 || lat > 14.3 || lng < 120.45 || lng > 121.65) {
      status.textContent = 'Choose a location within the Batangas area.';
      return;
    }
    latitude.value = lat.toFixed(7);
    longitude.value = lng.toFixed(7);
    validatePair();
    if (!map) return;
    if (marker) marker.setLatLng([lat, lng]);
    else {
      marker = L.marker([lat, lng], { draggable: true, title: 'Location pin — drag to adjust' }).addTo(map);
      marker.on('dragend', () => { const point = marker.getLatLng(); showPin(point.lat, point.lng); });
    }
    if (zoom) map.setView([lat, lng], 17);
    status.textContent = 'Pin selected. Save location to publish it on both maps.';
  }
  function syncFields() {
    validatePair();
    if (latitude.value !== '' && longitude.value !== '' && latitude.validity.valid && longitude.validity.valid) showPin(Number(latitude.value), Number(longitude.value), true);
    else {
      if (marker && map) map.removeLayer(marker);
      marker = null;
      status.textContent = latitude.value === '' && longitude.value === '' ? 'No pin placed. Click the map or enter coordinates.' : 'Enter valid coordinates within the Batangas area.';
    }
  }
  [latitude, longitude].forEach((input) => {
    input.addEventListener('input', validatePair);
    input.addEventListener('change', syncFields);
  });
  form.addEventListener('submit', event => { validatePair(); if (!form.reportValidity()) event.preventDefault(); });
  form.addEventListener('reset', () => setTimeout(syncFields, 0));
  center.addEventListener('click', syncFields);
  unpin.addEventListener('click', () => {
    if (marker) map.removeLayer(marker);
    marker = null;
    latitude.value = ''; longitude.value = '';
    validatePair();
    status.textContent = 'Pin removed from this draft. Save location to apply the change.';
  });
  validatePair();
  if (!window.L) { status.textContent = 'Map unavailable. You can still edit details, enter coordinates, or remove the pin and save.'; return; }
  const batangasBounds = L.latLngBounds([13.45, 120.45], [14.3, 121.65]);
  map = L.map(container, {maxBounds: batangasBounds, maxBoundsViscosity: 1, minZoom: 10})
    .setView(container.dataset.area.startsWith('Mabini') ? [13.75, 120.94] : [13.775, 121.055], 13);
  const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' }).addTo(map);
  tiles.on('tileerror', () => { status.textContent = 'Some map tiles could not load. Check your connection or use the coordinate fields.'; });
  map.on('click', (event) => showPin(event.latlng.lat, event.latlng.lng));
  syncFields();
  if (window.ResizeObserver) new ResizeObserver(() => map.invalidateSize()).observe(container);
})();
