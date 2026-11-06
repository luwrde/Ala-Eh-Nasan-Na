<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$driver = current_driver();
if ($driver !== null) {
    redirect('dashboard.php');
}

page_start('Driver portal', 'home');
?>
<section class="hero">
  <div class="hero-copy">
    <span class="eyebrow"><span class="eyebrow-dot"></span> YOUR JOURNEY STARTS HERE</span>
    <h1>Move your<br><span>career forward.</span></h1>
    <p class="hero-description">A simpler way to begin your driver journey. Create your account, submit your details, and keep up with your application—all in one place.</p>
    <div class="hero-actions">
      <a class="button button-primary" href="register.php">Create your account <span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#arrow"></use></svg></span></a>
      <a class="button button-quiet" href="commuter/user-login.html">I already have an account <span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#arrow-up"></use></svg></span></a>
      <a class="button button-quiet" href="commuter/index.html">Find a jeepney <span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#map"></use></svg></span></a>
    </div>
    <div class="trust-note"><span class="trust-check" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#check"></use></svg></span> A clear, secure start to your next opportunity</div>
  </div>
  <div class="hero-visual" aria-label="Driver application journey">
    <div class="visual-orbit orbit-one"></div>
    <div class="visual-orbit orbit-two"></div>
    <div class="journey-card">
      <div class="journey-card-top">
        <span class="journey-icon" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#sparkle"></use></svg></span>
        <span class="journey-label">YOUR DRIVER JOURNEY</span>
        <span class="journey-menu" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#more"></use></svg></span>
      </div>
      <p class="journey-title">A great next step.</p>
      <p class="journey-subtitle">Get started in three simple steps.</p>
      <div class="journey-steps">
        <div class="journey-step">
          <span class="step-number step-complete"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#check"></use></svg></span>
          <span><strong>Create your account</strong><small>Your profile, your starting point</small></span>
        </div>
        <div class="step-connector"></div>
        <div class="journey-step">
          <span class="step-number">02</span>
          <span><strong>Submit your details</strong><small>Share your driver information</small></span>
        </div>
        <div class="step-connector"></div>
        <div class="journey-step">
          <span class="step-number">03</span>
          <span><strong>Follow your application</strong><small>Check your status anytime</small></span>
        </div>
      </div>
      <div class="journey-card-footer"><span class="footer-pulse"></span> Your progress, all in one place</div>
    </div>
    <div class="floating-badge"><span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#sparkle"></use></svg></span> Your next chapter</div>
  </div>
</section>
<section class="benefits" aria-label="Portal benefits">
  <div class="benefit-item">
    <span class="benefit-icon" aria-hidden="true">01</span>
    <div><h2>Easy to get started</h2><p>Create your account with a few essential details.</p></div>
  </div>
  <div class="benefit-item">
    <span class="benefit-icon" aria-hidden="true">02</span>
    <div><h2>Know where you stand</h2><p>Sign in whenever you want to check your application status.</p></div>
  </div>
  <div class="benefit-item">
    <span class="benefit-icon" aria-hidden="true">03</span>
    <div><h2>Your account, protected</h2><p>Your sign-in details are securely protected.</p></div>
  </div>
</section>
<?php page_end(); ?>
