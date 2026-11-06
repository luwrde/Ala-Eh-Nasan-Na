import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const source = await readFile(new URL('../public/assets/terminals-common.js', import.meta.url), 'utf8');
const { googleMapsUrl, directionsUrl, mapCoordinates, verifiedCoordinates, landmarkCoordinates } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
const reference = { google_maps_url: 'https://maps.app.goo.gl/mi7CqzXjvnwnZBSy8', coordinates_verified: false, coordinates: null,
  landmark_coordinates_verified: true, landmark_coordinates: { latitude: 13.7560962, longitude: 121.0518226 } };

test('landmark references support map/navigation coordinates without changing boarding verification', () => {
  assert.deepEqual(mapCoordinates(reference), [13.7560962, 121.0518226]);
  assert.equal(verifiedCoordinates(reference), null);
  assert.equal(googleMapsUrl(reference), reference.google_maps_url);
  const directions = new URL(directionsUrl(reference));
  assert.equal(directions.searchParams.get('api'), '1');
  assert.equal(directions.searchParams.get('destination'), '13.7560962,121.0518226');
});
test('configured precise coordinates take priority over landmark coordinates', () => {
  const precise = { ...reference, coordinates_verified: true, coordinates: { latitude: 1, longitude: 2 } };
  assert.deepEqual(mapCoordinates(precise), [1, 2]);
  assert.deepEqual(verifiedCoordinates(precise), [1, 2]);
});
test('missing or invalid reference data never becomes a pin or unsafe navigation URL', () => {
  for (const coordinates of [null, { latitude: 91, longitude: 0 }, { latitude: '13.7', longitude: 121 }]) {
    assert.equal(landmarkCoordinates({ ...reference, landmark_coordinates: coordinates }), null);
  }
  assert.equal(landmarkCoordinates({ ...reference, landmark_coordinates_verified: false }), null);
  for (const url of ['javascript:alert(1)', 'https://evil.example/maps/', 'https://www.google.com.evil.example/maps/', 'https://user:password@www.google.com/maps/', 'http://maps.app.goo.gl/example']) {
    assert.equal(googleMapsUrl({ google_maps_url: url }), null);
  }
  assert.equal(directionsUrl({}), null);
  assert.equal(mapCoordinates({}), null);
});
test('Grand Terminal can open Google Maps using its existing pin without inventing a supplied link', () => {
  const grand = { coordinates_verified: true, coordinates: { latitude: 13.78986, longitude: 121.06235 } };
  const url = new URL(googleMapsUrl(grand));
  assert.equal(url.pathname, '/maps/search/');
  assert.equal(url.searchParams.get('query'), '13.78986,121.06235');
});
