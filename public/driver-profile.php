<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();
$driverId = (int) $driver['id'];

$profileQuery = db()->prepare(
    'SELECT full_name, email, phone, license_number
     FROM drivers WHERE id = :id LIMIT 1'
);
$profileQuery->execute(['id' => $driverId]);
$profile = $profileQuery->fetch();
if ($profile === false) {
    http_response_code(404);
    exit('Driver account not found.');
}

$errors = [];
$notice = '';
$email = (string) $profile['email'];
$phone = (string) $profile['phone'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $emailInput = $_POST['email'] ?? '';
    $phoneInput = $_POST['phone'] ?? '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $errors['email'] = 'Enter a valid email address (up to 254 characters).';
    }

    $phoneDigits = preg_replace('/\D/', '', $phone);
    if (
        strlen($phone) > 32 ||
        preg_match('/^\+?[0-9][0-9 ().-]*$/', $phone) !== 1 ||
        !is_string($phoneDigits) ||
        strlen($phoneDigits) < 7 ||
        strlen($phoneDigits) > 15
    ) {
        $errors['phone'] = 'Enter a phone number with 7 to 15 digits; spaces, +, parentheses, and hyphens are allowed.';
    }

    if ($errors === []) {
        $update = db()->prepare(
            'UPDATE drivers SET email = :email, phone = :phone WHERE id = :id'
        );
        try {
            $update->execute([
                'email' => $email,
                'phone' => $phone,
                'id' => $driverId,
            ]);
            $_SESSION['driver']['email'] = $email;
            $profile['email'] = $email;
            $profile['phone'] = $phone;
            $notice = 'Your contact information was updated.';
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $errors['email'] = 'That email address is already associated with another account.';
        }
    }
}

page_start('My profile', 'driver-profile');
?>
<section class="driver-profile-page">
  <div class="driver-profile-heading">
    <div>
      <span class="eyebrow"><span class="eyebrow-dot"></span> YOUR ACCOUNT</span>
      <h1>Contact information</h1>
      <p>Keep your account contact details current.</p>
    </div>
    <span class="profile-secure-badge"><span aria-hidden="true">◈</span> Private account details</span>
  </div>

  <?php if ($notice !== ''): ?><p class="message message-success profile-message" role="status"><span aria-hidden="true">✓</span> <?= h($notice) ?></p><?php endif; ?>
  <?php if ($errors !== []): ?><p class="message message-error profile-message" role="alert">Please review the highlighted contact information.</p><?php endif; ?>

  <div class="driver-profile-layout">
    <section class="driver-profile-card">
      <div class="driver-profile-card-heading">
        <span class="driver-profile-icon" aria-hidden="true">◎</span>
        <div><h2>Authorized contact details</h2><p>Only your own email and phone number can be changed here.</p></div>
      </div>
      <form class="form-stack driver-profile-form" method="post" action="driver-profile.php" autocomplete="on">
        <?= csrf_field() ?>
        <label>Email address
          <input type="email" name="email" required maxlength="254" autocomplete="email" value="<?= h($email) ?>" aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>" aria-describedby="profile-email-error">
          <?php if (isset($errors['email'])): ?><span class="vehicle-field-error" id="profile-email-error"><?= h($errors['email']) ?></span><?php endif; ?>
        </label>
        <label>Phone number
          <input type="tel" name="phone" required maxlength="32" autocomplete="tel" value="<?= h($phone) ?>" placeholder="+63 9XX XXX XXXX" aria-invalid="<?= isset($errors['phone']) ? 'true' : 'false' ?>" aria-describedby="profile-phone-hint profile-phone-error">
          <span class="vehicle-field-hint" id="profile-phone-hint">Use 7 to 15 digits. International prefix and common separators are accepted.</span>
          <?php if (isset($errors['phone'])): ?><span class="vehicle-field-error" id="profile-phone-error"><?= h($errors['phone']) ?></span><?php endif; ?>
        </label>
        <button class="button button-primary profile-submit" type="submit">Save contact details <span aria-hidden="true">→</span></button>
      </form>
    </section>

    <aside class="driver-profile-card profile-readonly-card">
      <div class="driver-profile-card-heading">
        <span class="driver-profile-icon profile-readonly-icon" aria-hidden="true">✓</span>
        <div><h2>Verified account details</h2><p>These details are not editable on this page.</p></div>
      </div>
      <dl class="profile-readonly-list">
        <div><dt>Full name</dt><dd><?= h((string) $profile['full_name']) ?></dd></div>
        <div><dt>Driver license number</dt><dd><?= h((string) $profile['license_number']) ?></dd></div>
      </dl>
      <p class="profile-security-note">Your changes are saved only to your signed-in driver account. Your login email changes when you update the email address above.</p>
    </aside>
  </div>
</section>
<?php page_end(); ?>
