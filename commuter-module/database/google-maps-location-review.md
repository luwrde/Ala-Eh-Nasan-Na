# Google Maps location update — 2026-11-01

All five user-supplied short links resolved successfully to named Google Maps places (HTTP 200). Coordinates below come from the destination place's `!3d`/`!4d` fields, not the `@` viewport or Street View camera position. The original links are stored unchanged in `google_maps_url`; cleaned place references are stored in `google_maps_resolved_url`.

| ID | Project location | Resolved Google Maps place | Original link | Landmark latitude | Landmark longitude |
| --- | --- | --- | --- | --- | --- |
| 1 | Total Gas Station | Total | [Google Maps](https://maps.app.goo.gl/mi7CqzXjvnwnZBSy8) | 13.7560962 | 121.0518226 |
| 2 | Diversion Road | Kwatogs Bolbok Diversion | [Google Maps](https://maps.app.goo.gl/Zh9HJ8AVyuARsWjh6) | 13.7715645 | 121.0499724 |
| 4 | Mabini Tourism Triangle | Mabini Tourism Triangle | [Google Maps](https://maps.app.goo.gl/7YPKhDnvYGaoEx8e9) | 13.7525621 | 120.9406161 |
| 5 | 7-Eleven Mabini | 7-Eleven (1079) Mabini | [Google Maps](https://maps.app.goo.gl/L1pNnSffCY6zzKs8A) | 13.749087 | 120.9404809 |
| 6 | Iconails Beauty Lounge | Iconails Beauty Lounge Mabini | [Google Maps](https://maps.app.goo.gl/ujKj51cVLsVsBJrj8) | 13.7477068 | 120.9406855 |

These match the supplied business/landmark references in Batangas City and Mabini. They do not independently establish the exact roadside boarding spots. Each is stored separately as verified landmark coordinates with `location_verification_status = landmark_verified`; the five primary boarding coordinate pairs remain NULL and unverified. No business entrance or camera position is promoted to a precise boarding point.

Record 3, Batangas City Grand Terminal, keeps its existing 13.78986, 121.06235 mapped terminal-area reference, source, and caveat. No Google Maps link was supplied for this record, so its stored original URL remains NULL. Its Open in Google Maps action uses a coordinate search. The exact jeepney bay still requires confirmation.

## Database changes

Schema version 3 adds Google Maps source links, separate landmark names/coordinates and verification flags, a location verification status, and a UTC `updated_at` timestamp. The existing six records retain their IDs and names; no duplicates are inserted. Primary coordinates and existing owner-configured boarding instructions are preserved. The live database was migrated and checked through the API.

The following instructions fill previously missing boarding directions:

- Mabini Tourism Triangle: Left side of F. Castillo Boulevard.
- 7-Eleven Mabini: In front of 7-Eleven, left side of F. Castillo Boulevard.
- Iconails Beauty Lounge: In front of the establishment along Mabini Circumferential Road.

Routes, destinations, fares, schedules, classifications, and seat data are unchanged.

## Map and navigation behavior

The existing Leaflet/OpenStreetMap integration displays amber landmark-reference markers separately from the blue terminal-reference marker. Selecting a location focuses its marker; popups show the name, address, verification note, details link, and Google Maps action. Directory, details, and map views offer Open in Google Maps; Get Directions opens a valid Google Maps directions URL to the available reference coordinates.

Update on 2026-11-02: at the owner's request, in-app walking/driving distance calculations now use the checked landmark reference when primary coordinates are unavailable. Results say **Estimated distance to landmark** and do not change the boarding-point verification flags. External navigation also leads to the landmark. Grand Terminal retains its existing estimate behavior and terminal-area caveat. Missing or invalid map data cannot produce a marker or routing request.

## Files updated

- Database/backend: `database/terminals-google-references.sql`, `app/terminals_repository.php`, and the generated local `storage/terminals.sqlite`.
- Frontend logic: `public/assets/terminals-common.js`, `terminals-map.js`, `map-selection.js`, `map.js`, `terminal-details.js`, `terminals.js`, and `preferences.js`.
- Frontend pages/styles: `public/user-map.html`, `user-terminal-details.html`, `user-terminals.html`, and `public/assets/map.css`.
- Checks: `tests/terminals-test.php`, `tests/google-references.test.mjs`, and `tests/map-selection.test.mjs`.
- Documentation: this report, `database/README.md`, `database/location-verification.md`, and the root `README.md`.

## Validation and remaining manual checks

PHP syntax and isolated database migration/persistence checks passed. All 29 JavaScript tests passed, covering map rendering/selection, coordinate validation, safe navigation URLs, search/filter behavior, routing, preferences, and local HTTP/API integration. Live API inspection confirmed six records, five exact original links, five separate landmark coordinate pairs, unchanged Grand Terminal coordinates, and saved verification statuses/timestamps. All five external links resolved to the expected named places.

Visual browser interaction was not verified in this run. Confirm the exact boarding spots on site or with the terminal operator before publishing precise boarding coordinates. Grand Terminal's exact jeepney bay also needs confirmation. No new routes, destinations, fares, or schedules were inferred.
