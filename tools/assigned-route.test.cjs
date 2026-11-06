const test = require('node:test');
const assert = require('node:assert/strict');
const { assignedJourney, assignedRoutePreview, assignedAreaRoute, distance } = require('../public/assets/jeepney-routing.js');
const diversionRoad = require('../public/assets/diversion-mabini-road.json');
const mabiniRoads = require('../public/assets/mabini-area-roads.json');

// Synthetic geometry tests joining/direction; not production location data.
const route = {
  id: 1, name: 'Intended corridor', roundtrip: false,
  coordinates: [[121, 13], [121.01, 13], [121.02, 13]],
};
const start = [121, 13.02];
const connector = {
  roundtrip: false,
  coordinates: [start, [120.99, 13.02], [120.99, 13], [121, 13]],
};

test('assigned start follows the recorded connector, then the intended corridor', () => {
  const result = assignedJourney(route, start, [connector]);
  assert.deepEqual(result.coordinates[0], start);
  assert.deepEqual(result.coordinates.at(-1), route.coordinates.at(-1));
  assert.ok(result.coordinates.some(p => p[0] === 120.99 && p[1] === 13.02));
  assert.ok(result.coordinates.some(p => p[0] === 120.99 && p[1] === 13));
  assert.equal(result.route.id, route.id);
});

test('changing starting area trims the route and leaves the source unchanged', () => {
  const snapshot = JSON.stringify(route);
  const result = assignedJourney(route, [121.01, 13], []);
  assert.deepEqual(result.coordinates[0], [121.01, 13]);
  assert.deepEqual(result.coordinates.at(-1), [121.02, 13]);
  assert.ok(!result.coordinates.some(p => p[0] < 121.01));
  assert.equal(JSON.stringify(route), snapshot);
});

test('missing pins and unmapped gaps produce no fabricated route', () => {
  assert.equal(assignedJourney(route, null, [connector]), null);
  assert.equal(assignedJourney(route, start, []), null);
  assert.equal(assignedJourney(route, [NaN, 13], [connector]), null);
});

test('connector direction is respected', () => {
  assert.equal(assignedJourney(route, start, [{
    ...connector, coordinates: connector.coordinates.slice().reverse(),
  }]), null);
});

test('all six approved areas choose the outgoing route for their municipality', () => {
  for (const key of ['total-djpmm', 'diversion-kwatogs', 'grand-terminal']) {
    assert.equal(assignedAreaRoute(key), '12000002', key);
  }
  for (const key of ['mabini-tourism-triangle', 'mabini-seven-eleven', 'mabini-iconails']) {
    assert.equal(assignedAreaRoute(key), '12000001', key);
  }
  for (const key of ['', 'custom-pin', 'toString', null]) {
    assert.equal(assignedAreaRoute(key), '');
  }
});

test('reverse travel trims the orange line toward the opposite destination', () => {
  const reverse = { ...route, coordinates: route.coordinates.slice().reverse() };
  const result = assignedJourney(reverse, [121.01, 13]);
  assert.deepEqual(result.coordinates[0], [121.01, 13]);
  assert.deepEqual(result.coordinates.at(-1), [121, 13]);
  assert.ok(result.coordinates.every(p => p[0] <= 121.01));
});

test('reverse travel follows its directional connector before joining the corridor', () => {
  const reverse = { ...route, coordinates: route.coordinates.slice().reverse() };
  const reverseStart = [121.02, 13.02];
  const reverseConnector = { roundtrip: false, coordinates: [reverseStart, [121.03, 13.02], [121.03, 13], [121.02, 13]] };
  const result = assignedJourney(reverse, reverseStart, [reverseConnector]);
  assert.deepEqual(result.coordinates[0], reverseStart);
  assert.deepEqual(result.coordinates.at(-1), [121, 13]);
  assert.ok(result.coordinates.some(p => p[0] === 121.03 && p[1] === 13.02));
});

test('missing assigned pins keep both directional routes visible without invented stops', () => {
  for (const directional of [route, { ...route, coordinates: route.coordinates.slice().reverse() }]) {
    const result = assignedRoutePreview(directional, null);
    assert.equal(result.isGeneralRoute, true);
    assert.deepEqual(result.coordinates, directional.coordinates);
    assert.notEqual(result.coordinates, directional.coordinates);
    assert.deepEqual(result.route.stops, []);
    assert.ok(result.distance > 0);
  }
});

test('unconnected assigned pin falls back to service geometry without a straight connector', () => {
  const result = assignedRoutePreview(route, start);
  assert.equal(result.isGeneralRoute, true);
  assert.deepEqual(result.coordinates, route.coordinates);
  assert.ok(!result.coordinates.some(point => point[1] === start[1]));
});

test('verified connected assigned pin still produces an exact starting route', () => {
  const result = assignedRoutePreview(route, start, [connector]);
  assert.equal(result.isGeneralRoute, false);
  assert.deepEqual(result.coordinates[0], start);
  assert.deepEqual(result.coordinates.at(-1), route.coordinates.at(-1));
});

test('Diversion Road to Mabini follows a detailed continuous road trace', () => {
  const points = diversionRoad.coordinates;
  assert.ok(points.length > 400);
  assert.ok(distance(points[0], [121.0499724, 13.7715645]) < 40);
  assert.deepEqual(points.at(-1), [120.940614, 13.748091]);
  assert.ok(points.slice(1).every((point, i) => distance(points[i], point) < 500));
  assert.ok(points.some(point => point[0] === 121.040871 && point[1] === 13.778187));
});

test('each Mabini landmark has a continuous road trace to Batangas City', () => {
  const pins = new Map([
    ['mabini-tourism-triangle', [120.9406161, 13.7525621]],
    ['mabini-seven-eleven', [120.9404809, 13.749087]],
    ['mabini-iconails', [120.9406855, 13.7477068]],
  ]);
  assert.equal(mabiniRoads.routes.length, pins.size);
  for (const road of mabiniRoads.routes) {
    assert.ok(pins.has(road.locationKey));
    assert.ok(road.coordinates.length > 450);
    assert.ok(distance(road.coordinates[0], pins.get(road.locationKey)) < 20);
    assert.deepEqual(road.coordinates.at(-1), [121.058717, 13.755145]);
    assert.ok(road.coordinates.slice(1).every((point, i) => distance(road.coordinates[i], point) < 500));
  }
});
