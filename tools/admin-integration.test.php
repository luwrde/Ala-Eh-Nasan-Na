<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Exercise real page handlers in an isolated MySQL database; never use live accounts.
if (($argv[1] ?? '') === '--worker') {
    $request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'POST';
    $_SERVER['SCRIPT_NAME'] = '/public/' . $request['page'];
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    $_POST = $request['post'] ?? [];
    $_GET = $request['get'] ?? [];
    require_once __DIR__ . '/../app/' . (str_starts_with($request['page'], 'admin-') ? 'admin-bootstrap.php' : 'bootstrap.php');
    $_SESSION = ['csrf_token' => 'integration-test-token'];
    if (isset($request['admin'])) $_SESSION['admin'] = ['id' => $request['admin'], 'email' => 'fixture@example.invalid'];
    if (isset($request['driver'])) $_SESSION['driver'] = ['id' => $request['driver']];
    if (isset($request['commuter'])) $_SESSION['commuter'] = ['id' => $request['commuter']];
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        $status = http_response_code() ?: 200;
        $identity = ['admin' => $_SESSION['admin'] ?? null, 'driver' => $_SESSION['driver'] ?? null, 'commuter' => $_SESSION['commuter'] ?? null];
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION = []; session_destroy();
        echo json_encode(['status' => $status, 'identity' => $identity, 'bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'pdf' => str_starts_with($body, '%PDF-'), 'body' => strlen($body) < 250 ? $body : '']);
    });
    if (isset($request['commuter_login'])) {
        require_once __DIR__ . '/../app/commuter-auth.php';
        $result = login_commuter_account($request['commuter_login']);
        http_response_code($result[0]);
        echo json_encode($result);
        exit;
    }
    if (isset($request['record_commuter_activity'])) {
        require_once __DIR__ . '/../app/commuter-auth.php';
        echo json_encode(['recorded' => record_commuter_activity()]);
        exit;
    }
    require __DIR__ . '/../public/' . $request['page'];
    exit;
}

require_once __DIR__ . '/../app/bootstrap.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
$live = db();
$database = 'ala_eh_admin_test_' . bin2hex(random_bytes(6));
$checks = 0;
$documentTestPaths = [];
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$run = static function (array $request) use ($database): array {
    $env = getenv(); $env['DB_NAME'] = $database;
    $proc = proc_open([PHP_BINARY, '-d', 'session.save_path=' . __DIR__ . '/../storage', __FILE__, '--worker'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $env);
    fwrite($pipes[0], json_encode($request)); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    if ($errors !== '' || $code !== 0) throw new RuntimeException('Handler failed: ' . $errors);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
};
$post = static fn(string $page, int $admin, array $data): array => $run(['page'=>$page,'admin'=>$admin,'post'=>['csrf_token'=>'integration-test-token']+$data]);
try {
    $live->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $live->exec('USE `' . $database . '`');
    $schema = (string) file_get_contents(__DIR__ . '/../database/schema.sql');
    $schema = preg_replace('/CREATE DATABASE.*?;|USE\s+ala_eh\s*;/is', '', $schema);
    $live->exec($schema);
    foreach (['commuter-accounts.sql','map-locations.sql','seat-availability.sql','trip-status.sql','driver-pickup-areas.sql'] as $file) $live->exec((string) file_get_contents(__DIR__ . '/../database/' . $file));
    foreach (glob(__DIR__ . '/../database/admin-migrations/*.sql') as $file) $live->exec((string) file_get_contents($file));
    $live->exec("ALTER TABLE commuter_accounts ADD phone VARCHAR(32) NULL, ADD account_status ENUM('active','suspended','deactivated') DEFAULT 'active'");
    require_once __DIR__ . '/../app/database-session-handler.php';
    $sessionHandler = new DatabaseSessionHandler();
    $sessionId = bin2hex(random_bytes(16));
    $assert($sessionHandler->write($sessionId, 'integration-session-data'), 'Shared session data must save');
    $assert($sessionHandler->validateId($sessionId), 'Shared session ID must validate');
    $assert($sessionHandler->read($sessionId) === 'integration-session-data', 'Shared session data must load');
    $assert($sessionHandler->updateTimestamp($sessionId, 'integration-session-data'), 'Shared session expiry must refresh');
    $assert($sessionHandler->destroy($sessionId) && !$sessionHandler->validateId($sessionId), 'Shared session must be destroyable');
    $presenceMigration = (string) file_get_contents(__DIR__ . '/../database/commuter-presence.sql');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $presenceMigration, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
        $live->exec(trim($statement));
    }
    $hash = password_hash('Fixture password 123!', PASSWORD_DEFAULT);
    $r = $run(['page'=>'admin-register.php','post'=>['csrf_token'=>'integration-test-token','email'=>'owner@example.invalid','password'=>'Fixture password 123!','password_confirm'=>'Fixture password 123!']]);
    $assert($r['status']===303 && $live->query('SELECT role FROM admin_roles WHERE admin_id=1')->fetchColumn()==='owner', 'First registration must create the owner');
    $r = $run(['page'=>'admin-register.php','method'=>'GET']);
    $assert($r['status']===303, 'First-owner setup must close after registration');
    $insert = $live->prepare('INSERT INTO admins (email, password_hash) VALUES (?, ?)');
    foreach (['admin','viewer'] as $role) {
        $insert->execute([$role . '@example.invalid', $hash]);
        $live->prepare('INSERT INTO admin_roles (admin_id, role) VALUES (?, ?)')->execute([(int)$live->lastInsertId(),$role]);
    }
    $live->prepare("INSERT INTO drivers (full_name,email,phone,license_number,password_hash) VALUES ('Fixture Driver','driver@example.invalid','09123456789','QA-123',?)")->execute([$hash]);
    $token = 'integration-test-token';
    foreach (['owner', 'admin', 'viewer'] as $role) {
        foreach (['login.php', 'admin-login.php'] as $loginPage) {
            $r = $run(['page'=>$loginPage,'commuter'=>999,'post'=>['csrf_token'=>$token,'email'=>$role . '@example.invalid','password'=>'Fixture password 123!']]);
            $assert($r['status']===303 && ($r['identity']['admin']['role'] ?? '')===$role && $r['identity']['commuter']===null, 'Admin credentials must work through ' . $loginPage . ' with the correct role');
        }
        $r = $run(['page'=>'commuter/api/auth.php','commuter'=>999,'driver'=>1,'commuter_login'=>['email'=>$role . '@example.invalid','password'=>'Fixture password 123!']]);
        $assert($r['status']===200 && ($r['identity']['admin']['role'] ?? '')===$role && $r['identity']['driver']===null && $r['identity']['commuter']===null && json_decode($r['body'],true)[2]==='admin', 'Passenger login must establish only the correct admin identity');
    }
    $r = $run(['page'=>'login.php','post'=>['csrf_token'=>$token,'email'=>'driver@example.invalid','password'=>'Fixture password 123!']]);
    $assert($r['status']===303 && ($r['identity']['driver']['id'] ?? 0)===1 && $r['identity']['admin']===null, 'Regular driver login must still work without admin access');
    $r = $run(['page'=>'commuter/api/auth.php','commuter_login'=>['email'=>'owner@example.invalid','password'=>'wrong']]);
    $assert($r['status']===401 && $r['identity']['admin']===null, 'Wrong admin password cannot enter through passenger login');
    $r = $run(['page'=>'login.php','post'=>['csrf_token'=>$token,'email'=>'owner@example.invalid','password'=>'wrong']]);
    $assert($r['identity']['admin']===null, 'Wrong admin password cannot enter through driver login');
    $r = $run(['page'=>'login.php','post'=>['csrf_token'=>$token,'email'=>'owner@example.invalid','password'=>'Fixture password 123!']]);
    $r = $run(['page'=>'admin-drivers.php','admin'=>1,'post'=>['action'=>'verification','driver_id'=>'1','status'=>'verified']]);
    $assert($r['status']===403, 'CSRF must reject missing tokens');
    $r = $post('admin-drivers.php',3,['action'=>'verification','driver_id'=>'1','status'=>'verified']);
    $assert($r['status']===403, 'Viewer must not approve drivers');
    $r = $post('admin-drivers.php',2,['action'=>'verification','driver_id'=>'1','status'=>'verified']);
    $assert($r['status']===303 && $live->query('SELECT verification_status FROM drivers WHERE id=1')->fetchColumn()==='verified', 'Admin approval must update driver');
    $r = $post('admin-users.php',2,['action'=>'create_admin']);
    $assert($r['status']===403, 'Only owner manages accounts');
    $r = $post('admin-users.php',1,['action'=>'create_commuter','full_name'=>'Fixture Passenger','email'=>'passenger@example.invalid','phone'=>'','password'=>'Fixture password 123!','password_confirm'=>'Fixture password 123!']);
    $assert($r['status']===400 && (int)$live->query('SELECT COUNT(*) FROM commuter_accounts')->fetchColumn()===0, 'Admin user management must not create commuter accounts');
    $live->prepare("INSERT INTO commuter_accounts (full_name,email,password_hash) VALUES ('Fixture Passenger', 'passenger@example.invalid', ?)")->execute([password_hash('Fixture password 123!', PASSWORD_DEFAULT)]);
    $assert(password_verify('Fixture password 123!',(string)$live->query('SELECT password_hash FROM commuter_accounts LIMIT 1')->fetchColumn()), 'Commuter password must be hashed');
    $r = $run(['page'=>'commuter/api/auth.php','commuter_login'=>['email'=>'passenger@example.invalid','password'=>'Fixture password 123!']]);
    $assert($r['status']===200 && $r['identity']['admin']===null && ($r['identity']['commuter']['id'] ?? 0)===1, 'Regular passenger login must retain commuter access only');
    $assert((int)$live->query('SELECT last_activity_at IS NOT NULL FROM commuter_accounts WHERE id=1')->fetchColumn() === 1, 'Commuter sign-in must record activity');
    $live->exec('UPDATE commuter_accounts SET last_activity_at = CURRENT_TIMESTAMP - INTERVAL 6 MINUTE WHERE id=1');
    $presence = $live->query("SELECT CASE WHEN account_status = 'active' AND last_activity_at >= CURRENT_TIMESTAMP - INTERVAL 5 MINUTE THEN 'active' ELSE 'inactive' END FROM commuter_accounts WHERE id=1")->fetchColumn();
    $assert($presence === 'inactive', 'Commuter presence must expire after five minutes without activity');
    $r = $run(['page'=>'commuter/api/auth.php','commuter'=>1,'record_commuter_activity'=>true]);
    $assert($r['status']===200 && json_decode($r['body'], true)['recorded'] === true, 'Commuter activity heartbeat must refresh presence');
    $presence = $live->query("SELECT CASE WHEN account_status = 'active' AND last_activity_at >= CURRENT_TIMESTAMP - INTERVAL 5 MINUTE THEN 'active' ELSE 'inactive' END FROM commuter_accounts WHERE id=1")->fetchColumn();
    $assert($presence === 'active', 'Recent commuter activity must display as active');
    $r = $run(['page'=>'admin-users.php','method'=>'GET','admin'=>1,'get'=>['action'=>'commuter_presence']]);
    $presenceStatuses = json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($r['status']===200 && ($presenceStatuses['1'] ?? null)==='active', 'Admin presence refresh must return current commuter status');
    $live->prepare("INSERT INTO commuter_accounts (full_name,email,password_hash) VALUES ('Same email passenger', 'owner@example.invalid', ?)")->execute([password_hash('Passenger different password!', PASSWORD_DEFAULT)]);
    $r = $run(['page'=>'commuter/api/auth.php','commuter_login'=>['email'=>'owner@example.invalid','password'=>'Passenger different password!']]);
    $assert($r['status']===200 && $r['identity']['admin']===null && $r['identity']['commuter']!==null, 'An email match alone must never grant admin privileges');
    $r = $post('admin-users.php',1,['action'=>'update_commuter','commuter_id'=>'1','account_status'=>'suspended']);
    $assert($r['status']===400 && $live->query('SELECT account_status FROM commuter_accounts WHERE id=1')->fetchColumn()==='active', 'Commuter accounts must be displayed without admin editing controls');
    $live->exec("INSERT INTO routes (route_name) VALUES ('Fixture route')");
    $routeId = (int)$live->lastInsertId();
    $terminalIds = [];
    require_once __DIR__ . '/../app/terminal-landmark.php';
    foreach (['https://maps.app.goo.gl/AbC123', 'https://goo.gl/maps/AbC123?coh=178571', 'https://www.google.com/maps/place/Batangas/', 'https://www.google.com/maps/?q=13.7,121.0', 'https://maps.google.com/?q=Batangas', 'https://www.google.com.ph/maps/place/Mabini'] as $url) {
        $assert(is_terminal_maps_link($url), 'Google Maps location URLs must be accepted: ' . $url);
    }
    $beforeTerminals = (int)$live->query('SELECT COUNT(*) FROM terminals')->fetchColumn();
    foreach (['Nearby landmark', 'https://example.com/maps/place/test', 'https://www.google.com.evil.test/maps/place/test', 'https://www.google.com/search?q=Batangas', 'https://maps.app.goo.gl@evil.test/test', 'javascript:alert(1)', 'https://www.google.com/maps', 'https://maps.app.goo.gl/', 'https://www.google.com/maps/place/' . str_repeat('x', 2048)] as $url) {
        $assert(!is_terminal_maps_link($url), 'Non-location and invalid URLs must be rejected');
        $post('admin-routes.php',2,['action'=>'save_terminal','terminal_name'=>'Invalid URL terminal','municipality'=>'Batangas','landmark'=>'Nearby landmark','gps_link'=>$url]);
        $assert((int)$live->query('SELECT COUNT(*) FROM terminals')->fetchColumn()===$beforeTerminals, 'Invalid landmark must not create a terminal');
    }
    foreach (['Origin', 'Destination'] as $name) {
        $gpsLink = 'https://www.google.com/maps/place/' . str_repeat('a', 300);
        $r = $post('admin-routes.php',2,['action'=>'save_terminal','terminal_id'=>'','terminal_name'=>'Fixture ' . $name,'municipality'=>'Batangas','landmark'=>'Fixture landmark ' . $name,'gps_link'=>$gpsLink,'is_active'=>'1']);
        $terminalIds[] = (int)$live->query("SELECT MAX(id) FROM terminals")->fetchColumn();
        $assert($r['status']===303, 'Terminal creation must succeed');
    }
    $terminalDetails = $live->query('SELECT landmark, gps_link FROM terminal_details WHERE terminal_id=' . $terminalIds[0])->fetch();
    $assert($terminalDetails['landmark']==='Fixture landmark Origin' && strlen($terminalDetails['gps_link'])>255, 'Landmark text and complete GPS link must be stored separately');
    $post('admin-routes.php',2,['action'=>'save_terminal','terminal_id'=>(string)$terminalIds[0],'terminal_name'=>'Fixture Origin','municipality'=>'Batangas','landmark'=>'Changed landmark','gps_link'=>'https://example.com/not-maps']);
    $terminalDetailsAfterInvalidEdit = $live->query('SELECT landmark, gps_link FROM terminal_details WHERE terminal_id=' . $terminalIds[0])->fetch();
    $assert($terminalDetailsAfterInvalidEdit===$terminalDetails, 'Invalid GPS edits must preserve the saved landmark and GPS link');
    $r = $post('admin-routes.php',2,['action'=>'save_route','route_id'=>(string)$routeId,'route_name'=>'Fixture route','origin_terminal_id'=>(string)$terminalIds[0],'destination_terminal_id'=>(string)$terminalIds[1],'is_active'=>'1']);
    $assert($r['status']===303 && (int)$live->query('SELECT COUNT(*) FROM route_terminals WHERE route_id=' . $routeId)->fetchColumn()===2, 'Route endpoints must persist');
    $r = $post('admin-routes.php',2,['action'=>'save_stop','route_id'=>(string)$routeId,'stop_id'=>'','stop_sequence'=>'1','location_name'=>'Fixture stop','area_label'=>'Batangas','landmark'=>'Fixture landmark','stop_type'=>'stop','latitude'=>'13.76','longitude'=>'121.04']);
    $assert($r['status']===303 && (int)$live->query('SELECT COUNT(*) FROM route_stop_coordinates')->fetchColumn()===1, 'Stop coordinates must persist');
    $r = $post('admin-jeepneys.php',2,['action'=>'save_vehicle','vehicle_id'=>'','driver_id'=>'1','plate_number'=>'ABC 1234','capacity'=>'20','route_assignment'=>'Fixture route','operational_status'=>'active']);
    $assert($r['status']===303 && (int)$live->query('SELECT COUNT(*) FROM jeepney_profiles')->fetchColumn()===1, 'Jeepney creation must persist');
    $live->exec("UPDATE jeepney_profiles SET verification_status='pending_verification', pending_plate_number='ABC 1234', pending_capacity=22, pending_route_assignment='Fixture route' WHERE id=1");
    $r = $post('admin-dashboard.php',2,['action'=>'approve','record_type'=>'vehicle','record_id'=>'1']);
    $assert($r['status']===303 && (int)$live->query('SELECT capacity FROM jeepney_profiles WHERE id=1')->fetchColumn()===22, 'Vehicle approval must apply pending details');
    $count = static function () use ($run): int {
        $r = $run(['page'=>'api/admin-trip-count.php','admin'=>3,'method'=>'GET']);
        if ($r['status'] !== 200) throw new RuntimeException('Trip count endpoint failed');
        return json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR)['ongoing_trips'];
    };
    $assert($count()===0, 'Idle jeepney must not count as ongoing');
    $r = $run(['page'=>'api/admin-trip-count.php','method'=>'GET']);
    $assert($r['status']===401, 'Trip count requires admin authentication');
    $r = $run(['page'=>'api/admin-trip-count.php','admin'=>3,'method'=>'POST']);
    $assert($r['status']===405, 'Trip count must be read-only');
    $saveSeats = static fn(int $occupied): array => $run(['page'=>'api/seat-availability.php','driver'=>1,'post'=>['csrf_token'=>$token,'occupied'=>(string)$occupied]]);
    $saveSeats(22);
    $assert($count()===1, 'Driver Full button must increase the admin count');
    $saveSeats(22);
    $assert($count()===1, 'Repeated Full reports must not double count');
    $saveSeats(21);
    $assert($count()===0, 'Reopened seats must remove a seat-only full jeepney');
    $live->exec("INSERT INTO driver_trip_status (driver_id, status) VALUES (1, 'full')");
    $assert($count()===1, 'Explicit Full trip status must count');
    $live->exec("UPDATE driver_trip_status SET status='out' WHERE driver_id=1");
    $saveSeats(22);
    $assert($count()===0, 'Off-duty status overrides a full seat report');
    $live->exec("UPDATE driver_trip_status SET status='available' WHERE driver_id=1");
    $live->exec("UPDATE driver_seat_availability SET capacity=20, occupied=20 WHERE driver_id=1");
    $assert($count()===0, 'Old capacity reports must not count as full');
    $saveSeats(22);
    $live->exec("UPDATE jeepney_admin_status SET operational_status='maintenance' WHERE jeepney_profile_id=1");
    $assert($count()===0, 'Maintenance vehicles must be excluded');
    $live->exec("UPDATE jeepney_admin_status SET operational_status='active' WHERE jeepney_profile_id=1");
    $r = $run(['page'=>'driver-documents.php','driver'=>1,'method'=>'GET']);
    $assert($r['status']===200 && $r['bytes']>1000, 'Driver document submission page must render');
    require_once __DIR__ . '/../app/driver-documents.php';
    $databaseDocument = tempnam(__DIR__ . '/../storage', 'database-document-test-');
    $documentTestPaths[] = $databaseDocument;
    $databaseDocumentContents = '%PDF-1.4 persistent document';
    file_put_contents($databaseDocument, $databaseDocumentContents);
    $previousDocumentStorage = getenv('DRIVER_DOCUMENT_STORAGE');
    putenv('DRIVER_DOCUMENT_STORAGE=database');
    try {
        store_driver_document($live, 1, 'driver_license', 'database-test.pdf', 'application/pdf', $databaseDocument);
    } finally {
        if ($previousDocumentStorage === false) putenv('DRIVER_DOCUMENT_STORAGE');
        else putenv('DRIVER_DOCUMENT_STORAGE=' . $previousDocumentStorage);
    }
    $databaseStored = $live->query('SELECT storage_key, file_contents FROM driver_documents ORDER BY id DESC LIMIT 1')->fetch();
    $assert($databaseStored['storage_key'] === null && $databaseStored['file_contents'] === $databaseDocumentContents, 'Vercel document storage must persist file contents in MySQL');
    $source = tempnam(__DIR__ . '/../storage', 'document-test-');
    $documentTestPaths[] = $source;
    $largePdf = '%PDF-1.4' . str_repeat('x', 8 * 1024 * 1024 - 8);
    file_put_contents($source, $largePdf);
    store_driver_document($live, 1, 'driver_license', 'large-test.pdf', 'application/pdf', $source);
    $stored = $live->query('SELECT * FROM driver_documents ORDER BY id DESC LIMIT 1')->fetch();
    $documentTestPaths[] = driver_document_path($stored['storage_key']);
    $assert($stored['file_contents']===null && hash_file('sha256', end($documentTestPaths))===hash('sha256', $largePdf), '8 MB files must store intact without a database blob or packet limit');
    $documentId = (string) $stored['id'];
    foreach (['driver-document.php'=>['driver'=>1], 'admin-document.php'=>['admin'=>2]] as $page=>$identity) {
        $r = $run(['page'=>$page,'method'=>'GET','get'=>['id'=>$documentId]] + $identity);
        $assert($r['status']===200 && $r['sha256']===hash('sha256', $largePdf), 'Authorized download must return intact bytes through ' . $page);
    }
    $live->prepare("INSERT INTO drivers (full_name,email,phone,license_number,password_hash) VALUES ('Other Driver','other@example.invalid','09123456780','QA-456',?)")->execute([$hash]);
    $otherDriver = (int) $live->lastInsertId();
    $r = $run(['page'=>'driver-document.php','driver'=>$otherDriver,'method'=>'GET','get'=>['id'=>$documentId]]);
    $assert($r['status']===404, 'Drivers must not download another driver document');
    $r = $run(['page'=>'driver-document.php','method'=>'GET','get'=>['id'=>$documentId]]);
    $assert($r['status']===303, 'Document downloads require authentication');
    $live->prepare('UPDATE driver_documents SET file_contents = ? WHERE id = ?')->execute(['%PDF-legacy', $documentId]);
    $r = $run(['page'=>'driver-document.php','driver'=>1,'method'=>'GET','get'=>['id'=>$documentId]]);
    $assert($r['body']==='%PDF-legacy', 'Legacy database documents must remain downloadable');
    $r = $run(['page'=>'admin-document.php','admin'=>3,'method'=>'GET','get'=>['id'=>$documentId,'preview'=>'1']]);
    $assert($r['status']===200 && $r['body']==='%PDF-legacy', 'Admin viewers may preview documents');
    $assert((int)$live->query("SELECT COUNT(*) FROM activity_logs WHERE action='document_previewed'")->fetchColumn()===1, 'Document previews must be audited');
    $r = $run(['page'=>'admin-document.php','method'=>'GET','get'=>['id'=>$documentId,'preview'=>'1']]);
    $assert($r['status']===303, 'Document previews require admin authentication');
    $r = $run(['page'=>'admin-document.php','driver'=>1,'method'=>'GET','get'=>['id'=>$documentId,'preview'=>'1']]);
    $assert($r['status']===303, 'Driver sessions cannot access admin previews');
    $r = $run(['page'=>'driver-documents.php','driver'=>1,'post'=>['csrf_token'=>$token,'document_type'=>'invalid']]);
    $assert($r['status']===200 && $r['bytes']>1000, 'Invalid document type must render the form without a server error');
    $assert(driver_document_path('../outside')===null, 'Storage keys must not allow path traversal');
    $r = $run(['page'=>'driver-trip.php','driver'=>1,'post'=>['csrf_token'=>$token,'action'=>'start']]);
    $assert($r['status']===303 && (int)$live->query("SELECT COUNT(*) FROM trips WHERE trip_status='active'")->fetchColumn()===1, 'Verified driver can start a monitored trip');
    $assert($count()===1, 'Full jeepney with an active GPS trip counts only once');
    $saveSeats(5);
    $assert($count()===1, 'An active trip remains counted when passengers leave');
    $tripId = (int)$live->query('SELECT id FROM trips LIMIT 1')->fetchColumn();
    $r = $run(['page'=>'driver-trip.php','driver'=>1,'post'=>['csrf_token'=>$token,'action'=>'location','trip_id'=>(string)$tripId,'latitude'=>'13.76','longitude'=>'121.04','accuracy'=>'15']]);
    $assert($r['status']===200 && (int)$live->query('SELECT COUNT(*) FROM trip_locations')->fetchColumn()===1, 'Consented GPS updates must be stored');
    $r = $run(['page'=>'admin-trip-history.php','admin'=>3,'method'=>'GET','get'=>['id'=>(string)$tripId]]);
    $assert($r['status']===200 && $r['bytes']>1000, 'Trip history must render');
    $r = $post('admin-trips.php',2,['action'=>'cancel_trip','trip_id'=>(string)$tripId]);
    $assert($r['status']===303 && $live->query('SELECT status FROM driver_trip_status WHERE driver_id=1')->fetchColumn()==='out', 'Admin cancellation must sync driver status');
    $assert($count()===0, 'Cancelled off-duty trip must leave the count');
    $r = $run(['page'=>'driver-trip.php','driver'=>1,'post'=>['csrf_token'=>$token,'action'=>'location','trip_id'=>(string)$tripId,'latitude'=>'13.76','longitude'=>'121.04','accuracy'=>'15']]);
    $assert($r['status']===409, 'Cancelled trips must reject GPS');
    $post('admin-drivers.php',2,['action'=>'account_status','driver_id'=>'1','status'=>'suspended','reason'=>'Test']);
    $live->exec("UPDATE driver_trip_status SET status='full' WHERE driver_id=1");
    $assert($count()===0, 'Suspended drivers must not count');
    $r = $run(['page'=>'driver-trip.php','driver'=>1,'method'=>'GET']);
    $assert($r['status']===303, 'Suspension must revoke existing driver sessions');
    $r = $run(['page'=>'api/vehicle.php','driver'=>1,'method'=>'GET']);
    $assert($r['status']===403, 'Suspended driver cannot use vehicle API');
    $r = $run(['page'=>'admin-reports.php','admin'=>3,'method'=>'GET','get'=>['export'=>'drivers','format'=>'csv']]);
    $assert($r['status']===200 && $r['bytes']>50, 'Viewer CSV export must work');
    $r = $run(['page'=>'admin-reports.php','admin'=>1,'method'=>'GET','get'=>['export'=>'drivers','format'=>'pdf']]);
    $assert($r['status']===200 && $r['pdf'], 'PDF export must produce a valid PDF');
    $r = $post('admin-settings.php',1,['action'=>'save_settings','map_refresh_seconds'=>'35']);
    $assert($r['status']===303 && $live->query("SELECT setting_value FROM app_settings WHERE setting_key='map_refresh_seconds'")->fetchColumn()==='35', 'Owner settings must persist');
    $r = $post('admin-settings.php',3,['action'=>'save_settings','map_refresh_seconds'=>'40']);
    $assert($r['status']===403, 'Viewer cannot edit settings');
    $r = $post('admin-users.php',1,['action'=>'update_admin','admin_id'=>'3','role'=>'viewer','account_status'=>'deactivated']);
    $r = $run(['page'=>'admin-dashboard.php','admin'=>3,'method'=>'GET']);
    $assert($r['status']===303, 'Deactivation revokes existing administrator sessions');
    foreach (['login.php','admin-login.php'] as $loginPage) {
        $r = $run(['page'=>$loginPage,'post'=>['csrf_token'=>$token,'email'=>'viewer@example.invalid','password'=>'Fixture password 123!']]);
        $assert($r['identity']['admin']===null, 'Deactivated admin must be rejected through ' . $loginPage);
    }
    $r = $run(['page'=>'commuter/api/auth.php','commuter_login'=>['email'=>'viewer@example.invalid','password'=>'Fixture password 123!']]);
    $assert($r['status']===401 && $r['identity']['admin']===null, 'Deactivated admin must be rejected through passenger login');
    $live->exec('DELETE FROM login_rate_limits');
    $pin = $live->query("SELECT * FROM map_locations WHERE location_name='Total Gas Station' LIMIT 1")->fetch();
    $pinData = ['id'=>(string)$pin['id'],'name'=>$pin['location_name'],'area'=>$pin['area_label'],'landmark'=>$pin['landmark'],
        'type'=>$pin['location_type'],'latitude'=>'13.7561539','longitude'=>'121.0516556','active'=>'1','routes'=>[]];
    $r = $post('admin-locations.php',2,$pinData);
    $assert($r['status']===303, 'Admin can save a location');
    $r = $post('admin-locations.php',2,array_merge($pinData,['latitude'=>'13.8','longitude'=>'']));
    $assert($r['status']===422, 'Partial coordinates must be rejected');
    $r = $post('admin-locations.php',2,array_merge($pinData,['latitude'=>'90']));
    $assert($r['status']===422, 'Out-of-area coordinates must be rejected');
    $r = $post('admin-locations.php',2,array_merge($pinData,['latitude'=>'','longitude'=>'']));
    require_once __DIR__ . '/../app/map-locations.php';
    $savedPin = array_values(array_filter(map_locations(true), static fn(array $row):bool=>$row['recordId']===(int)$pin['id']))[0];
    $assert($r['status']===303 && $savedPin['coordinates']===null, 'Removed Total pin must remain removed in the shared map feed');
    unset($pinData['active']);
    $r = $post('admin-locations.php',2,$pinData);
    $assert($r['status']===303 && !array_filter(map_locations(), static fn(array $row):bool=>$row['recordId']===(int)$pin['id']), 'Hidden locations must disappear from public maps');
    $assert((int)$live->query("SELECT COUNT(*) FROM activity_logs WHERE action='map_location_updated'")->fetchColumn()===3, 'Successful map edits must be recorded in activity logs');
    $r = $run(['page'=>'admin-locations.php','admin'=>1,'method'=>'GET','get'=>['edit'=>'999999']]);
    $assert($r['status']===404, 'Unknown edit IDs must show a not-found response');
    for ($i=0;$i<5;$i++) $run(['page'=>'commuter/api/auth.php','commuter_login'=>['email'=>'owner@example.invalid','password'=>'wrong']]);
    $r = $run(['page'=>'login.php','post'=>['csrf_token'=>$token,'email'=>'owner@example.invalid','password'=>'Fixture password 123!']]);
    $assert($r['status']===429 && $r['identity']['admin']===null, 'Switching login portals must not bypass rate limits');
    $r = $run(['page'=>'commuter/api/auth.php','commuter_login'=>['email'=>'owner@example.invalid','password'=>'Fixture password 123!']]);
    $assert($r['status']===429, 'Passenger portal must throttle repeated admin attempts');
    $assert((int)$live->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn()>=5, 'Administrative mutations must be audited');
    echo $checks . " admin integration checks passed in an isolated database.\n";
} finally {
    foreach ($documentTestPaths as $path) if (is_file($path)) unlink($path);
    // This name is generated above; never drop a caller-supplied database.
    $live->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    $_SESSION=[]; session_destroy();
}
