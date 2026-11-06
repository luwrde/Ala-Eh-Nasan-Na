import { test } from 'node:test';
import assert from 'node:assert/strict';

// Start the project's PHP server before this optional integration check.
const base = new URL(process.env.ALA_EH_TEST_URL || 'http://localhost/');
test('live terminal API, details, error responses, and frontend assets', async () => {
  const response = await fetch(new URL('api/terminals.php', base));
  assert.equal(response.status, 200);
  assert.match(response.headers.get('content-type'), /application\/json/);
  const data = await response.json();
  assert.equal(data.totals.locations, data.terminals.length);
  assert.equal(data.totals.municipalities, new Set(data.terminals.map(record => record.municipality)).size);
  for (const record of data.terminals) {
    const detail = await fetch(new URL(`api/terminals.php?id=${record.id}`, base));
    assert.equal(detail.status, 200);
    assert.equal((await detail.json()).terminal.name, record.name);
  }
  for (const [query, status] of [['?id=99999999', 404], ['?id=0', 400], ['?id=-1', 400], ['?id=1%20OR%201%3D1', 400], ['?id[]=1', 400]]) {
    const error = await fetch(new URL(`api/terminals.php${query}`, base));
    assert.equal(error.status, status);
    assert.ok((await error.json()).error);
  }
  assert.equal((await fetch(new URL('api/terminals.php', base), { method: 'POST' })).status, 405);
  for (const asset of ['assets/route-preview.js', 'assets/route-preview.css', 'user-login.html', 'assets/login-intro.js', 'assets/login-intro.css', 'assets/jeepney-intro.svg', 'assets/intro-streetscape.svg']) {
    assert.equal((await fetch(new URL(asset, base))).status, 200, asset);
  }
  for (const asset of ['user-terminals.html', 'user-terminal-details.html?id=1', 'user-map.html', 'user-settings.html', 'assets/drawer-navigation.css', 'assets/map-experience.css', 'assets/settings.js', 'assets/settings.css', 'assets/map.js', 'assets/location-tracking.js', 'assets/map.css', 'assets/jeepney-tracing.js', 'assets/jeepney-tracing.css', 'assets/nearest-terminal.js', 'assets/routing.js', 'assets/terminals.js', 'assets/terminal-details.js', 'assets/terminals-common.js', 'assets/terminals-map.js', 'assets/terminals.css']) {
    assert.equal((await fetch(new URL(asset, base))).status, 200, asset);
  }
});

test('live jeepney paths API is read-only, exposes configured records, and validates IDs', async () => {
  const url = new URL('api/jeepney-routes.php', base);
  const response = await fetch(url);
  assert.equal(response.status, 200);
  assert.match(response.headers.get('content-type'), /application\/json/);
  const data = await response.json();
  assert.ok(Array.isArray(data.routes));
  const preview = data.routes.find(route => route.slug === 'grand-terminal-to-mabini');
  assert.ok(preview, 'Requested single pending test route is available');
  assert.equal(preview.status, 'pending');
  assert.equal(preview.geometry, null);
  assert.equal(preview.preview.route_verified, false);
  assert.equal(preview.preview.points.length, 7);
  assert.ok(preview.preview.geometry.coordinates.length > 100);
  assert.deepEqual(preview.stops, []);
  for (const route of data.routes) {
    const detail = await fetch(new URL(`api/jeepney-routes.php?id=${route.id}`, base));
    assert.equal(detail.status, 200);
    assert.equal((await detail.json()).route.id, route.id);
    if (route.status === 'verified') {
      assert.equal(route.geometry.type, 'LineString');
      assert.ok(route.verification_source && route.verified_by && route.verified_at);
    }
  }
  for (const [query, status] of [['?id=99999999', 404], ['?id=0', 400], ['?id=-1', 400], ['?id[]=1', 400], ['?id=1%20OR%201=1', 400]]) {
    const error = await fetch(new URL(`api/jeepney-routes.php${query}`, base));
    assert.equal(error.status, status);
    assert.ok((await error.json()).error);
  }
  assert.equal((await fetch(url, { method: 'POST' })).status, 405);
});
