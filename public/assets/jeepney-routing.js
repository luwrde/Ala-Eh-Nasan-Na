/* Direction-aware matching against the recorded jeepney route, never a car shortcut. */
((root) => {
  'use strict';
  const radians = (value) => value * Math.PI / 180;
  function distance(a, b) {
    const dLat = radians(b[1] - a[1]);
    const dLon = radians(b[0] - a[0]);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(radians(a[1])) * Math.cos(radians(b[1])) * Math.sin(dLon / 2) ** 2;
    return 6371000 * 2 * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  function projections(point, coordinates, cumulative) {
    const scale = Math.cos(radians(point[1]));
    const candidates = [];
    for (let i = 0; i < coordinates.length - 1; i += 1) {
      const a = coordinates[i];
      const b = coordinates[i + 1];
      const dx = (b[0] - a[0]) * scale;
      const dy = b[1] - a[1];
      const length = dx * dx + dy * dy;
      if (!length) continue;
      const t = Math.max(0, Math.min(1, (((point[0] - a[0]) * scale * dx) + (point[1] - a[1]) * dy) / length));
      const projected = [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
      candidates.push({ index: i, point: projected, offset: distance(point, projected), along: cumulative[i] + distance(a, projected) });
    }
    candidates.sort((a, b) => a.offset - b.offset);
    return candidates;
  }
  function anchors(point, coordinates, cumulative) {
    const candidates = projections(point, coordinates, cumulative);
    const minimum = candidates[0]?.offset ?? Infinity;
    return candidates.filter((candidate) => candidate.offset <= Math.min(1200, minimum + 80)).slice(0, 8);
  }
  // A loop can pass the same road twice. Preserve the source's stop sequence
  // when assigning distances so opposite-direction platforms aren't mixed.
  function positionStops(route) {
    if (!route.stops?.length) return [];
    const cumulative = [0];
    for (let i = 1; i < route.coordinates.length; i += 1) cumulative.push(cumulative[i - 1] + distance(route.coordinates[i - 1], route.coordinates[i]));
    const stages = [];
    for (const stop of route.stops) {
      const candidates = projections(stop.coordinates, route.coordinates, cumulative).filter((point) => point.offset <= 100);
      const previous = stages.at(-1);
      for (const candidate of candidates) {
        const prior = previous?.filter((point) => point.along <= candidate.along + 1).sort((a, b) => a.cost - b.cost)[0];
        candidate.previous = prior;
        candidate.cost = candidate.offset ** 2 + (previous ? prior?.cost ?? Infinity : 0);
      }
      stages.push(candidates.filter((candidate) => Number.isFinite(candidate.cost)));
    }
    let last = stages.at(-1).sort((a, b) => a.cost - b.cost)[0];
    if (!last) throw new Error(`Cannot place stops in recorded travel order for route ${route.id}.`);
    const positioned = [];
    for (let i = route.stops.length - 1; i >= 0; i -= 1) {
      positioned.unshift({ ...route.stops[i], along: last.along });
      last = last.previous;
    }
    return positioned;
  }
  function match(routes, start, end) {
    if (distance(start, end) < 10) return null;
    let best = null;
    for (const route of routes) {
      const coordinates = route.coordinates;
      if (!Array.isArray(coordinates) || coordinates.length < 2) continue;
      const cumulative = [0];
      for (let i = 1; i < coordinates.length; i += 1) cumulative.push(cumulative[i - 1] + distance(coordinates[i - 1], coordinates[i]));
      const total = cumulative.at(-1);
      const routeAnchors = (point) => {
        const stop = (route.stops || []).filter((stop) => Number.isFinite(stop.along))
          .map((stop) => ({ ...stop, nearby: distance(point, stop.coordinates) }))
          .sort((a, b) => a.nearby - b.nearby)[0];
        if (stop && stop.nearby < 30) {
          const candidates = projections(point, coordinates, cumulative).filter((candidate) => Math.abs(candidate.along - stop.along) < 25);
          if (candidates.length) return candidates.slice(0, 1);
        }
        return anchors(point, coordinates, cumulative);
      };
      for (const from of routeAnchors(start)) {
        for (const to of routeAnchors(end)) {
          const wraps = to.along < from.along;
          if (wraps && !route.roundtrip) continue;
          const length = to.along - from.along + (wraps ? total : 0);
          if (length < 10) continue;
          const score = length + 8 * (from.offset + to.offset);
          if (best && score >= best.score) continue;
          const middle = wraps
            ? coordinates.slice(from.index + 1).concat(coordinates.slice(1, to.index + 1))
            : coordinates.slice(from.index + 1, to.index + 1);
          const names = route.roadNames || [];
          const segmentNames = wraps ? names.slice(from.index).concat(names.slice(0, to.index + 1)) : names.slice(from.index, to.index + 1);
          best = { route, score, distance: length, startOffset: from.offset, endOffset: to.offset,
            fromAlong: from.along, toAlong: to.along, totalLength: total,
            coordinates: [from.point, ...middle, to.point],
            roads: segmentNames.filter((name, index) => index === 0 || name !== segmentNames[index - 1]),
          };
        }
      }
    }
    return best;
  }
  function stopsAlong(journey, extraLocations = []) {
    const route = journey.route;
    const cumulative = [0];
    for (let i = 1; i < route.coordinates.length; i += 1) cumulative.push(cumulative[i - 1] + distance(route.coordinates[i - 1], route.coordinates[i]));
    const from = journey.fromAlong ?? 0;
    const to = journey.toAlong ?? cumulative.at(-1);
    const wraps = to < from;
    const candidates = [
      ...(route.stops || []),
      ...extraLocations.filter((place) => place.coordinates && place.routeIds.includes(route.id)),
    ];
    const ordered = candidates.flatMap((stop) => {
      const anchor = Number.isFinite(stop.along) ? null : anchors(stop.coordinates, route.coordinates, cumulative)[0];
      if (!Number.isFinite(stop.along) && (!anchor || anchor.offset > 100)) return [];
      const along = stop.along ?? anchor.along;
      // Only include stops on the selected directional segment, not elsewhere on the service.
      if (wraps ? along < from - 25 && along > to + 25 : along < from - 25 || along > to + 25) return [];
      return [{ ...stop, along: along - from + (wraps && along < from - 25 ? cumulative.at(-1) : 0) }];
    }).sort((a, b) => a.along - b.along);
    return ordered.filter((stop, index) => !ordered.slice(0, index).some((other) => other.id === stop.id || distance(other.coordinates, stop.coordinates) < 5));
  }
  // Join an approved starting point to the intended corridor using recorded
  // directional road geometry. Never bridge an unmapped gap with a long line.
  function assignedJourney(route, start, connectors = []) {
    if (!Array.isArray(start) || start.length !== 2 || !start.every(Number.isFinite)) return null;
    const destination = route.coordinates.at(-1);
    const candidates = [];
    const direct = match([route], start, destination);
    if (direct && direct.startOffset <= 80 && direct.endOffset <= 30) candidates.push(direct.coordinates);
    for (const connector of connectors) {
      for (let i = 0; i < route.coordinates.length - 1; i += 1) {
        const leg = match([connector], start, route.coordinates[i]);
        if (!leg || leg.startOffset > 80 || leg.endOffset > 30) continue;
        candidates.push([...leg.coordinates, ...route.coordinates.slice(i)]);
      }
    }
    const length = (coordinates) => coordinates.slice(1).reduce(
      (sum, point, i) => sum + distance(coordinates[i], point), 0);
    const paths = candidates.map((path) => [start, ...path].filter(
      (point, i, all) => i === 0 || distance(all[i - 1], point) > 0.1));
    paths.sort((a, b) => length(a) - length(b));
    if (!paths.length) return null;
    const coordinates = paths[0];
    return {
      route: { ...route, coordinates, stops: [], roadNames: [] },
      coordinates, distance: length(coordinates), roads: [],
    };
  }
  // Keep the service visible even when its exact assigned start is not mapped.
  // The fallback is explicitly a general route, never a claimed driver location.
  function assignedRoutePreview(route, start, connectors = []) {
    const exact = assignedJourney(route, start, connectors);
    if (exact) return { ...exact, isGeneralRoute: false };
    const coordinates = route.coordinates.map(point => point.slice());
    const length = coordinates.slice(1).reduce((sum, point, i) => sum + distance(coordinates[i], point), 0);
    return {
      route: { ...route, coordinates, stops: [] }, coordinates,
      distance: length, roads: [], isGeneralRoute: true,
    };
  }
  // Only approved starting areas can choose a default travel direction.
  function assignedAreaRoute(locationKey) {
    const directions = new Map([
      ['total-djpmm', '12000002'],
      ['diversion-kwatogs', '12000002'],
      ['grand-terminal', '12000002'],
      ['mabini-tourism-triangle', '12000001'],
      ['mabini-seven-eleven', '12000001'],
      ['mabini-iconails', '12000001'],
    ]);
    return directions.get(locationKey) || '';
  }
  // The Mabini corridor is maintained locally until a verified OSM jeepney
  // relation is available. Both directions are explicit so reverse journeys
  // match the correct travel order.
  const mabiniBatangasCoordinates = [
    // Sampled from the road-following Mabini–Batangas City driving path.
    [120.940614, 13.748091], [120.940588, 13.75082], [120.941452, 13.752944],
    [120.944796, 13.755187], [120.947428, 13.755139], [120.950711, 13.758444],
    [120.954574, 13.760045], [120.958777, 13.762248], [120.959765, 13.764635],
    [120.960672, 13.767648], [120.962195, 13.772621], [120.964592, 13.774944],
    [120.970461, 13.7796], [120.978718, 13.786102], [120.985514, 13.789713],
    [120.995176, 13.79359], [121.000702, 13.795707], [121.005751, 13.79356],
    [121.007541, 13.790152], [121.01099, 13.790538], [121.020187, 13.788283],
    [121.030341, 13.782558], [121.040871, 13.778187], [121.050683, 13.771307],
    [121.056033, 13.764385], [121.057804, 13.758945], [121.058717, 13.755145],
  ];
  const mabiniBatangasStops = [
    { id: 'mabini-town', name: 'Mabini', coordinates: [120.9407878, 13.7480896], type: 'terminal', landmark: 'Mabini town area', access: 'Boarding only' },
    { id: 'mabini-tourism-triangle', name: 'Mabini Tourism Triangle', coordinates: [120.947428, 13.755139], type: 'boarding_point', landmark: 'F. Castillo Blvd', access: 'Boarding and alighting' },
    { id: 'mabini-iconails', name: 'In front of Iconails Beauty Lounge', coordinates: [120.978718, 13.786102], type: 'boarding_point', landmark: 'Mabini Circumferential Rd', access: 'Boarding and alighting' },
    { id: 'batangas-grand-terminal', name: 'Batangas City Grand Terminal', coordinates: [121.0612976, 13.7895245], type: 'terminal', landmark: 'Community-mapped terminal', access: 'Boarding and alighting' },
    { id: 'batangas-city-proper', name: 'Batangas City proper', coordinates: [121.0591276, 13.755232], type: 'destination', landmark: 'Batangas City', access: 'Alighting only' },
  ];
  const mabiniBatangasRoutes = [
    { id: 12000001, ref: 'MAB-01', name: 'Mabini → Batangas City', roundtrip: false, source: null, coordinates: mabiniBatangasCoordinates, roadNames: Array(26).fill('Mabini–Batangas road'), stops: mabiniBatangasStops },
    { id: 12000002, ref: 'MAB-01', name: 'Batangas City → Mabini', roundtrip: false, source: null, coordinates: mabiniBatangasCoordinates.slice().reverse(), roadNames: Array(26).fill('Mabini–Batangas road'), stops: mabiniBatangasStops.slice().reverse() },
  ];


  // Shared by driver previews and commuter View route.
  function assignedServiceData(data, diversionRoad, mabiniRoads) {
    if (!Array.isArray(data?.routes) || !data.routes.length) throw new Error('No mapped jeepney routes are available.');
    const assignedAreaRoads = new Map();
    if (diversionRoad?.coordinates?.length > 200) assignedAreaRoads.set('diversion-kwatogs', diversionRoad);
    for (const road of mabiniRoads?.routes || []) {
      if (road.coordinates?.length > 400 && assignedAreaRoute(road.locationKey) === '12000001') {
        assignedAreaRoads.set(road.locationKey, road);
      }
    }
    return {
      ...data, routes: [...data.routes.filter(route => !route.name.includes('Bauan')), ...mabiniBatangasRoutes],
      connectorRoutes: data.routes, assignedAreaRoads,
    };
  }

  function assignedAreaPreview(route, area, connectorRoutes, assignedAreaRoads) {
    const mappedRoad = assignedAreaRoads.get(area?.locationKey);
    const detailedDirection = area?.locationKey === 'diversion-kwatogs' ? 12000002 : 12000001;
    const needsDetailedRoad = ['diversion-kwatogs', 'mabini-tourism-triangle',
      'mabini-seven-eleven', 'mabini-iconails'].includes(area?.locationKey) && route.id === detailedDirection;
    // The locally sampled service corridor can cut across bends. Use a full
    // road trace for these four named boarding landmarks in their direction.
    let journey = assignedRoutePreview(route,
      needsDetailedRoad && !mappedRoad ? null : area?.coordinates, connectorRoutes);
    if (mappedRoad && route.id === detailedDirection && area?.coordinates) {
      const road = mappedRoad.coordinates;
      if (distance(area.coordinates, road[0]) < 80
          && distance(road.at(-1), route.coordinates.at(-1)) < 30) {
        const coordinates = [area.coordinates, ...road];
        const length = coordinates.slice(1).reduce((sum, point, i) => sum + distance(coordinates[i], point), 0);
        journey = {
          route: { ...route, coordinates, source: mappedRoad.source, stops: [], roadNames: [] },
          coordinates, distance: length, roads: [], isGeneralRoute: false,
        };
      }
    }
    return journey;
  }

  async function loadAssignedRoute(routeKey, area, assetsUrl) {
    if (assignedAreaRoute(area?.locationKey) !== String(routeKey)) throw new Error('Invalid driver route assignment');
    const read = async (name, optional = false) => {
      try {
        const response = await fetch(new URL(name, assetsUrl), { cache: 'no-cache', signal: AbortSignal.timeout(12000) });
        if (!response.ok) throw new Error('Route data unavailable');
        return await response.json();
      } catch (error) { if (optional) return null; throw error; }
    };
    const data = assignedServiceData(...await Promise.all([
      read('jeepney-routes.json'), read('diversion-mabini-road.json', true), read('mabini-area-roads.json', true),
    ]));
    const route = data.routes.find(item => String(item.id) === String(routeKey));
    if (!route) throw new Error('Driver route unavailable');
    return assignedAreaPreview(route, area, data.connectorRoutes, data.assignedAreaRoads);
  }

  const api = { match, distance, stopsAlong, positionStops, projections, assignedJourney, assignedRoutePreview, assignedAreaRoute, assignedServiceData, assignedAreaPreview, loadAssignedRoute };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.JeepneyRouting = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
