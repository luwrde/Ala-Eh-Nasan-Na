# Terminal map review — 2026-11-01

This is the earlier review before the owner supplied Google Maps links. See [the subsequent Google Maps update](google-maps-location-review.md) for the five newly verified landmark references. Their precise boarding coordinates still remain NULL; landmark markers are separately labeled.

Coordinates are published only for a confidently matched mapped location. No street midpoint, municipality center, nearby-business coordinate, photograph camera position, or guessed roadside position is used as a terminal pin.

| Project location | Result | Evidence and limitation |
| --- | --- | --- |
| Total Gas Station | Pending; coordinates remain NULL | [Business directory](https://www.cybo.com/PH/batangas-city/gas-station/?p=2) matches the DJPMM Access Road address. A separate clinic listing describes being across from Total; the clinic's coordinates are not the gas station's and were not reused. |
| Diversion Road | Pending; coordinates remain NULL | The provided landmark is in front of Kwatog's in Bolbok. No sufficiently clear matching point was found. A general Diversion Road position would not verify this boarding location. |
| Batangas City Grand Terminal | Mapped terminal-area reference published | [Mapcarta's OpenStreetMap record](https://mapcarta.com/W236809581) identifies the named terminal and OSM way 236809581, at 13.78986, 121.06235. This locates the terminal area, not a verified jeepney bay. The API and UI retain that limitation in `coordinate_note`. Direct OSM API access was unavailable during this review. |
| Mabini Tourism Triangle | Pending; coordinates remain NULL | [Local resort's published directions](https://www.lachevrerie-resorts.com/transfer-rates.pdf) corroborate the landmark but do not establish the exact left-side boarding point on F. Castillo Boulevard. |
| 7-Eleven Mabini | Pending; coordinates remain NULL | [Matching store address](https://placesph.com/provinces/batangas/municipalities/mabini/7-eleven-mabini-batangas) is on F. Castillo Boulevard. That source explicitly labels its map approximate; no coordinate was copied. |
| Iconails Beauty Lounge | Pending; coordinates remain NULL | Results included similarly named salons in other municipalities, not a confidently matched point on Mabini Circumferential Road. None were used. |

Migration `terminals-mapped-locations.sql` stores the Grand Terminal source and note, and updates only that record when its coordinates are still NULL and unverified. Existing configured pins are preserved. Classification, routes, fares, schedules, and seats are unchanged.

Walking and driving estimates use the selected verified map reference, which may differ from the exact boarding bay. These are network route estimates from FOSSGIS/OSRM, not straight-line distances and not live-traffic predictions. The other five records continue to display **Exact map location pending verification.**
