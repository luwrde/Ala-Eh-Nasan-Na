import { mapCoordinates, element } from './terminals-common.js?v=8';
import { formatDistance, validPosition } from './routing.js?v=2';

export const directions = {
  'batangas-to-mabini': { label: 'Batangas City to Mabini', start: 'Batangas City', end: 'Mabini', reverse: 'mabini-to-batangas' },
  'mabini-to-batangas': { label: 'Mabini to Batangas City', start: 'Mabini', end: 'Batangas City', reverse: 'batangas-to-mabini' },
};

export function matchingTraces(routes, start, end, direction) {
  return routes.filter(route => route.start_terminal_id === Number(start) && route.end_terminal_id === Number(end)
    && route.travel_direction === direction);
}

export function validTraceGeometry(geometry) {
  return geometry?.type === 'LineString' && Array.isArray(geometry.coordinates) && geometry.coordinates.length >= 2
    && geometry.coordinates.length <= 50000 && geometry.coordinates.every(point => Array.isArray(point)
      && point.length === 2 && validPosition([point[1], point[0]]));
}

/** Draw only supplied road geometry. End markers use the trace endpoints, never connecting lines. */
export function drawJeepneyTrace(state, route, start, end, { endpointsOnly = false } = {}) {
  if (!state || !validTraceGeometry(route.geometry)) throw new Error('Invalid road geometry');
  const verified = route.status === 'verified';
  const L = state.leaflet;
  const group = L.layerGroup().addTo(state.map);
  try {
    const points = route.geometry.coordinates;
    const routeGeometry = route.geometry;
    const line = L.geoJSON(routeGeometry, { style: { color: verified ? '#2563eb' : '#d97706', weight: 6,
      opacity: .95, dashArray: verified ? null : '10 7' }, smoothFactor: 0 }).addTo(group);
    line.bringToFront();
    const marker = (point, title, description, color) => {
      const popup = element('div', 'terminal-map-popup');
      popup.append(element('strong', '', title), element('p', '', description));
      L.circleMarker([point[1], point[0]], { radius: 10, color: '#ffffff', weight: 3, fillColor: color, fillOpacity: 1 })
        .bindPopup(popup).addTo(group);
    };
    // GeoJSON is longitude/latitude; Leaflet markers use latitude/longitude.
    const firstPoint = routeGeometry.coordinates[0];
    const startPopup = element('div', 'terminal-map-popup');
    startPopup.append(element('strong', '', `Start: ${start.name}`),
      element('p', '', route.isGeneralRoute ? 'General service route; the assigned starting point is not mapped.' : verified ? 'Starting point of the driver route.' : 'Start reference on an unverified path.'));
    L.marker([firstPoint[1], firstPoint[0]], {
      icon: L.icon({
        iconUrl: 'assets/jeepney-route-start.png',
        iconSize: [80, 30], iconAnchor: [40, 15], popupAnchor: [0, -18],
        className: 'jeepney-route-start',
      }),
      title: `Route start: ${start.name}`, alt: `Jeepney at route start: ${start.name}`, zIndexOffset: 1200,
    }).bindPopup(startPopup).addTo(group);
    marker(points.at(-1), `Endpoint: ${end.name}`, verified ? 'End of the verified road trace.' : 'Endpoint reference on an unverified path.', '#16a34a');
    if (verified && !endpointsOnly) {
      for (const stop of route.stops || []) {
        if (stop.verified === true && stop.verification_source && validPosition([stop.coordinates?.[1], stop.coordinates?.[0]])) {
          marker(stop.coordinates, stop.name, `Verified ${stop.kind === 'landmark' ? 'landmark' : 'stop'}. ${stop.verification_source}`, '#2563eb');
        }
      }
    }
    // A full corridor must fit on narrow screens; the ordinary city view keeps its usual zoom floor.
    state.map.setMinZoom(8);
    state.map.invalidateSize({ pan: false, animate: false });
    state.map.fitBounds(line.getBounds(), { padding: [30, 30], maxZoom: 17, animate: false });
    return group;
  } catch (error) { group.remove(); throw error; }
}

export async function fetchJeepneyTraces() {
  const response = await fetch(new URL('../api/jeepney-routes.php', import.meta.url), { cache: 'no-store', signal: AbortSignal.timeout(12000) });
  if (!response.ok) throw new Error('Saved routes unavailable');
  const data = await response.json();
  if (!Array.isArray(data.routes)) throw new Error('Invalid route response');
  return data.routes;
}

export function createJeepneyTracing({ getMapState, requestPreview, beforeShow, onReset, fetchRoutes = fetchJeepneyTraces }) {
  const get = id => document.getElementById(id);
  if (!get('jeepney-trace-form') || get('jeepney-tracing-panel')?.hidden) {
    return { clear() {}, setTerminals() {}, setStartTerminal() {}, setMapState() {} };
  }
  const form = get('jeepney-trace-form'), end = get('trace-end');
  const variant = get('trace-variant'), show = get('trace-show'), preview = get('trace-preview');
  const reverse = get('trace-reverse'), reset = get('trace-reset'), retry = get('trace-retry');
  let terminals = [], routes = [], layer = null, controller = null, version = 0;
  let fetching = false, fetchFailed = false, busy = false, displayed = null, pendingSaved = false;
  let startId = null, travelDirection = 'batangas-to-mabini';
  const selectedRoute = () => matchingTraces(routes, startId, end.value, travelDirection).find(route => route.id === Number(variant.value));
  const endpoints = () => [terminals.find(item => item.id === startId), terminals.find(item => item.id === Number(end.value))];
  function clear() {
    version++; controller?.abort(); controller = null; busy = false;
    layer?.remove(); layer = null; displayed = null; pendingSaved = false;
    getMapState()?.map.setMinZoom(12);
    get('trace-legend').hidden = true;
  }
  function fill(node, items, previous) {
    node.replaceChildren(...items.map(item => {
      const option = element('option', '', item.name); option.value = String(item.id); return option;
    }));
    node.value = String(items.find(item => String(item.id) === String(previous))?.id ?? items[0]?.id ?? '');
    node.disabled = !items.length;
  }
  function fillVariants() {
    const matches = matchingTraces(routes, startId, end.value, travelDirection);
    fill(variant, matches, variant.value);
    get('trace-variant-field').hidden = matches.length < 2;
  }
  function info() {
    const [origin, destination] = endpoints();
    const route = displayed || selectedRoute();
    const verified = route?.status === 'verified' && validTraceGeometry(route.geometry);
    const hasGeometry = validTraceGeometry(route?.geometry);
    get('trace-detail-start').textContent = origin?.name || 'Not yet available';
    get('trace-detail-end').textContent = destination?.name || 'Not yet available';
    get('trace-detail-direction').textContent = directions[travelDirection].label;
    get('trace-detail-distance').textContent = hasGeometry && Number.isFinite(route.distance_meters) ? formatDistance(route.distance_meters) : 'Not yet available';
    get('trace-detail-source').textContent = verified ? route.verification_source : 'To be verified';
    get('trace-detail-updated').textContent = route?.updated_at && Number.isFinite(Date.parse(route.updated_at)) ? new Date(route.updated_at).toLocaleString() : 'Not yet available';
    const roads = verified ? route.roads || [] : [];
    const stops = verified ? route.stops || [] : [];
    get('trace-roads').replaceChildren(...(roads.length ? roads.map(road => element('li', '', road)) : [element('li', '', 'Not yet available. Roads require route verification.')]));
    get('trace-stops').replaceChildren(...(stops.length ? stops.map(stop => element('li', '', stop.name)) : [element('li', '', 'Not yet available. No verified intermediate stops configured.')]));
    const label = verified ? 'Verified actual jeepney route' : route?.status === 'preview' ? 'Unverified driving preview'
      : hasGeometry ? 'Unverified imported trace' : 'Verification pending';
    const badge = get('trace-verification');
    badge.textContent = label; badge.className = `trace-verification${verified ? ' is-verified' : ''}`;
    get('trace-detail-status').textContent = label;
    let message = 'Verified jeepney road path unavailable for this selection. A driving preview may use different roads.';
    if (fetching) message = 'Loading saved jeepney paths…';
    else if (fetchFailed) message = 'Saved paths could not be loaded. Try again; driving previews remain unverified.';
    else if (busy) message = 'Generating an unverified driving preview…';
    else if (displayed) message = verified ? 'Showing the stored, verified jeepney road trace.'
      : route.status === 'preview' ? 'Unverified driving preview — this is not a confirmed jeepney route. Pins identify mapped references; exact boarding points may differ.'
      : 'Unverified imported trace — the actual jeepney path still requires review.';
    else if (hasGeometry) message = 'A saved path is available. Choose Show saved route to display it.';
    if (route?.verification_issue) message = route.verification_issue;
    get('trace-status').textContent = message;
    const mapReady = Boolean(getMapState());
    show.disabled = !mapReady || fetching || busy || !validTraceGeometry(selectedRoute()?.geometry);
    preview.disabled = !mapReady || fetching || busy || !origin || !destination || !mapCoordinates(origin) || !mapCoordinates(destination);
    reverse.disabled = !origin || !destination;
    reset.disabled = !mapReady;
    retry.hidden = !fetchFailed;
  }
  function draw(route) {
    const [origin, destination] = endpoints();
    if (!getMapState() || !origin || !destination || !validTraceGeometry(route?.geometry)) return;
    beforeShow();
    layer?.remove();
    layer = drawJeepneyTrace(getMapState(), route, origin, destination);
    displayed = route;
    get('trace-legend').hidden = false;
    get('trace-line-key').className = route.status === 'verified' ? 'trace-line-key' : 'trace-line-key is-preview';
    get('trace-line-label').textContent = route.status === 'verified' ? 'Verified jeepney path' : 'Unverified path';
    info();
  }
  function showSaved() {
    clear(); info();
    try { draw(selectedRoute()); }
    catch { clear(); info(); get('trace-status').textContent = 'The saved path could not be displayed. Please try again.'; }
  }
  function selectionChanged(autoShow = true) {
    clear(); fillVariants(); info();
    if (autoShow && selectedRoute()?.geometry) {
      if (getMapState()) showSaved();
      else pendingSaved = true;
    }
  }
  function fillEndpoints(originId, endId, autoShow = true) {
    const origin = terminals.find(item => item.id === Number(originId))
      || terminals.find(item => item.municipality === directions[travelDirection].start);
    startId = origin?.id ?? null;
    travelDirection = origin?.municipality === 'Mabini' ? 'mabini-to-batangas' : 'batangas-to-mabini';
    const towns = directions[travelDirection];
    fill(end, terminals.filter(item => item.municipality === towns.end), endId);
    selectionChanged(autoShow);
  }
  async function load() {
    fetching = true; fetchFailed = false; info();
    try { routes = await fetchRoutes(); }
    catch { fetchFailed = true; }
    finally { fetching = false; selectionChanged(); }
  }
  async function previewRoute() {
    const [origin, destination] = endpoints();
    const from = origin && mapCoordinates(origin), to = destination && mapCoordinates(destination);
    if (!from || !to || !getMapState()) return;
    clear(); beforeShow(); busy = true; info();
    const current = version;
    controller = new AbortController();
    try {
      const result = await requestPreview(from, to, 'driving', controller.signal);
      if (current !== version) return;
      busy = false;
      draw({ status: 'preview', geometry: result.geometry, distance_meters: result.distance, roads: [], stops: [] });
    } catch (error) {
      if (current !== version || error.name === 'AbortError') return;
      busy = false; info();
      get('trace-status').textContent = error.code === 'no-route' ? 'No driving preview was found for these references.'
        : error.code === 'busy' ? 'The routing service is busy. Please try again shortly.'
        : 'The driving preview is unavailable. No replacement line was drawn.';
    } finally { if (current === version) { controller = null; busy = false; } }
  }
  form.addEventListener('submit', event => { event.preventDefault(); showSaved(); });
  end.addEventListener('change', () => selectionChanged());
  variant.addEventListener('change', () => { clear(); info(); showSaved(); });
  preview.addEventListener('click', previewRoute);
  reverse.addEventListener('click', () => {
    // Look up the other directional record; never reverse an existing geometry.
    fillEndpoints(end.value, startId);
  });
  reset.addEventListener('click', () => {
    clear(); travelDirection = 'batangas-to-mabini';
    const towns = directions[travelDirection];
    startId = terminals.find(item => item.municipality === towns.start)?.id ?? null;
    fill(end, terminals.filter(item => item.municipality === towns.end));
    fillVariants(); info(); onReset();
  });
  retry.addEventListener('click', load);
  info(); load();
  return {
    clear() { clear(); info(); },
    setTerminals(records, selectedId = startId) { terminals = records; fillEndpoints(selectedId, end.value); },
    setStartTerminal(id) { fillEndpoints(id, end.value, false); },
    setMapState() { info(); if (pendingSaved) showSaved(); },
  };
}
