import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const common = moduleUrl(await readFile(new URL('../public/assets/terminals-common.js', import.meta.url), 'utf8'));
const routing = moduleUrl(await readFile(new URL('../public/assets/routing.js', import.meta.url), 'utf8'));
const source = (await readFile(new URL('../public/assets/jeepney-tracing.js', import.meta.url), 'utf8'))
  .replace(/'\.\/terminals-common\.js(?:\?[^']*)?'/, JSON.stringify(common))
  .replace(/'\.\/routing\.js(?:\?[^']*)?'/, JSON.stringify(routing));
const { createJeepneyTracing, drawJeepneyTrace, matchingTraces, validTraceGeometry } = await import(moduleUrl(source));
const html = await readFile(new URL('../public/user-map.html', import.meta.url), 'utf8');
const terminal = (id, name, municipality, longitude) => ({ id, name, address: 'SYNTHETIC TEST ADDRESS', municipality,
  coordinates_verified: true, coordinates: { latitude: 0, longitude } });
const terminals = [terminal(1, 'TEST START', 'Batangas City', 0), terminal(2, 'TEST OTHER START', 'Batangas City', .001), terminal(4, 'TEST END', 'Mabini', .02)];
const geometry = { type: 'LineString', coordinates: [[0,0],[.005,.002],[.01,.002],[.015,.001],[.02,0]] };
const verified = { id: 10, name: 'TEST OUTBOUND', start_terminal_id: 1, end_terminal_id: 4, travel_direction: 'batangas-to-mabini',
  geometry, status: 'verified', distance_meters: 2300, verification_source: 'TEST ONLY', updated_at: '2026-11-01T00:00:00Z',
  roads: ['TEST ROAD A', 'TEST ROAD B'], stops: [{ name: '<img src=x>', coordinates: [.005,.002], verified: true, kind: 'stop', verification_source: 'TEST SOURCE' }] };

function fixture() {
  const nodes = new Map();
  const node = () => ({ value: '', textContent: '', hidden: false, disabled: false, children: [], events: {},
    append(...items) { this.children.push(...items); }, replaceChildren(...items) { this.children = items; },
    addEventListener(event, callback) { this.events[event] = callback; } });
  for (const match of html.matchAll(/\bid="([^"]+)"/g)) nodes.set(match[1], node());
  globalThis.document = { getElementById: id => nodes.get(id), createElement: node };
  const calls = { geometries: [], styles: [], markers: [], popups: [], bounds: [], groups: [], zooms: [], previews: [], before: 0, reset: 0 };
  const map = { setMinZoom: zoom => calls.zooms.push(zoom), invalidateSize() {}, fitBounds: (...args) => calls.bounds.push(args) };
  const state = { map, leaflet: {
    layerGroup: () => ({ addTo() { calls.groups.push(this); return this; }, remove() { this.removed = true; } }),
    geoJSON(path, options) {
      calls.geometries.push(path); calls.styles.push(options.style);
      return { addTo() { return this; }, bringToFront() {}, getBounds: () => path.coordinates };
    },
    circleMarker(point, options) {
      calls.markers.push({ point, options });
      return { bindPopup(popup) { calls.popups.push(popup); return this; }, addTo() { return this; } };
    },
    icon: options => options,
    marker(point, options) {
      calls.markers.push({ point, options });
      return { bindPopup(popup) { calls.popups.push(popup); return this; }, addTo() { return this; } };
    },
  } };
  return { nodes, calls, state };
}
async function browser(routes = [], preview = async () => ({ geometry, distance: 2300 }), failLoad = false) {
  const f = fixture();
  const controller = createJeepneyTracing({ getMapState: () => f.state,
    requestPreview: (...args) => { f.calls.previews.push(args); return preview(...args); },
    beforeShow: () => f.calls.before++, onReset: () => f.calls.reset++,
    fetchRoutes: async () => { if (failLoad) throw new Error('offline'); return routes; },
  });
  controller.setTerminals(terminals);
  await new Promise(resolve => setImmediate(resolve));
  return { ...f, controller };
}

test('hidden tracing card stays inactive while the rest of Map can call its controller', () => {
  const f = fixture();
  f.nodes.get('jeepney-tracing-panel').hidden = true;
  const unexpected = () => { throw new Error('Hidden tracing must not load or draw routes'); };
  const controller = createJeepneyTracing({ getMapState: unexpected, requestPreview: unexpected,
    beforeShow: unexpected, onReset: unexpected, fetchRoutes: unexpected });
  controller.setTerminals(terminals);
  controller.setStartTerminal(1);
  controller.setMapState(f.state);
  controller.clear();
  assert.equal(f.calls.geometries.length, 0);
  assert.equal(f.calls.groups.length, 0);
});

test('verified stored road geometry, endpoints, source, and safe stop popups are displayed without routing', async () => {
  const f = await browser([verified]);
  assert.equal(f.calls.previews.length, 0);
  assert.equal(f.calls.geometries.at(-1), geometry);
  assert.equal(f.calls.styles.at(-1).color, '#2563eb');
  assert.equal(f.calls.styles.at(-1).dashArray, null);
  assert.equal(f.calls.markers[0].options.icon.iconUrl, 'assets/jeepney-route-start.png');
  assert.deepEqual(f.calls.markers.slice(1).map(item => item.options.fillColor), ['#16a34a', '#2563eb']);
  assert.deepEqual(f.calls.markers[0].point, [0,0]);
  assert.deepEqual(f.calls.markers[1].point, [0,.02]);
  assert.equal(f.calls.popups[2].children[0].textContent, '<img src=x>');
  assert.equal(f.nodes.get('trace-verification').textContent, 'Verified actual jeepney route');
  assert.equal(f.nodes.get('trace-detail-distance').textContent, '2.3 km');
  assert.equal(f.nodes.get('trace-roads').children[0].textContent, 'TEST ROAD A');
  assert.deepEqual(f.calls.bounds.at(-1)[0], geometry.coordinates);
  assert.equal(f.calls.zooms.at(-1), 8);
});

test('jeepney start marker matches the route start in Leaflet coordinate order', () => {
  const f = fixture();
  const route = { ...verified, geometry: { type: 'LineString', coordinates: [[121.04, 13.78], [121.03, 13.77], [120.94, 13.75]] } };
  drawJeepneyTrace(f.state, route, terminals[0], terminals[2]);
  assert.deepEqual(f.calls.markers[0].point, [13.78, 121.04]);
  assert.deepEqual(f.calls.markers[1].point, [13.75, 120.94]);
  drawJeepneyTrace(f.state, route, terminals[0], terminals[2], { endpointsOnly: true });
  const startMarker = f.calls.markers.at(-2);
  assert.deepEqual(startMarker.point, [13.78, 121.04]);
  assert.deepEqual(f.calls.geometries.at(-1), route.geometry);
  assert.deepEqual(startMarker.options.icon.iconAnchor, [40, 15]);
  assert.match(startMarker.options.alt, /Jeepney at route start/);
});

test('Reverse Route selects its own saved geometry, and a missing return trace draws no substitute line', async () => {
  const returnGeometry = { type: 'LineString', coordinates: [[.02,0],[.015,-.001],[.01,-.002],[.005,-.001],[0,0]] };
  const reverse = { ...verified, id: 11, name: 'TEST RETURN', start_terminal_id: 4, end_terminal_id: 1,
    travel_direction: 'mabini-to-batangas', geometry: returnGeometry, stops: [] };
  const f = await browser([verified, reverse]);
  f.nodes.get('trace-reverse').events.click();
  assert.equal(f.nodes.get('trace-detail-start').textContent, 'TEST END');
  assert.equal(f.nodes.get('trace-end').value, '1');
  assert.equal(f.calls.geometries.at(-1), returnGeometry);
  assert.notDeepEqual(returnGeometry.coordinates, [...geometry.coordinates].reverse());
  const missing = await browser([verified]);
  missing.nodes.get('trace-reverse').events.click();
  assert.equal(missing.calls.geometries.length, 1);
  assert.equal(missing.calls.groups.at(-1).removed, true);
  assert.match(missing.nodes.get('trace-status').textContent, /unavailable/);
  assert.equal(missing.nodes.get('trace-show').disabled, true);
  assert.equal(missing.calls.previews.length, 0);
});

test('origin follows the selected map terminal without direction or starting-terminal dropdowns', async () => {
  const f = await browser([]);
  assert.equal(f.nodes.has('trace-start'), false);
  assert.equal(f.nodes.has('trace-direction'), false);
  f.controller.setStartTerminal(2);
  assert.equal(f.nodes.get('trace-detail-start').textContent, 'TEST OTHER START');
  assert.equal(f.nodes.get('trace-detail-direction').textContent, 'Batangas City to Mabini');
  assert.equal(f.nodes.get('trace-end').value, '4');
  f.controller.setStartTerminal(4);
  assert.equal(f.nodes.get('trace-detail-start').textContent, 'TEST END');
  assert.equal(f.nodes.get('trace-detail-direction').textContent, 'Mabini to Batangas City');
  assert.equal(f.nodes.get('trace-end').value, '1');
  await f.nodes.get('trace-preview').events.click();
  assert.deepEqual(f.calls.previews.at(-1).slice(0, 2), [[0,.02],[0,0]]);
});

test('driving previews stay unverified and never publish inferred jeepney roads or stops', async () => {
  const f = await browser([]);
  assert.match(f.nodes.get('trace-status').textContent, /unavailable/);
  await f.nodes.get('trace-preview').events.click();
  assert.equal(f.calls.previews[0][2], 'driving');
  assert.deepEqual(f.calls.previews[0].slice(0, 2), [[0,0],[0,.02]]);
  assert.equal(f.nodes.get('trace-verification').textContent, 'Unverified driving preview');
  assert.match(f.nodes.get('trace-status').textContent, /not a confirmed jeepney route/);
  assert.equal(f.calls.styles.at(-1).dashArray, '10 7');
  assert.equal(f.calls.markers.length, 2);
  assert.match(f.nodes.get('trace-roads').children[0].textContent, /Not yet available/);
  assert.match(f.nodes.get('trace-stops').children[0].textContent, /No verified/);
});

test('direction changes cancel stale previews; Reset Map removes layers and restores city zoom rules', async () => {
  let resolve;
  const f = await browser([], () => new Promise(done => { resolve = done; }));
  const pending = f.nodes.get('trace-preview').events.click();
  const signal = f.calls.previews[0][3];
  f.nodes.get('trace-reverse').events.click();
  assert.equal(signal.aborted, true);
  resolve({ geometry, distance: 2300 }); await pending;
  assert.equal(f.calls.geometries.length, 0);
  const drawn = await browser([verified]);
  drawn.nodes.get('trace-reset').events.click();
  assert.equal(drawn.calls.groups.at(-1).removed, true);
  assert.equal(drawn.nodes.get('trace-legend').hidden, true);
  assert.equal(drawn.calls.zooms.at(-1), 12);
  assert.equal(drawn.calls.reset, 1);
});

test('API/provider failures, missing coordinates, and drafts remain honest and do not fabricate geometry', async () => {
  const offline = await browser([], async () => { throw Object.assign(new Error('offline'), { code: 'unavailable' }); }, true);
  assert.equal(offline.nodes.get('trace-retry').hidden, false);
  await offline.nodes.get('trace-preview').events.click();
  assert.match(offline.nodes.get('trace-status').textContent, /No replacement line/);
  assert.equal(offline.calls.geometries.length, 0);
  offline.controller.setTerminals(terminals.map(item => ({ ...item, coordinates_verified: false, coordinates: null })));
  assert.equal(offline.nodes.get('trace-preview').disabled, true);
  const draft = await browser([{ ...verified, status: 'draft' }]);
  assert.equal(draft.nodes.get('trace-verification').textContent, 'Unverified imported trace');
  assert.equal(draft.calls.markers.length, 2);
  assert.match(draft.nodes.get('trace-roads').children[0].textContent, /Not yet available/);
  assert.equal(validTraceGeometry({ type: 'LineString', coordinates: [[0,0],[181,0]] }), false);
  assert.throws(() => drawJeepneyTrace(draft.state, { geometry: null }, terminals[0], terminals[2]));
  assert.equal(matchingTraces([verified], 4, 1, 'mabini-to-batangas').length, 0);
});
