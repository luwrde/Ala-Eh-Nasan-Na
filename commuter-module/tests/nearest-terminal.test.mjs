import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const common = moduleUrl(await readFile(new URL('../public/assets/terminals-common.js', import.meta.url), 'utf8'));
const routing = moduleUrl(await readFile(new URL('../public/assets/routing.js', import.meta.url), 'utf8'));
const source = (await readFile(new URL('../public/assets/nearest-terminal.js', import.meta.url), 'utf8'))
  .replace(/'\.\/terminals-common\.js(?:\?[^']*)?'/, JSON.stringify(common))
  .replace(/'\.\/routing\.js(?:\?[^']*)?'/, JSON.stringify(routing));
const { closestTerminal, terminalProximity } = await import(moduleUrl(source));
const record = (id, latitude, longitude) => ({ id, coordinates_verified: true, coordinates: { latitude, longitude } });

test('closest location uses spherical proximity, independent of directory order', () => {
  const far = record(1, 0, 1), near = record(2, 0, .1);
  assert.equal(closestTerminal([far, near], [0, 0]), near);
  // Longitude distances become much shorter near the poles.
  const east = record(3, 80, 1), south = record(4, 79.5, 0);
  assert.equal(closestTerminal([south, east], [80, 0]), east);
  assert.equal(closestTerminal([record(5, 0, 170), record(6, 0, -179.9)], [0, 179.9]).id, 6);
});

test('verified landmarks participate without promoting pending boarding coordinates', () => {
  const landmark = { id: 2, coordinates_verified: false, coordinates: null,
    landmark_coordinates_verified: true, landmark_coordinates: { latitude: 0, longitude: .01 } };
  const unchecked = { ...record(3, 0, 0), coordinates_verified: false };
  assert.equal(closestTerminal([record(1, 0, 1), unchecked, landmark], [0, 0]), landmark);
  assert.equal(landmark.coordinates, null);
  assert.equal(landmark.coordinates_verified, false);
});

test('unavailable GPS or references never produce an invented closest terminal', () => {
  const references = [record(1, 0, 0)];
  for (const origin of [null, undefined, [91, 0], [0, NaN], ['0', 0]]) assert.equal(closestTerminal(references, origin), null);
  assert.equal(closestTerminal([], [0, 0]), null);
  assert.equal(closestTerminal([{ id: 1 }, record(2, 200, 0)], [0, 0]), null);
  assert.equal(closestTerminal([record(1, 0, 0), record(2, 0, 0)], [0, 0]).id, 1);
});

// Exercise the actual My location handler with browser location and Leaflet adapters.
const mapSource = (await readFile(new URL('../public/assets/map.js', import.meta.url), 'utf8')).replace(/^import .*;\r?\n/gm, '');
const mapHtml = await readFile(new URL('../public/user-map.html', import.meta.url), 'utf8');
const helpers = await import(common), routingHelpers = await import(routing);
const { createLocationTracker } = await import(moduleUrl((await readFile(new URL('../public/assets/location-tracking.js', import.meta.url), 'utf8')).replace(/'\.\/routing\.js(?:\?[^']*)?'/, JSON.stringify(routing))));
async function mapBrowser(records, denied = false, options = {}) {
  const nodes = new Map();
  let testDocument;
  let clock = Date.now();
  class TestDate extends Date { static now() { return clock; } }
  const node = () => ({ children: [], events: {}, dataset: {}, value: '', hidden: false, textContent: '', scrollTop: 0,
    append(...children) { this.children.push(...children); },
    replaceChildren(...children) { this.children = children; this.scrollTop = 0; },
    setAttribute(name, value) { this[name] = value; },
    addEventListener(name, fn) { this.events[name] = fn; },
    showModal() { this.open = true; }, close() { this.open = false; },
    focus() { testDocument.activeElement = this; }, scrollIntoView() {},
  });
  for (const match of mapHtml.matchAll(/\bid="([^"]+)"/g)) nodes.set(match[1], node());
  nodes.get('location-prompt').hidden = true;
  const calls = { navigations: [], bounds: [], views: [], selected: [], gps: 0, gpsOptions: [], routes: 0, routeRequests: [], lines: [], popups: 0, markers: [], circles: [], cleared: [], mapEvents: {}, documentEvents: {}, windowEvents: {} };
  let reportPosition, reportError;
  const locationLayer = (coordinates, options, collection) => {
    const layer = { coordinates, options, removed: false, events: {},
      addTo() { return this; }, bindPopup() { return this; }, openPopup() { calls.popups++; return this; },
      getElement() { return null; },
      on(event, handler) { this.events[event] = handler; return this; },
      setLatLng(value) { this.coordinates = value; return this; }, setRadius(value) { this.radius = value; return this; }, remove() { this.removed = true; },
    };
    collection.push(layer); return layer;
  };
  const state = {
    map: { invalidateSize() {}, stop() {}, getZoom: () => 16, on(event, fn) { calls.mapEvents[event] = fn; }, setView(...args) { calls.views.push(args); }, fitBounds(...args) { calls.bounds.push(args); } },
    leaflet: { latLngBounds: points => points, circleMarker: () => ({ addTo() { return this; }, bindPopup() { return this; }, openPopup() { calls.popups++; return this; }, remove() {} }),
      divIcon: options => options,
      marker: (coordinates, options) => locationLayer(coordinates, options, calls.markers),
      circle: (coordinates, options) => locationLayer(coordinates, options, calls.circles),
      geoJSON(geometry, style) { calls.lines.push({ geometry, style }); return { addTo() { return this; }, bringToBack() {}, getBounds: () => geometry.coordinates, remove() {} }; },
      featureGroup(layers) { return { addTo() { return this; }, getBounds: () => layers[0].getBounds(), remove() { layers.forEach(layer => layer.remove()); } }; } },
  };
  const location = { href: `http://localhost/user-map.html${options.search || ''}`, search: options.search || '' };
  const sandbox = {
    ...helpers, ...routingHelpers, closestTerminal, terminalProximity, URL, URLSearchParams, AbortController, location, Date: TestDate,
    isSecureContext: options.secure !== false,
    document: { hidden: false, body: { classList: { toggle() {}, contains: () => false } }, getElementById: id => nodes.get(id), querySelector: selector => selector === 'dialog[open]' ? null : ({ value: options.mode || 'walking' }), querySelectorAll: () => [], addEventListener(event, fn) { calls.documentEvents[event] = fn; } },
    window: { location: { assign(url) { calls.navigations.push(url); } }, addEventListener(event, fn) { calls.windowEvents[event] = fn; } },
    history: { replaceState(unused, title, url) { location.href = String(url); } },
    navigator: { geolocation: { watchPosition(success, failure, gpsOptions) {
      calls.gps++; calls.gpsOptions.push(gpsOptions);
      reportPosition = success; reportError = failure;
      if (options.geolocate) options.geolocate(success, failure);
      else if (denied) failure({ code: 1 }); else success({ coords: { latitude: 0, longitude: 0, accuracy: 10 } });
      return calls.gps;
    }, clearWatch(id) { calls.cleared.push(id); } } },
    createLocationTracker: options => createLocationTracker({ ...options, now: () => clock }),
    fetchTerminals: async () => ({ terminals: records }),
    element(tag, className, text) { const result = node(); result.textContent = text; return result; },
    boardingRouteSummary(record, query) { const result = node(); result.routes = helpers.matchingTerminalRoutes(record, query); return result; },
    terminalMap(container, status, options) { return () => { options.onReady(state); }; },
    createRoutePreview: () => ({ clear() {}, setMapState() {} }),
    createJeepneyTracing: () => ({ clear() {}, setTerminals() {}, setMapState() {}, setStartTerminal() {} }),
    focusTerminal(mapState, record) { if (!record) return false; calls.selected.push(record.id); return Boolean(helpers.mapCoordinates(record)); },
    routeClient: () => (...args) => {
      calls.routes++; calls.routeRequests.push(args);
      if (options.requestRoute) return options.requestRoute(...args);
      return Promise.resolve({ distance: 1600, duration: 900, geometry: { type: 'LineString',
        coordinates: [[args[0][1], args[0][0]], [args[1][1], args[1][0]]] } }); // Isolated routing-service fixture.
    },
  };
  testDocument = sandbox.document;
  vm.runInNewContext(`${mapSource}\nglobalThis.selection = () => selectedId;`, sandbox);
  await new Promise(resolve => setImmediate(resolve));
  return { nodes, calls, sandbox, locate: () => nodes.get('map-locate').events.click(), advanceTime: ms => { clock += ms; },
    position: (latitude, longitude, accuracy = 10) => reportPosition({ coords: { latitude, longitude, accuracy }, timestamp: clock }),
    error: code => reportError({ code }),
  };
}

const searchRecords = () => [
  { ...record(1, 0, .01), name: 'City Terminal', address: 'Alangilan', municipality: 'Batangas City' },
  { ...record(3, 0, .03), name: 'Distant boarding point', address: 'Town center', municipality: 'Mabini' },
  { ...record(2, 0, .02), name: 'Triangle', address: 'F. Castillo Boulevard', municipality: 'Mabini' },
];
function search(app, query) {
  app.nodes.get('map-search-query').value = query;
  app.nodes.get('map-search-query').events.input();
}
const resultIds = app => app.nodes.get('map-locations').children.map(card => card.children[0]?.id);

test('location gate stays modal until a valid location arrives and prevents Escape dismissal', async () => {
  const app = await mapBrowser(searchRecords(), false, { geolocate() {} });
  const gate = app.nodes.get('location-access');
  assert.equal(gate.open, true);
  assert.equal(gate.dataset.state, 'locating');
  assert.equal(app.nodes.get('location-jeepney-loader').hidden, false);
  assert.equal(app.nodes.get('location-access-enable').hidden, true);
  assert.equal(app.nodes.get('location-access-help').hidden, true);
  assert.equal(app.nodes.get('location-access-enable').disabled, true);
  assert.match(app.nodes.get('location-access-message').textContent, /Choose Allow/);
  let prevented = false;
  gate.events.cancel({ preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(gate.open, true);
  app.position(91, 0);
  assert.equal(gate.open, true, 'invalid GPS never unlocks the map');
  app.position(0, 0);
  assert.equal(gate.open, false);
  assert.equal(app.sandbox.document.activeElement, app.nodes.get('system-map'));
  assert.equal(app.sandbox.selection(), null, 'granting access does not select a terminal');
});

test('denied location stays gated with instructions; explicit retry unlocks and revocation locks again', async () => {
  let attempts = 0;
  const app = await mapBrowser(searchRecords(), false, { geolocate(success, failure) {
    if (++attempts === 1) failure({ code: 1 });
    else success({ coords: { latitude: 0, longitude: 0, accuracy: 10 } });
  } });
  assert.equal(app.nodes.get('location-access').open, true);
  assert.equal(app.nodes.get('location-jeepney-loader').hidden, false);
  assert.equal(app.nodes.get('location-access-enable').hidden, false);
  assert.match(app.nodes.get('location-access-message').textContent, /Safari.*website settings/);
  assert.equal(app.nodes.get('location-access-enable').disabled, false);
  assert.equal(attempts, 1, 'denial does not cause a repeated prompt loop');
  app.nodes.get('location-access-enable').events.click();
  assert.equal(attempts, 2);
  assert.equal(app.nodes.get('location-access').open, false);
  app.error(2);
  assert.equal(app.nodes.get('location-access').open, false, 'temporary signal loss does not revoke granted access');
  app.error(1);
  assert.equal(app.nodes.get('location-access').open, true);
});

test('HTTP location gate explains trusted HTTPS without offering an ineffective permission request', async () => {
  const app = await mapBrowser(searchRecords(), false, { secure: false });
  assert.equal(app.nodes.get('location-access').open, true);
  assert.match(app.nodes.get('location-access-message').textContent, /trusted HTTPS/);
  assert.equal(app.nodes.get('location-jeepney-loader').hidden, false);
  assert.equal(app.nodes.get('location-access-enable').textContent, 'HTTPS required');
  assert.equal(app.nodes.get('location-access-enable').disabled, true);
  app.nodes.get('location-access-enable').events.click();
  assert.equal(app.calls.gps, 0);
  assert.match(mapHtml, /href="user-dashboard.html">Back to Dashboard/);
});

test('Search opens a compact terminal finder without moving the map and restores focus on close', async () => {
  const app = await mapBrowser(searchRecords());
  const views = app.calls.bounds.length;
  app.nodes.get('map-search').events.click();
  assert.equal(app.nodes.get('map-terminal-panel').hidden, false);
  assert.equal(app.nodes.get('map-search')['aria-expanded'], 'true');
  assert.equal(app.sandbox.document.activeElement, app.nodes.get('map-search-query'));
  assert.equal(app.calls.bounds.length, views);
  assert.equal(app.sandbox.selection(), null);
  let prevented = false;
  app.nodes.get('map-search-form').events.submit({ preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  app.nodes.get('map-panel-close').events.click();
  assert.equal(app.nodes.get('map-terminal-panel').hidden, true);
  assert.equal(app.sandbox.document.activeElement, app.nodes.get('map-search'));
});

test('Map search filters name, landmark and area; clearing an empty result restores nearby ordering', async () => {
  const app = await mapBrowser(searchRecords());
  app.nodes.get('map-search').events.click();
  for (const query of [' TRIANGLE ', 'Castillo', 'Mabini Castillo']) {
    search(app, query);
    assert.deepEqual(resultIds(app), ['map-select-2']);
    assert.match(app.nodes.get('map-result-count').textContent, /Showing 1 of 3/);
  }
  search(app, 'Mabini');
  assert.deepEqual(resultIds(app), ['map-select-2', 'map-select-3']);
  assert.ok(app.nodes.get('map-locations').children[0].children[0].children.some(child => child.textContent === 'Closest match'));
  search(app, 'unmatched-area');
  assert.match(app.nodes.get('map-locations').children[0].textContent, /No terminals match/);
  assert.equal(app.nodes.get('map-search-nearest').disabled, true);
  assert.equal(app.nodes.get('map-search-clear').hidden, false);
  app.nodes.get('map-search-clear').events.click();
  assert.deepEqual(resultIds(app), ['map-select-1', 'map-select-2', 'map-select-3']);
  assert.equal(app.nodes.get('map-search-clear').hidden, true);
  assert.equal(app.calls.routes, 0);
});

test('Closest to me pinpoints the closest matching terminal, keeping search and distance actions open', async () => {
  const app = await mapBrowser(searchRecords());
  app.nodes.get('map-search').events.click();
  search(app, 'Mabini');
  await app.nodes.get('map-search-nearest').events.click();
  assert.equal(app.sandbox.selection(), 2, 'Match scope excludes the closer city terminal');
  assert.equal(app.nodes.get('map-terminal-panel').hidden, false);
  assert.equal(app.nodes.get('route-terminal-name').textContent, 'Triangle');
  assert.equal(app.nodes.get('estimate-route').disabled, false);
  const pin = app.calls.markers.findLast(marker => marker.options.icon.className === 'nearest-terminal-pin' && !marker.removed);
  assert.match(pin.options.title, /Closest matching terminal: Triangle/);
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, 0], [0, .02]]);
  assert.equal(app.nodes.get('map-search-nearest').disabled, false);
  assert.equal(app.calls.routes, 1);
  search(app, 'Distant');
  app.nodes.get('map-locations').children[0].children[0].events.click();
  assert.equal(app.sandbox.selection(), 3);
  assert.equal(pin.removed, true);
});

test('denied GPS keeps terminal search data intact behind the location gate without guessing a closest terminal', async () => {
  const app = await mapBrowser(searchRecords(), true);
  app.nodes.get('map-search').events.click();
  search(app, 'Alangilan');
  assert.deepEqual(resultIds(app), ['map-select-1']);
  await app.nodes.get('map-search-nearest').events.click();
  assert.equal(app.nodes.get('location-prompt').hidden, false);
  assert.match(app.nodes.get('location-prompt-message').textContent, /Location access is blocked/);
  assert.equal(app.sandbox.selection(), null);
  assert.equal(app.nodes.get('map-terminal-panel').hidden, false);
  assert.equal(app.calls.routes, 0);
});

test('changing the query cancels a pending closest-match selection', async () => {
  let confirm;
  const app = await mapBrowser(searchRecords(), false, { geolocate(success) { confirm = success; } });
  app.nodes.get('map-search').events.click();
  search(app, 'Mabini');
  const pending = app.nodes.get('map-search-nearest').events.click();
  assert.equal(app.nodes.get('map-search-nearest')['aria-busy'], 'true');
  search(app, 'Alangilan');
  confirm({ coords: { latitude: 0, longitude: 0, accuracy: 10 } });
  await pending;
  assert.equal(app.sandbox.selection(), null);
  assert.deepEqual(resultIds(app), ['map-select-1']);
  assert.equal(app.nodes.get('map-search-nearest')['aria-busy'], 'false');
});

test('closing search cancels a pending closest lookup without reopening the panel', async () => {
  let confirm;
  const app = await mapBrowser(searchRecords(), false, { geolocate(success) { confirm = success; } });
  app.nodes.get('map-search').events.click();
  const pending = app.nodes.get('map-search-nearest').events.click();
  app.nodes.get('map-panel-close').events.click();
  confirm({ coords: { latitude: 0, longitude: 0, accuracy: 10 } });
  await pending;
  assert.equal(app.sandbox.selection(), null);
  assert.equal(app.nodes.get('map-terminal-panel').hidden, true);
  assert.equal(app.nodes.get('map-search-nearest')['aria-busy'], 'false');
});

test('Crosshair selects the closest reference and frames it with the user', async () => {
  const near = { id: 1, name: 'Near landmark', address: 'Test address', municipality: 'Batangas City',
    coordinates_verified: false, coordinates: null, landmark_coordinates_verified: true,
    landmark_coordinates: { latitude: 0, longitude: .1 } };
  const far = { ...record(2, 0, 1), name: 'Far terminal', address: 'Test address', municipality: 'Mabini' };
  const app = await mapBrowser([far, near]);
  await app.locate();
  assert.equal(app.sandbox.selection(), 1);
  assert.equal(app.nodes.get('map-selection-name').textContent, near.name);
  assert.equal(app.nodes.get('map-selection-nearest').hidden, false);
  assert.match(app.nodes.get('map-selection-note').textContent, /Exact boarding point pending verification/);
  assert.equal(app.nodes.get('estimate-route').disabled, false);
  assert.equal(app.nodes.get('map-locations').children[0].children[0].id, 'map-select-1');
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, 0], [0, .1]]);
  assert.equal(app.calls.gps, 1);
  assert.equal(app.nodes.get('map-locate')['aria-pressed'], 'false');
  assert.equal(app.calls.routes, 1);
  assert.equal(new URL(app.sandbox.location.href).searchParams.get('terminal'), '1');
});

test('Crosshair shows the closest reference with a compact indicator and frames both markers', async () => {
  const app = await mapBrowser([
    { ...record(1, 0, .02), name: 'Far terminal', address: 'Test', municipality: 'Mabini' },
    { id: 2, name: 'Near landmark', address: 'Test', municipality: 'Batangas City', coordinates_verified: false,
      landmark_coordinates_verified: true, landmark_coordinates: { latitude: 0, longitude: .01 } },
    { id: 3, name: 'Pending location', address: 'Test', municipality: 'Batangas City' },
  ]);
  assert.equal(app.sandbox.selection(), null, 'opening Map still starts at the user');
  await app.nodes.get('map-locate').events.click();
  assert.equal(app.sandbox.selection(), 2);
  assert.equal(app.nodes.get('map-terminal-panel').hidden, true);
  assert.equal(app.nodes.get('nearest-terminal-status').hidden, false);
  assert.match(app.nodes.get('nearest-terminal-status').textContent, /Closest terminal to you.*Near landmark.*Est. path distance: 1.6 km/);
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, 0], [0, .01]]);
  assert.equal(app.nodes.get('map-locate')['aria-pressed'], 'false');
  assert.equal(app.nodes.get('map-locate').disabled, false);
  assert.equal(app.calls.routes, 1, 'finder obtains its distance from the routing service');
  assert.equal(app.calls.lines.length, 0, 'the compact indicator estimates distance without opening a route overlay');
  const pin = app.calls.markers.find(marker => marker.options.icon.className === 'nearest-terminal-pin');
  assert.deepEqual(JSON.parse(JSON.stringify(pin.coordinates)), [0, .01], 'pin uses the selected reference coordinates');
  assert.match(pin.options.title, /Closest terminal to you: Near landmark/);
  const views = app.calls.bounds.length;
  app.advanceTime(1000); app.position(0, .001);
  assert.equal(app.calls.bounds.length, views, 'GPS updates do not steal the finder view');
  assert.match(app.nodes.get('nearest-terminal-status').textContent, /1.6 km/);
  assert.equal(app.calls.routes, 1, 'GPS updates do not continuously recalculate the estimate');
  app.nodes.get('map-locations').children[1].children[0].events.click();
  assert.equal(app.nodes.get('nearest-terminal-status').hidden, true, 'manual selection clears the finder result');
  assert.equal(pin.removed, true, 'manual selection removes the old nearest pin');
  await app.locate();
  const activePins = app.calls.markers.filter(marker => marker.options.icon.className === 'nearest-terminal-pin' && !marker.removed);
  assert.equal(activePins.length, 1, 'repeat lookups show one nearest pin');
  activePins[0].events.click();
  assert.deepEqual(app.calls.navigations, ['user-terminal-details.html?id=2'], 'tapping the nearest pin opens its dedicated details page');
});

test('nearest indicator and selected card use the returned path length instead of spherical proximity', async () => {
  const app = await mapBrowser(searchRecords(), false, { requestRoute: async () => ({ distance: 3700, duration: 2000,
    geometry: { type: 'LineString', coordinates: [[0,0],[.01,0]] } }) });
  await app.locate();
  assert.match(app.nodes.get('nearest-terminal-status').textContent, /Est. path distance: 3.7 km/);
  assert.doesNotMatch(app.nodes.get('nearest-terminal-status').textContent, /straight-line|1.1 km/);
  assert.equal(app.nodes.get('route-distance').textContent, '3.7 km');
  assert.ok(app.nodes.get('map-locations').children[0].children[0].children.some(child => child.textContent === 'Estimated path distance: 3.7 km'));
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.routeRequests[0].slice(0, 3))), [[0,0],[0,.01],'walking']);
});

test('nearest distance shows loading and failures without substituting a straight-line distance', async () => {
  let reject;
  const app = await mapBrowser(searchRecords(), false, { requestRoute: () => new Promise((resolve, fail) => { reject = fail; }) });
  const pending = app.locate();
  await new Promise(resolve => setImmediate(resolve));
  assert.match(app.nodes.get('nearest-terminal-status').textContent, /Estimating distance/);
  assert.doesNotMatch(app.nodes.get('nearest-terminal-status').textContent, /\d[.,]?\d*\s*(?:km|m)\b/);
  reject(Object.assign(new Error('No foot path'), { code: 'no-route' }));
  await pending;
  assert.match(app.nodes.get('nearest-terminal-status').textContent, /Distance unavailable/);
  assert.equal(app.nodes.get('route-result').hidden, true);
  assert.equal(app.nodes.get('location-prompt').hidden, true, 'routing failure is not a GPS permission failure');
  assert.equal(app.calls.lines.length, 0);
  assert.doesNotMatch(app.nodes.get('map-locations').children[0].children[0].children.map(child => child.textContent).join(' '), /straight-line/);
});

test('late nearest-distance responses cannot replace a manual terminal selection', async () => {
  let resolve;
  const app = await mapBrowser(searchRecords(), false, { requestRoute: () => new Promise(done => { resolve = done; }) });
  const pending = app.locate();
  await new Promise(resolve => setImmediate(resolve));
  const signal = app.calls.routeRequests[0][3];
  app.nodes.get('map-locations').children[1].children[0].events.click();
  assert.equal(signal.aborted, true);
  resolve({ distance: 9999, duration: 9000, geometry: { type: 'LineString', coordinates: [[0,0],[.01,0]] } });
  await pending;
  assert.equal(app.sandbox.selection(), 2);
  assert.equal(app.nodes.get('nearest-terminal-status').hidden, true);
  assert.equal(app.nodes.get('route-result').hidden, true);
});

test('old path estimates expire without continuous requests, and revoked location cancels pending distances', async () => {
  const app = await mapBrowser(searchRecords());
  await app.locate();
  app.advanceTime(60001); app.position(0, 0);
  assert.match(app.nodes.get('nearest-terminal-status').textContent, /Refresh My location/);
  assert.equal(app.calls.routes, 1);
  let resolve;
  const revoked = await mapBrowser(searchRecords(), false, { requestRoute: () => new Promise(done => { resolve = done; }) });
  const pending = revoked.locate();
  await new Promise(resolve => setImmediate(resolve));
  revoked.error(1);
  assert.equal(revoked.calls.routeRequests[0][3].aborted, true);
  resolve({ distance: 9999, duration: 9000, geometry: { type: 'LineString', coordinates: [[0,0],[.01,0]] } });
  await pending;
  assert.equal(revoked.nodes.get('route-result').hidden, true);
  assert.doesNotMatch(revoked.nodes.get('nearest-terminal-status').textContent, /9\.9 km/);
});

test('pending nearest lookup does not override a manual terminal selection', async () => {
  let confirm;
  const app = await mapBrowser([
    { ...record(1, 0, .01), name: 'Nearest', address: 'Test', municipality: 'Batangas City' },
    { ...record(2, 0, .02), name: 'Chosen', address: 'Test', municipality: 'Batangas City' },
  ], false, { geolocate(success) { confirm = success; } });
  const lookup = app.nodes.get('map-locate').events.click();
  assert.equal(app.nodes.get('map-locate').disabled, true);
  assert.equal(app.nodes.get('map-locate')['aria-busy'], 'true');
  app.nodes.get('map-locations').children[1].children[0].events.click();
  confirm({ coords: { latitude: 0, longitude: 0, accuracy: 10 } });
  await lookup;
  assert.equal(app.sandbox.selection(), 2);
  assert.equal(app.nodes.get('map-locate').disabled, false);
  assert.equal(app.nodes.get('nearest-terminal-status').hidden, true);
});

test('clicking any terminal shows its red pin without GPS and moves the pin to the next selection', async () => {
  const app = await mapBrowser([
    { ...record(1, 0, .01), name: 'Selected A', address: 'Test', municipality: 'Batangas City' },
    { ...record(2, 0, .02), name: 'Selected B', address: 'Test', municipality: 'Batangas City' },
    { id: 3, name: 'Unmapped', address: 'Test', municipality: 'Mabini' },
  ], true);
  const activePins = () => app.calls.markers.filter(marker => marker.options.icon.className === 'nearest-terminal-pin' && !marker.removed);
  app.nodes.get('map-locations').children[0].children[0].events.click();
  assert.equal(activePins().length, 1);
  assert.deepEqual(JSON.parse(JSON.stringify(activePins()[0].coordinates)), [0, .01]);
  assert.equal(activePins()[0].options.title, 'Selected terminal: Selected A');
  app.nodes.get('map-locations').children[1].children[0].events.click();
  assert.equal(activePins().length, 1);
  assert.deepEqual(JSON.parse(JSON.stringify(activePins()[0].coordinates)), [0, .02]);
  activePins()[0].events.click();
  assert.deepEqual(app.calls.navigations, ['user-terminal-details.html?id=2'], 'tapping the selected pin opens the correct terminal details page');
  app.nodes.get('map-locations').children[2].children[0].events.click();
  assert.equal(activePins().length, 0, 'an unmapped location never gets a guessed pin');
});

test('nearest finder handles permission denial and missing map references without a guessed result', async () => {
  const denied = await mapBrowser([{ ...record(1, 0, .01), name: 'TEST', address: 'Test', municipality: 'Batangas City' }], true);
  await denied.nodes.get('map-locate').events.click();
  assert.equal(denied.sandbox.selection(), null);
  assert.equal(denied.nodes.get('location-prompt').hidden, false);
  assert.equal(denied.nodes.get('map-locate').disabled, false);
  assert.equal(denied.calls.routes, 0);
  const missing = await mapBrowser([{ id: 1, name: 'Pending', address: 'Test', municipality: 'Mabini' }]);
  await missing.nodes.get('map-locate').events.click();
  assert.equal(missing.sandbox.selection(), null);
  assert.equal(missing.nodes.get('map-terminal-panel').hidden, true);
  assert.match(missing.nodes.get('nearest-terminal-status').textContent, /No mapped boarding points/);
  assert.equal(missing.calls.routes, 0);
});

  test('one distance action routes from device GPS to the landmark without mode choices or a time estimate', async () => {
    const landmark = { id: 1, name: 'TEST LANDMARK', address: 'Test address', municipality: 'Batangas City',
      coordinates_verified: false, coordinates: null, landmark_coordinates_verified: true,
      landmark_coordinates: { latitude: 0, longitude: .01 } };
    const geometry = { type: 'LineString', coordinates: [[0,0],[.004,.001],[.01,0]] };
    const app = await mapBrowser([landmark], false, {
      requestRoute: async () => ({ geometry, distance: 1400, duration: 1000 }) });
    app.nodes.get('map-locations').children[0].children[0].events.click();
    assert.equal(app.nodes.has('route-modes'), false);
    assert.equal(app.nodes.has('route-duration'), false);
    assert.equal(app.nodes.get('estimate-route').disabled, false);
    assert.equal(app.calls.routes, 0);
    await app.nodes.get('estimate-route').events.click();
    assert.equal(app.calls.gps, 1);
    assert.equal(app.calls.routes, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(app.calls.routeRequests[0].slice(0, 3))), [[0,0],[0,.01],'walking']);
    assert.equal(app.nodes.get('route-distance').textContent, '1.4 km');
    assert.equal(app.nodes.get('route-distance-label').textContent, 'Estimated distance to landmark');
    assert.equal(app.nodes.get('route-result').hidden, false);
    assert.equal(app.nodes.get('route-privacy').hidden, false);
    assert.match(app.nodes.get('route-status').textContent, /Estimated distance to the mapped landmark/);
    assert.equal(app.calls.lines[0].geometry, geometry);
    assert.equal(app.calls.lines[0].style.style.dashArray, null);
    assert.equal(app.calls.lines[0].style.style.smoothFactor, .8);
    assert.equal(app.calls.lines[0].style.style.color, '#ffffff');
    assert.equal(app.calls.lines[1].geometry, geometry);
    assert.equal(app.calls.lines[1].style.style.color, '#7c3aed');
    assert.equal(app.calls.lines[1].style.style.weight, 5);
    assert.equal(app.calls.lines[1].style.style.dashArray, null);
    assert.equal(landmark.coordinates_verified, false);
    assert.equal(landmark.coordinates, null);
  });
test('missing coordinates and GPS/provider errors never invent a landmark distance', async () => {
  const landmark = { id: 1, name: 'TEST LANDMARK', address: 'Test address', municipality: 'Batangas City',
    coordinates_verified: false, coordinates: null, landmark_coordinates_verified: true,
    landmark_coordinates: { latitude: 0, longitude: .01 } };
  const denied = await mapBrowser([landmark], true);
  denied.nodes.get('map-locations').children[0].children[0].events.click();
  await denied.nodes.get('estimate-route').events.click();
  assert.equal(denied.calls.routes, 0);
  assert.equal(denied.nodes.get('route-result').hidden, true);
  assert.equal(denied.nodes.get('location-prompt').hidden, false);
  assert.match(denied.nodes.get('location-prompt-message').textContent, /Location access is blocked/);
  const failed = await mapBrowser([landmark], false, { requestRoute: async () => { throw new Error('offline'); } });
  failed.nodes.get('map-locations').children[0].children[0].events.click();
  await failed.nodes.get('estimate-route').events.click();
  assert.equal(failed.nodes.get('route-result').hidden, true);
  assert.equal(failed.calls.lines.length, 0);
  assert.match(failed.nodes.get('route-status').textContent, /temporarily unavailable/);
  const missing = await mapBrowser([{ ...landmark, landmark_coordinates: null }]);
  missing.nodes.get('map-locations').children[0].children[0].events.click();
  assert.equal(missing.nodes.get('estimate-route').disabled, true);
  await missing.nodes.get('estimate-route').events.click();
  assert.equal(missing.calls.gps, 1); // Opening Map requests location, even without a destination.
  assert.equal(missing.calls.routes, 0);
});

test('Map automatically watches and centers the user without selecting a terminal or routing', async () => {
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'TEST', address: 'Test', municipality: 'Batangas City' }]);
  assert.equal(app.calls.gps, 1);
  assert.equal(app.calls.gpsOptions[0].enableHighAccuracy, true);
  assert.equal(app.calls.gpsOptions[0].maximumAge, 0);
  assert.equal(app.calls.routes, 0);
  assert.equal(app.calls.popups, 0);
  assert.equal(app.calls.views.length, 0);
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, 0], [0, 0]]);
  assert.match(app.nodes.get('map-location-label').textContent, /Following/);
  assert.equal(app.calls.markers.length, 1);
  assert.match(app.calls.markers[0].options.icon.html, /user-location-dot/);
  assert.equal(app.calls.circles[0].options.radius, 10);
  assert.equal(app.sandbox.selection(), null);
  assert.equal(app.nodes.get('map-terminal-panel').hidden, true);
  app.nodes.get('map-locations').children[0].children[0].events.click();
  assert.equal(app.nodes.get('map-terminal-panel').hidden, false);
  app.nodes.get('map-panel-close').events.click();
  assert.equal(app.nodes.get('map-terminal-panel').hidden, true);
  assert.equal(app.calls.gps, 1);
});

test('opening a terminal link waits for the user location without focusing the destination', async () => {
  let confirm;
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'DESTINATION', address: 'Test', municipality: 'Batangas City' }], false,
    { search: '?terminal=1', geolocate(success) { confirm = success; } });
  assert.equal(app.sandbox.selection(), 1);
  assert.equal(app.nodes.get('route-terminal-name').textContent, 'DESTINATION');
  assert.equal(app.calls.selected.length, 0, 'URL destination must not move the camera on entry');
  assert.equal(app.calls.bounds.length, 0);
  assert.equal(app.calls.markers.filter(marker => marker.options.icon.className === 'nearest-terminal-pin').length, 1, 'saved terminal links receive a pin without moving the user camera');
  confirm({ coords: { latitude: 0, longitude: .002, accuracy: 10 } });
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, .002], [0, .002]]);
  assert.equal(app.nodes.get('map-location-label').textContent, 'Following your location');
  assert.equal(app.calls.selected.length, 0, 'refreshing the terminal list must not steal location focus');
  app.nodes.get('map-locations').children[0].children[0].events.click();
  assert.equal(app.calls.selected.at(-1), 1, 'explicit selection still focuses the terminal');
  assert.equal(app.nodes.get('map-location-label').textContent, 'Live location');
});

test('directory navigation shows its terminal pin before GPS arrives and keeps the selected location in view', async () => {
  let confirm;
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'CLICKED TERMINAL', address: 'Test', municipality: 'Batangas City' }], false,
    { search: '?terminal=1&focus=terminal', geolocate(success) { confirm = success; } });
  assert.equal(app.calls.selected.at(-1), 1);
  assert.equal(app.nodes.get('map-terminal-panel').hidden, false);
  const pins = app.calls.markers.filter(marker => marker.options.icon.className === 'nearest-terminal-pin' && !marker.removed);
  assert.equal(pins.length, 1);
  assert.deepEqual(JSON.parse(JSON.stringify(pins[0].coordinates)), [0, .01]);
  const views = app.calls.bounds.length;
  confirm({ coords: { latitude: 0, longitude: .002, accuracy: 10 } });
  assert.equal(app.calls.bounds.length, views, 'late GPS must not recenter away from the clicked terminal');
  assert.equal(app.nodes.get('map-location-label').textContent, 'Live location');
});

test('invalid or unmapped directory targets never create a guessed pin and fall back to the user view', async () => {
  for (const records of [[{ ...record(1, 0, .01), name: 'Other', address: 'Test', municipality: 'Batangas City' }],
    [{ id: 99, name: 'Unmapped', address: 'Test', municipality: 'Batangas City' }]]) {
    const app = await mapBrowser(records, false, { search: '?terminal=99&focus=terminal' });
    assert.equal(app.calls.markers.filter(marker => marker.options.icon.className === 'nearest-terminal-pin').length, 0);
    assert.equal(app.nodes.get('map-location-label').textContent, 'Following your location');
    assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, 0], [0, 0]]);
  }
});

test('denied location on a terminal link never substitutes the terminal for the user location', async () => {
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'DESTINATION', address: 'Test', municipality: 'Batangas City' }], true,
    { search: '?terminal=1' });
  assert.equal(app.calls.selected.length, 0);
  assert.equal(app.calls.bounds.length, 0);
  assert.equal(app.calls.markers.filter(marker => marker.options.icon.className === 'user-location-marker').length, 0);
  assert.equal(app.calls.markers.filter(marker => marker.options.icon.className === 'nearest-terminal-pin').length, 1, 'terminal pin uses its saved coordinates without claiming a user location');
  assert.match(app.nodes.get('user-location-status').textContent, /not allowed/);
});

test('phone access over insecure HTTP explains the HTTPS requirement without requesting location', async () => {
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'TEST', address: 'Test', municipality: 'Batangas City' }], false, { secure: false });
  assert.equal(app.calls.gps, 0);
  assert.match(app.nodes.get('user-location-status').textContent, /secure HTTPS connection/);
  await app.locate();
  assert.equal(app.nodes.get('map-locate').disabled, false);
  app.nodes.get('map-locations').children[0].children[0].events.click();
  await app.nodes.get('estimate-route').events.click();
  assert.equal(app.nodes.get('location-prompt').hidden, false);
  assert.match(app.nodes.get('location-prompt-message').textContent, /trusted HTTPS link/);
  assert.equal(app.calls.gps, 0);
  assert.equal(app.calls.routes, 0);
});

test('terminal selection preserves the list scroll position', async () => {
  const app = await mapBrowser([
    { ...record(1, 0, .01), name: 'TEST A', address: 'Test', municipality: 'Batangas City' },
    { ...record(2, 0, .02), name: 'TEST B', address: 'Test', municipality: 'Mabini' },
  ]);
  const list = app.nodes.get('map-locations');
  list.scrollTop = 180;
  list.children[1].children[0].events.click();
  assert.equal(list.scrollTop, 180);
});

test('pending location shows the popup and blocks routing until a valid fix arrives', async () => {
  let confirm;
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'TEST', address: 'Test', municipality: 'Batangas City' }], false,
    { geolocate(success) { confirm = success; } });
  app.nodes.get('map-locations').children[0].children[0].events.click();
  await app.nodes.get('estimate-route').events.click();
  assert.equal(app.nodes.get('location-prompt').hidden, false);
  assert.match(app.nodes.get('location-prompt-message').textContent, /Confirming your location/);
  assert.equal(app.nodes.has('location-prompt-retry'), false);
  assert.equal(app.calls.routes, 0);
  assert.equal(app.calls.gps, 1);
  confirm({ coords: { latitude: 0, longitude: 0 } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(app.nodes.get('location-prompt').hidden, true);
  assert.equal(app.nodes.get('map-locate').disabled, false);
});

test('notification uses the existing My location button; stale fixes still need reconfirmation', async () => {
  let requests = 0;
  const app = await mapBrowser([
    { ...record(1, 0, .001), name: 'CLOSEST', address: 'Test', municipality: 'Batangas City' },
    { ...record(2, 0, .01), name: 'CHOSEN', address: 'Test', municipality: 'Batangas City' },
  ], false, { geolocate(success, failure) { if (++requests === 1) failure({ code: 1 }); else success({ coords: { latitude: 0, longitude: 0 } }); } });
  app.nodes.get('map-locations').children[1].children[0].events.click();
  await app.nodes.get('estimate-route').events.click();
  assert.equal(app.nodes.get('location-prompt').hidden, false);
  app.nodes.get('location-prompt-dismiss').events.click();
  await app.locate();
  assert.equal(app.sandbox.selection(), 1);
  assert.equal(app.nodes.get('location-prompt').hidden, true);
  assert.equal(app.calls.gps, 2);
  assert.equal(app.calls.routes, 1);
  app.advanceTime(60001);
  await app.nodes.get('estimate-route').events.click();
  assert.equal(app.nodes.get('location-prompt').hidden, false);
  assert.match(app.nodes.get('location-prompt-message').textContent, /needs to be refreshed/);
  assert.equal(app.calls.routes, 1, 'a stale fix must not start another routing request');
  app.nodes.get('location-prompt-dismiss').events.click();
  assert.equal(app.nodes.get('location-prompt').hidden, true);
});

for (const accuracy of [114, 750]) {
test(`My location accepts an approximate ${accuracy} m fix and closes the confirmation popup`, async () => {
  let attempts = 0;
  const geometry = { type: 'LineString', coordinates: [[0,0],[.004,.001],[.01,0]] };
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'TEST', address: 'Test', municipality: 'Batangas City' }], false,
    { geolocate(success, failure) {
      if (++attempts === 1) failure({ code: 1 });
      else success({ coords: { latitude: 0, longitude: 0, accuracy } });
    }, requestRoute: async () => ({ geometry, distance: 1400, duration: 1000 }) });
  app.nodes.get('map-locations').children[0].children[0].events.click();
  await app.nodes.get('estimate-route').events.click();
  assert.equal(app.calls.routes, 0);
  assert.equal(app.nodes.get('location-prompt').hidden, false);
  await app.locate();
  assert.equal(app.nodes.get('location-prompt').hidden, true);
  assert.equal(app.calls.gps, 2);
  assert.equal(app.nodes.get('map-locate').disabled, false);
  assert.match(app.nodes.get('user-location-status').textContent, new RegExp(`${accuracy} m accuracy`));
  assert.equal(app.calls.routes, 1, 'My location now estimates the nearest path distance');
  await app.nodes.get('estimate-route').events.click();
  assert.equal(app.calls.routes, 2, 'the explicit action also draws the path; provider caching is tested separately');
  assert.equal(app.nodes.get('route-result').hidden, false);
  assert.equal(app.nodes.get('location-prompt').hidden, true);
});
}

test('denied location permission retains terminal records without selecting a guessed terminal', async () => {
  const app = await mapBrowser([{ ...record(1, 0, .1), name: 'Test', address: 'Test', municipality: 'Mabini' }], true);
  await app.locate();
  assert.equal(app.sandbox.selection(), null);
  assert.match(app.nodes.get('user-location-status').textContent, /not allowed/);
  assert.equal(app.nodes.get('map-locate').disabled, false);
  assert.equal(app.calls.bounds.length, 0);
});

test('location sharing without verified references shows the user alone and a clear empty state', async () => {
  const app = await mapBrowser([{ id: 1, name: 'Pending', address: 'Test', municipality: 'Mabini' }]);
  await app.locate();
  assert.equal(app.sandbox.selection(), null);
  assert.match(app.nodes.get('user-location-status').textContent, /No verified map references/);
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, 0], [0, 0]]);
});

test('live updates move the blue marker; crosshair keeps the closest terminal framed after dragging', async () => {
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'TEST', address: 'Test', municipality: 'Batangas City' }]);
  app.advanceTime(1000); app.position(0, .002, 24);
  assert.equal(app.calls.markers.length, 1);
  assert.equal(app.calls.circles.length, 1);
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.markers[0].coordinates)), [0, .002]);
  assert.equal(app.calls.circles[0].radius, 24);
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, .002], [0, .002]]);
  app.calls.mapEvents.dragstart();
  const views = app.calls.bounds.length;
  app.advanceTime(1000); app.position(0, .003);
  assert.equal(app.calls.bounds.length, views);
  assert.equal(app.nodes.get('map-location-label').textContent, 'Live location');
  assert.equal(app.nodes.get('map-locate')['aria-pressed'], 'false');
  await app.locate();
  const finderViews = app.calls.bounds.length;
  app.advanceTime(1000); app.position(0, .004);
  assert.equal(app.nodes.get('map-locate')['aria-pressed'], 'false');
  assert.equal(app.calls.bounds.length, finderViews);
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.bounds.at(-1)[0])), [[0, .003], [0, .01]]);
  assert.equal(app.calls.gps, 1);
  assert.equal(app.calls.routes, 1);
});

test('terminal browsing stays put during GPS updates and distance estimation uses the latest position', async () => {
  const geometry = { type: 'LineString', coordinates: [[.002, 0], [.01, 0]] };
  const app = await mapBrowser([{ ...record(1, 0, .01), name: 'TEST', address: 'Test', municipality: 'Batangas City' }], false,
    { requestRoute: async () => ({ geometry, distance: 1000, duration: 800 }) });
  app.nodes.get('map-locations').children[0].children[0].events.click();
  const views = app.calls.bounds.length;
  app.advanceTime(1000); app.position(0, .002);
  assert.equal(app.calls.bounds.length, views);
  assert.equal(app.nodes.get('route-terminal-name').textContent, 'TEST');
  await app.nodes.get('estimate-route').events.click();
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.routeRequests[0][0])), [0, .002]);
  app.advanceTime(1000); app.position(0, .003);
  assert.equal(app.calls.routes, 1, 'watching must not send continuous routing requests');
});

test('hidden/leaving pages stop watching; returning starts only one watch and ignores old callbacks', async () => {
  let report;
  const app = await mapBrowser([], false, { geolocate(success) { report = success; success({ coords: { latitude: 0, longitude: 0, accuracy: 10 } }); } });
  const oldReport = report;
  app.sandbox.document.hidden = true; app.calls.documentEvents.visibilitychange();
  assert.deepEqual(app.calls.cleared, [1]);
  assert.equal(app.nodes.get('map-location-label').textContent, 'Location updates paused');
  oldReport({ coords: { latitude: 0, longitude: .5, accuracy: 10 } });
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.markers[0].coordinates)), [0, 0]);
  app.sandbox.document.hidden = false; app.calls.documentEvents.visibilitychange();
  app.calls.windowEvents.pageshow({ persisted: true });
  assert.equal(app.calls.gps, 2);
  app.calls.windowEvents.pagehide();
  assert.deepEqual(app.calls.cleared, [1, 2]);
  assert.equal(app.calls.markers.length, 1);
});

test('invalid/stale fixes never move the marker and revoked permission removes the old location', async () => {
  let report;
  const app = await mapBrowser([], false, { geolocate(success) { report = success; success({ coords: { latitude: 0, longitude: 0, accuracy: 10 } }); } });
  report({ coords: { latitude: 91, longitude: 1 }, timestamp: Date.now() });
  report({ coords: { latitude: 1, longitude: 1 }, timestamp: Date.now() - 120000 });
  assert.deepEqual(JSON.parse(JSON.stringify(app.calls.markers[0].coordinates)), [0, 0]);
  app.error(2);
  assert.equal(app.nodes.get('map-location-label').textContent, 'Waiting for location signal\u2026');
  app.advanceTime(1000); app.position(0, .001);
  assert.equal(app.nodes.get('map-location-label').textContent, 'Following your location');
  app.error(1);
  assert.equal(app.calls.markers[0].removed, true);
  assert.equal(app.calls.circles[0].removed, true);
  assert.equal(app.nodes.get('map-location-label').textContent, 'Location access unavailable');
  assert.deepEqual(app.calls.cleared, [1]);
});
