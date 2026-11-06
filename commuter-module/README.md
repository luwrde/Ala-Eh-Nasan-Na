# Ala-Eh Nasaan Na? — Passenger Portal

A standalone passenger interface for the jeepney locator project, retaining the original logo, blue palette, typography, and component styling.

## Preview

Start Apache in XAMPP with this project's `public/` folder as the document root, then open:
http://localhost/user-login.html

If the project is instead served as an `htdocs` subfolder, use its corresponding `/public/` URL. All module links and API requests are relative, so both arrangements work.

Create an account or sign in with a registered commuter account. In the integrated project, commuter accounts are stored in MySQL in `commuter_accounts`; apply `database/commuter-accounts.sql` before using registration or login.
On a fresh login visit, tap or click the small jeepney once. It starts its engine with a brief vibration and subtle exhaust puffs, then slowly pulls the existing login page in from the right. Startup takes about 0.7 seconds; the synchronized journey takes about 2.4 seconds. Repeated taps cannot restart or skip the sequence. Keyboard users can Tab to the jeepney and press Enter or Space. Reduced-motion settings reveal the page immediately after activation. The intro does not store a completion flag or collect location data; existing active sessions continue directly to Map. If the intro script cannot load, the normal login remains accessible.
Sign-in opens the full-screen Map. Authenticated sessions continue directly to Map. Dashboard remains available from Menu.
The terminal directory requires PHP 8.1+ with PDO SQLite. MySQL and database credentials are not required. On the first terminal API request, the app creates `storage/terminals.sqlite` outside the public web root and seeds the six supplied locations. The PHP process needs write access to `storage/`. A static file server or `file://` preview cannot run the terminal API.

Alternatively, run `php -S 127.0.0.1:8080 -t public` from this folder and visit `http://127.0.0.1:8080/user-login.html`.

## Files

- public/index.html: entry page leading to login.
- public/user-login.html: passenger login interface.
- public/assets/login-intro.js, login-intro.css, and jeepney-intro.svg: tap-to-start engine animation, synchronized page pull, and the local jeepney illustration.
- public/assets/intro-streetscape.svg: decorative houses, shopfronts, trees and palms along the intro road. Eight inline SVG pedestrians walk in both directions with individual speeds, step durations, phases, stride lengths, sizes and clothing. The scenery ignores pointer input and reduced-motion settings stop the walking.
- public/user-dashboard.html: simple passenger dashboard.
- public/user-terminals.html: Terminals & Boarding Points directory, grouped by municipality.
- public/user-terminal-details.html?id=1: reusable, database-backed terminal details view.
- public/user-map.html: full-screen map home with floating controls, an expandable terminal panel, verified references, and optional device location.
- public/assets/jeepney-tracing.js and jeepney-tracing.css: directional jeepney road tracing and clearly labeled driving previews within Map.
- app/jeepney_routes_repository.php and public/api/jeepney-routes.php: stored directional traces and their read-only API.
- tools/import_jeepney_route.php: local CLI import of reviewed GeoJSON or ordered GPS traces.
- public/assets/route-preview.js and route-preview.css: compact single-route Map control, full blue preview, reference markers, and details.
- database/grand-terminal-mabini-preview.json: the requested pending route and sourced full road preview.
- tools/build_route_preview.mjs: regenerate that pending preview through the existing OSRM service before review/import.
- public/user-settings.html: Settings module for display name and appearance, available from every passenger navigation drawer.
- public/api/terminals.php: read-only JSON directory and details API.
- app/terminals_repository.php: PDO SQLite initialization and record queries.
- database/terminals-schema.sql and database/terminals-seed.sql: schema and initial project locations.
- database/terminal-destination-search.sql: destination-service metadata, without seeded routes.
- database/terminal-example-cleanup.sql: removes the previously added example from existing databases.
- database/README.md: field verification and data maintenance notes.
- public/assets/terminals*.js and terminal-details.js: directory/detail rendering and verified-location map support.
- public/assets/terminals.css: responsive terminal module styles.
- public/assets/styles.css: shared design tokens and passenger components.
- public/assets/user.css: passenger-specific styling and responsive adjustments.
- public/assets/user.js: demo login, password visibility, logout, and accessible navigation drawer.
- public/assets/drawer-navigation.css: shared hidden module drawer and compact headers.
- public/assets/map-experience.css: full-screen map, floating controls, responsive terminal panel, and animated blue location marker.
- public/assets/location-tracking.js: foreground geolocation watch, validated fixes, and watch cleanup.
- public/assets/preferences.js and preferences.css: pencil profile editor, browser-local display name, and light/dark/device themes.
- public/assets/settings.js and settings.css: responsive Settings page using the same preferences as the profile pencil.
- public/assets/ala-eh-logo.png: project logo.

## Backend handoff

Use **Search** above the lower-right Map controls to open the terminal finder. Search by registered name, landmark/address, municipality, or a configured verified destination. Matches update without a reload and sort by straight-line proximity when a fresh location is available. **Closest to me** requests the existing location fix, selects the nearest mapped match for the current query, and displays its red pin and distance actions. Clear search returns all registered locations. Permission denial preserves search and shows the existing Dismiss-only notice. Results do not assert current departures/opening status; confirm those with the driver. The crosshair retains its separate all-locations nearest-terminal action.

The explicitly requested **Batangas Grand Terminal to Mabini** test route is now available from its small button on Map. It draws one stored blue road-following preview through Bolbok, Sta. Rita, San Pascual, Bauan, Manghinao Junction, and a provisional Mabini Town Proper endpoint. Clicking the path highlights it and opens compact details. It is **Pending Verification**, not a published jeepney service. The provisional endpoint uses the mapped Mabini Municipal Hall; the exact bay, actual roads, stops, and final endpoint still require confirmation. No return route is added. The preview is stored separately from verified traces; all six terminal records and existing personal-location features remain intact. See the [route guide](database/jeepney-route-tracing.md) for sourced references, schema version 7, and editing the same test route.

Passenger sign-in remains a demo, not real authentication. Credentials are never sent or stored. A browser session flag controls the existing page flow only and provides no access protection. Terminal names and landmarks are read from the database through a public, read-only API; no account or administrative write API has been added.

The pencil beside the passenger profile opens profile settings. Display name and theme are saved under `alaEhPassengerPreferences` in local browser storage and apply to the dashboard, directory, details, and Map pages. They persist across reloads and synchronize between tabs on the same origin. Cancel and Escape discard unsaved changes. These are browser preferences, not server account updates or settings synchronized between devices. The interface uses English; the dashboard retains its Filipino welcome heading and subtitle. Previously saved dialect choices are ignored and omitted the next time settings are saved.

The Settings sidebar module offers the same display-name and Light/Dark/Match device preferences on a dedicated page. Save changes updates the header and theme immediately; Discard changes restores the saved values. It uses the existing demo session and shares changes with the profile pencil and other open passenger tabs. Failed storage writes display an error without applying unsaved settings.

At every screen size, Menu opens a drawer containing Dashboard, Terminals, Map, and Settings. The drawer starts closed, preserves the active-page state, traps keyboard focus while open, and closes using its close button, the backdrop, or Escape. Map keeps the logo and menu in floating controls instead of a permanent header or bottom navigation bar. The profile pencil and Log out are available inside its drawer; Log out also remains under Settings → Account.

The Map home fills the viewport. Selecting a terminal marker opens its details and the independently scrollable location list. The floating terminal-list shortcut has been removed; the full directory remains available through Menu > Terminals. The previous search field and large bottom launcher have been removed from Map, and the directory search field is also removed. Choosing a terminal opens its distance controls without resizing the map. Map framing accounts for the panel so the selected marker and route stay visible. The panel can be closed to return to the full map; other modules retain their existing dashboard appearance.

Jeepney service pairs must be provided and confirmed by the project owner. No example destinations or routes are seeded. Current departures, fares, schedules, exact jeepney bays, and classification remain unconfirmed and appear as unavailable or to be verified. The three owner-supplied Mabini boarding instructions are preserved. Batangas City Grand Terminal retains its sourced terminal-area reference. Five owner-supplied Google Maps links provide separate verified landmark references; their exact boarding coordinates remain NULL. All terminal and boarding-point references use red markers; the user location uses a pulsing blue dot. Reference verification remains described in the location text, including whether the exact boarding spot is pending verification. Live seats are not supported. No address geocoding or estimated terminal coordinates are used.

The map integration uses pinned Leaflet 1.9.4 and OpenStreetMap tiles. The dedicated Map page shows a general area map even when no terminals have verified coordinates. The initial viewport uses the city geographic reference from the [Batangas City geographic profile](https://www.batangascity.gov.ph/web/physical-geographical-aspects-2); this is a viewport center, never a terminal pin. The directory displays location cards and links to Map; its embedded map section has been removed. Terminal details and the dedicated Map page display verified references with their limitations; missing coordinates show a pending-verification message. No estimated terminal pins or live jeepney tracking are generated.

The Map navigation item is available on all passenger pages. Map and the directory display all registered locations without search or municipality filters. Existing confirmed service information remains on location cards and details. Clicking a terminal card/name in the directory or View on Map from its details opens `user-map.html?terminal=ID&focus=terminal`, displays its red pin immediately, and centers the selected mapped reference. Location updates do not move the camera away from that explicit selection. Once Map is ready, it starts a high-accuracy browser location watch, with the browser's normal permission requirement. The first valid fix centers the map on the user; subsequent fixes move a single blue dot and its reported accuracy circle. The pulse indicates an active watch and does not simulate coordinates. Opening Map from its menu link starts at the user location. A saved `?terminal=ID` link without the explicit focus flag displays that destination pin while retaining the user view. Selecting a terminal on the map or in the list afterward focuses its pin. While location is pending or denied, the map keeps its general area view rather than substituting a terminal for the user location. Dragging, manual zooming, selecting a terminal, and estimating a route stop camera following without stopping location updates. The crosshair frames the user and closest mapped boarding point. Tracking pauses when the page is hidden or left and resumes when it becomes visible again. Old callbacks and invalid, stale, or out-of-order fixes are ignored. Transient failures keep a dimmed last-known marker; denied permission removes it. Location is held only in page memory, never saved or submitted to the terminal API. Browser location access requires HTTPS or localhost. Maps need internet access for library files and tiles; failures display a retry action and preserve location browsing.

Each map location card is selectable with a click or keyboard. Selection focuses its available marker and opens its route panel. Walking/driving estimates use the checked terminal coordinates or the saved landmark reference. Landmark results are labeled Estimated distance to landmark; they do not change the boarding-point verification status. Open in Google Maps preserves the supplied link; Get Directions navigates to the available reference. Missing or invalid map coordinates disable estimates. Grand Terminal retains its terminal-area limitation. See the [Google Maps coordinate report](database/google-maps-location-review.md) for all five resolved links, coordinates, and remaining manual verification.

Click the lower-right crosshair (My location) to find the closest mapped terminal or boarding point. It obtains a current location fix, selects a mapped reference by geographic proximity, displays its red pin, and frames it with the blue user marker. It then requests a foot-path distance from the existing FOSSGIS/OSRM routing client. The compact label shows **Est. path distance**, using the returned path length, never a straight-line substitute. While loading it says Estimating distance; provider/no-path failures say Distance unavailable. The selected location card and details use the same result. Other cards have no numeric distance until a path is calculated. Geographic proximity still selects and sorts candidates locally; this does not guarantee the chosen location has the shortest road/path route among all terminals. The full terminal panel stays closed for the crosshair; Search's Closest to me keeps it open and limits candidates to the query. Both actions send the current location and destination to the routing provider. Selecting Estimate distance draws the path, sharing the existing request cache. GPS updates do not continuously request new routes or replace the estimate with a geodesic number; estimates expire after one minute and require My location to refresh. Switching selections/search or revoking location permission cancels stale responses. A landmark estimate still refers to its checked map reference, not a confirmed boarding bay. Permission denial retains browsing and the existing Dismiss-only notice. No guessed terminal, geometry, or numeric fallback is generated.

For a mapped location, press **Estimate distance**. Map now has one distance action without Walking/Driving choices or a travel-time result. It keeps the former default foot-path calculation and draws the returned road/path geometry. The app uses the latest valid location fix and requires a refresh after one minute; location updates do not automatically request or recalculate routes. Approximate fixes are accepted with an accuracy notice. Pending, denied, and unavailable location states show the existing message and Dismiss button; retry using the floating My location control. With a confirmed fix, the start and end points are sent to [FOSSGIS/OSRM](https://routing.openstreetmap.de/about.html), as disclosed beside the button. The foot graph supplies alternative paths; the shortest valid returned distance is selected, with duration used only to break ties. Endpoint snaps are limited to 50 m. Missing paths never become invented or straight-line route estimates. Location and five-minute route caches stay in page memory; provider requests are queued at least 1.1 seconds apart. Changing terminals or the search cancels stale estimates. Provider failures show a message without invented numbers. The shared routing utility retains car routing for the separately stored road-preview tools; Map's distance button uses the foot graph.

These routing options follow the [OSRM route-service documentation](https://project-osrm.org/docs/v5.24.0/api/#route-service). Browser accuracy is a reported uncertainty radius, as described in [MDN's geolocation accuracy documentation](https://developer.mozilla.org/en-US/docs/Web/API/GeolocationCoordinates/accuracy); requesting high accuracy cannot guarantee a GPS-quality fix on every device.

See the [Leaflet quick start](https://leafletjs.com/examples/quick-start/), [OpenStreetMap tile policy](https://operations.osmfoundation.org/policies/tiles/), and [Google Maps directions URL documentation](https://developers.google.com/maps/documentation/urls/get-started#directions).

Replace demo login with server authentication and authorization when integrating protected user features. Other dashboard route, location, and saved-place cards remain placeholders.

## Checks

Run `node --test tests/login-intro.test.mjs` for tap activation, startup/travel ordering, repeated-tap protection, transition fallback, keyboard/reduced-motion activation, resizing, page interruption, storage failure, and existing-session bypass. Native headless-browser checks additionally exercised phone taps, mouse clicks, visible engine vibration, synchronized slow page/jeepney positioning, native keyboard activation, script-failure/no-JavaScript fallback, registration/driver/password controls, demo sign-in, and Map redirects.

Run `node --test tests/route-preview.test.mjs` for the single pending preview's solid blue line, reference markers, highlighting, fitting, minimizing/hiding, and retry behavior. The route/database suite also checks its migration, sourced geometry, and separation from verified paths. Phone and desktop browser checks cover complete-route framing and returning to terminal selection.

The Jeepney route tracing controller and storage remain in the project; its Map panel is currently hidden. Actual jeepney paths have not yet been supplied for either Batangas City → Mabini or its return direction. Saved verified paths display without regeneration; driving previews remain explicitly unverified. See the [route tracing/import guide](database/jeepney-route-tracing.md) for current limitations, schema, importing actual geometry, independent reverse routes, and validation. The six terminal records remain unchanged.

Run `php tests/jeepney-routes-test.php` and `node --test tests/jeepney-tracing.test.mjs` for directional trace storage/import and map/controller checks.

Run `php tests/terminals-test.php` for isolated database initialization, seed data, persistence, and verification checks. Run `node --test tests/terminals.test.mjs` for combined search/filter behavior, coordinate validation, and directions URLs. Database tests use a temporary file and never modify the live terminal database.

Run `node --test tests/terminals-map.test.mjs` for map marker filtering and safe popup checks. With Apache or the PHP server running, `node --test tests/terminals-http.test.mjs` checks API responses and frontend asset availability; set `ALA_EH_TEST_URL` when the site is served anywhere other than `http://localhost/`.

Run `node --test tests/preferences.test.mjs` for the settings dialog handlers, saved names, theme changes, English wording with legacy preferences, reload persistence, tab synchronization, invalid input, and storage errors.

Run `node --test tests/routing.test.mjs` for separate walking/driving endpoints, route geometry and snap validation, distance/time formatting, request rate limiting, caching, cancellation, and error handling. These tests use synthetic points and mocked responses, not a user's device location.

Run `node --test tests/google-references.test.mjs tests/map-selection.test.mjs` for separate landmark/boarding coordinate handling, safe Google Maps actions, and marker focus when selecting a location.

Run `node --test tests/nearest-terminal.test.mjs` for nearest-reference ranking, automatic location following, moving marker/accuracy-circle updates, foreground watch cleanup, browsing without camera snapping, unconfirmed/stale-location popups, notification dismissal and the existing My location action, landmark walking/driving estimates, denied permission, and missing coordinates.
