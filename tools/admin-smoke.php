<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$page = $argv[1] ?? 'admin-dashboard.php';
if (!preg_match('/\Aadmin-[a-z-]+\.php\z/', $page) || !is_file(__DIR__ . '/../public/' . $page)) exit(2);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/Ala-Eh_Nasan_Na_Projec/public/' . $page;
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
require_once __DIR__ . '/../app/admin-bootstrap.php';
$owner = db()->query("SELECT a.id, a.email, r.role FROM admins a JOIN admin_roles r ON r.admin_id = a.id WHERE r.role = 'owner' AND r.account_status = 'active' LIMIT 1")->fetch();
if (!$owner) throw new RuntimeException('No owner available for read-only page checks.');
$_SESSION['admin'] = $owner;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
// This test session is CLI-only and destroyed after rendering.
register_shutdown_function(static function (): void { $_SESSION = []; session_destroy(); });
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
if (isset($argv[2])) parse_str($argv[2], $_GET);
ob_start();
require __DIR__ . '/../public/' . $page;
$html = ob_get_clean();
if (!str_contains($html, '</html>')) throw new RuntimeException('Page did not render completely.');
if (!is_dir(__DIR__ . '/../storage/admin-qa')) mkdir(__DIR__ . '/../storage/admin-qa', 0700, true);
file_put_contents(__DIR__ . '/../storage/admin-qa/' . $page . '.html', $html);
echo $page . " rendered successfully\n";
