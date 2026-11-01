<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (current_driver() !== null) {
    redirect('dashboard.php');
}

$error = '';
$notice = isset($_GET['registered']) ? 'Registration received. Log in to view your verification status.' : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = $_POST['password'] ?? '';
    if (!is_string($password) || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email or password is incorrect.';
    } else {
        $pdo = db();
        $keys = login_bucket_keys($email, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if (!begin_login_attempt($pdo, $keys)) {
            http_response_code(429);
            $error = 'Too many login attempts. Please wait 15 minutes before trying again.';
        } else {
            $query = $pdo->prepare(
                'SELECT id, full_name, email, password_hash, verification_status
                 FROM drivers WHERE email = :email LIMIT 1'
            );
            $query->execute(['email' => $email]);
            $driver = $query->fetch();

            if ($driver !== false && password_verify($password, $driver['password_hash'])) {
                if (password_needs_rehash($driver['password_hash'], PASSWORD_DEFAULT)) {
                    $rehash = $pdo->prepare('UPDATE drivers SET password_hash = :hash WHERE id = :id');
                    $rehash->execute([
                        'hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => $driver['id'],
                    ]);
                }
                clear_login_attempts($pdo, $keys);
                session_regenerate_id(true);
                $_SESSION['driver'] = [
                    'id' => (int) $driver['id'],
                    'full_name' => $driver['full_name'],
                    'email' => $driver['email'],
                    'verification_status' => $driver['verification_status'],
                ];
                unset($_SESSION['csrf_token']);
                redirect('dashboard.php');
            }

            $error = 'Email or password is incorrect.';
        }
    }
}

page_start('Driver login');
?>
<h1>Driver login</h1>
<?php if ($notice !== ''): ?><p class="message"><?= h($notice) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="message" role="alert"><?= h($error) ?></p><?php endif; ?>
<form method="post" action="login.php" autocomplete="on">
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" required autocomplete="username" value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
  <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
  <button type="submit">Log in</button>
</form>
<?php page_end(); ?>
