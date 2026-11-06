import { readFile, writeFile } from 'node:fs/promises';
import { viaRouteUrl, readRoute } from '../public/assets/routing.js';

try {
  const file = process.argv.find(value => value.startsWith('--file='))?.slice(7);
  if (!file) throw new Error('Use --file=path/to/pending-route.json');
  const route = JSON.parse(await readFile(file, 'utf8'));
  if (route.status !== 'pending' || route.geometry !== null || route.preview?.kind !== 'road-routing-preview') {
    throw new Error('This tool updates only a pending road preview, never a verified jeepney trace.');
  }
  const points = route.preview.points;
  if (!Array.isArray(points)) throw new Error('Supply ordered, sourced preview points.');
  const url = viaRouteUrl(points.map(point => [point.coordinates?.[1], point.coordinates?.[0]]), 'driving');
  const response = await fetch(url, {
    headers: { 'User-Agent': 'AlaEhRoutePreview/0.1 (local development route preview builder)' },
    signal: AbortSignal.timeout(30000),
  });
  if (!response.ok) throw new Error(`Routing service HTTP ${response.status}`);
  const preview = readRoute(await response.json(), 'driving', points.length);
  route.preview.geometry = preview.geometry;
  route.preview.provider_url = url.href;
  route.preview.generated_at = new Date().toISOString();
  route.preview.route_verified = false;
  await writeFile(file, JSON.stringify(route, null, 2) + '\n');
  console.log(`Saved road preview (${preview.geometry.coordinates.length} vertices) to ${file}. Review it, then import it with import_jeepney_route.php --replace. It remains Pending Verification.`);
} catch (error) {
  console.error(`Preview not changed: ${error.message}`);
  process.exitCode = 1;
}
