<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/jeepney_routes_repository.php';
$options = getopt('', ['file:', 'replace']);
try {
    $path = $options['file'] ?? '';
    if (!is_string($path) || !is_file($path) || filesize($path) > 8000000) throw new InvalidArgumentException('Use --file=path/to/route.json (local JSON, up to 8 MB).');
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) throw new InvalidArgumentException('The file must contain one route metadata object.');
    $id = import_jeepney_route(terminals_database(), $data, array_key_exists('replace', $options));
    fwrite(STDOUT, "Saved directional route ID $id. Terminal records were not changed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Import failed: ' . $error->getMessage() . "\n");
    exit(1);
}
