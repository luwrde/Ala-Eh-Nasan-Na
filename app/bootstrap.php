<?php
declare(strict_types=1);

ini_set('display_errors', '0');

function load_local_environment(): void
{
    $environmentFile = dirname(__DIR__) . '/.env';
    if (!is_file($environmentFile)) {
        return;
    }

    $values = parse_ini_file($environmentFile, false, INI_SCANNER_RAW);
    if ($values === false) {
        throw new RuntimeException('Unable to read the local environment configuration.');
    }

    $allowedNames = [
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'SESSION_COOKIE_SECURE',
    ];
    foreach ($values as $name => $value) {
        if (in_array($name, $allowedNames, true) && is_string($value) && getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

load_local_environment();

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

function page_start(string $title, string $page = ''): void
{
    $safeTitle = h($title);
    $safePage = h($page);
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} | Driver Portal</title>
  <meta name="theme-color" content="#f5f8ff">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="page-{$safePage}">
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="index.php" aria-label="Ala-Eh Driver Portal home">
      <span class="brand-mark" aria-hidden="true">A</span>
      <span class="brand-name">Ala-Eh<span>Driver Portal</span></span>
    </a>
    <nav class="site-nav" aria-label="Main navigation">
      <a href="index.php">Home</a>
HTML;
    if (current_driver() !== null) {
        echo '<a href="dashboard.php">My application</a>';
        echo '<form class="nav-logout" method="post" action="logout.php">' . csrf_field() . '<button class="button button-outline button-small" type="submit">Log out</button></form>';
    } else {
        echo '<a href="login.php">Log in</a>';
        echo '<a class="button button-primary button-small" href="register.php">Get started <span aria-hidden="true">→</span></a>';
    }
    echo <<<HTML
    </nav>
  </div>
</header>
<main class="site-main" id="main-content">
HTML;
}

function page_end(): void
{
    echo <<<HTML
</main>
<footer class="site-footer">
  <div class="footer-inner">
    <a class="footer-brand" href="index.php">Ala-Eh Driver Portal</a>
    <span>Built for the road ahead.</span>
  </div>
</footer>
</body>
</html>
HTML;
}
