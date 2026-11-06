CREATE TABLE IF NOT EXISTS driver_trip_status (
    driver_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    status ENUM('available', 'in_transit', 'full', 'out') NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_trip_status_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
