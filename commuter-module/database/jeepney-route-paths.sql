-- Actual directional traces are separate from terminal_routes (fare/service records).
-- No geometry or service pair is seeded: the supplied links verify landmarks only.
CREATE TABLE jeepney_route_paths (
    id INTEGER PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    start_terminal_id INTEGER NOT NULL REFERENCES terminals(id) ON DELETE RESTRICT,
    end_terminal_id INTEGER NOT NULL REFERENCES terminals(id) ON DELETE RESTRICT,
    travel_direction TEXT NOT NULL CHECK (travel_direction IN ('batangas-to-mabini', 'mabini-to-batangas')),
    geometry_geojson TEXT,
    roads_json TEXT NOT NULL DEFAULT '[]',
    stops_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'draft', 'verified')),
    verification_source TEXT,
    verified_by TEXT,
    verified_at TEXT,
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    CHECK (start_terminal_id <> end_terminal_id),
    CHECK (status <> 'verified' OR (
        geometry_geojson IS NOT NULL AND verification_source IS NOT NULL
        AND length(trim(verification_source)) > 0 AND verified_by IS NOT NULL
        AND length(trim(verified_by)) > 0 AND verified_at IS NOT NULL
    ))
);
CREATE INDEX jeepney_route_selection ON jeepney_route_paths(start_terminal_id, end_terminal_id, travel_direction);
