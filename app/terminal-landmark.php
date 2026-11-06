<?php
declare(strict_types=1);

// Accept Google Maps location links and its two standard sharing domains.
// Keep this pattern shared with the browser validation.
const TERMINAL_MAPS_LINK_PATTERN = 'https://(?:(?:www\\.)?google\\.com(?:\\.ph)?/maps(?:/[^\\s?#]+[^\\s]*|/\\?[^\\s]+|\\?[^\\s]+)|maps\\.google\\.com(?:\\.ph)?/(?:[^\\s?#]+[^\\s]*|\\?[^\\s]+)|maps\\.app\\.goo\\.gl/[A-Za-z0-9_-]+(?:\\?[^\\s]*)?|goo\\.gl/maps/[A-Za-z0-9_-]+(?:\\?[^\\s]*)?)';

function is_terminal_maps_link(string $value): bool
{
    return strlen($value) <= 2048
        && filter_var($value, FILTER_VALIDATE_URL) !== false
        && preg_match('~\\A(?:' . TERMINAL_MAPS_LINK_PATTERN . ')\\z~i', $value) === 1;
}
