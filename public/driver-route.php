<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();

$route = null;
$stops = [];
$routeAssignment = null;
$driverStatusQuery = db()->prepare(
    'SELECT verification_status FROM drivers WHERE id = :driver_id LIMIT 1'
);
$driverStatusQuery->execute(['driver_id' => (int) $driver['id']]);
$driverStatus = $driverStatusQuery->fetchColumn();
if ($driverStatus === false) {
    http_response_code(404);
    exit('Driver account not found.');
}

if ($driverStatus === 'verified') {
    $query = db()->prepare(
        "SELECT r.id AS route_id, r.route_name, s.stop_sequence, s.location_name,
                s.area_label, s.landmark, s.stop_type, s.implementation_note
         FROM jeepney_profiles j
         INNER JOIN routes r
            ON BINARY r.route_name = BINARY j.route_assignment AND r.is_active = 1
         LEFT JOIN RouteStops s ON s.route_id = r.id
         WHERE j.driver_id = :driver_id
           AND j.verification_status = 'verified'
         ORDER BY s.stop_sequence ASC"
    );
    $query->execute(['driver_id' => (int) $driver['id']]);
    $rows = $query->fetchAll();

    foreach ($rows as $row) {
        if ($route === null) {
            $route = [
                'id' => (int) $row['route_id'],
                'name' => (string) $row['route_name'],
            ];
        }
        if ($row['stop_sequence'] !== null) {
            $stops[] = $row;
        }
    }

    if ($route === null) {
        $assignment = db()->prepare(
            "SELECT route_assignment
             FROM jeepney_profiles
             WHERE driver_id = :driver_id AND verification_status = 'verified'
             LIMIT 1"
        );
        $assignment->execute(['driver_id' => (int) $driver['id']]);
        $routeAssignment = $assignment->fetchColumn();
    }
}

$terminals = [];
$destination = null;
foreach ($stops as $stop) {
    if ($stop['stop_type'] === 'terminal') {
        $terminals[] = $stop;
    }
    if ($stop['stop_type'] === 'destination') {
        $destination = $stop;
    }
}

page_start('My route', 'driver-route');
?>
<section class="route-page">
  <div class="route-page-heading">
    <div>
      <span class="eyebrow"><span class="eyebrow-dot"></span> YOUR DRIVER PORTAL</span>
      <h1>My assigned route</h1>
      <p>View your approved route, terminal, destination, and boarding points in travel order.</p>
    </div>
    <span class="route-driver-chip"><?= h((string) $driver['full_name']) ?></span>
  </div>

  <?php if ($driverStatus !== 'verified'): ?>
    <section class="route-empty-card">
      <span class="route-empty-icon" aria-hidden="true">◷</span>
      <h2>Route details are not available yet</h2>
      <p>Your driver account must be verified before an assigned route can be shown.</p>
    </section>
  <?php elseif ($route === null): ?>
    <section class="route-empty-card">
      <span class="route-empty-icon" aria-hidden="true">↗</span>
      <h2>No configured route found</h2>
      <?php if (is_string($routeAssignment) && $routeAssignment !== ''): ?>
        <p>Your approved vehicle route is <strong><?= h($routeAssignment) ?></strong>, but it does not match an active route record yet. Contact the service administrator to configure the route.</p>
      <?php else: ?>
        <p>An approved vehicle route assignment is needed before your route details can appear.</p>
      <?php endif; ?>
      <a class="button button-outline" href="jeepney-profile.php">View vehicle profile</a>
    </section>
  <?php elseif ($stops === []): ?>
    <section class="route-empty-card">
      <span class="route-empty-icon" aria-hidden="true">◎</span>
      <h2><?= h($route['name']) ?></h2>
      <p>This route is active, but its ordered stops have not been configured yet.</p>
    </section>
  <?php else: ?>
    <section class="route-overview-card">
      <div class="route-overview-mark" aria-hidden="true">↗</div>
      <div class="route-overview-copy">
        <span class="route-kicker">APPROVED ROUTE</span>
        <h2><?= h($route['name']) ?></h2>
        <p><?= count($stops) ?> <?= count($stops) === 1 ? 'location' : 'locations' ?> listed in travel sequence</p>
      </div>
      <span class="route-active-badge"><span aria-hidden="true">●</span> Active</span>
    </section>

    <div class="route-summary-grid">
      <article class="route-summary-card">
        <span class="route-summary-icon" aria-hidden="true">⌂</span>
        <div><span>Terminal locations</span>
          <?php if ($terminals === []): ?><strong>Not specified</strong>
          <?php else: foreach ($terminals as $terminal): ?>
            <strong><?= h((string) $terminal['location_name']) ?></strong>
            <small><?= h((string) $terminal['area_label']) ?> · <?= h((string) $terminal['landmark']) ?></small>
          <?php endforeach; endif; ?>
        </div>
      </article>
      <article class="route-summary-card">
        <span class="route-summary-icon route-destination-icon" aria-hidden="true">⚑</span>
        <div><span>Destination</span><strong><?= $destination === null ? 'Not specified' : h((string) $destination['location_name']) ?></strong>
          <?php if ($destination !== null): ?><small><?= h((string) $destination['landmark']) ?></small><?php endif; ?>
        </div>
      </article>
    </div>

    <section class="route-stops-card">
      <div class="route-stops-heading">
        <div><span class="route-kicker">TRAVEL SEQUENCE</span><h2>Terminals and stops</h2></div>
        <span><?= count($stops) ?> locations</span>
      </div>
      <ol class="route-stop-list">
        <?php foreach ($stops as $stop): ?>
          <li class="route-stop-item">
            <span class="route-stop-marker" aria-hidden="true"><?= h((string) $stop['stop_sequence']) ?></span>
            <div class="route-stop-content">
              <div class="route-stop-title">
                <h3><?= h((string) $stop['location_name']) ?></h3>
                <span class="route-stop-type"><?= h(str_replace('_', ' ', (string) $stop['stop_type'])) ?></span>
              </div>
              <p><?= h((string) $stop['area_label']) ?> · <?= h((string) $stop['landmark']) ?></p>
              <?php if (is_string($stop['implementation_note']) && $stop['implementation_note'] !== ''): ?>
                <small><?= h((string) $stop['implementation_note']) ?></small>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <p class="route-data-note"><span aria-hidden="true">i</span> Route details are shown from the approved vehicle assignment and configured route stops.</p>
  <?php endif; ?>
</section>
<?php page_end(); ?>
