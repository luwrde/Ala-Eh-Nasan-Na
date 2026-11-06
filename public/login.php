<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/admin-auth.php';

if (current_admin() !== null) {
    require_admin();
    redirect('admin-dashboard.php');
}

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
            if (authenticate_admin_account($pdo, $email, $password)) {
                clear_login_attempts($pdo, $keys);
                redirect('admin-dashboard.php');
            }
            $query = $pdo->prepare(
                "SELECT d.id, d.full_name, d.email, d.password_hash, d.verification_status
                 FROM drivers d LEFT JOIN driver_admin_status s ON s.driver_id = d.id
                 WHERE d.email = :email AND COALESCE(s.account_status, 'active') = 'active' LIMIT 1"
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
                unset($_SESSION['csrf_token'], $_SESSION['admin'], $_SESSION['commuter']);
                redirect('dashboard.php');
            }

            $error = 'Email or password is incorrect.';
        }
    }
}

page_start('Driver login', 'login');
?>
<section class="auth-layout">
  <div class="auth-aside">
    <span class="eyebrow eyebrow-light"><span class="eyebrow-dot"></span> WELCOME BACK</span>
    <h1>Good to have<br>you <span>back.</span></h1>
    <p>Pick up right where you left off. Your driver application is just a sign-in away.</p>
    <div class="aside-note"><span class="aside-note-icon" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#check"></use></svg></span><span><strong>One place for your journey</strong><small>Check your application status anytime.</small></span></div>
    <div class="aside-decoration" aria-hidden="true">A</div>
  </div>
  <div class="auth-panel">
    <div class="form-heading">
      <span class="form-kicker">DRIVER PORTAL</span>
      <h2>Sign in to your account</h2>
      <p>Sign in with your driver or administrator account.</p>
    </div>
    <?php if ($notice !== ''): ?><p class="message message-success" role="status"><span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#check"></use></svg></span> <?= h($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form class="form-stack" method="post" action="login.php" autocomplete="on">
      <?= csrf_field() ?>
      <label>Email address <input type="email" name="email" required autocomplete="username" placeholder="you@example.com" value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
      <label>Password <input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"></label>
      <button class="button button-primary button-submit" type="submit">Sign in <span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#arrow"></use></svg></span></button>
    </form>
    <p class="form-switch">New to the portal? <a href="register.php">Create an account</a></p>
    <div class="form-security"><span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#shield"></use></svg></span> Your information is protected and kept private.</div>
  </div>
</section>
<?php page_end(); ?>
