ALTER TABLE terminals ADD COLUMN google_maps_url TEXT;
ALTER TABLE terminals ADD COLUMN google_maps_resolved_url TEXT;
ALTER TABLE terminals ADD COLUMN landmark_name TEXT;
ALTER TABLE terminals ADD COLUMN landmark_latitude REAL CHECK (landmark_latitude BETWEEN -90 AND 90);
ALTER TABLE terminals ADD COLUMN landmark_longitude REAL CHECK (landmark_longitude BETWEEN -180 AND 180 AND ((landmark_latitude IS NULL) = (landmark_longitude IS NULL)));
ALTER TABLE terminals ADD COLUMN landmark_coordinates_verified INTEGER NOT NULL DEFAULT 0 CHECK (landmark_coordinates_verified IN (0, 1) AND (landmark_coordinates_verified = 0 OR (landmark_latitude IS NOT NULL AND landmark_longitude IS NOT NULL)));
ALTER TABLE terminals ADD COLUMN location_verification_status TEXT NOT NULL DEFAULT 'pending' CHECK (location_verification_status IN ('pending', 'landmark_verified', 'verified_reference', 'boarding_verified'));
ALTER TABLE terminals ADD COLUMN updated_at TEXT;

-- Reviewed user-provided Google Maps links on 2026-11-01. !3d/!4d are the named
-- place positions, not the @ Street View camera coordinates. These establish
-- landmarks only; never copy them into the precise boarding-point fields.
UPDATE terminals SET
 google_maps_url = 'https://maps.app.goo.gl/mi7CqzXjvnwnZBSy8',
 google_maps_resolved_url = 'https://www.google.com/maps/place/Total/data=!4m6!3m5!1s0x33bd056b424762d9:0x6d411a780139809d!8m2!3d13.7560962!4d121.0518226!16s%2Fg%2F11csqwg2s0',
 landmark_name = 'Total', landmark_latitude = 13.7560962, landmark_longitude = 121.0518226,
 landmark_coordinates_verified = 1
WHERE id = 1 AND slug = 'total-gas-station';

UPDATE terminals SET
 google_maps_url = 'https://maps.app.goo.gl/Zh9HJ8AVyuARsWjh6',
 google_maps_resolved_url = 'https://www.google.com/maps/place/Kwatogs+Bolbok+Diversion/data=!4m6!3m5!1s0x33bd05007e70cba7:0x19a84d6e239c7c54!8m2!3d13.7715645!4d121.0499724!16s%2Fg%2F11x6skt2xg',
 landmark_name = 'Kwatogs Bolbok Diversion', landmark_latitude = 13.7715645, landmark_longitude = 121.0499724,
 landmark_coordinates_verified = 1
WHERE id = 2 AND slug = 'diversion-road';

UPDATE terminals SET
 google_maps_url = 'https://maps.app.goo.gl/7YPKhDnvYGaoEx8e9',
 google_maps_resolved_url = 'https://www.google.com/maps/place/Mabini+Tourism+Triangle/data=!4m6!3m5!1s0x33bd07ab21255555:0x879770585028d30f!8m2!3d13.7525621!4d120.9406161!16s%2Fg%2F11zj14zs09',
 landmark_name = 'Mabini Tourism Triangle', landmark_latitude = 13.7525621, landmark_longitude = 120.9406161,
 landmark_coordinates_verified = 1,
 boarding_direction = COALESCE(NULLIF(trim(boarding_direction), ''), 'Left side of F. Castillo Boulevard.')
WHERE id = 4 AND slug = 'mabini-tourism-triangle';

UPDATE terminals SET
 google_maps_url = 'https://maps.app.goo.gl/L1pNnSffCY6zzKs8A',
 google_maps_resolved_url = 'https://www.google.com/maps/place/7-Eleven+(1079)+Mabini/data=!4m6!3m5!1s0x33bd070012523707:0xfc2cc0e608c3ab!8m2!3d13.749087!4d120.9404809!16s%2Fg%2F1ptyv4cvf',
 landmark_name = '7-Eleven (1079) Mabini', landmark_latitude = 13.749087, landmark_longitude = 120.9404809,
 landmark_coordinates_verified = 1,
 boarding_direction = COALESCE(NULLIF(trim(boarding_direction), ''), 'In front of 7-Eleven, left side of F. Castillo Boulevard.')
WHERE id = 5 AND slug = '7-eleven-mabini';

UPDATE terminals SET
 google_maps_url = 'https://maps.app.goo.gl/ujKj51cVLsVsBJrj8',
 google_maps_resolved_url = 'https://www.google.com/maps/place/Iconails+Beauty+Lounge+Mabini/data=!4m6!3m5!1s0x33bd07264bd675e3:0xa97b47f3a007f12!8m2!3d13.7477068!4d120.9406855!16s%2Fg%2F11scty4dpg',
 landmark_name = 'Iconails Beauty Lounge Mabini', landmark_latitude = 13.7477068, landmark_longitude = 120.9406855,
 landmark_coordinates_verified = 1,
 boarding_direction = COALESCE(NULLIF(trim(boarding_direction), ''), 'In front of the establishment along Mabini Circumferential Road.')
WHERE id = 6 AND slug = 'iconails-beauty-lounge';

UPDATE terminals SET location_verification_status = CASE
 WHEN coordinates_verified = 1 THEN 'verified_reference'
 WHEN landmark_coordinates_verified = 1 THEN 'landmark_verified'
 ELSE 'pending' END,
 updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
WHERE id IN (1, 2, 3, 4, 5, 6);
