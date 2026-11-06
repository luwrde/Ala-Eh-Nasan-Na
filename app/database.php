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
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $caPath = trim((string) (getenv('DB_SSL_CA') ?: ''));
    $caContents = getenv('DB_SSL_CA_BASE64');
    if ($caContents !== false && $caContents !== '') {
        $decodedCa = base64_decode($caContents, true);
        if (!is_string($decodedCa) || $decodedCa === '') {
            throw new RuntimeException('DB_SSL_CA_BASE64 must contain a valid base64-encoded CA certificate.');
        }
        $caPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ala-eh-mysql-ca.pem';
        if (file_put_contents($caPath, $decodedCa, LOCK_EX) !== strlen($decodedCa)) {
            throw new RuntimeException('Unable to prepare the database CA certificate.');
        }
        @chmod($caPath, 0600);
    }
    if ($caPath !== '') {
        if (!is_file($caPath) || !is_readable($caPath) || !defined('PDO::MYSQL_ATTR_SSL_CA')) {
            throw new RuntimeException('The configured database CA certificate is unavailable.');
        }
        $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $caPath;
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
        }
    }

    $connection = new PDO($dsn, $user, $password, $options);

    return $connection;
}
