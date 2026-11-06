-- Preserve existing landmarks while allowing complete Google Maps location URLs.
ALTER TABLE terminal_details MODIFY landmark VARCHAR(2048) NOT NULL;
