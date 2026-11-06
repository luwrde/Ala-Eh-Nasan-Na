((root) => {
  'use strict';
  const routing = typeof module === 'object' && module.exports ? require('./jeepney-routing.js') : root.JeepneyRouting;
  function bearing(a, b) {
    const rad = Math.PI / 180;
    const lng = (b[0] - a[0]) * rad;
    const y = Math.sin(lng) * Math.cos(b[1] * rad);
    const x = Math.cos(a[1] * rad) * Math.sin(b[1] * rad) - Math.sin(a[1] * rad) * Math.cos(b[1] * rad) * Math.cos(lng);
    return (Math.atan2(y, x) / rad + 360) % 360;
  }
  function locate(route, fix, previous = null, nextStopId = '') {
    if (!Array.isArray(fix.coordinates) || !fix.coordinates.every(Number.isFinite) || !Number.isFinite(fix.accuracy) || fix.accuracy < 0) return { state: 'invalid' };
    if (fix.accuracy > 80) return { state: 'weak' };
    const cumulative = [0];
    for (let i = 1; i < route.coordinates.length; i++) cumulative.push(cumulative[i - 1] + routing.distance(route.coordinates[i - 1], route.coordinates[i]));
    const total = cumulative.at(-1);
    const candidates = routing.projections(fix.coordinates, route.coordinates, cumulative);
    const closest = candidates[0];
    if (!closest || closest.offset > Math.max(70, fix.accuracy * 1.5)) return { state: 'off_route', offset: closest?.offset };
    let options = candidates.filter((point) => point.offset <= closest.offset + Math.max(15, fix.accuracy));
    const hasHeading = Number.isFinite(fix.heading) && Number.isFinite(fix.speed) && fix.speed >= 2.5;
    const hintIndex = (route.stops || []).findIndex((stop) => stop.id === nextStopId);
    if (!previous && hintIndex >= 0) {
      const lower = hintIndex === 0 ? 0 : route.stops[hintIndex - 1].along;
      const upper = route.stops[hintIndex].along;
      options = options.filter((point) => point.along >= lower - 80 && point.along <= upper + 80);
      if (!options.length) return { state: 'wrong_hint' };
    }
    options.forEach((point) => {
      point.turn = hasHeading ? Math.abs(((bearing(route.coordinates[point.index], route.coordinates[point.index + 1]) - fix.heading + 540) % 360) - 180) : 0;
      point.delta = previous ? point.along - previous.along : 0;
      if (previous && route.roundtrip && previous.along > total - 250 && point.along < 250) point.delta += total;
      point.score = point.offset + point.turn * 0.8 + (previous ? Math.abs(point.delta) * .25 : 0);
    });
    if (previous) {
      const movement = routing.distance(previous.coordinates, fix.coordinates);
      options = options.filter((point) => point.delta >= -100 && point.delta <= Math.max(200, movement * 3 + 100));
      if (!options.length) return { state: 'reacquire' };
    }
    options.sort((a, b) => a.score - b.score);
    const selected = options[0];
    if (!selected) return { state: 'ambiguous' };
    if (hasHeading && selected.turn > 110) return { state: 'wrong_direction' };
    if (!previous && hintIndex < 0 && options.some((point) => Math.abs(point.along - selected.along) > 500 && point.score < selected.score + 25)) return { state: 'ambiguous' };
    let along = selected.along;
    if (previous && selected.delta < 0 && selected.delta > -60) along = previous.along;
    const atStop = (route.stops || []).find((stop) => Math.abs(stop.along - along) <= 40 && routing.distance(stop.coordinates, fix.coordinates) <= 70);
    const upcoming = (route.stops || []).filter((stop) => stop.along > along + (atStop ? 40 : 15))
      .map((stop) => ({ ...stop, distance: Math.max(0, stop.along - along) }));
    return { state: 'ready', along, total, atStop: atStop || null, upcoming, road: route.roadNames?.[selected.index] || 'Mapped route',
      finished: !route.roundtrip && total - along < 60,
      remaining: total - along, offset: selected.offset,
      progress: { along, coordinates: fix.coordinates },
    };
  }
  const api = { locate };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.LiveNavigation = api;
})(typeof globalThis !== 'undefined' ? globalThis : this);
