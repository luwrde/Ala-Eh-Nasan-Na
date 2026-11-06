ALTER TABLE terminals ADD COLUMN coordinate_note TEXT;

-- A mapped terminal-area reference, not an inferred roadside or jeepney boarding bay.
-- Source names Batangas City Grand Terminal and identifies OpenStreetMap way 236809581.
-- https://mapcarta.com/W236809581 (reviewed 2026-11-01).
-- Preserve any coordinates already configured by the project owner.
UPDATE terminals
SET latitude = 13.78986,
    longitude = 121.06235,
    coordinates_verified = 1,
    coordinate_source = 'OpenStreetMap way 236809581, via https://mapcarta.com/W236809581',
    coordinates_verified_at = '2026-11-01',
    coordinate_note = 'Mapped terminal area. The exact jeepney boarding bay is still to be verified.'
WHERE slug = 'batangas-city-grand-terminal'
  AND coordinates_verified = 0 AND latitude IS NULL AND longitude IS NULL;
