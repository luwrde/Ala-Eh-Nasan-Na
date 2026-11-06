-- Approved driver pickup/waiting area assignments.
CREATE TABLE IF NOT EXISTS driver_pickup_assignments (
    driver_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    location_key VARCHAR(80) NOT NULL,
    availability ENUM('available', 'waiting', 'on_trip', 'off_duty') NOT NULL DEFAULT 'off_duty',
    route_key VARCHAR(40) NULL,
    route_name VARCHAR(160) NULL,
    route_ref VARCHAR(40) NULL,
    route_start VARCHAR(120) NULL,
    route_destination VARCHAR(120) NULL,
    route_revision INT UNSIGNED NOT NULL DEFAULT 0,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pickup_assignment_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
    CONSTRAINT fk_pickup_assignment_location FOREIGN KEY (location_key) REFERENCES map_locations(location_key) ON UPDATE CASCADE,
    KEY idx_pickup_assignment_location (location_key, availability)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the verified pin when this migration is applied to an older local database.
UPDATE map_locations
SET latitude = 13.7561539, longitude = 121.0516556,
    source_url = 'https://www.openstreetmap.org/way/419438675'
WHERE location_key = 'total-djpmm' AND (latitude IS NULL OR longitude IS NULL);
