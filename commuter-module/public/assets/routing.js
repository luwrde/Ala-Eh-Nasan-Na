// Separate, genuine foot and car routing graphs. Never substitute car routes for walking.
const services = {
  walking: 'https://routing.openstreetmap.de/routed-foot/route/v1/foot/',
  driving: 'https://routing.openstreetmap.de/routed-car/route/v1/driving/',
};

export function validPosition(position) {
  return Array.isArray(position) && position.length === 2
    && Number.isFinite(position[0]) && Number.isFinite(position[1])
    && Math.abs(position[0]) <= 90 && Math.abs(position[1]) <= 180;
}

function routingError(code) {
  const error = new Error(code);
  error.code = code;
  return error;
}

export function routeUrl(origin, destination, mode) {
  return viaRouteUrl([origin, destination], mode);
}

export function viaRouteUrl(points, mode = 'driving') {
  if (!Object.hasOwn(services, mode) || !Array.isArray(points) || points.length < 2 || points.length > 30 || !points.every(validPosition)) throw routingError('invalid-points');
  const pair = point => `${point[1].toFixed(6)},${point[0].toFixed(6)}`;
  const url = new URL(`${services[mode]}${points.map(pair).join(';')}`);
  url.searchParams.set('overview', 'full');
  url.searchParams.set('geometries', 'geojson');
  url.searchParams.set('steps', 'false');
  url.searchParams.set('alternatives', mode === 'walking' ? 'true' : 'false');
  url.searchParams.set('radiuses', points.map(() => mode === 'walking' ? 50 : 200).join(';'));
  return url;
}

export function readRoute(payload, mode = 'driving', expectedWaypoints = 2) {
  if (payload?.code === 'NoRoute' || payload?.code === 'NoSegment') throw routingError('no-route');
  if (payload?.code !== 'Ok' || !Array.isArray(payload.routes)
    || !Array.isArray(payload.waypoints) || payload.waypoints.length !== expectedWaypoints
    || payload.waypoints.some(point => !Number.isFinite(point.distance) || point.distance < 0)) {
    throw routingError('unavailable');
  }
  if (payload.waypoints.some(point => point.distance > (mode === 'walking' ? 50 : 200))) throw routingError('off-road');
  const candidates = payload.routes.filter(route => route && Number.isFinite(route.distance) && route.distance >= 0
    && Number.isFinite(route.duration) && route.duration >= 0
    && route.geometry?.type === 'LineString' && Array.isArray(route.geometry.coordinates)
    && route.geometry.coordinates.length >= 2
    && route.geometry.coordinates.every(point => Array.isArray(point) && point.length === 2 && validPosition([point[1], point[0]])));
  // Compare only road paths actually returned by the walking graph. No invented shortcuts.
  if (mode === 'walking') candidates.sort((a, b) => a.distance - b.distance || a.duration - b.duration);
  const route = candidates[0];
  if (!route) throw routingError('unavailable');
  return { distance: route.distance, duration: route.duration, geometry: route.geometry };
}

export function formatDistance(meters) {
  if (!Number.isFinite(meters) || meters < 0) return '—';
  return meters < 1000 ? `${Math.round(meters)} m` : `${(meters / 1000).toFixed(1)} km`;
}

export function formatDuration(seconds) {
  if (!Number.isFinite(seconds) || seconds < 0) return '—';
  if (seconds > 0 && seconds < 60) return '<1 min';
  const minutes = Math.ceil(seconds / 60);
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h${minutes % 60 ? ` ${minutes % 60} min` : ''}`;
}

// At most one new provider request per 1.1 seconds per page; cache stays in memory only.
export function routeClient({ fetcher = fetch, now = Date.now, wait = ms => new Promise(resolve => setTimeout(resolve, ms)) } = {}) {
  const cache = new Map();
  let queue = Promise.resolve(), nextRequest = 0;
  return function request(origin, destination, mode, signal) {
    const url = routeUrl(origin, destination, mode);
    const checkAbort = () => { if (signal?.aborted) throw new DOMException('Cancelled', 'AbortError'); };
    const job = async () => {
      checkAbort();
      const cached = cache.get(url.href);
      if (cached && now() - cached.time < 300000) return cached.route;
      if (nextRequest > now()) await wait(nextRequest - now());
      checkAbort();
      nextRequest = now() + 1100;
      const controller = new AbortController();
      const abort = () => controller.abort();
      signal?.addEventListener('abort', abort, { once: true });
      const timer = setTimeout(abort, 15000);
      try {
        const response = await fetcher(url, { signal: controller.signal, credentials: 'omit', cache: 'no-store' });
        checkAbort();
        if (response.status === 429) throw routingError('busy');
        const payload = await response.json();
        if (!response.ok && !['NoRoute', 'NoSegment'].includes(payload.code)) throw routingError('unavailable');
        const route = readRoute(payload, mode);
        cache.set(url.href, { route, time: now() });
        if (cache.size > 20) cache.delete(cache.keys().next().value);
        return route;
      } catch (error) {
        checkAbort();
        if (error.code && ['busy', 'no-route', 'off-road', 'unavailable'].includes(error.code)) throw error;
        throw routingError('unavailable');
      } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abort);
      }
    };
    const result = queue.then(job);
    queue = result.catch(() => {});
    return result;
  };
}
