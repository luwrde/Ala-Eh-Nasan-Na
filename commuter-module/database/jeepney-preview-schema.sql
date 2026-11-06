-- Road-routing previews remain separate from operator-verified jeepney geometry/stops.
ALTER TABLE jeepney_route_paths ADD COLUMN preview_json TEXT;
