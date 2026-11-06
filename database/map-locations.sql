-- Apply after schema.sql. Reapplying preserves administrator changes.
CREATE TABLE IF NOT EXISTS map_locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_key VARCHAR(80) NOT NULL UNIQUE,
    location_name VARCHAR(120) NOT NULL,
    area_label VARCHAR(120) NOT NULL,
    landmark VARCHAR(255) NOT NULL,
    location_type ENUM('terminal', 'boarding_point', 'landmark') NOT NULL DEFAULT 'boarding_point',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    mapped_route_ids VARCHAR(120) NOT NULL DEFAULT '',
    source_url VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_map_location_coordinates CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO map_locations (location_key, location_name, area_label, landmark, location_type, latitude, longitude, mapped_route_ids, source_url) VALUES
('diversion-kwatogs', 'Diversion Road', 'Batangas City', 'In front of Kwatog’s, Brgy. Bolbok', 'boarding_point', NULL, NULL, '', NULL),
('grand-terminal', 'Grand Terminal', 'Batangas City', 'Grand Terminal Footbridge, Banaba Ibaba, Batangas City, Batangas, Calabarzon, 4200, Philippines', 'terminal', 13.7899130, 121.0618380, '10755763', 'https://maps.app.goo.gl/7heUhdRm7ssahv5h8'),
('total-djpmm', 'Total Gas Station', 'Batangas City', 'DJPMM Access Rd.', 'boarding_point', 13.7561539, 121.0516556, '', 'https://www.openstreetmap.org/way/419438675'),
('mabini-seven-eleven', 'In front of 7/11 Mabini', 'Mabini — POV from Mabini', 'Left side, F. Castillo Blvd', 'boarding_point', NULL, NULL, '', NULL),
('mabini-iconails', 'In front of Iconails Beauty Lounge', 'Mabini — POV from Mabini', 'Mabini Circumferential Rd', 'boarding_point', NULL, NULL, '', NULL),
('mabini-tourism-triangle', 'Mabini Tourism Triangle', 'Mabini — POV from Mabini', 'Left side, F. Castillo Blvd', 'boarding_point', NULL, NULL, '', NULL);

-- Replace the previous community-mapped departure label with the terminal address.
UPDATE map_locations
SET landmark = 'Grand Terminal Footbridge, Banaba Ibaba, Batangas City, Batangas, Calabarzon, 4200, Philippines'
WHERE location_key = 'grand-terminal'
  AND landmark = 'Bauan departures, bay 1 (community-mapped)';

UPDATE map_locations
SET landmark = 'Grand Terminal Footbridge, Banaba Ibaba, Batangas City, Batangas, Calabarzon, 4200, Philippines',
    latitude = 13.7899130,
    longitude = 121.0618380,
    source_url = 'https://maps.app.goo.gl/7heUhdRm7ssahv5h8'
WHERE location_key = 'grand-terminal';

-- Google Maps places Kwatogs Bolbok Diversion at this landmark coordinate.
-- This is the restaurant pin, not a surveyed curbside boarding position.
-- Preserve any administrator-confirmed coordinates already entered locally.
UPDATE map_locations
SET latitude = 13.7715645, longitude = 121.0499724,
    source_url = 'https://www.google.com/maps/place/Kwatogs+Bolbok+Diversion/@13.7715645,121.0499724,17z'
WHERE location_key = 'diversion-kwatogs' AND latitude IS NULL AND longitude IS NULL;

-- Landmark pins from Google Maps. These identify the named places, not a
-- surveyed boarding side. Preserve administrator-confirmed replacements.
UPDATE map_locations SET latitude = 13.7525621, longitude = 120.9406161,
    source_url = 'https://www.google.com/maps/place/Mabini+Tourism+Triangle/@13.7525621,120.9406161,17z'
WHERE location_key = 'mabini-tourism-triangle' AND latitude IS NULL AND longitude IS NULL;
UPDATE map_locations SET latitude = 13.7490870, longitude = 120.9404809,
    source_url = 'https://www.google.com/maps/place/7-Eleven+(1079)+Mabini/@13.749087,120.9404809,17z'
WHERE location_key = 'mabini-seven-eleven' AND latitude IS NULL AND longitude IS NULL;
-- Google lists this Iconails branch on I. Calangi St; the stated pickup on
-- Mabini Circumferential Rd still needs local confirmation.
UPDATE map_locations SET latitude = 13.7477068, longitude = 120.9406855,
    source_url = 'https://www.google.com/maps/place/Iconails+Beauty+Lounge+Mabini/@13.7477068,120.9406855,17z'
WHERE location_key = 'mabini-iconails' AND latitude IS NULL AND longitude IS NULL;
