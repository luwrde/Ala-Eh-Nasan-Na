import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const asModule = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const common = asModule(await readFile(new URL('../public/assets/terminals-common.js', import.meta.url), 'utf8'));
const source = (await readFile(new URL('../public/assets/terminals-map.js', import.meta.url), 'utf8')).replace(/'\.\/terminals-common\.js(?:\?[^']*)?'/, JSON.stringify(common));
const { terminalMap } = await import(asModule(source));

function fixture() {
  const calls = { popups: [], coordinates: [], clears: 0, views: [], bounds: [], clicks: [], mapOptions: [] };
  const map = { invalidateSize() {}, setView(...args) { calls.views.push(args); }, fitBounds(...args) { calls.bounds.push(args); } };
  globalThis.window = { L: {
    map: (container, options) => { calls.mapOptions.push(options); return map; },
    tileLayer: () => ({ on() { return this; }, addTo() { return this; } }),
    layerGroup: () => ({ addTo() { return this; }, clearLayers() { calls.clears++; } }),
    circleMarker: coordinates => {
      calls.coordinates.push(coordinates);
      return { bindPopup(node) { calls.popups.push(node); return this; }, addTo() { return this; }, on(type, callback) { if (type === 'click') calls.clicks.push(callback); return this; } };
    },
    latLngBounds: positions => positions,
  } };
  globalThis.document = { createElement: tag => ({ tag, children: [], textContent: '', append(...nodes) { this.children.push(...nodes); } }) };
  const container = { hidden: true }, status = { textContent: '' };
  return { calls, container, status, render: terminalMap(container, status), withOptions: options => terminalMap(container, status, options) };
}

test('unverified coordinates create no map or markers and show a clear message', async () => {
  const f = fixture();
  window.L.map = () => { throw new Error('Must not initialize map without a verified position'); };
  await f.render([{ coordinates_verified: false, coordinates: { latitude: 0, longitude: 0 } }]);
  assert.equal(f.container.hidden, true);
  assert.match(f.status.textContent, /Exact coordinates are still to be verified/);
  assert.equal(f.calls.coordinates.length, 0);
});
test('a verified location gets a details popup with safe text and disappears when filtered out', async () => {
  const f = fixture();
  await f.render([{ id: 2, name: '<img src=x onerror=alert(1)>', address: "Kwatog's", coordinates_verified: true, coordinates: { latitude: 0, longitude: 0 } }]);
  assert.equal(f.container.hidden, false);
  assert.deepEqual(f.calls.coordinates, [[0, 0]]);
  assert.equal(f.calls.popups[0].children[0].textContent, '<img src=x onerror=alert(1)>');
  assert.equal(f.calls.popups[0].children[1].textContent, "Kwatog's");
  assert.equal(f.calls.popups[0].children[2].href, 'user-terminal-details.html?id=2');
  await f.render([]);
  assert.equal(f.container.hidden, true);
  assert.equal(f.calls.clears, 2);
});
test('multiple verified markers fit their real bounds and ignore invalid coordinates', async () => {
  const f = fixture();
  await f.render([
    { id: 1, name: 'Synthetic A', address: 'Test', coordinates_verified: true, coordinates: { latitude: 0, longitude: 0 } },
    { id: 2, name: 'Synthetic B', address: 'Test', coordinates_verified: true, coordinates: { latitude: 1, longitude: 1 } },
    { id: 3, coordinates_verified: true, coordinates: { latitude: null, longitude: 1 } },
  ]);
  assert.equal(f.calls.coordinates.length, 2);
  assert.deepEqual(f.calls.bounds[0][0], [[0, 0], [1, 1]]);
  assert.match(f.status.textContent, /1 awaiting verified coordinates/);
});

test('overview remains visible without GPS records and never creates estimated terminal markers', async () => {
  const f = fixture();
  let ready;
  const overview = [0, 0]; // Synthetic viewport, not a project terminal.
  const render = f.withOptions({ overviewCenter: overview, overviewZoom: 10, onReady: context => { ready = context; } });
  await render([{ id: 1, coordinates_verified: false, coordinates: null }]);
  assert.equal(f.container.hidden, false);
  assert.deepEqual(f.calls.views[0], [overview, 10]);
  assert.equal(f.calls.coordinates.length, 0);
  assert.equal(ready.markers.size, 0);
  assert.match(f.status.textContent, /General area map/);
});

test('map errors keep the page usable and expose the retry callback', async () => {
  const f = fixture();
  let failed = false;
  window.L.map = () => { throw new Error('Library initialization failed'); };
  await f.withOptions({ overviewCenter: [0, 0], onError: () => { failed = true; } })([]);
  assert.equal(f.container.hidden, true);
  assert.equal(failed, true);
  assert.match(f.status.textContent, /temporarily unavailable/);
});

test('marker selection reports the terminal and refreshing labels preserves the current view', async () => {
  const f = fixture();
  let selected = null;
  const render = f.withOptions({ overviewCenter: [0, 0], preserveView: true, onSelect: id => { selected = id; } });
  const records = [{ id: 3, name: 'Test terminal', address: 'Test address', coordinates_verified: true, coordinates: { latitude: 0.1, longitude: 0.2 } }];
  await render(records);
  f.calls.clicks[0]();
  assert.equal(selected, 3);
  await render(records);
  assert.equal(f.calls.views.length, 1);
});

test('dedicated map starts at the city overview even when a verified terminal loads first', async () => {
  const f = fixture();
  const center = [0, 0]; // Synthetic overview reference, not a real terminal.
  const render = f.withOptions({ overviewCenter: center, overviewZoom: 13, minZoom: 12, startWithOverview: true, preserveView: true });
  await render([{ id: 3, name: 'Test terminal', address: 'Test', coordinates_verified: true, coordinates: { latitude: 1, longitude: 1 } }]);
  assert.deepEqual(f.calls.views[0], [center, 13]);
  assert.equal(f.calls.mapOptions[0].minZoom, 12);
  assert.equal(f.calls.bounds.length, 0);
});
