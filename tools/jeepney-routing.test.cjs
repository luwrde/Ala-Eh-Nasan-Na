const test = require('node:test');
const assert = require('node:assert/strict');
const { match, distance, stopsAlong, positionStops } = require('../public/assets/jeepney-routing.js');
const { routes } = require('../public/assets/jeepney-routes.json');
const bauan = [121.0077783, 13.7899158];
const city = [121.0591276, 13.755232];
const terminal = [121.0612976, 13.7895245];

test('Bauan to city proper follows BW-02 National Highway 436', () => {
  const result = match(routes, bauan, city);
  assert.equal(result.route.id, 5391157);
  assert.ok(result.roads.includes('National Highway 436'));
  assert.ok(result.roads.includes('P. Burgos Street'));
  assert.ok(!result.roads.some((road) => /bypass/i.test(road)));
  assert.ok(result.distance > 6000 && result.distance < 9000);
});
test('Grand Terminal selects its own directional variants', () => {
  assert.equal(match(routes, bauan, terminal).route.id, 10755762);
  assert.equal(match(routes, terminal, bauan).route.id, 10755763);
});
test('reverse city journey follows the loop instead of reversing one-way roads', () => {
  const outbound = match(routes, bauan, city);
  const inbound = match(routes, city, bauan);
  assert.ok(inbound.roads.includes('M. H. del Pilar Street'));
  assert.ok(inbound.distance > outbound.distance);
  assert.ok(distance(inbound.coordinates[0], city) < 200);
});
test('uncovered locations and identical endpoints do not fabricate a jeepney route', () => {
  assert.equal(match(routes, bauan, [121.16, 13.94]), null);
  assert.equal(match(routes, bauan, bauan), null);
});
test('non-loop geometry cannot be traversed backwards', () => {
  const route = { coordinates: [[121, 13], [121.04, 13]], roadNames: ['One-way route'], roundtrip: false };
  assert.ok(match([route], [121, 13], [121.04, 13]));
  assert.equal(match([route], [121.04, 13], [121, 13]), null);
});
test('bundled route segments remain continuous and have matching road names', () => {
  for (const route of routes) {
    assert.equal(route.roadNames.length, route.coordinates.length - 1);
    for (let i = 1; i < route.coordinates.length; i += 1) assert.ok(distance(route.coordinates[i - 1], route.coordinates[i]) < 1000);
    if (route.roundtrip) assert.deepEqual(route.coordinates[0], route.coordinates.at(-1));
  }
});
test('loop stops preserve recorded travel order across shared outbound and return roads', () => {
  const route = routes.find((route) => route.id === 5391157);
  assert.deepEqual(stopsAlong({ route }).map((stop) => stop.id), route.stops.map((stop) => stop.id));
  assert.deepEqual(positionStops(route).map((stop) => stop.along), route.stops.map((stop) => stop.along));
  const outbound = stopsAlong(match(routes, bauan, city));
  const inbound = stopsAlong(match(routes, city, bauan));
  assert.ok(outbound.some((stop) => stop.id === 'osm-5301974436'));
  assert.ok(!outbound.some((stop) => stop.id === 'osm-7579285295'));
  assert.ok(inbound.some((stop) => stop.id === 'osm-7579285295'));
  assert.ok(!inbound.some((stop) => stop.id === 'osm-5301974436'));
});
test('terminal arrival and departure keep their directional boarding restrictions', () => {
  const arrivalPlatform = routes.find((route) => route.id === 10755762).stops.at(-1).coordinates;
  const arriving = stopsAlong(match(routes, bauan, arrivalPlatform));
  const leaving = stopsAlong(match(routes, terminal, bauan));
  assert.ok(arriving.some((stop) => stop.access === 'Alighting only'));
  assert.equal(leaving[0].id, 'osm-7246626950');
  assert.equal(leaving[0].access, 'Boarding only');
});
test('unlocated, unassociated, and distant custom locations never become trip stops', () => {
  const route = routes.find((route) => route.id === 10755763);
  const extras = [
    { id: 'missing', coordinates: null, routeIds: [route.id] },
    { id: 'unassigned', coordinates: terminal, routeIds: [] },
    { id: 'far', coordinates: [120.94, 13.75], routeIds: [route.id] },
    { id: 'duplicate', coordinates: terminal, routeIds: [route.id] },
  ];
  assert.deepEqual(stopsAlong({ route }, extras).map((stop) => stop.id), stopsAlong({ route }).map((stop) => stop.id));
});
