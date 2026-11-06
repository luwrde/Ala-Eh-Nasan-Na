-- Preserve existing Google Maps URLs in a dedicated field and free landmark for descriptive text.
ALTER TABLE terminal_details
    ADD COLUMN gps_link VARCHAR(2048) NULL AFTER landmark;

UPDATE terminal_details
SET gps_link = CASE
        WHEN landmark REGEXP '^https://(www[.])?google([.]com([.]ph)?/maps)|^https://maps[.]google[.]com([.]ph)?/|^https://maps[.]app[.]goo[.]gl/|^https://goo[.]gl/maps/'
        THEN landmark
        ELSE NULL
    END,
    landmark = CASE
        WHEN landmark REGEXP '^https://(www[.])?google([.]com([.]ph)?/maps)|^https://maps[.]google[.]com([.]ph)?/|^https://maps[.]app[.]goo[.]gl/|^https://goo[.]gl/maps/'
        THEN ''
        ELSE landmark
    END
WHERE gps_link IS NULL;

ALTER TABLE terminal_details
    MODIFY landmark VARCHAR(255) NOT NULL DEFAULT '';
