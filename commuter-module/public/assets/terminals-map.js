import { verifiedCoordinates, mapCoordinates, googleMapsUrl, detailsUrl, element, translate } from './terminals-common.js?v=8';

let leafletPromise;
function loadLeaflet() {
  if (window.L) return Promise.resolve(window.L);
  if (!leafletPromise) {
    leafletPromise = new Promise((resolve, reject) => {
      const css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
      css.integrity = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';
      css.crossOrigin = '';
      const script = document.createElement('script');
      script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
      script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
      script.crossOrigin = '';
      let cssReady = false, scriptReady = false, settled = false;
      const timeout = setTimeout(() => failed(), 12000);
      function failed() {
        if (settled) return;
        settled = true;
        clearTimeout(timeout);
        script.remove();
        css.remove();
        leafletPromise = null;
        reject(new Error('Map unavailable'));
      }
      function completed() {
        if (settled || !cssReady || !scriptReady) return;
        settled = true;
        clearTimeout(timeout);
        resolve(window.L);
      }
      css.onload = () => { cssReady = true; completed(); };
      css.onerror = failed;
      script.onload = () => { scriptReady = true; completed(); };
      script.onerror = failed;
      document.head.append(css, script);
    });
  }
  return leafletPromise;
}

export function terminalMap(container, status, options = {}) {
  let map, markers, tiles, version = 0;
  return async function render(records) {
    const current = ++version;
    const located = records.filter((record) => mapCoordinates(record));
    if (!located.length && !options.overviewCenter) {
      markers?.clearLayers();
      container.hidden = true;
      status.textContent = records.length
        ? translate('Map location not yet available. Exact coordinates are still to be verified.')
        : translate('No matching locations to show on the map.');
      return;
    }
    status.textContent = translate('Loading map…');
    try {
      const L = await loadLeaflet();
      if (current !== version) return;
      container.hidden = false;
      const hadView = Boolean(map);
      if (!map) {
        map = L.map(container, {
          scrollWheelZoom: true,
          ...(Number.isFinite(options.minZoom) ? { minZoom: options.minZoom } : {}),
        });
        tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).on('tileerror', () => {
          status.textContent = translate('Map tiles could not be loaded. You can still open details or use Get Directions.');
          options.onError?.();
        }).addTo(map);
        markers = L.layerGroup().addTo(map);
      }
      markers.clearLayers();
      const markerById = new Map();
      if (options.showMarkers !== false) for (const record of located) {
        const popup = element('div', 'terminal-map-popup');
        const link = element('a', '', translate('View Details'));
        link.href = detailsUrl(record.id);
        popup.append(element('strong', '', record.name), element('p', '', record.address), link);
        const googleUrl = googleMapsUrl(record);
        if (googleUrl) {
          const google = element('a', 'terminal-google-link', translate('Open in Google Maps'));
          google.href = googleUrl; google.target = '_blank'; google.rel = 'noopener noreferrer';
          popup.append(element('br'), google);
        }
        const marker = L.circleMarker(mapCoordinates(record), {
          radius: 9, color: '#ffffff', weight: 2, fillColor: '#dc2626', fillOpacity: 1,
        });
        if (options.showPopups !== false) marker.bindPopup(popup);
        marker.addTo(markers);
        markerById.set(record.id, marker);
        if (options.onSelect) marker.on('click', () => options.onSelect(record.id));
      }
      map.invalidateSize({ pan: false, animate: false });
      const positions = located.map(mapCoordinates);
      if (!hadView || !options.preserveView) {
        if (options.startWithOverview && options.overviewCenter) map.setView(options.overviewCenter, options.overviewZoom ?? 13);
        else if (positions.length === 1) map.setView(positions[0], 16);
        else if (positions.length > 1) map.fitBounds(L.latLngBounds(positions), { padding: [28, 28], maxZoom: 16 });
        else map.setView(options.overviewCenter, options.overviewZoom ?? 11);
      }
      const missing = records.length - located.length;
      const referenceCount = located.filter(record => !verifiedCoordinates(record)).length;
      status.textContent = !located.length
        ? translate('General area map. Terminal pins will appear once their coordinates are verified.')
        : (referenceCount
          ? translate('{count} mapped locations. {references} are landmark references; exact boarding points still need verification.', { count: located.length, references: referenceCount })
          : translate(located.length === 1 ? '1 verified location on the map.' : '{count} verified locations on the map.', { count: located.length }))
          + (missing ? ` ${translate('{count} awaiting verified coordinates.', { count: missing })}` : '');
      options.onReady?.({ map, leaflet: L, markers: markerById, markerLayer: markers, retryTiles: () => tiles.redraw() });
    } catch {
      if (current !== version) return;
      container.hidden = true;
      status.textContent = translate('The map is temporarily unavailable. Terminal details and directions are still available.');
      options.onError?.();
    }
  };
}
