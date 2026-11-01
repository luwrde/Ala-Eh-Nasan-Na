<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();

page_start('Application status', 'dashboard');
?>
<section class="dashboard-wrap">
  <div class="dashboard-heading">
    <span class="eyebrow"><span class="eyebrow-dot"></span> YOUR DRIVER PORTAL</span>
    <h1>Welcome back, <span><?= h((string) $driver['full_name']) ?>.</span></h1>
    <p>Here’s the latest on your driver application.</p>
  </div>
  <?php if ($driver['verification_status'] === 'pending_verification'): ?>
    <div class="application-card status-pending">
      <div class="application-icon" aria-hidden="true">◷</div>
      <div class="application-copy">
        <span class="form-kicker">APPLICATION STATUS</span>
        <h2>Under review</h2>
        <p>Your application is awaiting driver validation. You can check back here for updates.</p>
      </div>
      <span class="status-pill"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span>
    </div>
    <div class="next-step-card">
      <span class="next-step-icon" aria-hidden="true">i</span>
      <div><h2>What happens next?</h2><p>Your details will be reviewed. Access to driver services remains restricted until your account is verified.</p></div>
    </div>
  <?php elseif ($driver['verification_status'] === 'rejected'): ?>
    <div class="application-card status-rejected">
      <div class="application-icon" aria-hidden="true">!</div>
      <div class="application-copy">
        <span class="form-kicker">APPLICATION STATUS</span>
        <h2>More information needed</h2>
        <p>Your application was not approved. Please contact the service team for next steps.</p>
      </div>
      <span class="status-pill"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span>
    </div>
  <?php else: ?>
    <div class="application-card status-verified">
      <div class="application-icon" aria-hidden="true">✓</div>
      <div class="application-copy">
        <span class="form-kicker">APPLICATION STATUS</span>
        <h2>You’re verified</h2>
        <p>Your driver account is verified. You’re all set.</p>
      </div>
      <span class="status-pill"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span>
    </div>
    <div class="next-step-card">
      <span class="next-step-icon" aria-hidden="true">✓</span>
      <div><h2>Your account is ready</h2><p>Your driver account has been verified. Thank you for completing the process.</p></div>
    </div>
  <?php endif; ?>
  <section class="driver-vehicle-link">
    <div class="driver-vehicle-link-icon" aria-hidden="true">▰</div>
    <div><h2>Your jeepney</h2><p>Add or update your plate number, passenger capacity, and route assignment.</p></div>
    <a class="button button-outline" href="jeepney-profile.php">Manage vehicle <span aria-hidden="true">→</span></a>
  </section>
  <div class="dashboard-footer-note"><span aria-hidden="true">◈</span> Your account information is private and protected.</div>
</section>
<?php page_end(); ?>
