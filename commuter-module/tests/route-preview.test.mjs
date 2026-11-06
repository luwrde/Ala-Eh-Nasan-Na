import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const moduleUrl = source => `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
const common = moduleUrl(await readFile(new URL('../public/assets/terminals-common.js', import.meta.url), 'utf8'));
const routing = moduleUrl(await readFile(new URL('../public/assets/routing.js', import.meta.url), 'utf8'));
const linked = source => source.replace(/'\.\/terminals-common\.js(?:\?[^']*)?'/, JSON.stringify(common))
  .replace(/'\.\/routing\.js(?:\?[^']*)?'/, JSON.stringify(routing));
const tracing = moduleUrl(linked(await readFile(new URL('../public/assets/jeepney-tracing.js', import.meta.url), 'utf8')));
const source = linked(await readFile(new URL('../public/assets/route-preview.js', import.meta.url), 'utf8'))
  .replace(/'\.\/jeepney-tracing\.js(?:\?[^']*)?'/, JSON.stringify(tracing));
const { createRoutePreview, usablePreview } = await import(moduleUrl(source));
const html = await readFile(new URL('../public/user-map.html', import.meta.url), 'utf8');
// Synthetic coordinates stay in this isolated test adapter, never in the seed/database.
const geometry = { type: 'LineString', coordinates: [[0,0],[.001,0],[.002,.001],[.003,.001],[.004,0],[.005,0],[.006,0]] };
const route = { slug: 'grand-terminal-to-mabini', name: 'TEST ROUTE', status: 'pending', geometry: null,
  preview: { kind: 'road-routing-preview', route_verified: false, geometry, distance_meters: 800,
    points: geometry.coordinates.map((point, i) => ({ role: i === 0 ? 'start' : i === 6 ? 'end' : 'via',
      name: `TEST ${i}`, coordinates: point, road_coordinates: point, route_stop_verified: false,
      note: 'Preliminary reference only', reference_name: '<img src=x>', source_url: 'https://www.openstreetmap.org/' })) } };

function fixture() {
  const nodes = new Map();
  const node = () => ({ hidden: true, disabled: false, textContent: '', children: [], events: {}, attributes: {},
    append(...children) { this.children.push(...children); }, replaceChildren(...children) { this.children = children; },
    setAttribute(name, value) { this.attributes[name] = value; }, addEventListener(type, fn) { this.events[type] = fn; },
    focus() {}, getBoundingClientRect() { return { width: 306, top: 500 }; } });
  for (const match of html.matchAll(/\bid="([^"]+)"/g)) nodes.set(match[1], node());
  nodes.get('system-map').getBoundingClientRect = () => ({ width: 390, bottom: 844 });
  globalThis.document = { getElementById: id => nodes.get(id), createElement: node };
  const calls = { lines: [], markers: [], groups: [], fits: [], zooms: [], before: 0 };
  const state = { map: { setMinZoom: n => calls.zooms.push(n), invalidateSize() {}, fitBounds: (...args) => calls.fits.push(args) },
    leaflet: {
      layerGroup() { const group = { addTo() { return this; }, remove() { this.removed = true; } }; calls.groups.push(group); return group; },
      geoJSON(geometry, options) {
        const path = node();
        const line = { geometry, options, events: {}, path, addTo() { return this; },
          setStyle(value) { this.style = value; }, getBounds: () => geometry.coordinates,
          on(event, fn) { this.events[event] = fn; }, eachLayer(fn) { fn({ getElement: () => path }); } };
        calls.lines.push(line); return line;
      },
      circleMarker(coordinates, options) {
        const marker = { coordinates, options, bindPopup(content) { this.popup = content; return this; }, addTo() { return this; } };
        calls.markers.push(marker); return marker;
      },
    } };
  return { nodes, calls, state };
}
async function browser(fetchRoutes = async () => [route]) {
  const f = fixture();
  const controller = createRoutePreview({ fetchRoutes, beforeShow: () => f.calls.before++ });
  controller.setMapState(f.state);
  await new Promise(resolve => setImmediate(resolve));
  return { ...f, controller };
}

test('only pending road previews with sourced ordered references are eligible', () => {
  assert.equal(usablePreview(route), true);
  assert.equal(usablePreview({ ...route, status: 'verified' }), false);
  assert.equal(usablePreview({ ...route, preview: { ...route.preview, route_verified: true } }), false);
  assert.equal(usablePreview({ ...route, preview: { ...route.preview, geometry: null } }), false);
  assert.equal(usablePreview({ ...route, preview: { ...route.preview, points: [...route.preview.points].reverse() } }), false);
});
test('one explicit route action draws the complete solid blue path, seven references, and compact details', async () => {
  const f = await browser();
  assert.equal(f.calls.lines.length, 0); // Ordinary Map opening keeps the user-location view.
  assert.equal(f.nodes.get('route-preview-panel').hidden, true);
  await f.nodes.get('route-preview-toggle').events.click();
  assert.equal(f.calls.before, 1);
  const line = f.calls.lines.at(-1);
  assert.equal(line.geometry, geometry);
  assert.equal(line.options.style.color, '#2563eb');
  assert.equal(line.options.style.dashArray, null);
  assert.equal(line.style.weight, 7);
  assert.equal(f.calls.markers.length, 7);
  assert.deepEqual(f.calls.markers.map(m => m.options.fillColor), ['#16a34a', ...Array(5).fill('#64748b'), '#16a34a']);
  assert.equal(f.calls.markers[0].popup.children[2].textContent, 'Map reference: <img src=x>');
  assert.equal(f.calls.markers[0].popup.children.at(-1).rel, 'noopener noreferrer');
  assert.equal(f.nodes.get('route-preview-title').textContent, 'TEST ROUTE');
  assert.equal(f.nodes.get('route-preview-start').textContent, 'TEST 0');
  assert.equal(f.nodes.get('route-preview-end').textContent, 'TEST 6');
  assert.equal(f.nodes.get('route-preview-waypoints').children.length, 6);
  assert.equal(f.nodes.get('route-preview-distance').textContent, '800 m');
  assert.deepEqual(f.calls.fits[0][0], geometry.coordinates);
  assert.equal(f.calls.zooms.at(-1), 8);
  assert.ok(f.calls.fits[0][1].paddingBottomRight[1] > 300); // Leave mobile details unobscured.
});
test('clicking/keyboard-selecting the whole trace highlights it after minimizing; hide removes layers', async () => {
  const f = await browser();
  await f.nodes.get('route-preview-toggle').events.click();
  const line = f.calls.lines.at(-1);
  f.nodes.get('route-preview-minimize').events.click();
  assert.equal(f.nodes.get('route-preview-panel').hidden, true);
  assert.equal(line.style.weight, 5);
  line.events.click();
  assert.equal(f.nodes.get('route-preview-panel').hidden, false);
  assert.equal(line.style.weight, 7);
  f.nodes.get('route-preview-minimize').events.click();
  line.path.events.keydown({ key: 'Enter', preventDefault() {} });
  assert.equal(f.nodes.get('route-preview-panel').hidden, false);
  f.nodes.get('route-preview-fit').events.click();
  assert.equal(f.calls.fits.length, 2);
  f.nodes.get('route-preview-hide').events.click();
  assert.equal(f.calls.groups.at(-1).removed, true);
  assert.equal(f.nodes.get('route-preview-toggle').attributes['aria-pressed'], 'false');
  assert.equal(f.calls.zooms.at(-1), 12);
});
test('missing/malformed routes and API failure draw no fallback; explicit retry can recover', async () => {
  let attempts = 0;
  const f = await browser(async () => { if (++attempts === 1) throw new Error('offline'); return [route]; });
  assert.equal(f.nodes.get('route-preview-message').hidden, false);
  assert.equal(f.calls.lines.length, 0);
  await f.nodes.get('route-preview-toggle').events.click();
  assert.equal(attempts, 2);
  assert.equal(f.calls.lines.length, 2);
  f.controller.clear();
  assert.equal(f.nodes.get('route-preview-panel').hidden, true);
  const missing = await browser(async () => [{ ...route, slug: 'other-route' }]);
  await missing.nodes.get('route-preview-toggle').events.click();
  assert.equal(missing.calls.lines.length, 0);
  assert.equal(missing.nodes.get('route-preview-message').hidden, false);
});
test('restoring city zoom never queues an animation that can override the next route fit', async () => {
  const f = await browser();
  const zooms = [];
  f.state.map.getZoom = () => 11;
  f.state.map.setZoom = (zoom, options) => zooms.push({ zoom, options });
  await f.nodes.get('route-preview-toggle').events.click();
  assert.deepEqual(zooms[0], { zoom: 12, options: { animate: false } });
  f.controller.clear();
  assert.deepEqual(zooms.at(-1), { zoom: 12, options: { animate: false } });
});
