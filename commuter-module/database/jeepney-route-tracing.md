# Jeepney route tracing

The compact route button is temporarily hidden at the owner's request. `createRoutePreview` is initialized with `enabled: false` on Map, so it does not fetch or display the saved preview. The data and implementation remain available. To restore the button later, enable that controller in `map.js` and restore the `route-preview.css` link on `user-map.html`.

## One pending test route

Open **Map**, then tap **Batangas Grand Terminal to Mabini** below the location indicator. This is the only seeded route. Its complete road preview is solid blue with a white outline, green starting and endpoint references, and grey preliminary references. Red markers identify terminals and boarding points. Tap the line or press Enter/Space while it is focused to highlight the entire path and open its compact details. Fit route frames the whole path; Minimize keeps it visible; Hide route removes it. Terminal selection, personal distance estimates, the crosshair, and Area overview clear it. The original large tracing form remains hidden.

Ordinary Map opening still follows the user's location when permission is granted. This route appears only after pressing its button. No live jeepney tracking was added; existing device-location behavior, terminal data, dashboard, and authentication flow are preserved.

**Status: Pending Verification.** The blue line is a stored FOSSGIS/OSRM **driving preview**, not an operator-verified jeepney path. Real OpenStreetMap references were checked on 2026-11-02. The full routing response supplies 562 road vertices; geometry-derived length is approximately **19.6 km**. This is a preview distance, not a fare, scheduled journey time, confirmed service, or guarantee that jeepneys use these roads.

| Order | Requested reference | Mapped reference used | Source |
| --- | --- | --- | --- |
| Start | Batangas City Grand Terminal, Alangilan | Existing terminal-area reference; exact bay unconfirmed | [OSM way 236809581](https://www.openstreetmap.org/way/236809581) |
| 1 | Bolbok | Bolbok place node | [OSM node 1450800875](https://www.openstreetmap.org/node/1450800875) |
| 2 | Sta. Rita | Santa Rita Karsada place node | [OSM node 3914585071](https://www.openstreetmap.org/node/3914585071) |
| 3 | San Pascual | San Pascual Municipal Hall | [OSM node 7853237085](https://www.openstreetmap.org/node/7853237085) |
| 4 | Bauan | Bauan Municipal Hall | [OSM way 312556517](https://www.openstreetmap.org/way/312556517) |
| 5 | Manghinao Junction | Shared road junction of National Highway, F. Mangobos Street, and Bauan-Mabini Road | [OSM node 260212541](https://www.openstreetmap.org/node/260212541) |
| End | Mabini Town Proper | Mabini Municipal Hall as a provisional town-proper reference | [OSM way 369853924](https://www.openstreetmap.org/way/369853924) |

All intermediate references, the exact starting bay, road choices, boarding stops, and final jeepney endpoint still need operator/on-site confirmation. The municipal halls identify their towns; they are **not confirmed jeepney stops**. The municipal-hall endpoint is disclosed in the panel and popup. Markers use the nearest ordered vertex of the returned road trace (within 250 m of the geocoded reference), rather than invented connecting lines. Source links retain the original geocoded feature.

No return route or other route is seeded. Do not reverse this polyline to infer a return service. No fares, schedules, verified road names, or verified stops have been invented.

## Storage and maintenance

Schema version 4 introduced `jeepney_route_paths`; version 7 adds nullable `preview_json` and inserts this one pending record by unique slug, preserving existing records. It changes no terminal coordinates or `terminal_routes` service/fare records. Actual `geometry_geojson` remains null; verified roads/stops remain empty. Viewing the stored preview makes no routing-provider request.

- [grand-terminal-mabini-preview.json](grand-terminal-mabini-preview.json): editable metadata, seven sourced references, and the full road preview.
- [route-references/grand-terminal-mabini-sources.json](route-references/grand-terminal-mabini-sources.json): Nominatim responses, chosen OSM IDs, junction evidence, routing URL/time, and provider snap distances.
- `preview.points`: original `[longitude, latitude]` coordinates, name, role, mapped feature, source URL, and limitations. The API derives `road_coordinates` on the actual preview and its distance.
- Existing `start_terminal_id = 3` links Grand Terminal. `end_terminal_id = 4` retains the existing Mabini terminal association required by the corridor schema; **it does not assert that Tourism Triangle is the final stop**. The pending preview's endpoint is independently defined by its last point, Mabini Municipal Hall. Confirm/update that association before publishing an actual verified trace.

To edit this same route, change its reference coordinates only after checking reliable sources, regenerate the preview, review it, and explicitly import it:

```powershell
node tools/build_route_preview.mjs --file=database/grand-terminal-mabini-preview.json
php tools/import_jeepney_route.php --file=database/grand-terminal-mabini-preview.json --replace
```

The build tool makes one full-geometry driving request through the ordered points, validates the response/snaps, and writes only that JSON file. It never geocodes guessed positions or marks the route verified. Failure leaves the file unchanged. The importer validates geometry/references and updates the existing slug rather than adding a route. Keep `grand-terminal-to-mabini` so the current single-route button finds it. The migration runs once; editing the seed alone after initialization does not change the live database.

Data derives from [OpenStreetMap contributors](https://www.openstreetmap.org/copyright). Preview generation uses [FOSSGIS routing](https://routing.openstreetmap.de/about.html) and the [OSRM Route service](https://project-osrm.org/docs/v5.24.0/api/#route-service); geocoding used [Nominatim](https://nominatim.org/release-docs/latest/api/Search/). Tiles/Leaflet assets need internet access. Missing route data shows Retry and never a substitute straight line.

## Importing an actual path later

The existing importer accepts operator-recorded/reviewed full GeoJSON traces. Consistency checks cannot prove actual service coverage. Confirm each direction separately, including one-way sections, exact endpoint/bay, and intermediate stops.

JSON metadata fields are `slug`, `name`, `start_terminal_id`, `end_terminal_id`, `travel_direction`, `status`, `geometry`, `roads`, `stops`, and optional verification evidence. GeoJSON order is **[longitude, latitude]**. Geometry accepts a LineString, one Feature containing it, or a FeatureCollection containing exactly one LineString. An ordered top-level `coordinates` array is also supported. Directions are `batangas-to-mabini` and `mabini-to-batangas`.

- `pending`: actual geometry must be null. Optional `preview` requires kind `road-routing-preview`, full LineString, 2–30 sourced ordered points, HTTPS URL from the configured FOSSGIS/OSRM car service, and valid UTC `generated_at`. Previews stay unverified.
- `draft`: valid imported actual geometry for review, labeled unverified. Remove `preview` when replacing the pending record with an actual trace.
- `verified`: reviewed full geometry plus `verification_source`, `verified_by`, and exact UTC `verified_at`, for example `2026-11-02T09:00:00Z`. These record owner review, never automatic routing confirmation.

Verified `roads` is an ordered list of confirmed road names. Published `stops` need `name`, coordinates, `kind` (`stop` or `landmark`), `verified: true`, and `verification_source`. Preview points never become verified stops automatically. The compact test-route controller intentionally displays pending previews only; publishing a verified passenger route requires a deliberate UI/data review too.

Validation requires 3–50,000 numeric vertices, nonzero length, no consecutive gap over 1 km, and actual endpoints within 500 m of configured terminal references. Verified stops must be within 500 m of a vertex; preview references within 250 m of ordered trace vertices. These are consistency checks, not proof of the operator's path. Files are limited to 8 MB, roads to 300 entries, and stops to 200. Distances derive from geometry.

`--replace` updates the supplied slug, preserving its ID; otherwise existing slugs fail. Database selection follows `ALA_EH_TERMINALS_DB`, outside the public web root. No public importer or administrative login was added.

## API and validation

`GET /api/jeepney-routes.php` returns `{ "routes": [...] }`; `?id=ID` returns `{ "route": ... }`. Invalid IDs return 400, unknown IDs 404, writes 405, and storage failure 503. Invalid manually edited data is downgraded to pending with no usable path/preview. The API remains public and read-only.

Run `php tests/jeepney-routes-test.php` for isolated migration/import, single pending seed, verification separation, malformed previews, independent return records, persistence, and unchanged terminals. Synthetic geometry stays in temporary test databases. `node --test tests/route-preview.test.mjs tests/routing.test.mjs` checks full blue drawing, markers, selection, minimizing/hiding, safe popups, fitting, failures/retries, and ordered routing references. Existing tracing/location/navigation suites cover retained features; the live HTTP suite checks APIs/assets. Phone and desktop browser checks additionally verify framing and terminal selection.
