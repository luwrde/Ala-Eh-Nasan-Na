import { mapCoordinates } from './terminals-common.js?v=8';
import { validPosition } from './routing.js?v=2';

// Compare checked map references locally. This is proximity, not a road route.
export function closestTerminal(records, origin) {
  let closest = null, smallest = Infinity;
  for (const record of records) {
    const distance = terminalProximity(record, origin);
    if (distance !== null && distance < smallest) { smallest = distance; closest = record; }
  }
  return closest;
}

export function terminalProximity(record, origin) {
  if (!validPosition(origin)) return null;
  const point = mapCoordinates(record);
  if (!point) return null;
  const radians = degrees => degrees * Math.PI / 180;
  const latitude = radians(origin[0]);
  const deltaLat = radians(point[0] - origin[0]);
  const deltaLng = radians(point[1] - origin[1]);
  const haversine = Math.sin(deltaLat / 2) ** 2
    + Math.cos(latitude) * Math.cos(radians(point[0])) * Math.sin(deltaLng / 2) ** 2;
  return 6371008.8 * 2 * Math.asin(Math.sqrt(Math.min(1, Math.max(0, haversine))));
}
