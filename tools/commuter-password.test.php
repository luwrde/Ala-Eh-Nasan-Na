<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/commuter-auth.php';
require_once __DIR__ . '/../app/security.php';
ini_set('session.use_cookies', '0');
session_cache_limiter('');
session_save_path(sys_get_temp_dir());
session_start();
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE commuter_accounts (id INTEGER PRIMARY KEY, password_hash TEXT NOT NULL)');
$current = 'Existing test password 123';
$next = 'Replacement test password 456';
$hash = password_hash($current, PASSWORD_DEFAULT);
$insert = $pdo->prepare('INSERT INTO commuter_accounts (id, password_hash) VALUES (?, ?)');
$insert->execute([1, $hash]);
$insert->execute([2, $hash]);
$valid = ['current_password' => $current, 'new_password' => $next, 'confirm_password' => $next];
$checks = 0;
function check(bool $condition, string $description): void {
    global $checks;
    if (!$condition) throw new RuntimeException($description);
    $checks++;
}
check(change_commuter_password($valid, $pdo)[0] === 401, 'Anonymous requests must fail');
$_SESSION['commuter'] = ['id' => 1, 'full_name' => 'Test Commuter'];
foreach ([
    ['current_password' => 'wrong'],
    ['current_password' => []],
    ['new_password' => 'short', 'confirm_password' => 'short'],
    ['confirm_password' => 'different password'],
    ['new_password' => str_repeat('a', 73), 'confirm_password' => str_repeat('a', 73)],
    ['new_password' => str_repeat('é', 37), 'confirm_password' => str_repeat('é', 37)],
    ['new_password' => $current, 'confirm_password' => $current],
] as $invalid) {
    check(change_commuter_password(array_replace($valid, $invalid), $pdo)[0] === 422, 'Invalid password change must fail');
    check($pdo->query('SELECT password_hash FROM commuter_accounts WHERE id = 1')->fetchColumn() === $hash, 'Rejected changes must not write');
}
$previousSession = session_id();
check(change_commuter_password($valid + ['id' => 2], $pdo)[0] === 200, 'Valid change must succeed');
$updated = $pdo->query('SELECT password_hash FROM commuter_accounts WHERE id = 1')->fetchColumn();
check(password_verify($next, $updated), 'New password must verify');
check(!password_verify($current, $updated), 'Old password must no longer verify');
check($pdo->query('SELECT password_hash FROM commuter_accounts WHERE id = 2')->fetchColumn() === $hash, 'Other accounts must remain unchanged');
check(session_id() !== $previousSession, 'Session ID must rotate');
check($_SESSION['commuter']['id'] === 1, 'Account must remain signed in');
check(change_commuter_password($valid, $pdo)[0] === 422, 'Old password cannot be reused to change credentials');
check(!csrf_value_is_valid(''), 'Missing CSRF token must fail');
check(!csrf_value_is_valid('incorrect'), 'Wrong CSRF token must fail');
check(csrf_value_is_valid(csrf_token()), 'Session CSRF token must pass');
$_SESSION['commuter']['id'] = 99;
check(change_commuter_password($valid, $pdo)[0] === 401, 'Deleted account must fail');
session_destroy();
echo "$checks commuter password checks passed using an isolated in-memory database.\n";
