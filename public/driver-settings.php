<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$driver = require_driver();
$driverId = (int) $driver['id'];
$error = '';
$notice = $_SESSION['driver_settings_notice'] ?? '';
unset($_SESSION['driver_settings_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    if (($_POST['action'] ?? '') === 'preferences') {
        $theme = $_POST['theme'] ?? null;
        $language = $_POST['language'] ?? null;
        if (!is_string($theme) || !in_array($theme, ['light', 'dark'], true)
            || !is_string($language) || !in_array($language, ['en', 'fil'], true)) {
            $error = 'Choose a valid theme and language.';
        } else {
            $cookieOptions = [
                'expires' => time() + 31536000,
                'path' => '/',
                'secure' => (bool) session_get_cookie_params()['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ];
            if (!setcookie('driver_theme', $theme, $cookieOptions)
                || !setcookie('driver_language', $language, $cookieOptions)) {
                throw new RuntimeException('Unable to save the display preferences.');
            }
            $_COOKIE['driver_theme'] = $theme;
            $_COOKIE['driver_language'] = $language;
            $_SESSION['driver_theme'] = $theme;
            $_SESSION['driver_language'] = $language;
            $_SESSION['driver_settings_notice'] = 'Display preferences saved.';
            redirect('driver-settings.php');
        }
    } elseif (($_POST['action'] ?? '') === 'language') {
        $language = $_POST['language'] ?? null;
        if (!is_string($language) || !in_array($language, ['en', 'fil'], true)) {
            $error = 'Choose English or Filipino.';
        } else {
            $cookieOptions = [
                'expires' => time() + 31536000,
                'path' => '/',
                'secure' => (bool) session_get_cookie_params()['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ];
            if (!setcookie('driver_language', $language, $cookieOptions)) {
                throw new RuntimeException('Unable to save the language preference.');
            }
            $_COOKIE['driver_language'] = $language;
            $_SESSION['driver_language'] = $language;
            $_SESSION['driver_settings_notice'] = 'Language preference saved.';
            redirect('driver-settings.php');
        }
    } else {
        $current = $_POST['current_password'] ?? null;
        $next = $_POST['new_password'] ?? null;
        $confirm = $_POST['confirm_password'] ?? null;

        if (!is_string($current) || !is_string($next) || !is_string($confirm)) {
            $error = 'Enter your current and new password.';
        } elseif (strlen($next) < 12 || strlen($next) > 72) {
            $error = 'Use a new password between 12 and 72 bytes.';
        } elseif (!hash_equals($next, $confirm)) {
            $error = 'The new passwords do not match.';
        } else {
            $query = db()->prepare('SELECT password_hash FROM drivers WHERE id = :id LIMIT 1');
            $query->execute(['id' => $driverId]);
            $storedHash = $query->fetchColumn();
            if (!is_string($storedHash) || !password_verify($current, $storedHash)) {
                $error = 'Current password is incorrect.';
            } elseif (password_verify($next, $storedHash)) {
                $error = 'Choose a password different from your current one.';
            } else {
                $update = db()->prepare('UPDATE drivers SET password_hash = :new_hash WHERE id = :id AND password_hash = :old_hash');
                $update->execute([
                    'new_hash' => password_hash($next, PASSWORD_DEFAULT),
                    'id' => $driverId,
                    'old_hash' => $storedHash,
                ]);
                if ($update->rowCount() !== 1) {
                    $error = 'Your password changed in another session. Reload and try again.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['driver_settings_notice'] = 'Your password was changed successfully.';
                    redirect('driver-settings.php');
                }
            }
        }
    }
}

page_start('Settings', 'driver-settings');
?>
<section class="driver-settings-page" aria-labelledby="settings-title">
  <div class="driver-settings-heading">
    <span class="eyebrow"><span class="eyebrow-dot"></span> DRIVER ACCOUNT</span>
    <h1 class="driver-settings-page-title" id="settings-title">
      <span class="driver-settings-icon driver-settings-title-icon" aria-hidden="true"><svg class="ui-icon"><use href="assets/icons.svg#settings"></use></svg></span>
      Settings
    </h1>
    <p>Manage your account security. Your route and pickup area remain on the Jeepney map.</p>
  </div>

  <?php if ($notice !== ''): ?><p class="message message-success" role="status"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= h($error) ?></p><?php endif; ?>

  <div class="driver-settings-grid">
    <form class="driver-settings-preferences" method="post" action="driver-settings.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="preferences">
    <section class="driver-settings-card" aria-labelledby="settings-theme-title">
      <div class="driver-settings-card-heading">
        <span class="driver-settings-icon" aria-hidden="true"><svg class="ui-icon"><use href="assets/icons.svg#settings"></use></svg></span>
        <div><h2 id="settings-theme-title">Theme</h2><p>Choose the appearance for your driver pages.</p></div>
      </div>
      <div class="driver-settings-form">
        <fieldset class="theme-options">
          <legend>Display theme</legend>
          <label class="theme-option"><input type="radio" name="theme" value="light" <?= driver_theme() === 'light' ? 'checked' : '' ?>><span><strong>Light</strong><small>Bright and clear</small></span></label>
          <label class="theme-option"><input type="radio" name="theme" value="dark" <?= driver_theme() === 'dark' ? 'checked' : '' ?>><span><strong>Dark</strong><small>Comfortable in low light</small></span></label>
        </fieldset>
      </div>
    </section>
    <section class="driver-settings-card" aria-labelledby="settings-language-title">
      <div class="driver-settings-card-heading">
        <span class="driver-settings-icon" aria-hidden="true"><svg class="ui-icon"><use href="assets/icons.svg#settings"></use></svg></span>
        <div><h2 id="settings-language-title">Language</h2><p>Choose the language used across your driver pages.</p></div>
      </div>
      <div class="driver-settings-form">
        <label for="driver-language">Display language</label>
        <select id="driver-language" name="language">
          <option value="en" <?= driver_language() === 'en' ? 'selected' : '' ?>>English</option>
          <option value="fil" <?= driver_language() === 'fil' ? 'selected' : '' ?>>Filipino</option>
        </select>
      </div>
    </section>
    <div class="driver-settings-preferences-actions"><button class="button button-primary" type="submit">Save preferences</button></div>
    </form>
    <section class="driver-settings-card" aria-labelledby="settings-password-title">
      <div class="driver-settings-card-heading">
        <span class="driver-settings-icon" aria-hidden="true"><svg class="ui-icon"><use href="assets/icons.svg#shield"></use></svg></span>
        <div><h2 id="settings-password-title">Change password</h2><p>Enter your current password to protect your account.</p></div>
      </div>
      <form class="driver-settings-form" method="post" action="driver-settings.php" autocomplete="on">
        <?= csrf_field() ?>
        <label for="current-password">Current password</label>
        <input id="current-password" name="current_password" type="password" required autocomplete="current-password">
        <label for="new-password">New password</label>
        <input id="new-password" name="new_password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help">
        <p id="password-help">Use at least 12 characters. The password must be no more than 72 bytes.</p>
        <label for="confirm-password">Confirm new password</label>
        <input id="confirm-password" name="confirm_password" type="password" required minlength="12" maxlength="72" autocomplete="new-password">
        <button class="button button-primary" type="submit">Update password</button>
      </form>
    </section>
    <aside class="driver-settings-card" aria-labelledby="settings-profile-title">
      <div class="driver-settings-card-heading">
        <span class="driver-settings-icon" aria-hidden="true"><svg class="ui-icon"><use href="assets/icons.svg#user"></use></svg></span>
        <div><h2 id="settings-profile-title">Profile details</h2><p>Keep your contact information current.</p></div>
      </div>
      <p>Email and phone number are managed on your Profile page.</p>
      <a class="button button-outline" href="driver-profile.php">Go to Profile</a>
    </aside>
  </div>
</section>
<?php page_end(); ?>
