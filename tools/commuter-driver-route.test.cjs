const test = require('node:test');
const assert = require('node:assert/strict');
const routing = require('../public/assets/jeepney-routing.js');
const routes = require('../public/assets/jeepney-routes.json');
const diversion = require('../public/assets/diversion-mabini-road.json');
const mabini = require('../public/assets/mabini-area-roads.json');

const areas = [
  { locationKey: 'grand-terminal', coordinates: [121.061838, 13.789913] },
  { locationKey: 'total-djpmm', coordinates: [121.0516556, 13.7561539] },
  { locationKey: 'diversion-kwatogs', coordinates: [121.0499724, 13.7715645] },
  { locationKey: 'mabini-tourism-triangle', coordinates: [120.9406161, 13.7525621] },
  { locationKey: 'mabini-seven-eleven', coordinates: [120.9404809, 13.749087] },
  { locationKey: 'mabini-iconails', coordinates: [120.9406855, 13.7477068] },
];
const assets = new Map([
  ['jeepney-routes.json', routes], ['diversion-mabini-road.json', diversion], ['mabini-area-roads.json', mabini],
]);
const base = new URL('https://example.test/app/assets/');

test('commuter View route matches every coordinate of the driver preview for all six assigned areas', async t => {
  const requests = [];
  t.mock.method(globalThis, 'fetch', async url => {
    assert.equal(new URL('.', url).href, base.href);
    const name = url.pathname.split('/').at(-1);
    requests.push(name);
    return { ok: true, json: async () => structuredClone(assets.get(name)) };
  });
  const data = routing.assignedServiceData(routes, diversion, mabini);
  for (const area of areas) {
    const key = routing.assignedAreaRoute(area.locationKey);
    const service = data.routes.find(route => String(route.id) === key);
    const driver = routing.assignedAreaPreview(service, area, data.connectorRoutes, data.assignedAreaRoads);
    const commuter = await routing.loadAssignedRoute(key, area, base);
    assert.deepEqual(commuter.coordinates, driver.coordinates, area.locationKey);
    assert.equal(commuter.isGeneralRoute, driver.isGeneralRoute, area.locationKey);
    assert.deepEqual(commuter.coordinates.at(-1), key === '12000002' ? [120.940614, 13.748091] : [121.058717, 13.755145]);
    if (!commuter.isGeneralRoute) assert.deepEqual(commuter.coordinates[0], area.coordinates);
    assert.notEqual(commuter.route.id, 10755763, 'must not substitute the Bauan service');
  }
  assert.equal(requests.length, areas.length * 3);
});

test('missing detailed roads preserve the same general fallback as the driver without moving its start', async t => {
  t.mock.method(globalThis, 'fetch', async url => ({ ok: url.pathname.endsWith('jeepney-routes.json'), json: async () => routes }));
  const area = areas[2];
  const commuter = await routing.loadAssignedRoute('12000002', area, base);
  const data = routing.assignedServiceData(routes, null, null);
  const driver = routing.assignedAreaPreview(data.routes.find(route => route.id === 12000002), area, data.connectorRoutes, data.assignedAreaRoads);
  assert.equal(commuter.isGeneralRoute, true);
  assert.deepEqual(commuter.coordinates, driver.coordinates);
  assert.notDeepEqual(commuter.coordinates[0], area.coordinates);
});

test('invalid assignments and unavailable route data do not silently show a different route', async t => {
  t.mock.method(globalThis, 'fetch', async () => { throw new Error('offline'); });
  await assert.rejects(routing.loadAssignedRoute('12000001', areas[0], base), /Invalid driver route assignment/);
  await assert.rejects(routing.loadAssignedRoute('12000002', areas[0], base), /offline/);
});
