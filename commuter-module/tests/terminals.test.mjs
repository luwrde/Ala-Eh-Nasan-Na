import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/terminals-common.js', import.meta.url), 'utf8');
const { filterTerminals, matchingTerminalRoutes, routeSourceUrl, verifiedCoordinates, directionsUrl, detailsUrl } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const records = [
  { id: 1, name: 'Total Gas Station', address: 'DJPMM Access Road, Batangas City', municipality: 'Batangas City' },
  { id: 2, name: 'Diversion Road', address: "In front of Kwatog's, Barangay Bolbok, Batangas City", municipality: 'Batangas City' },
  { id: 3, name: 'Batangas City Grand Terminal', address: 'Diversion Road, Barangay Alangilan, Batangas City', municipality: 'Batangas City' },
  { id: 4, name: 'Mabini Tourism Triangle', address: 'Left side, F. Castillo Boulevard, Mabini', municipality: 'Mabini' },
  { id: 5, name: '7-Eleven Mabini', address: 'In front of 7-Eleven, left side of F. Castillo Boulevard, Mabini', municipality: 'Mabini' },
  { id: 6, name: 'Iconails Beauty Lounge', address: 'In front of Iconails Beauty Lounge, Mabini Circumferential Road', municipality: 'Mabini' },
];

test('name, landmark, municipality, word order, and case-insensitive search', () => {
  assert.equal(filterTerminals(records, ' TOTAL  ').length, 1);
  assert.equal(filterTerminals(records, "kwatog's")[0].id, 2);
  assert.equal(filterTerminals(records, 'bolbok diversion')[0].id, 2);
  assert.equal(filterTerminals(records, 'mabini').length, 3);
  assert.equal(filterTerminals(records, 'Mabini Batangas').length, 3);
});
test('municipality filter combines with search and can reset', () => {
  assert.equal(filterTerminals(records, 'Castillo', 'Mabini').length, 2);
  assert.equal(filterTerminals(records, 'Castillo', 'Batangas City').length, 0);
  assert.equal(filterTerminals(records, '', 'Batangas City').length, 3);
  assert.equal(filterTerminals(records).length, 6);
  assert.equal(filterTerminals(records, 'no such landmark').length, 0);
});

test('destination searches find serving boarding points, not just locations with that municipality name', () => {
  const services = records.map(record => record.id === 3 ? { ...record, routes: [
    { id: 1, name: 'Batangas - Testville (Grand Terminal)', destination: 'Testville', verified: true },
    { id: 2, name: 'Unreviewed Lipa service', destination: 'Lipa', verified: false },
  ] } : record);
  assert.deepEqual(filterTerminals(services, 'TESTVILLE').map(record => record.id), [3]);
  assert.deepEqual(filterTerminals(services, 'Testville Alangilan', 'Batangas City').map(record => record.id), [3]);
  assert.equal(matchingTerminalRoutes(services[2], 'testville alangilan')[0].destination, 'Testville');
  assert.equal(filterTerminals(services, 'Testville', 'Mabini').length, 0);
  assert.equal(filterTerminals(services, 'Lipa').length, 0, 'unverified routes never become boarding advice');
  assert.equal(matchingTerminalRoutes(services[2], 'Mabini').length, 0);
  assert.equal(filterTerminals(services, 'Testville Lipa').length, 0, 'different routes must not be combined into a made-up service');
  assert.equal(filterTerminals(records, 'Testville').length, 0, 'missing route data must not suggest an unrelated nearby terminal');
});

test('route reference URLs reject executable and credential-bearing links', () => {
  assert.equal(routeSourceUrl({ source_url: 'https://example.org/route.pdf#page=2' }), 'https://example.org/route.pdf#page=2');
  for (const value of ['javascript:alert(1)', 'data:text/html,hello', 'http://example.org', 'https://user:pass@example.org', null]) assert.equal(routeSourceUrl({ source_url: value }), null);
});
test('map ignores missing, unverified, nonnumeric, infinite, or out-of-range coordinates', () => {
  for (const record of [{}, { coordinates_verified: true, coordinates: null },
    { coordinates_verified: false, coordinates: { latitude: 0, longitude: 0 } },
    ...[null, '', '10', NaN, Infinity, 91].map(latitude => ({ coordinates_verified: true, coordinates: { latitude, longitude: 0 } })),
    { coordinates_verified: true, coordinates: { latitude: 0, longitude: 181 } }]) {
    assert.equal(verifiedCoordinates(record), null);
    assert.equal(directionsUrl(record), null);
  }
});
test('valid zero coordinates and safely encoded external directions URL', () => {
  const record = { coordinates_verified: true, coordinates: { latitude: 0, longitude: 0 } };
  assert.deepEqual(verifiedCoordinates(record), [0, 0]);
  const url = new URL(directionsUrl(record));
  assert.equal(url.origin, 'https://www.google.com');
  assert.equal(url.searchParams.get('api'), '1');
  assert.equal(url.searchParams.get('destination'), '0,0');
  assert.equal(detailsUrl(6), 'user-terminal-details.html?id=6');
});
