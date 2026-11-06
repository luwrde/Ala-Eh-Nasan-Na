CREATE TABLE IF NOT EXISTS terminals (
    id INTEGER PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    municipality TEXT NOT NULL,
    address TEXT NOT NULL,
    boarding_direction TEXT,
    classification TEXT,
    operating_schedule TEXT,
    latitude REAL CHECK (latitude BETWEEN -90 AND 90),
    longitude REAL CHECK (longitude BETWEEN -180 AND 180),
    coordinates_verified INTEGER NOT NULL DEFAULT 0 CHECK (coordinates_verified IN (0, 1)),
    coordinate_source TEXT,
    coordinates_verified_at TEXT,
    data_source TEXT NOT NULL DEFAULT 'Project-provided location; operational details to be verified',
    CHECK ((latitude IS NULL) = (longitude IS NULL)),
    CHECK (coordinates_verified = 0 OR (
        latitude IS NOT NULL AND longitude IS NOT NULL
        AND length(trim(coordinate_source)) > 0 AND coordinate_source IS NOT NULL
        AND coordinates_verified_at IS NOT NULL
    ))
);

CREATE TABLE IF NOT EXISTS terminal_routes (
    id INTEGER PRIMARY KEY,
    terminal_id INTEGER NOT NULL REFERENCES terminals(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    destination TEXT,
    fare_information TEXT,
    operating_schedule TEXT,
    verified INTEGER NOT NULL DEFAULT 0 CHECK (verified IN (0, 1))
);
CREATE INDEX IF NOT EXISTS terminal_routes_terminal ON terminal_routes(terminal_id);
