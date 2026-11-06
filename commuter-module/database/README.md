# Terminal data

`terminals-schema.sql` and `terminals-seed.sql` initialize a persistent SQLite database at `storage/terminals.sqlite`. Initialization happens once, under a transaction and a SQLite version check; later API reads preserve edits. The generated database is ignored by Git. Back it up before changing operational data.

For tests or a different deployment, the server environment variable `ALA_EH_TERMINALS_DB` can select a different SQLite file. Keep that file outside the web document root. The application includes no administrative data editor or public write endpoint.

The six seeded names, municipalities, and landmarks are project-provided. They are not proof of official terminal classification, travel direction, GPS position, route, fare, or schedule. Unknown text fields use SQL `NULL`; do not populate them with guessed information. The client displays an appropriate missing-data label.

Schema version 2 adds `coordinate_note` and a sourced map reference for Batangas City Grand Terminal. Its reference identifies the mapped terminal area; the exact jeepney bay remains unverified. The migration never overwrites coordinates already configured by the project owner.

Schema version 3 adds the five user-supplied Google Maps links and verified landmark references in separate fields. Their primary boarding coordinates remain NULL. Terminal and landmark references both use red map markers; the location text identifies landmark references and their verification limits. Walking/driving estimates may use these checked landmark coordinates and are labeled as estimates to the landmark. This does not verify the actual boarding spot. See [google-maps-location-review.md](google-maps-location-review.md) for coordinates, source links, and verification limits, and [location-verification.md](location-verification.md) for the earlier review.

## Configuring verified information

Schema version 5 adds `source_url`, `source_title`, and `service_note` to the existing `terminal_routes` service records. It does not seed services. Route data must be supplied and confirmed by the project owner. Schema version 6 removes the exact example record previously inserted by version 5, while preserving owner-entered services and all six terminal records.

Destination search uses only `terminal_routes` rows with `verified = 1` and a nonempty `destination`. For each additional service, confirm its boarding location and destination before publishing it, retain the reference in `source_url`/`source_title`, and describe any operational limitations in `service_note`. The API exposes `verified` as a boolean and omits unverified services. A destination match never proves a transfer connection or an intermediate stop. Stored GeoJSON traces remain a separate feature.

Schema version 4 adds a separate `jeepney_route_paths` table for full directional GeoJSON paths, verification evidence, ordered roads/stops, and update timestamps. It seeds no actual routes and leaves all six terminal records and existing service/fare records intact. See [jeepney-route-tracing.md](jeepney-route-tracing.md) for the read-only API and local import workflow. Both corridor directions still need actual operator-reviewed road geometry.

Schema version 7 adds `preview_json` and the explicitly requested single **Batangas Grand Terminal to Mabini** test route, marked **Pending Verification**. Its real OSRM road preview and sourced preliminary references remain separate from actual verified geometry, roads, and stops. No reverse route or published service/fare row is added. All six terminals remain unchanged. See the route guide for provenance, the provisional municipal-hall endpoint, and the terminal-ID association to review before publishing an actual service. Edit/reimport the same slug to update the route; startup does not overwrite owner edits.

Use a trusted SQLite administration tool to update records by their stable `id` or `slug` after checking the information. A real change to a record is read on the next API request; there is no second hardcoded details dataset in the browser.

- `boarding_direction`, `classification`, and `operating_schedule` are nullable descriptions on `terminals`.
- `google_maps_url` preserves the original supplied link; `google_maps_resolved_url` stores the cleaned named-place reference. `landmark_name`, `landmark_latitude`, `landmark_longitude`, and `landmark_coordinates_verified` describe a checked business/landmark only. They never enable precise boarding distance estimates. Clear landmark verification when changing its coordinates until the new position is checked.
- `location_verification_status` supports `pending`, `landmark_verified`, `verified_reference`, and `boarding_verified`. The API derives a consistent status from valid verification flags and coordinates. Use `boarding_verified` only when the actual boarding spot is confirmed. Update `updated_at` with an ISO 8601 UTC timestamp when maintaining a record.
- GPS data requires both `latitude` and `longitude`, valid numeric ranges, a nonempty `coordinate_source`, and `coordinates_verified_at` (an ISO 8601 timestamp). Set `coordinates_verified = 1` only after confidently matching the mapped terminal or boarding location against that source. Use `coordinate_note` to disclose limitations, such as a verified terminal-area reference with an unverified boarding bay. Setting numeric coordinates alone does not publish a pin or enable directions. Clear verification when changing a location until the new position is checked.
- `terminal_routes` references a terminal by `terminal_id`. Store route `name`, `destination`, `fare_information`, and `operating_schedule` only when known. Set `verified = 1` only after verifying the route serves that location. Unverified routes are not returned by the API. A verified route can still have unknown fares or schedules; those fields stay `NULL`.
- Seat availability is deliberately returned as `NULL`. Connect a reliable live source with freshness handling before displaying seat counts; no static or random counts are generated.

The repository exposes `/api/terminals.php` (all records and directory totals) and `/api/terminals.php?id=1` (one record, with its verified routes). Invalid IDs return 400, missing records 404, unsupported HTTP methods 405, and storage problems 503. Details URLs use `user-terminal-details.html?id=1`.
