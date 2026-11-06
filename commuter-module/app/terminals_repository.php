<?php
declare(strict_types=1);

/** Separate from the removed authentication backend. This repository contains public location data. */
function terminals_database(): PDO
{
    $path = getenv('ALA_EH_TERMINALS_DB') ?: dirname(__DIR__) . '/storage/terminals.sqlite';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create terminal storage.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() < 7) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            // Recheck after obtaining the lock: concurrent first requests must not seed twice.
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 0) {
                foreach (['terminals-schema.sql', 'terminals-seed.sql'] as $file) {
                    $sql = file_get_contents(dirname(__DIR__) . '/database/' . $file);
                    if ($sql === false) throw new RuntimeException('Missing terminal database migration.');
                    $db->exec($sql);
                }
                $db->exec('PRAGMA user_version = 1');
            }
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 1) {
                $sql = file_get_contents(dirname(__DIR__) . '/database/terminals-mapped-locations.sql');
                if ($sql === false) throw new RuntimeException('Missing terminal coordinate migration.');
                $db->exec($sql);
                $db->exec('PRAGMA user_version = 2');
            }
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 2) {
                $sql = file_get_contents(dirname(__DIR__) . '/database/terminals-google-references.sql');
                if ($sql === false) throw new RuntimeException('Missing Google Maps reference migration.');
                $db->exec($sql);
                $db->exec('PRAGMA user_version = 3');
            }
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 3) {
                $sql = file_get_contents(dirname(__DIR__) . '/database/jeepney-route-paths.sql');
                if ($sql === false) throw new RuntimeException('Missing jeepney route migration.');
                $db->exec($sql);
                $db->exec('PRAGMA user_version = 4');
            }
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 4) {
                $sql = file_get_contents(dirname(__DIR__) . '/database/terminal-destination-search.sql');
                if ($sql === false) throw new RuntimeException('Missing terminal destination migration.');
                $db->exec($sql);
                $db->exec('PRAGMA user_version = 5');
            }
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 5) {
                $sql = file_get_contents(dirname(__DIR__) . '/database/terminal-example-cleanup.sql');
                if ($sql === false) throw new RuntimeException('Missing terminal example cleanup migration.');
                $db->exec($sql);
                $db->exec('PRAGMA user_version = 6');
            }
            if ((int) $db->query('PRAGMA user_version')->fetchColumn() === 6) {
                $schema = file_get_contents(dirname(__DIR__) . '/database/jeepney-preview-schema.sql');
                $seed = file_get_contents(dirname(__DIR__) . '/database/grand-terminal-mabini-preview.json');
                if ($schema === false || $seed === false) throw new RuntimeException('Missing single-route preview migration.');
                $columns = array_column($db->query('PRAGMA table_info(jeepney_route_paths)')->fetchAll(), 'name');
                if (!in_array('preview_json', $columns, true)) $db->exec($schema);
                $route = json_decode($seed, true, 512, JSON_THROW_ON_ERROR);
                $insert = $db->prepare("INSERT INTO jeepney_route_paths (slug, name, start_terminal_id, end_terminal_id, travel_direction, status, preview_json)
                    SELECT :slug, :name, :start, :end, :direction, 'pending', :preview
                    WHERE NOT EXISTS (SELECT 1 FROM jeepney_route_paths WHERE slug = :slug)");
                $insert->execute(['slug' => $route['slug'], 'name' => $route['name'], 'start' => $route['start_terminal_id'],
                    'end' => $route['end_terminal_id'], 'direction' => $route['travel_direction'],
                    'preview' => json_encode($route['preview'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
                $db->exec('PRAGMA user_version = 7');
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            $db->exec('ROLLBACK');
            throw $error;
        }
    }
    return $db;
}

function terminal_records(PDO $db, ?int $id = null): array
{
    $statement = $db->prepare('SELECT * FROM terminals' . ($id !== null ? ' WHERE id = :id' : '') . ' ORDER BY municipality, id');
    $statement->execute($id !== null ? ['id' => $id] : []);
    $terminals = $statement->fetchAll();
    $routes = $db->prepare('SELECT id, terminal_id, name, destination, fare_information, operating_schedule, verified, source_url, source_title, service_note FROM terminal_routes WHERE verified = 1' . ($id !== null ? ' AND terminal_id = :id' : '') . ' ORDER BY id');
    $routes->execute($id !== null ? ['id' => $id] : []);
    $byTerminal = [];
    foreach ($routes as $route) {
        $route['id'] = (int) $route['id'];
        $route['verified'] = true;
        $terminalId = (int) $route['terminal_id'];
        unset($route['terminal_id']);
        $byTerminal[$terminalId][] = $route;
    }
    foreach ($terminals as &$terminal) {
        $terminal['id'] = (int) $terminal['id'];
        $lat = $terminal['latitude'];
        $lng = $terminal['longitude'];
        $verified = (bool) $terminal['coordinates_verified'] && is_numeric($lat) && is_numeric($lng)
            && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180;
        // Never expose an unverified coordinate as a usable map position.
        $terminal['coordinates'] = $verified ? ['latitude' => (float) $lat, 'longitude' => (float) $lng] : null;
        unset($terminal['latitude'], $terminal['longitude']);
        $terminal['coordinates_verified'] = $verified;
        $landmarkLat = $terminal['landmark_latitude'];
        $landmarkLng = $terminal['landmark_longitude'];
        $landmarkVerified = (bool) $terminal['landmark_coordinates_verified']
            && is_numeric($landmarkLat) && is_numeric($landmarkLng)
            && (float) $landmarkLat >= -90 && (float) $landmarkLat <= 90
            && (float) $landmarkLng >= -180 && (float) $landmarkLng <= 180;
        $terminal['landmark_coordinates'] = $landmarkVerified
            ? ['latitude' => (float) $landmarkLat, 'longitude' => (float) $landmarkLng] : null;
        $terminal['landmark_coordinates_verified'] = $landmarkVerified;
        unset($terminal['landmark_latitude'], $terminal['landmark_longitude']);
        $terminal['location_verification_status'] = $verified
            ? ($terminal['location_verification_status'] === 'boarding_verified' ? 'boarding_verified' : 'verified_reference')
            : ($landmarkVerified ? 'landmark_verified' : 'pending');
        $terminal['routes'] = $byTerminal[$terminal['id']] ?? [];
        // There is no live seat source in this project; do not imply availability.
        $terminal['seat_availability'] = null;
    }
    unset($terminal);
    return $terminals;
}
