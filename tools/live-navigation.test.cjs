const test = require('node:test');
const assert = require('node:assert/strict');
const { distance } = require('../public/assets/jeepney-routing.js');
const { locate } = require('../public/assets/live-navigation.js');
const a = [121, 13], b = [121.02, 13];
const length = distance(a, b);
const route = { coordinates: [a, b], roadNames: ['Test road'], roundtrip: false,
  stops: [{ id: 'one', name: 'First', coordinates: [121.005, 13], along: length * .25 }, { id: 'two', name: 'Second', coordinates: [121.015, 13], along: length * .75 }] };
const fix = (coordinates, extra = {}) => ({ coordinates, accuracy: 8, speed: null, heading: null, ...extra });
test('GPS movement advances the next stop and reduces route distance', () => {
  const first = locate(route, fix([121.001, 13]));
  const closer = locate(route, fix([121.003, 13]), first.progress);
  assert.equal(first.upcoming[0].id, 'one');
  assert.ok(closer.upcoming[0].distance < first.upcoming[0].distance);
  const passed = locate(route, fix([121.006, 13]), closer.progress);
  assert.equal(passed.upcoming[0].id, 'two');
});
test('being at a stop displays the following stop as next', () => {
  const result = locate(route, fix([121.005, 13]));
  assert.equal(result.atStop.id, 'one'); assert.equal(result.upcoming[0].id, 'two');
});
test('poor GPS and off-route fixes do not supply guessed next stops', () => {
  assert.equal(locate(route, fix(a, { accuracy: 150 })).state, 'weak');
  assert.equal(locate(route, fix([121.01, 13.01])).state, 'off_route');
  assert.equal(locate(route, fix([NaN, 13])).state, 'invalid');
});
test('wrong-way movement is flagged and route completion is detected', () => {
  assert.equal(locate(route, fix([121.01, 13], { speed: 8, heading: 270 })).state, 'wrong_direction');
  assert.equal(locate(route, fix(b)).finished, true);
});
const loop = { coordinates: [a, b, a], roadNames: ['Outbound', 'Return'], roundtrip: true,
  stops: [...route.stops, { id: 'return', name: 'Return stop', coordinates: [121.005, 13], along: length * 1.75 }] };
test('overlapping route legs require direction instead of guessing', () => {
  assert.equal(locate(loop, fix([121.01, 13])).state, 'ambiguous');
  assert.equal(locate(loop, fix([121.01, 13], { heading: 90, speed: 8 })).road, 'Outbound');
  assert.equal(locate(loop, fix([121.01, 13], { heading: 270, speed: 8 })).road, 'Return');
});
test('a next-stop hint resolves the return leg; an incompatible hint is rejected', () => {
  assert.equal(locate(loop, fix([121.01, 13]), null, 'return').road, 'Return');
  assert.equal(locate(route, fix([121.019, 13]), null, 'one').state, 'wrong_hint');
});
test('previous progress keeps the same leg when GPS heading is unavailable', () => {
  const initial = locate(loop, fix([121.01, 13]), null, 'return');
  const next = locate(loop, fix([121.009, 13]), initial.progress);
  assert.equal(next.road, 'Return');
  assert.equal(next.upcoming[0].id, 'return');
});
