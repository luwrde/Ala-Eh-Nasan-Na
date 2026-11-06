<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-bootstrap.php';
require_once __DIR__ . '/../app/map-locations.php';
$admin = require_admin_role(['owner', 'admin']);
$locations = map_locations(true);
$routes = mapped_routes();
$editId = filter_var($_POST['id'] ?? $_GET['edit'] ?? 0, FILTER_VALIDATE_INT);
$editing = null;
foreach ($locations as $location) {
    if ($location['recordId'] === $editId) $editing = $location;
}
$error = '';
if (isset($_GET['edit']) && $editing === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    $error = 'This location could not be found. Choose a location from the list below.';
}
$flash = $_SESSION['location_flash'] ?? '';
unset($_SESSION['location_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin_role(['owner', 'admin']);
    verify_csrf_token();
    if ($editing === null) {
        http_response_code(404);
        exit('Location not found.');
    }
    $value = static fn (string $key): string => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    $name = $value('name');
    $area = $value('area');
    $landmark = $value('landmark');
    $type = $value('type');
    $latText = $value('latitude');
    $lngText = $value('longitude');
    $lat = $latText === '' ? null : filter_var($latText, FILTER_VALIDATE_FLOAT);
    $lng = $lngText === '' ? null : filter_var($lngText, FILTER_VALIDATE_FLOAT);
    $routeIds = $_POST['routes'] ?? [];
    $allowedRoutes = array_column($routes, 'id');
    if ($name === '' || mb_strlen($name) > 120 || $area === '' || mb_strlen($area) > 120 || $landmark === '' || mb_strlen($landmark) > 255) {
        $error = 'Enter a location name, area, and landmark within the field limits.';
    } elseif (!in_array($type, ['terminal', 'boarding_point', 'landmark'], true)) {
        $error = 'Choose a valid location type.';
    } elseif (($lat === null) !== ($lng === null) || $lat === false || $lng === false || ($lat !== null && ($lat < 13.45 || $lat > 14.3 || $lng < 120.45 || $lng > 121.65))) {
        $error = 'Place a pin within the Batangas map area, or leave both coordinates blank.';
    } elseif (!is_array($routeIds) || array_filter($routeIds, static fn ($id): bool => !is_string($id) || !ctype_digit($id) || !in_array((int) $id, $allowedRoutes, true))) {
        $error = 'Choose only routes from the list.';
    }
    if ($error === '') {
        $pdo = db();
        $query = $pdo->prepare('UPDATE map_locations SET location_name = ?, area_label = ?, landmark = ?, location_type = ?, latitude = ?, longitude = ?, mapped_route_ids = ?, is_active = ?, source_url = ? WHERE id = ?');
        $sameCoordinates = $editing['coordinates'] !== null && $lat !== null && abs($editing['coordinates'][0] - $lng) < 0.00000005 && abs($editing['coordinates'][1] - $lat) < 0.00000005;
        $pdo->beginTransaction();
        try {
            $query->execute([$name, $area, $landmark, $type, $lat, $lng, implode(',', array_unique(array_map('intval', $routeIds))), isset($_POST['active']) ? 1 : 0, $sameCoordinates ? $editing['source'] : null, $editId]);
            record_admin_activity($pdo, $admin, 'map_location_updated', 'map_location', (int) $editId, $name);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
        $_SESSION['location_flash'] = $name . ' saved. ' . (!isset($_POST['active']) ? 'This location is hidden from drivers and commuters.' : ($lat === null ? 'Coordinates are not set; this location has no map pin.' : 'The pin is available on the driver and commuter maps.'));
        redirect('admin-locations.php');
    }
    http_response_code(422);
    $editing = array_merge($editing, ['name' => $name, 'area' => $area, 'landmark' => $landmark, 'type' => $type]);
    $editing['routeIds'] = is_array($routeIds) ? array_map('intval', array_filter($routeIds, 'is_scalar')) : [];
    $editing['active'] = isset($_POST['active']);
}
$latitudeValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? $latText : (string) ($editing['coordinates'][1] ?? '');
$longitudeValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? $lngText : (string) ($editing['coordinates'][0] ?? '');
$visibleCount = count(array_filter($locations, static fn (array $location): bool => $location['active'] && $location['coordinates'] !== null));
$unplacedCount = count(array_filter($locations, static fn (array $location): bool => $location['coordinates'] === null));
$typeLabels = ['terminal' => 'Terminal', 'boarding_point' => 'Boarding point', 'landmark' => 'Landmark'];
page_start('Map locations', 'admin-locations');
?>
<link rel="stylesheet" href="assets/admin/locations.css?v=<?= h((string) filemtime(__DIR__ . '/assets/admin/locations.css')) ?>">
<?php if ($editing !== null): ?><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""><?php endif; ?>
<section class="admin-map-page">
  <header class="locations-heading"><div><span class="eyebrow">TERMINALS & BOARDING POINTS</span><h1>Map locations</h1><p>Keep pickup points accurate for your drivers and commuters.</p></div><a class="button button-outline" href="map.php" target="_blank" rel="noopener">Preview public map <span aria-hidden="true">↗</span></a></header>
  <div class="location-summary" aria-label="Location summary"><div><span>Total locations</span><strong><?= count($locations) ?></strong></div><div><span>Visible map pins</span><strong><?= $visibleCount ?></strong></div><div><span>Need coordinates</span><strong><?= $unplacedCount ?></strong></div></div>
  <?php if ($flash !== ''): ?><p class="message message-success" role="status"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="message message-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php if ($editing !== null): ?>
    <section class="location-edit-section" aria-labelledby="location-edit-title">
      <div class="location-section-heading"><div><span class="eyebrow">EDIT LOCATION</span><h2 id="location-edit-title"><?= h($editing['name']) ?></h2><p>Update the details and pin, then save to publish your changes.</p></div><a class="button button-outline button-small" href="admin-locations.php">Back to locations</a></div>
    <div class="map-editor-layout">
      <form class="form-stack map-editor-form" id="location-editor-form" method="post" action="admin-locations.php">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $editing['recordId'] ?>">
        <h3>Location details</h3>
        <label>Location name<input name="name" value="<?= h($editing['name']) ?>" maxlength="120" required></label>
        <label>Area / perspective<input name="area" value="<?= h($editing['area']) ?>" maxlength="120" required></label>
        <label>Landmark<input name="landmark" value="<?= h($editing['landmark']) ?>" maxlength="255" required></label>
        <label>Location type<select name="type"><?php foreach ($typeLabels as $type => $label): ?><option value="<?= $type ?>" <?= $editing['type'] === $type ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <div class="map-coordinate-fields">
          <label>Latitude<input id="location-lat" name="latitude" type="number" step="any" min="13.45" max="14.3" value="<?= h($latitudeValue) ?>"></label>
          <label>Longitude<input id="location-lng" name="longitude" type="number" step="any" min="120.45" max="121.65" value="<?= h($longitudeValue) ?>"></label>
        </div>
        <p class="location-help">Click the map or enter both coordinates. Leave both blank to remove the pin.</p>
        <div class="location-pin-actions"><button class="button button-outline button-small" id="location-center" type="button">Center on pin</button><button class="button button-outline button-small" id="location-unpin" type="button">Remove pin</button></div>
        <fieldset class="map-service-checks"><legend>Routes serving this stop</legend><p class="location-help">Choose the confirmed routes that serve this location.</p>
        <?php foreach ($routes as $route): ?><label><input type="checkbox" name="routes[]" value="<?= $route['id'] ?>" <?= in_array($route['id'], $editing['routeIds'], true) ? 'checked' : '' ?>><?= h($route['name']) ?></label><?php endforeach; ?>
        </fieldset>
        <label class="map-active-check"><input type="checkbox" name="active" value="1" <?= $editing['active'] ? 'checked' : '' ?>> Active — visible to drivers and commuters</label>
        <div class="location-save-actions"><button class="button button-primary" type="submit">Save changes</button><button class="button button-outline" type="reset">Reset changes</button></div>
      </form>
      <div class="map-editor-panel"><div class="location-map-heading"><h3>Pin position</h3><p>Click to place a pin. Drag it to fine-tune the boarding point.</p></div><div id="location-editor-map" data-area="<?= h($editing['area']) ?>" aria-label="Location editor map"></div><p id="location-editor-status" role="status" aria-live="polite">Loading map. You can also enter coordinates in the form.</p><p class="location-help">Changes stay in this draft until you select Save changes.</p></div>
    </div>
    </section>
  <?php endif; ?>
  <section class="locations-list" aria-labelledby="locations-list-title">
  <div class="location-section-heading"><div><h2 id="locations-list-title">All locations</h2><p>Select Edit to update a location or adjust its map pin.</p></div><span id="location-result-count" role="status"><?= count($locations) ?> locations</span></div>
  <div class="location-filters" id="location-filters" hidden><label>Search locations<input type="search" id="location-search" placeholder="Search name, area, or landmark"></label><label>Visibility<select id="location-filter"><option value="all">All locations</option><option value="visible">Visible on map</option><option value="unplaced">Need coordinates</option><option value="hidden">Hidden</option></select></label></div>
  <div class="map-locations-table-wrap"><table class="map-locations-table"><thead><tr><th scope="col">Location / area</th><th scope="col">Landmark</th><th scope="col">Map position</th><th scope="col">Visibility</th><th scope="col">Action</th></tr></thead><tbody>
  <?php foreach ($locations as $location): ?>
    <tr data-location-row data-state="<?= !$location['active'] ? 'hidden' : ($location['coordinates'] === null ? 'unplaced' : 'visible') ?>" data-unplaced="<?= $location['coordinates'] === null ? '1' : '0' ?>" <?= $editing !== null && $editing['recordId'] === $location['recordId'] ? 'class="is-editing"' : '' ?>>
      <td data-label="Location"><strong><?= h($location['name']) ?></strong><span class="location-area"><?= h($location['area']) ?></span><span class="location-type"><?= h($typeLabels[$location['type']] ?? $location['type']) ?></span></td>
      <td data-label="Landmark"><?= h($location['landmark']) ?></td>
      <td data-label="Map position"><?php if ($location['coordinates'] === null): ?><span class="location-badge is-unplaced">Not set</span><?php else: ?><span class="location-coordinates"><?= h(number_format($location['coordinates'][1], 7, '.', '')) ?><br><?= h(number_format($location['coordinates'][0], 7, '.', '')) ?></span><?php endif; ?></td>
      <td data-label="Visibility"><span class="location-badge <?= $location['active'] ? 'is-active' : 'is-hidden' ?>"><?= $location['active'] ? 'Active' : 'Hidden' ?></span><?php if ($location['active'] && $location['coordinates'] === null): ?><small>Pin not placed</small><?php endif; ?></td>
      <td data-label="Action"><a class="button button-outline button-small" href="admin-locations.php?edit=<?= $location['recordId'] ?>" aria-label="Edit <?= h($location['name']) ?>"><?= $editing !== null && $editing['recordId'] === $location['recordId'] ? 'Editing' : 'Edit location' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="location-empty" id="location-empty" <?= $locations !== [] ? 'hidden' : '' ?>><h3>No locations found</h3><p>Try a different search or visibility filter.</p><button class="button button-outline button-small" id="location-clear-filters" type="button">Clear filters</button></div>
  </section>
</section>
<script src="assets/admin/locations.js?v=<?= h((string) filemtime(__DIR__ . '/assets/admin/locations.js')) ?>" defer></script>
<?php if ($editing !== null): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
<script src="assets/location-editor.js?v=<?= h((string) filemtime(__DIR__ . '/assets/location-editor.js')) ?>" defer></script>
<?php endif; page_end(); ?>
