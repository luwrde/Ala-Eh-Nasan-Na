import { element } from './terminals-common.js?v=8';
import { formatDistance, validPosition } from './routing.js?v=3';
import { fetchJeepneyTraces, validTraceGeometry } from './jeepney-tracing.js?v=5';

export function usablePreview(route) {
  const preview = route?.preview;
  return route?.status === 'pending' && preview?.kind === 'road-routing-preview' && preview.route_verified === false
    && validTraceGeometry(preview.geometry) && preview.geometry.coordinates.length >= 3
    && Array.isArray(preview.points) && preview.points.length >= 2 && preview.points.length <= 30
    && preview.points.every((point, index) => point && typeof point.name === 'string'
      && validPosition([point.coordinates?.[1], point.coordinates?.[0]])
      && validPosition([point.road_coordinates?.[1], point.road_coordinates?.[0]])
      && point.role === (index === 0 ? 'start' : index === preview.points.length - 1 ? 'end' : 'via')
      && point.route_stop_verified === false);
}

export function createRoutePreview({ enabled = true, beforeShow, fetchRoutes = fetchJeepneyTraces }) {
  const get = id => document.getElementById(id);
  const toggle = get('route-preview-toggle');
  if (!toggle || !enabled) return { clear() {}, setMapState() {} };
  const panel = get('route-preview-panel'), message = get('route-preview-message');
  let state = null, route = null, group = null, line = null, outline = null;
  let loading = false, failed = false, active = false, version = 0;
  function controls() {
    toggle.hidden = !state;
    toggle.disabled = loading || !state;
    toggle.setAttribute('aria-pressed', String(active));
    get('route-preview-toggle-name').textContent = loading ? 'Loading route\u2026' : failed ? 'Route unavailable - retry' : route?.name || 'Jeepney route';
    message.hidden = !failed || !state;
    message.textContent = failed ? 'The road preview is unavailable. Tap the route button to retry.' : '';
  }
  function clear() {
    group?.remove(); group = null; line = null; outline = null; active = false;
    panel.hidden = true;
    state?.map.stop?.();
    // Changing the minimum can start a delayed Leaflet zoom. Restore it without
    // animation so a later route fit cannot be overwritten by that old zoom.
    if (state?.map.getZoom?.() < 12) state.map.setZoom(12, { animate: false });
    state?.map.setMinZoom?.(12);
    controls();
  }
  function fillDetails() {
    const points = route.preview.points;
    get('route-preview-title').textContent = route.name;
    get('route-preview-start').textContent = points[0].name;
    get('route-preview-end').textContent = points.at(-1).name;
    get('route-preview-waypoints').replaceChildren(...points.slice(1).map(point => element('li', '', point.name)));
    get('route-preview-distance').textContent = formatDistance(route.preview.distance_meters);
    get('route-preview-end-note').textContent = points.at(-1).note;
  }
  function fit() {
    if (!state || !line) return;
    const viewport = get('system-map').getBoundingClientRect?.();
    const bounds = !panel.hidden && panel.getBoundingClientRect?.();
    const padding = !viewport ? { padding: [30, 30] }
      : bounds && (viewport.width < 700 || bounds.width > viewport.width * .7)
        ? { paddingTopLeft: [20, 170], paddingBottomRight: [68, Math.max(30, viewport.bottom - bounds.top + 12)] }
        : bounds ? { paddingTopLeft: [bounds.width + 28, 170], paddingBottomRight: [68, 30] }
          : { paddingTopLeft: [20, 170], paddingBottomRight: [68, 30] };
    state.map.setMinZoom(8);
    state.map.invalidateSize({ pan: false, animate: false });
    state.map.fitBounds(line.getBounds(), { ...padding, maxZoom: 16, animate: false });
  }
  function select(fitRoute = false) {
    if (!active) return;
    panel.hidden = false;
    outline.setStyle({ weight: 11 }); line.setStyle({ weight: 7, opacity: 1 });
    if (fitRoute) fit();
  }
  function popup(point) {
    const content = element('div', 'terminal-map-popup');
    content.append(element('strong', '', point.name), element('p', '', point.note), element('p', '', `Map reference: ${point.reference_name}`));
    try {
      const url = new URL(point.source_url);
      if (url.protocol === 'https:' && !url.username && !url.password) {
        const source = element('a', '', 'Open mapped reference');
        source.href = url.href; source.target = '_blank'; source.rel = 'noopener noreferrer'; content.append(source);
      }
    } catch { /* Missing or unsafe references do not become links. */ }
    return content;
  }
  function show() {
    if (!state || !usablePreview(route)) { failed = true; controls(); return; }
    clear();
    beforeShow();
    const L = state.leaflet;
    try {
      group = L.layerGroup().addTo(state.map);
      const stroke = { lineCap: 'round', lineJoin: 'round', dashArray: null };
      outline = L.geoJSON(route.preview.geometry, { interactive: false, style: { ...stroke, color: '#ffffff', weight: 9, opacity: .95 }, smoothFactor: .6 }).addTo(group);
      line = L.geoJSON(route.preview.geometry, { style: { ...stroke, color: '#2563eb', weight: 5, opacity: .9 }, smoothFactor: .6 }).addTo(group);
      line.on('click', () => select());
      line.eachLayer?.(layer => {
        const path = layer.getElement?.();
        if (!path) return;
        path.setAttribute('tabindex', '0'); path.setAttribute('role', 'button'); path.setAttribute('aria-label', `Show ${route.name}, Pending Verification`);
        path.addEventListener('keydown', event => { if (['Enter', ' '].includes(event.key)) { event.preventDefault(); select(); } });
      });
      for (const point of route.preview.points) {
        const start = point.role === 'start', end = point.role === 'end';
        L.circleMarker([point.road_coordinates[1], point.road_coordinates[0]], {
          radius: start || end ? 9 : 5, weight: 2, color: '#fff', fillOpacity: 1,
          fillColor: start || end ? '#16a34a' : '#64748b',
        }).bindPopup(popup(point)).addTo(group);
      }
      active = true; fillDetails(); select(true); controls();
    } catch {
      clear(); failed = true; controls();
    }
  }
  async function load() {
    const current = ++version; loading = true; failed = false; controls();
    try {
      const routes = await fetchRoutes();
      if (current !== version) return;
      const candidate = routes.find(item => item.slug === 'grand-terminal-to-mabini');
      if (!usablePreview(candidate)) throw new Error('No usable road preview');
      route = candidate;
    } catch { if (current === version) { route = null; failed = true; } }
    finally { if (current === version) { loading = false; controls(); } }
  }
  toggle.addEventListener('click', async () => {
    if (active) { clear(); return; }
    if (failed || !route) await load();
    if (route && state && !failed) show();
  });
  get('route-preview-fit').addEventListener('click', () => select(true));
  get('route-preview-minimize').addEventListener('click', () => {
    panel.hidden = true; line?.setStyle({ weight: 5, opacity: .9 }); outline?.setStyle({ weight: 9 });
    toggle.focus({ preventScroll: true });
  });
  get('route-preview-hide').addEventListener('click', clear);
  load();
  return { clear, setMapState(mapState) { state = mapState; controls(); } };
}
