<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/Ala-Eh_Nasan_Na_Projec/public/driver-documents.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
require_once __DIR__ . '/../app/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
$driver = db()->query("SELECT d.id FROM drivers d LEFT JOIN driver_admin_status s ON s.driver_id=d.id WHERE COALESCE(s.account_status,'active')='active' LIMIT 1")->fetch();
if (!$driver) throw new RuntimeException('No active driver available for read-only rendering.');
$_SESSION = ['driver' => $driver, 'csrf_token' => bin2hex(random_bytes(32))];
register_shutdown_function(static function (): void { $_SESSION = []; session_destroy(); });
ob_start();
require __DIR__ . '/../public/driver-documents.php';
$html = ob_get_clean();
if (!str_contains($html, '</html>')) throw new RuntimeException('Page render incomplete.');
if (!is_dir(__DIR__ . '/../storage/admin-qa')) mkdir(__DIR__ . '/../storage/admin-qa', 0700, true);
file_put_contents(__DIR__ . '/../storage/admin-qa/driver-documents.html', $html);
echo "Driver documents rendered successfully.\n";
