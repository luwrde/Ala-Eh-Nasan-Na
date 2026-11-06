<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';

$email = strtolower(trim((string) ($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    fwrite(STDERR, "Usage: php tools/create_admin.php admin@example.com\n");
    exit(1);
}

fwrite(STDOUT, 'Admin password (input is visible): ');
$password = fgets(STDIN);
fwrite(STDOUT, 'Confirm password: ');
$confirmation = fgets(STDIN);

if ($password === false || $confirmation === false) {
    fwrite(STDERR, "Could not read the password from the terminal.\n");
    exit(1);
}

$password = rtrim($password, "\r\n");
$confirmation = rtrim($confirmation, "\r\n");
if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords must match and be at least 12 characters.\n");
    exit(1);
}

if (!create_first_admin($email, $password)) {
    fwrite(STDERR, "An admin account already exists; only one admin is allowed.\n");
    exit(1);
}

fwrite(STDOUT, "Admin account created for {$email}.\n");
