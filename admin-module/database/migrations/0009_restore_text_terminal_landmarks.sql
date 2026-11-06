-- Restore legacy descriptive landmarks that were mistakenly moved into gps_link.
UPDATE terminal_details
SET landmark = gps_link,
    gps_link = NULL
WHERE gps_link IS NOT NULL
  AND gps_link NOT REGEXP '^https://(www[.])?google([.]com([.]ph)?/maps)|^https://maps[.]google[.]com([.]ph)?/|^https://maps[.]app[.]goo[.]gl/|^https://goo[.]gl/maps/'
  AND CHAR_LENGTH(gps_link) <= 255;
