-- Remove only the exact unsolicited example added by the earlier migration.
-- Leave owner-entered route records and all terminal records untouched.
DELETE FROM terminal_routes
WHERE terminal_id IN (SELECT id FROM terminals WHERE slug = 'batangas-city-grand-terminal')
  AND name = 'Batangas - Bauan (Grand Terminal)'
  AND destination = 'Bauan'
  AND source_url = 'https://www.batangascity.gov.ph/web/images/Ordinances2019/ORDINANCENO.23S.2019.pdf#page=41'
  AND source_title = 'Batangas City Traffic Ordinance of 2020, Sections 140(e)(2) and 151(d)'
  AND service_note = 'Documented terminal route. Confirm the Bauan-bound jeepney and boarding bay with terminal staff. Current departures, fares, and schedules are not yet confirmed.';
