<?php
declare(strict_types=1);
require_once __DIR__ . '/terminals_repository.php';

function jeepney_position(mixed $point): bool
{
    return is_array($point) && array_is_list($point) && count($point) === 2
        && (is_int($point[0]) || is_float($point[0])) && (is_int($point[1]) || is_float($point[1]))
        && is_finite((float) $point[0]) && is_finite((float) $point[1])
        && abs($point[0]) <= 180 && abs($point[1]) <= 90;
}

/** GeoJSON order is longitude, latitude. Distances are mapped-path lengths, not live estimates. */
function jeepney_distance(array $a, array $b): float
{
    $lat = deg2rad($b[1] - $a[1]);
    $lng = deg2rad($b[0] - $a[0]);
    $h = sin($lat / 2) ** 2 + cos(deg2rad($a[1])) * cos(deg2rad($b[1])) * sin($lng / 2) ** 2;
    return 6371008.8 * 2 * asin(sqrt(min(1.0, max(0.0, $h))));
}

function jeepney_geometry(mixed $input): array
{
    if (is_array($input) && ($input['type'] ?? null) === 'FeatureCollection') {
        if (!is_array($input['features'] ?? null) || !array_is_list($input['features']) || count($input['features']) !== 1) throw new InvalidArgumentException('Supply exactly one continuous LineString feature per direction.');
        $input = $input['features'][0];
    }
    if (is_array($input) && ($input['type'] ?? null) === 'Feature') $input = $input['geometry'] ?? null;
    if (is_array($input) && array_is_list($input)) $input = ['type' => 'LineString', 'coordinates' => $input];
    $points = is_array($input) ? ($input['coordinates'] ?? null) : null;
    if (!is_array($input) || ($input['type'] ?? null) !== 'LineString' || !is_array($points) || !array_is_list($points)
        || count($points) < 3 || count($points) > 50000) {
        throw new InvalidArgumentException('A continuous LineString with 3–50,000 ordered [longitude, latitude] vertices is required.');
    }
    $length = 0.0;
    foreach ($points as $i => $point) {
        if (!jeepney_position($point)) throw new InvalidArgumentException('Invalid GeoJSON coordinate. Use numeric [longitude, latitude].');
        if ($i) {
            $segment = jeepney_distance($points[$i - 1], $point);
            if ($segment > 1000) throw new InvalidArgumentException('Trace has a gap over 1 km. Supply the full road geometry, not terminal-to-terminal lines.');
            $length += $segment;
        }
    }
    if ($length < 1) throw new InvalidArgumentException('The road trace must have a nonzero length.');
    return ['type' => 'LineString', 'coordinates' => $points];
}

function jeepney_text(mixed $value, int $limit = 500): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
        throw new InvalidArgumentException('Invalid or excessively long route text.');
    }
    return trim($value) ?: null;
}

function jeepney_preview_url(mixed $value): string
{
    $url = jeepney_text($value, 4000);
    $parts = $url ? parse_url($url) : false;
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Preview references require HTTPS source URLs.');
    }
    return $url;
}

function jeepney_preview(mixed $input): ?array
{
    if ($input === null) return null;
    if (!is_array($input) || ($input['kind'] ?? '') !== 'road-routing-preview') throw new InvalidArgumentException('Invalid preview kind.');
    $geometry = jeepney_geometry($input['geometry'] ?? null);
    $points = $input['points'] ?? null;
    if (!is_array($points) || !array_is_list($points) || count($points) < 2 || count($points) > 30) throw new InvalidArgumentException('Preview needs 2-30 ordered mapped references.');
    $clean = []; $vertexIndex = 0;
    foreach ($points as $i => $point) {
        $role = $i === 0 ? 'start' : ($i === count($points) - 1 ? 'end' : 'via');
        if (!is_array($point) || !jeepney_position($point['coordinates'] ?? null)
            || ($point['role'] ?? '') !== $role || !($name = jeepney_text($point['name'] ?? null, 200))
            || !($reference = jeepney_text($point['reference_name'] ?? null, 300))
            || !($note = jeepney_text($point['note'] ?? null, 1000))) throw new InvalidArgumentException('Each preliminary reference requires a role, name, mapped coordinates, and limitations.');
        $source = jeepney_preview_url($point['source_url'] ?? null);
        $best = INF; $bestIndex = $vertexIndex;
        for ($j = $vertexIndex; $j < count($geometry['coordinates']); $j++) {
            $distance = jeepney_distance($point['coordinates'], $geometry['coordinates'][$j]);
            if ($distance < $best) { $best = $distance; $bestIndex = $j; }
        }
        if ($best > 250) throw new InvalidArgumentException('The road preview must pass its mapped references in order (within 250 m).');
        $vertexIndex = $bestIndex;
        $clean[] = ['role' => $role, 'name' => $name, 'coordinates' => $point['coordinates'],
            'road_coordinates' => $geometry['coordinates'][$bestIndex],
            'reference_name' => $reference, 'note' => $note, 'source_url' => $source, 'route_stop_verified' => false];
    }
    if (jeepney_distance($clean[0]['coordinates'], $geometry['coordinates'][0]) > 250
        || jeepney_distance($clean[array_key_last($clean)]['coordinates'], $geometry['coordinates'][array_key_last($geometry['coordinates'])]) > 250) {
        throw new InvalidArgumentException('Preview endpoints must match the mapped references.');
    }
    $provider = jeepney_preview_url($input['provider_url'] ?? null);
    if (parse_url($provider, PHP_URL_HOST) !== 'routing.openstreetmap.de'
        || !str_starts_with(parse_url($provider, PHP_URL_PATH) ?: '', '/routed-car/route/v1/driving/')) throw new InvalidArgumentException('Use the existing FOSSGIS/OSRM driving service for this preview.');
    $generated = jeepney_text($input['generated_at'] ?? null, 40);
    $format = $generated && str_contains($generated, '.') ? 'Y-m-d\TH:i:s.v\Z' : 'Y-m-d\TH:i:s\Z';
    $date = $generated ? DateTimeImmutable::createFromFormat('!' . $format, $generated, new DateTimeZone('UTC')) : false;
    if (!$date || $date->format($format) !== $generated) throw new InvalidArgumentException('Valid preview generation timestamp required.');
    $length = 0.0;
    for ($i = 1; $i < count($geometry['coordinates']); $i++) $length += jeepney_distance($geometry['coordinates'][$i - 1], $geometry['coordinates'][$i]);
    return ['kind' => 'road-routing-preview', 'geometry' => $geometry, 'points' => $clean,
        'provider' => 'FOSSGIS / OSRM', 'provider_url' => $provider, 'generated_at' => $generated,
        'distance_meters' => round($length, 1), 'route_verified' => false];
}

function jeepney_route_input(PDO $db, array $input): array
{
    $slug = jeepney_text($input['slug'] ?? null, 100);
    $name = jeepney_text($input['name'] ?? null, 200);
    if (!$slug || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || !$name) throw new InvalidArgumentException('A lowercase hyphenated slug and route name are required.');
    $start = filter_var($input['start_terminal_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $end = filter_var($input['end_terminal_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $terminals = array_column(terminal_records($db), null, 'id');
    $direction = $input['travel_direction'] ?? '';
    $towns = ['batangas-to-mabini' => ['Batangas City', 'Mabini'], 'mabini-to-batangas' => ['Mabini', 'Batangas City']];
    if (!is_string($direction) || !isset($towns[$direction])) throw new InvalidArgumentException('Invalid travel direction.');
    if (!$start || !$end || $start === $end || !isset($terminals[$start], $terminals[$end], $towns[$direction])
        || $terminals[$start]['municipality'] !== $towns[$direction][0] || $terminals[$end]['municipality'] !== $towns[$direction][1]) {
        throw new InvalidArgumentException('Start, endpoint, and direction must match existing Batangas City/Mabini records.');
    }
    $status = $input['status'] ?? 'draft';
    if (!in_array($status, ['pending', 'draft', 'verified'], true)) throw new InvalidArgumentException('Invalid route status.');
    $raw = $input['geometry'] ?? $input['coordinates'] ?? null;
    $geometry = $raw === null ? null : jeepney_geometry($raw);
    if (($status === 'pending' && $geometry !== null) || ($status !== 'pending' && $geometry === null)) throw new InvalidArgumentException('Pending records have no path; draft and verified records require full geometry.');
    $source = jeepney_text($input['verification_source'] ?? null, 2000);
    $reviewer = jeepney_text($input['verified_by'] ?? null, 200);
    $verifiedAt = jeepney_text($input['verified_at'] ?? null, 40);
    if ($status === 'verified' && (!$source || !$reviewer || !$verifiedAt
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $verifiedAt)
        || ($date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $verifiedAt, new DateTimeZone('UTC'))) === false
        || $date->format('Y-m-d\TH:i:s\Z') !== $verifiedAt)) {
        throw new InvalidArgumentException('Verified traces require a source, reviewer, and valid UTC verified_at timestamp. A driving preview is not route verification.');
    }
    if ($geometry) {
        foreach ([[$start, $geometry['coordinates'][0]], [$end, $geometry['coordinates'][array_key_last($geometry['coordinates'])]]] as [$id, $point]) {
            $reference = $terminals[$id]['coordinates'] ?? $terminals[$id]['landmark_coordinates'];
            if ($reference && jeepney_distance([$reference['longitude'], $reference['latitude']], $point) > 500) {
                throw new InvalidArgumentException('Trace endpoints must be within 500 m of the selected mapped references. Check direction and coordinate order.');
            }
        }
    }
    $roads = $input['roads'] ?? [];
    $stops = $input['stops'] ?? [];
    if (!is_array($roads) || !array_is_list($roads) || count($roads) > 300 || !is_array($stops) || !array_is_list($stops) || count($stops) > 200) throw new InvalidArgumentException('Roads and stops must be ordered lists within supported limits.');
    foreach ($roads as &$road) {
        $road = jeepney_text($road, 200);
        if (!$road) throw new InvalidArgumentException('Road names must not be blank.');
    }
    unset($road);
    $preview = jeepney_preview($input['preview'] ?? null);
    if ($preview !== null && $status !== 'pending') throw new InvalidArgumentException('A road preview is stored only on a pending route; it is not verified jeepney geometry.');
    $cleanStops = [];
    foreach ($stops as $stop) {
        if (!is_array($stop) || ($stop['verified'] ?? false) !== true || !jeepney_position($stop['coordinates'] ?? null)
            || !($stopName = jeepney_text($stop['name'] ?? null, 200)) || !($stopSource = jeepney_text($stop['verification_source'] ?? null, 2000))) {
            throw new InvalidArgumentException('Every published stop needs a name, valid coordinates, verified=true, and its verification source.');
        }
        $kind = $stop['kind'] ?? 'stop';
        if (!in_array($kind, ['stop', 'landmark'], true)) throw new InvalidArgumentException('Stop kind must be stop or landmark.');
        if (!$geometry || min(array_map(fn($point) => jeepney_distance($point, $stop['coordinates']), $geometry['coordinates'])) > 500) throw new InvalidArgumentException('Stop is outside the mapped trace.');
        $cleanStops[] = ['name' => $stopName, 'coordinates' => $stop['coordinates'], 'kind' => $kind, 'verified' => true, 'verification_source' => $stopSource];
    }
    return ['slug' => $slug, 'name' => $name, 'start_terminal_id' => (int) $start, 'end_terminal_id' => (int) $end,
        'travel_direction' => $direction, 'geometry' => $geometry, 'roads' => $roads, 'stops' => $cleanStops,
        'status' => $status, 'verification_source' => $source, 'verified_by' => $reviewer, 'verified_at' => $verifiedAt, 'preview' => $preview];
}

function import_jeepney_route(PDO $db, array $input, bool $replace = false): int
{
    $data = jeepney_route_input($db, $input);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $lookup = $db->prepare('SELECT id FROM jeepney_route_paths WHERE slug = ?');
        $lookup->execute([$data['slug']]);
        $id = $lookup->fetchColumn();
        if ($id !== false && !$replace) throw new InvalidArgumentException('This slug already exists. Use --replace only to intentionally update that route.');
        $values = $data;
        foreach (['geometry' => 'geometry_geojson', 'roads' => 'roads_json', 'stops' => 'stops_json', 'preview' => 'preview_json'] as $field => $column) {
            $values[$column] = $data[$field] === null ? null : json_encode($data[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            unset($values[$field]);
        }
        $values['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $columns = array_keys($values);
        if ($id === false) {
            $sql = 'INSERT INTO jeepney_route_paths (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')';
        } else {
            $sql = 'UPDATE jeepney_route_paths SET ' . implode(', ', array_map(fn($column) => "$column = :$column", $columns)) . ' WHERE id = :id';
            $values['id'] = $id;
        }
        $db->prepare($sql)->execute($values);
        $routeId = $id === false ? (int) $db->lastInsertId() : (int) $id;
        $db->exec('COMMIT');
        return $routeId;
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
}

function jeepney_route_records(PDO $db, ?int $id = null): array
{
    $query = $db->prepare('SELECT * FROM jeepney_route_paths' . ($id !== null ? ' WHERE id = :id' : '') . " ORDER BY CASE status WHEN 'verified' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, name, id");
    $query->execute($id === null ? [] : ['id' => $id]);
    $result = [];
    foreach ($query as $row) {
        $input = $row;
        try {
            $input['geometry'] = $row['geometry_geojson'] === null ? null : json_decode($row['geometry_geojson'], true, 512, JSON_THROW_ON_ERROR);
            $input['roads'] = json_decode($row['roads_json'], true, 512, JSON_THROW_ON_ERROR);
            $input['stops'] = json_decode($row['stops_json'], true, 512, JSON_THROW_ON_ERROR);
            $input['preview'] = $row['preview_json'] === null ? null : json_decode($row['preview_json'], true, 512, JSON_THROW_ON_ERROR);
            $route = jeepney_route_input($db, $input);
            $route['verification_issue'] = null;
        } catch (Throwable $error) {
            // A malformed manual database edit must never publish a "verified" path.
            $route = ['slug' => $row['slug'], 'name' => $row['name'], 'start_terminal_id' => (int) $row['start_terminal_id'],
                'end_terminal_id' => (int) $row['end_terminal_id'], 'travel_direction' => $row['travel_direction'],
                'status' => 'pending', 'geometry' => null, 'roads' => [], 'stops' => [], 'preview' => null,
                'verification_source' => null, 'verified_by' => null, 'verified_at' => null,
                'verification_issue' => 'Stored route data requires review.'];
        }
        $route['id'] = (int) $row['id'];
        $route['updated_at'] = $row['updated_at'];
        $route['distance_meters'] = null;
        if ($route['geometry']) {
            $points = $route['geometry']['coordinates'];
            $length = 0.0;
            for ($i = 1; $i < count($points); $i++) $length += jeepney_distance($points[$i - 1], $points[$i]);
            $route['distance_meters'] = round($length, 1);
        }
        if ($route['status'] !== 'verified') { $route['roads'] = []; $route['stops'] = []; }
        $result[] = $route;
    }
    return $result;
}
