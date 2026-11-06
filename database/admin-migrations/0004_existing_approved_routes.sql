-- Keep existing approved assignments selectable in the imported admin portal.
-- This registers their names only; it does not invent stop order or road geometry.
INSERT IGNORE INTO routes (route_name, is_active)
SELECT DISTINCT TRIM(route_assignment), 1 FROM jeepney_profiles
WHERE plate_number IS NOT NULL AND route_assignment IS NOT NULL AND TRIM(route_assignment) <> '';

-- Only pins already designated as terminals are brought into terminal management.
INSERT IGNORE INTO terminals (terminal_name, municipality, latitude, longitude, is_active)
SELECT location_name, LEFT(area_label, 100), latitude, longitude, is_active
FROM map_locations WHERE location_type = 'terminal';

INSERT IGNORE INTO terminal_details (terminal_id, landmark)
SELECT t.id, l.landmark FROM terminals t JOIN map_locations l
ON BINARY t.terminal_name = BINARY l.location_name AND BINARY t.municipality = BINARY LEFT(l.area_label, 100)
WHERE l.location_type = 'terminal';
