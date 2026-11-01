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

page_start('Driver registration');
?>
<h1>Driver registration</h1>
<p>Your account will be marked <span class="status">pending verification</span> after registration.</p>
<?php if ($error !== ''): ?><p class="message" role="alert"><?= h($error) ?></p><?php endif; ?>
<form method="post" action="register.php" autocomplete="on">
  <?= csrf_field() ?>
  <label>Full name <input name="full_name" required maxlength="120" autocomplete="name" value="<?= h((string) ($_POST['full_name'] ?? '')) ?>"></label>
  <label>Email <input type="email" name="email" required maxlength="254" autocomplete="email" value="<?= h((string) ($_POST['email'] ?? '')) ?>"></label>
  <label>Phone <input type="tel" name="phone" required maxlength="32" autocomplete="tel" value="<?= h((string) ($_POST['phone'] ?? '')) ?>"></label>
  <label>Driver license number <input name="license_number" required maxlength="64" autocomplete="off" value="<?= h((string) ($_POST['license_number'] ?? '')) ?>"></label>
  <label>Password (at least 12 characters) <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
  <label>Confirm password <input type="password" name="password_confirm" required minlength="12" autocomplete="new-password"></label>
  <button type="submit">Register</button>
</form>
<?php page_end(); ?>
