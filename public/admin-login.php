<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-bootstrap.php';
require_once __DIR__ . '/../app/admin-auth.php';

if (current_admin() !== null) {
    require_admin();
    redirect('admin-dashboard.php');
}

$error = '';
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
            $error = 'Too many sign-in attempts. Please wait 15 minutes before trying again.';
        } else {
            if (authenticate_admin_account($pdo, $email, $password)) {
                clear_login_attempts($pdo, $keys);
                redirect(consume_auth_return_path('admin_return_to', 'admin-dashboard.php'));
            }

            $error = 'Email or password is incorrect.';
        }
    }
}

page_start('Admin sign in', 'admin-login');
?>
<section class="auth-layout">
  <div class="auth-aside admin-auth-aside admin-login-aside">
    <div class="admin-auth-visual">
      <img src="assets/admin/admin-login-jeepney.png" alt="Illustration of a jeepney traveling along a road">
    </div>
    <span class="eyebrow eyebrow-light"><span class="eyebrow-dot"></span> ADMINISTRATION</span>
    <h1>Review the<br><span>next journey.</span></h1>
    <p>Sign in to review driver applications and manage verification decisions.</p>
    <div class="aside-note"><span class="aside-note-icon" aria-hidden="true">✓</span><span><strong>Authorized access only</strong><small>Application decisions are for administrators.</small></span></div>
    <div class="aside-decoration" aria-hidden="true">A</div>
  </div>
  <div class="auth-panel">
    <div class="form-heading">
      <span class="form-kicker">ADMIN PORTAL</span>
      <h2>Admin sign in</h2>
      <p>Use your administrator account to continue.</p>
    </div>
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form class="form-stack" method="post" action="admin-login.php" autocomplete="on">
      <?= csrf_field() ?>
      <label>Email address <input type="email" name="email" required autocomplete="username" placeholder="admin@example.com" value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
      <label>Password <span class="password-field"><input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"><button class="password-visibility-toggle" type="button" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path data-eye-slash d="m4 4 16 16"></path></svg></button></span></label>
      <button class="button button-primary button-submit" type="submit">Sign in to admin <span aria-hidden="true">→</span></button>
    </form>
    <div class="form-security"><span aria-hidden="true">◈</span> Admin accounts are separate from driver accounts and use role-based access.</div>
    <p class="form-switch">Setting up the portal? <a href="admin-register.php" data-admin-registration="<?= first_admin_registration_available() ? 'available' : 'closed' ?>">Create the first admin account</a></p>
  </div>
</section>
<script>
(() => {
  const message = 'Only one first admin account can be created. The initial admin account has already been set up.';
  const registrationLink = document.querySelector('[data-admin-registration="closed"]');

  if (registrationLink) {
    registrationLink.addEventListener('click', (event) => {
      event.preventDefault();
      window.showToast(message, 'warning');
    });
  }

  if (new URLSearchParams(window.location.search).get('registration') === 'closed') {
    window.showToast(message, 'warning');
  }
})();
</script>
<?php page_end(); ?>
