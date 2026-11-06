CREATE TABLE IF NOT EXISTS driver_seat_availability (
    driver_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    occupied INT UNSIGNED NOT NULL,
    capacity INT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_seats_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
    CONSTRAINT chk_seats_capacity CHECK (capacity > 0 AND occupied <= capacity)
) ENGINE=InnoDB;
