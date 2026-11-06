import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const common = moduleUrl(await readFile(new URL('../public/assets/terminals-common.js', import.meta.url), 'utf8'));
const source = (await readFile(new URL('../public/assets/map-selection.js', import.meta.url), 'utf8')).replace(/'\.\/terminals-common\.js(?:\?[^']*)?'/, JSON.stringify(common));
const { focusTerminal } = await import(moduleUrl(source));
const record = { id: 3, name: 'Test terminal', address: 'Test address', coordinates_verified: true, coordinates: { latitude: 0.1, longitude: 0.2 } };

function fixture() {
  const calls = [];
  const state = {
    map: { stop() { calls.push(['stop']); }, invalidateSize(options) { calls.push(['resize', options]); }, setView(...args) { calls.push(['view', ...args]); } },
    markers: new Map([[3, { openPopup() { calls.push(['popup']); } }]]),
  };
  return { calls, state };
}

test('selecting a verified terminal automatically stops animations, centers the saved point, and opens its popup', () => {
  const f = fixture();
  assert.equal(focusTerminal(f.state, record), true);
  assert.deepEqual(f.calls, [
    ['stop'], ['resize', { pan: false, animate: false }],
    ['view', [0.1, 0.2], 17, { animate: false }], ['popup'],
  ]);
});

test('selection creates the verified marker when a stale marker index has not caught up', () => {
  const f = fixture();
  f.state.markers.clear();
  let addedTo;
  f.state.markerLayer = {};
  globalThis.document = { createElement: () => ({ children: [], append(...nodes) { this.children.push(...nodes); } }) };
  f.state.leaflet = { circleMarker(position) {
    assert.deepEqual(position, [0.1, 0.2]);
    return { bindPopup() { return this; }, addTo(layer) { addedTo = layer; return this; }, openPopup() { f.calls.push(['popup']); } };
  } };
  assert.equal(focusTerminal(f.state, record), true);
  assert.equal(addedTo, f.state.markerLayer);
  assert.ok(f.state.markers.has(3));
  assert.deepEqual(f.calls.at(-1), ['popup']);
});

test('pending locations and selections before map readiness never create guessed pins', () => {
  const f = fixture();
  assert.equal(focusTerminal(f.state, { ...record, coordinates_verified: false }), false);
  assert.equal(focusTerminal(f.state, { ...record, coordinates: null }), false);
  assert.equal(focusTerminal(null, record), false);
  assert.equal(focusTerminal(f.state, undefined), false);
  assert.equal(f.calls.length, 0);
});

test('a verified landmark reference focuses the map while the precise boarding pin remains pending', () => {
  const f = fixture();
  const landmark = { ...record, coordinates_verified: false, coordinates: null,
    landmark_coordinates_verified: true, landmark_coordinates: { latitude: 0.3, longitude: 0.4 } };
  assert.equal(focusTerminal(f.state, landmark), true);
  assert.deepEqual(f.calls[2], ['view', [0.3, 0.4], 17, { animate: false }]);
});
