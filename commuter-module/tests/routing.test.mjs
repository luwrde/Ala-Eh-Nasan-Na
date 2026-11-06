import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/routing.js', import.meta.url), 'utf8');
const { routeUrl, viaRouteUrl, readRoute, routeClient, validPosition, formatDistance, formatDuration } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const origin = [0, 0], destination = [0.01, 0.01]; // Isolated synthetic test points.
const payload = () => ({ code: 'Ok', waypoints: [{ distance: 4 }, { distance: 10 }], routes: [{ distance: 1500, duration: 900, geometry: { type: 'LineString', coordinates: [[0, 0], [0.01, 0.01]] } }] });

test('ordered via references use one full road response and reject missing or distant snaps', () => {
  const points = [[0, 0], [.005, .006], [.01, .01]];
  const url = viaRouteUrl(points);
  assert.match(url.pathname, /0\.000000,0\.000000;0\.006000,0\.005000;0\.010000,0\.010000$/);
  assert.equal(url.searchParams.get('overview'), 'full');
  assert.equal(url.searchParams.get('radiuses'), '200;200;200');
  const response = { ...payload(), waypoints: [{ distance: 4 }, { distance: 15 }, { distance: 10 }] };
  assert.equal(readRoute(response, 'driving', 3).geometry, response.routes[0].geometry);
  assert.throws(() => readRoute(payload(), 'driving', 3));
  response.waypoints[1].distance = 201;
  assert.throws(() => readRoute(response, 'driving', 3));
  for (const invalid of [[], [origin], [...points, [91, 0]], Array(31).fill(origin)]) assert.throws(() => viaRouteUrl(invalid));
});

test('walking and driving use distinct routing graphs with longitude-first coordinates', () => {
  const walk = routeUrl([10, 20], [11, 21], 'walking');
  const drive = routeUrl([10, 20], [11, 21], 'driving');
  assert.match(walk.pathname, /^\/routed-foot\/route\/v1\/foot\/20\.000000,10\.000000;21\.000000,11\.000000$/);
  assert.match(drive.pathname, /^\/routed-car\//);
  assert.equal(walk.searchParams.get('geometries'), 'geojson');
  assert.equal(walk.searchParams.get('radiuses'), '50;50');
  assert.equal(walk.searchParams.get('alternatives'), 'true');
  assert.equal(drive.searchParams.get('radiuses'), '200;200');
  assert.equal(drive.searchParams.get('alternatives'), 'false');
  for (const position of [null, [null, 0], [NaN, 0], [91, 0], [0, 181], ['0', 0]]) {
    assert.equal(validPosition(position), false);
    assert.throws(() => routeUrl(position, destination, 'walking'));
  }
  assert.throws(() => routeUrl(origin, destination, 'flying'));
});
test('walking chooses the shortest valid returned foot path, not the first recommendation', () => {
  const response = payload();
  const long = { ...response.routes[0], distance: 2000, duration: 1300 };
  const short = { ...response.routes[0], distance: 1100, duration: 800,
    geometry: { type: 'LineString', coordinates: [[0,0],[.004,.001],[.01,.01]] } };
  const invalidShortcut = { ...short, distance: 100, geometry: null };
  response.routes = [long, invalidShortcut, short];
  assert.equal(readRoute(response, 'walking').distance, 1100);
  assert.equal(readRoute(response, 'walking').geometry, short.geometry);
  assert.equal(readRoute(response, 'driving').distance, 2000);
  response.routes = [short];
  assert.equal(readRoute(response, 'walking').distance, 1100);
  response.waypoints[0].distance = 51;
  assert.throws(() => readRoute(response, 'walking'), error => error.code === 'off-road');
  assert.equal(readRoute(response, 'driving').distance, 1100);
});
test('only real valid route responses become distance estimates; no straight-line fallback', () => {
  assert.equal(readRoute(payload()).distance, 1500);
  for (const response of [{ code: 'NoRoute' }, { code: 'NoSegment' }, {},
    { ...payload(), routes: [{ ...payload().routes[0], distance: -1 }] },
    { ...payload(), routes: [{ ...payload().routes[0], duration: Infinity }] },
    { ...payload(), routes: [{ ...payload().routes[0], geometry: { type: 'LineString', coordinates: [[181, 0], [0, 0]] } }] },
    { ...payload(), waypoints: [{ distance: 201 }, { distance: 1 }] }]) {
    assert.throws(() => readRoute(response));
  }
  assert.equal(formatDistance(1500), '1.5 km');
  assert.equal(formatDistance(280), '280 m');
  assert.equal(formatDistance(null), '—');
  assert.equal(formatDuration(30), '<1 min');
  assert.equal(formatDuration(3660), '1 h 1 min');
});
test('cache and queue avoid duplicate calls and respect provider rate limits', async () => {
  let clock = 10000;
  const starts = [];
  const client = routeClient({ now: () => clock, wait: async delay => { clock += delay; }, fetcher: async () => { starts.push(clock); return { ok: true, status: 200, json: async () => payload() }; } });
  await Promise.all([client(origin, destination, 'walking'), client(origin, destination, 'driving'), client(origin, destination, 'walking')]);
  assert.equal(starts.length, 2);
  assert.ok(starts[1] - starts[0] >= 1100);
});
test('network failures, busy services, and cancelled requests never produce estimated numbers', async () => {
  const unavailable = routeClient({ fetcher: async () => { throw new Error('Offline'); } });
  await assert.rejects(unavailable(origin, destination, 'walking'), error => error.code === 'unavailable');
  const busy = routeClient({ fetcher: async () => ({ status: 429 }) });
  await assert.rejects(busy(origin, destination, 'walking'), error => error.code === 'busy');
  const farSnap = routeClient({ fetcher: async () => ({ ok: true, status: 200, json: async () => ({ ...payload(), waypoints: [{ distance: 80 }, { distance: 4 }] }) }) });
  await assert.rejects(farSnap(origin, destination, 'walking'), error => error.code === 'off-road');
  const controller = new AbortController(); controller.abort();
  let called = false;
  const cancelled = routeClient({ fetcher: async () => { called = true; } });
  await assert.rejects(cancelled(origin, destination, 'walking', controller.signal), error => error.name === 'AbortError');
  assert.equal(called, false);
});
