import { validPosition } from './routing.js?v=2';

// Location stays in page memory. Watching ends when the map leaves the foreground.
export function createLocationTracker({ geolocation, secure = true, onPosition, onState, onError, now = Date.now }) {
  let watchId = null, active = false, generation = 0, latest = null;
  const waiting = new Set();
  const fresh = fix => fix && now() - fix.time < 60000;
  function settle(error, fix) {
    for (const waiter of waiting) {
      clearTimeout(waiter.timeout);
      if (error) waiter.reject(error); else waiter.resolve(fix);
    }
    waiting.clear();
  }
  function fail(code) {
    const error = new Error(code === 'insecure'
      ? 'Location access requires a secure HTTPS connection. Open the system using a trusted HTTPS link.'
      : code === 'unsupported' ? 'Your browser does not support location sharing.'
      : code === 1 ? 'Location access was not allowed. Enable location permission for this website to use the map.'
      : 'Your location could not be determined. Please try again.');
    error.code = code;
    onState(code === 1 || code === 'insecure' || code === 'unsupported' ? 'blocked' : 'unavailable');
    onError(error);
    settle(error);
  }
  function start() {
    if (active) return true;
    if (!secure) { fail('insecure'); return false; }
    if (!geolocation?.watchPosition) { fail('unsupported'); return false; }
    active = true;
    const current = ++generation;
    onState('locating');
    try {
      const id = geolocation.watchPosition(position => {
        if (!active || generation !== current) return;
        const coordinates = [position?.coords?.latitude, position?.coords?.longitude];
        const time = position?.timestamp ?? now();
        if (!validPosition(coordinates) || !Number.isFinite(time) || time > now() + 5000 || now() - time >= 60000 || (latest && time < latest.time)) return;
        const accuracy = position.coords.accuracy;
        latest = { coordinates, accuracy: Number.isFinite(accuracy) && accuracy >= 0 ? accuracy : null, time };
        onPosition(latest);
        onState('tracking');
        settle(null, latest);
      }, error => {
        if (!active || generation !== current) return;
        if (error?.code === 1) {
          active = false; generation++;
          if (watchId !== null) geolocation.clearWatch(watchId);
          watchId = null; latest = null;
        }
        fail(error?.code);
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
      if (active && current === generation) watchId = id;
      else geolocation.clearWatch(id);
    } catch {
      active = false; generation++; fail('unavailable');
    }
    return active;
  }
  function stop() {
    active = false; generation++;
    if (watchId !== null) geolocation.clearWatch(watchId);
    watchId = null;
    const error = new Error('Location tracking paused.');
    error.name = 'AbortError'; settle(error);
    onState('paused');
  }
  function locate() {
    if (active && fresh(latest)) return Promise.resolve(latest);
    return new Promise((resolve, reject) => {
      const waiter = { resolve, reject, timeout: setTimeout(() => {
        waiting.delete(waiter);
        const error = new Error('Your location could not be determined. Please try again.');
        reject(error);
      }, 15000) };
      waiting.add(waiter);
      start();
    });
  }
  return { start, stop, locate };
}
