<?php
declare(strict_types=1);

ini_set('display_errors', '0');

set_exception_handler(static function (Throwable $exception): void {
    error_log((string) $exception);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'An unexpected server error occurred.';
});

$secureCookieSetting = getenv('SESSION_COOKIE_SECURE');
$secureCookieSetting = strtolower($secureCookieSetting === false ? 'true' : $secureCookieSetting);
$secureCookie = !in_array($secureCookieSetting, ['false', '0', 'off'], true);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name('driver_portal_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function current_driver(): ?array
{
    return isset($_SESSION['driver']) && is_array($_SESSION['driver'])
        ? $_SESSION['driver']
        : null;
}

function require_driver(): array
{
    $driver = current_driver();
    if ($driver === null) {
        redirect('login.php');
    }

    return $driver;
}

function page_start(string $title): void
{
    $safeTitle = h($title);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} | Driver Portal</title>
  <style>
    body { font: 16px/1.5 system-ui, sans-serif; max-width: 42rem; margin: 3rem auto; padding: 0 1rem; color: #17212b; }
    form { display: grid; gap: .8rem; max-width: 28rem; }
    label { display: grid; gap: .25rem; }
    input { font: inherit; padding: .55rem; }
    button { font: inherit; padding: .6rem 1rem; cursor: pointer; }
    .message { padding: .8rem; background: #eef4fa; border-left: 4px solid #326b9a; }
    .status { font-weight: 700; }
    nav { display: flex; gap: 1rem; margin-bottom: 2rem; }
  </style>
</head>
<body>
<nav><a href="index.php">Home</a><a href="register.php">Register</a><a href="login.php">Log in</a></nav>
<main>
HTML;
}

function page_end(): void
{
    echo "</main></body></html>";
}
