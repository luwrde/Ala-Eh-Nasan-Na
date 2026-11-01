<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();

page_start('Application status');
?>
<h1>Hello, <?= h((string) $driver['full_name']) ?></h1>
<p>Your application status:
  <span class="status"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span>
</p>
<?php if ($driver['verification_status'] === 'pending_verification'): ?>
  <p>Your account is awaiting driver validation. Access to driver services should remain restricted until the account is verified.</p>
<?php elseif ($driver['verification_status'] === 'rejected'): ?>
  <p>Your application was not approved. Contact the service team for next steps.</p>
<?php else: ?>
  <p>Your driver account is verified.</p>
<?php endif; ?>
<form method="post" action="logout.php">
  <?= csrf_field() ?>
  <button type="submit">Log out</button>
</form>
<?php page_end(); ?>
