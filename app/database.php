<?php
declare(strict_types=1);

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'ala_eh';
    $user = getenv('DB_USER') ?: 'driver_app';
    $password = getenv('DB_PASSWORD');
    if ($password === false) {
        // Apache can drop an intentionally blank environment value. Use the
        // local project configuration in that case (XAMPP's root password is
        // commonly blank during local development).
        $environmentFile = dirname(__DIR__) . '/.env';
        $values = is_file($environmentFile) ? parse_ini_file($environmentFile, false, INI_SCANNER_RAW) : false;
        if (!is_array($values) || !array_key_exists('DB_PASSWORD', $values)) {
            throw new RuntimeException('DB_PASSWORD must be configured in the environment.');
        }
        $password = (string) $values['DB_PASSWORD'];
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $connection = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
