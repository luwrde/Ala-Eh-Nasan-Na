<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (current_driver() !== null) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $licenseNumber = strtoupper(trim((string) ($_POST['license_number'] ?? '')));
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (
        $fullName === '' || mb_strlen($fullName) > 120 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
        $phone === '' || strlen($phone) > 32 ||
        $licenseNumber === '' || strlen($licenseNumber) > 64 ||
        !is_string($password) || strlen($password) < 12 ||
        !is_string($passwordConfirm) || !hash_equals($password, $passwordConfirm)
    ) {
        $error = 'Check all fields. Use a valid email and a password of at least 12 characters; both password entries must match.';
    } else {
        $insert = db()->prepare(
            'INSERT INTO drivers (full_name, email, phone, license_number, password_hash, verification_status)
             VALUES (:full_name, :email, :phone, :license_number, :password_hash, :verification_status)'
        );

        try {
            $insert->execute([
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'license_number' => $licenseNumber,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'verification_status' => 'pending_verification',
            ]);
            redirect('login.php?registered=1');
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $error = 'An account could not be created with those details. Check whether the email or license is already registered.';
        }
    }
}

page_start('Driver registration', 'register');
?>
<section class="registration-layout">
  <div class="registration-heading">
    <span class="eyebrow"><span class="eyebrow-dot"></span> TAKE THE FIRST STEP</span>
    <h1>Let’s get your<br><span>journey started.</span></h1>
    <p>Set up your driver account to submit your information and follow your application progress.</p>
    <div class="registration-progress"><span class="progress-number">01</span><span class="progress-line"></span><span class="progress-label">CREATE YOUR ACCOUNT</span></div>
  </div>
  <div class="registration-card">
    <div class="form-heading">
      <span class="form-kicker">YOUR DETAILS</span>
      <h2>Create your account</h2>
      <p>Complete each field to get started.</p>
    </div>
    <div class="status-note"><span class="status-note-icon" aria-hidden="true">i</span><span>Your application will be marked <strong>pending verification</strong> after registration.</span></div>
    <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <form class="form-stack registration-form" method="post" action="register.php" autocomplete="on">
      <?= csrf_field() ?>
      <label class="field-full">Full name <input name="full_name" required maxlength="120" autocomplete="name" placeholder="Your full name" value="<?= h((string) ($_POST['full_name'] ?? '')) ?>"></label>
      <label>Email address <input type="email" name="email" required maxlength="254" autocomplete="email" placeholder="you@example.com" value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
      <label>Phone number <input type="tel" name="phone" required maxlength="32" autocomplete="tel" placeholder="Your phone number" value="<?= h((string) ($_POST['phone'] ?? '')) ?>"></label>
      <label class="field-full">Driver license number <input name="license_number" required maxlength="64" autocomplete="off" placeholder="Enter your license number" value="<?= h((string) ($_POST['license_number'] ?? '')) ?>"></label>
      <label>Password <input type="password" name="password" required minlength="12" autocomplete="new-password" placeholder="At least 12 characters"><span class="field-hint">Use at least 12 characters.</span></label>
      <label>Confirm password <input type="password" name="password_confirm" required minlength="12" autocomplete="new-password" placeholder="Re-enter your password"></label>
      <button class="button button-primary button-submit field-full" type="submit">Create account <span aria-hidden="true">→</span></button>
    </form>
    <p class="form-switch">Already have an account? <a href="login.php">Sign in</a></p>
    <div class="form-security"><span aria-hidden="true">◈</span> Your information is protected and kept private.</div>
  </div>
</section>
<?php page_end(); ?>
