-- Destination-search metadata only. Routes must be supplied and confirmed by the owner.
ALTER TABLE terminal_routes ADD COLUMN source_url TEXT;
ALTER TABLE terminal_routes ADD COLUMN source_title TEXT;
ALTER TABLE terminal_routes ADD COLUMN service_note TEXT;
