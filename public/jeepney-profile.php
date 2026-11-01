<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();

page_start('My jeepney', 'jeepney-profile');
?>
<section class="vehicle-page">
  <div class="vehicle-heading">
    <div>
      <span class="eyebrow"><span class="eyebrow-dot"></span> YOUR VEHICLE</span>
      <h1>Jeepney profile</h1>
      <p>Manage your vehicle details and route assignment.</p>
    </div>
    <span class="vehicle-driver-chip"><span class="vehicle-avatar" aria-hidden="true"><?= h(strtoupper(substr((string) $driver['full_name'], 0, 1))) ?></span><?= h((string) $driver['full_name']) ?></span>
  </div>

  <div class="vehicle-status-banner" id="vehicle-status-banner" role="status" aria-live="polite">
    <span class="vehicle-banner-icon" aria-hidden="true">◷</span>
    <div><strong id="vehicle-status-title">Loading your vehicle profile</strong><p id="vehicle-status-message">Your approved vehicle information will appear here.</p></div>
  </div>

  <div class="vehicle-layout">
    <section class="vehicle-card">
      <div class="vehicle-card-heading">
        <span class="vehicle-card-icon" aria-hidden="true">▰</span>
        <div><h2>Vehicle details</h2><p>Changes are reviewed before they become official.</p></div>
      </div>
      <form class="vehicle-form" id="vehicle-form" data-api="api/vehicle.php">
        <?= csrf_field() ?>
        <label>Plate number
          <input name="plate_number" type="text" required maxlength="10" autocomplete="off" placeholder="ABC 1234" aria-describedby="plate-hint plate-error">
          <span class="vehicle-field-hint" id="plate-hint">Philippine format, for example ABC 1234.</span>
          <span class="vehicle-field-error" id="plate-error"></span>
        </label>
        <div class="vehicle-form-row">
          <label>Passenger capacity
            <input name="capacity" type="number" required min="1" step="1" inputmode="numeric" placeholder="e.g. 20" aria-describedby="capacity-error">
            <span class="vehicle-field-error" id="capacity-error"></span>
          </label>
          <label>Route assignment
            <input name="route_assignment" type="text" required maxlength="120" placeholder="e.g. Bayan – Terminal" aria-describedby="route-error">
            <span class="vehicle-field-error" id="route-error"></span>
          </label>
        </div>
        <div class="vehicle-form-footer">
          <span><span aria-hidden="true">◈</span> Only you can edit your vehicle profile.</span>
          <button class="button button-primary" id="vehicle-submit" type="submit">Submit for review <span aria-hidden="true">→</span></button>
        </div>
        <p class="vehicle-form-message" id="vehicle-form-message" role="status" aria-live="polite"></p>
      </form>
    </section>

    <aside class="vehicle-approved-card">
      <div class="vehicle-approved-heading"><span class="vehicle-approved-icon" aria-hidden="true">✓</span><div><h2>Current approved details</h2><p>Only verified details are active.</p></div></div>
      <div class="vehicle-approved-content" id="approved-vehicle-details">
        <p class="vehicle-empty-details">Loading approved vehicle information…</p>
      </div>
      <div class="vehicle-review-note"><span aria-hidden="true">i</span><p>New and edited details remain pending until an administrator verifies them.</p></div>
    </aside>
  </div>
</section>
<script src="assets/jeepney-profile.js" defer></script>
<?php page_end(); ?>
