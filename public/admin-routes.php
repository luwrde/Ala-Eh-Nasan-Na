<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/admin-bootstrap.php';
require_once __DIR__ . '/../app/terminal-landmark.php';
$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = require_admin_role(['owner', 'admin']);
    verify_csrf_token();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_terminal') {
        $terminalIdInput = $_POST['terminal_id'] ?? '';
        $terminalId = $terminalIdInput === '' ? null : validated_positive_id($terminalIdInput);
        $activeInput = $_POST['is_active'] ?? '1';
        if (($terminalIdInput !== '' && $terminalId === null) || !in_array($activeInput, ['0', '1'], true)) {
            http_response_code(400);
            exit('Invalid terminal record.');
        }
        $location = trim((string) ($_POST['terminal_name'] ?? ''));
        $area = trim((string) ($_POST['municipality'] ?? ''));
        $landmark = trim((string) ($_POST['landmark'] ?? ''));
        $gpsLink = trim((string) ($_POST['gps_link'] ?? ''));
        $isActive = $activeInput === '1' ? 1 : 0;
        if (!is_terminal_maps_link($gpsLink)) {
            $_SESSION['admin_terminal_error'] = 'Terminal not saved. GPS link must be a Google Maps location link.';
            redirect('admin-routes.php#terminals');
        }
        if (
            $location === '' || mb_strlen($location) > 120 ||
            $area === '' || mb_strlen($area) > 100 ||
            $landmark === '' || mb_strlen($landmark) > 255
        ) {
            $_SESSION['admin_terminal_error'] = 'Terminal not saved. Enter a valid location, area, and landmark.';
            redirect('admin-routes.php#terminals');
        }
        $pdo->beginTransaction();
        try {
            if ($terminalId === null) {
                $save = $pdo->prepare(
                    'INSERT INTO terminals (terminal_name, municipality, is_active)
                     VALUES (:location, :area, :active)'
                );
                $save->execute([
                    'location' => $location, 'area' => $area, 'active' => $isActive,
                ]);
                $terminalId = (int) $pdo->lastInsertId();
            } else {
                $save = $pdo->prepare(
                    'UPDATE terminals SET terminal_name = :location, municipality = :area,
                         is_active = :active
                     WHERE id = :id'
                );
                $save->execute([
                    'location' => $location, 'area' => $area,
                    'active' => $isActive, 'id' => $terminalId,
                ]);
            }
            $saveLandmark = $pdo->prepare(
                'INSERT INTO terminal_details (terminal_id, landmark, gps_link)
                 VALUES (:terminal_id, :landmark, :gps_link)
                 ON DUPLICATE KEY UPDATE landmark = VALUES(landmark), gps_link = VALUES(gps_link)'
            );
            $saveLandmark->execute(['terminal_id' => $terminalId, 'landmark' => $landmark, 'gps_link' => $gpsLink]);
            if ($isActive === 0) {
                $pdo->prepare(
                    'UPDATE routes r
                     INNER JOIN route_terminals rt ON rt.route_id = r.id
                     SET r.is_active = 0
                     WHERE rt.terminal_id = :terminal_id'
                )->execute(['terminal_id' => $terminalId]);
                $pdo->prepare(
                    "UPDATE trips t
                     INNER JOIN route_terminals rt ON rt.route_id = t.route_id
                     SET t.trip_status = 'cancelled', t.ended_at = CURRENT_TIMESTAMP
                     WHERE rt.terminal_id = :terminal_id AND t.trip_status = 'active'"
                )->execute(['terminal_id' => $terminalId]);
            }
            record_admin_activity($pdo, $admin, 'terminal_saved', 'terminal', $terminalId, $location . ', ' . $area);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $_SESSION['admin_flash'] = 'A terminal with that location is already registered for this area.';
                redirect('admin-routes.php#terminals');
            }
            throw $exception;
        }
        $_SESSION['admin_flash'] = 'Terminal saved.';
        redirect('admin-routes.php#terminals');
    }

    if ($action === 'save_route') {
        $routeIdInput = $_POST['route_id'] ?? '';
        $routeId = $routeIdInput === '' ? null : validated_positive_id($routeIdInput);
        $name = trim((string) ($_POST['route_name'] ?? ''));
        $originId = validated_positive_id($_POST['origin_terminal_id'] ?? null);
        $destinationId = validated_positive_id($_POST['destination_terminal_id'] ?? null);
        $activeInput = $_POST['is_active'] ?? '1';
        if (
            ($routeIdInput !== '' && $routeId === null) || !in_array($activeInput, ['0', '1'], true) ||
            $name === '' || mb_strlen($name) > 120 || $originId === null ||
            $destinationId === null || $originId === $destinationId
        ) {
            $_SESSION['admin_flash'] = 'Enter a route name and choose two different terminals.';
            redirect('admin-routes.php#routes');
        }
        $isActive = $activeInput === '1' ? 1 : 0;
        $terminalCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM terminals WHERE id IN (:origin, :destination) AND is_active = 1'
        );
        $terminalCheck->execute(['origin' => $originId, 'destination' => $destinationId]);
        if ((int) $terminalCheck->fetchColumn() !== 2) {
            $_SESSION['admin_flash'] = 'Both route terminals must be active.';
            redirect('admin-routes.php#routes');
        }
        if ($routeId !== null) {
            $existingRoute = $pdo->prepare('SELECT id FROM routes WHERE id = :id');
            $existingRoute->execute(['id' => $routeId]);
            if ($existingRoute->fetchColumn() === false) {
                http_response_code(404);
                exit('Route not found.');
            }
        }

        $pdo->beginTransaction();
        try {
            if ($routeId === null) {
                $save = $pdo->prepare('INSERT INTO routes (route_name, is_active) VALUES (:name, :active)');
                $save->execute(['name' => $name, 'active' => $isActive]);
                $routeId = (int) $pdo->lastInsertId();
            } else {
                $oldNameQuery = $pdo->prepare('SELECT route_name FROM routes WHERE id = :id FOR UPDATE');
                $oldNameQuery->execute(['id' => $routeId]);
                $oldName = $oldNameQuery->fetchColumn();
                if ($oldName === false) {
                    $pdo->rollBack();
                    http_response_code(404);
                    exit('Route not found.');
                }
                $save = $pdo->prepare(
                    'UPDATE routes SET route_name = :name, is_active = :active WHERE id = :id'
                );
                $save->execute(['name' => $name, 'active' => $isActive, 'id' => $routeId]);
                if (is_string($oldName) && $oldName !== $name) {
                    $updateVehicleNames = $pdo->prepare(
                        'UPDATE jeepney_profiles SET route_assignment = :name WHERE route_assignment = :old_name'
                    );
                    $updateVehicleNames->execute(['name' => $name, 'old_name' => $oldName]);
                }
                if ($isActive === 0) {
                    $pdo->prepare(
                        "UPDATE trips SET trip_status = 'cancelled', ended_at = CURRENT_TIMESTAMP
                         WHERE route_id = :route_id AND trip_status = 'active'"
                    )->execute(['route_id' => $routeId]);
                }
            }
            $pdo->prepare('DELETE FROM route_terminals WHERE route_id = :id')->execute(['id' => $routeId]);
            $link = $pdo->prepare(
                'INSERT INTO route_terminals (route_id, terminal_id, stop_order)
                 VALUES (:route_id, :terminal_id, :stop_order)'
            );
            $link->execute(['route_id' => $routeId, 'terminal_id' => $originId, 'stop_order' => 1]);
            $link->execute(['route_id' => $routeId, 'terminal_id' => $destinationId, 'stop_order' => 2]);
            record_admin_activity($pdo, $admin, 'route_saved', 'route', $routeId, $name);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $_SESSION['admin_flash'] = 'A route with that name already exists.';
                redirect('admin-routes.php#routes');
            }
            throw $exception;
        }
        $_SESSION['admin_flash'] = 'Authorized route and terminal endpoints saved.';
        redirect('admin-routes.php#routes');
    }

    if ($action === 'save_stop') {
        $routeId = validated_positive_id($_POST['route_id'] ?? null);
        $stopIdInput = $_POST['stop_id'] ?? '';
        $stopId = $stopIdInput === '' ? null : validated_positive_id($stopIdInput);
        $sequence = filter_var($_POST['stop_sequence'] ?? null, FILTER_VALIDATE_INT);
        $locationName = trim((string) ($_POST['location_name'] ?? ''));
        $areaLabel = trim((string) ($_POST['area_label'] ?? ''));
        $landmark = trim((string) ($_POST['landmark'] ?? ''));
        $stopType = $_POST['stop_type'] ?? 'stop';
        $note = trim((string) ($_POST['implementation_note'] ?? ''));
        $latRaw = trim((string) ($_POST['latitude'] ?? ''));
        $lngRaw = trim((string) ($_POST['longitude'] ?? ''));
        $latitude = $latRaw === '' ? null : validated_coordinate($latRaw, -90, 90);
        $longitude = $lngRaw === '' ? null : validated_coordinate($lngRaw, -180, 180);
        if (
            $routeId === null || ($stopIdInput !== '' && $stopId === null) ||
            $sequence === false || $sequence < 1 || $sequence > 65535 ||
            $locationName === '' || mb_strlen($locationName) > 120 ||
            $areaLabel === '' || mb_strlen($areaLabel) > 120 ||
            $landmark === '' || mb_strlen($landmark) > 255 ||
            mb_strlen($note) > 500 ||
            !in_array($stopType, ['terminal', 'boarding_point', 'stop', 'destination'], true) ||
            (($latRaw !== '' || $lngRaw !== '') && ($latitude === null || $longitude === null))
        ) {
            $_SESSION['admin_flash'] = 'Check stop details, sequence number, and optional coordinate pair.';
            redirect('admin-routes.php#routes');
        }
        $routeExists = $pdo->prepare('SELECT id FROM routes WHERE id = :id');
        $routeExists->execute(['id' => $routeId]);
        if ($routeExists->fetchColumn() === false) {
            http_response_code(404);
            exit('Route not found.');
        }
        if ($stopId !== null) {
            $stopExists = $pdo->prepare('SELECT id FROM RouteStops WHERE id = :id AND route_id = :route_id');
            $stopExists->execute(['id' => $stopId, 'route_id' => $routeId]);
            if ($stopExists->fetchColumn() === false) {
                http_response_code(404);
                exit('Route stop not found.');
            }
        }
        $pdo->beginTransaction();
        try {
            if ($stopId === null) {
                $save = $pdo->prepare(
                    'INSERT INTO RouteStops
                        (route_id, stop_sequence, location_name, area_label, landmark, stop_type, implementation_note)
                     VALUES (:route_id, :sequence, :name, :area, :landmark, :type, :note)'
                );
            } else {
                $save = $pdo->prepare(
                    'UPDATE RouteStops SET stop_sequence = :sequence, location_name = :name,
                        area_label = :area, landmark = :landmark, stop_type = :type,
                        implementation_note = :note
                     WHERE id = :id AND route_id = :route_id'
                );
            }
            $params = [
                'route_id' => $routeId, 'sequence' => $sequence, 'name' => $locationName,
                'area' => $areaLabel, 'landmark' => $landmark, 'type' => $stopType, 'note' => $note === '' ? null : $note,
            ];
            if ($stopId !== null) {
                $params['id'] = $stopId;
            }
            $save->execute($params);
            $savedStopId = $stopId ?? (int) $pdo->lastInsertId();
            if ($latitude !== null && $longitude !== null) {
                $coordinates = $pdo->prepare(
                    'INSERT INTO route_stop_coordinates (route_stop_id, latitude, longitude)
                     VALUES (:id, :latitude, :longitude)
                     ON DUPLICATE KEY UPDATE latitude = VALUES(latitude), longitude = VALUES(longitude)'
                );
                $coordinates->execute(['id' => $savedStopId, 'latitude' => $latitude, 'longitude' => $longitude]);
            } else {
                $pdo->prepare('DELETE FROM route_stop_coordinates WHERE route_stop_id = :id')->execute(['id' => $savedStopId]);
            }
            record_admin_activity($pdo, $admin, 'route_stop_saved', 'route_stop', $savedStopId, $locationName);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $_SESSION['admin_flash'] = 'That stop sequence is already used on this route.';
                redirect('admin-routes.php#route-' . $routeId);
            }
            throw $exception;
        }
        $_SESSION['admin_flash'] = 'Route stop saved.';
        redirect('admin-routes.php#route-' . $routeId);
    }

    if ($action === 'delete_stop') {
        $routeId = validated_positive_id($_POST['route_id'] ?? null);
        $stopId = validated_positive_id($_POST['stop_id'] ?? null);
        if ($routeId === null || $stopId === null) {
            http_response_code(400);
            exit('Invalid route stop.');
        }
        $delete = $pdo->prepare('DELETE FROM RouteStops WHERE id = :id AND route_id = :route_id');
        $delete->execute(['id' => $stopId, 'route_id' => $routeId]);
        if ($delete->rowCount() === 1) {
            record_admin_activity($pdo, $admin, 'route_stop_removed', 'route_stop', $stopId);
        }
        $_SESSION['admin_flash'] = 'Route stop removed.';
        redirect('admin-routes.php#route-' . $routeId);
    }

    http_response_code(400);
    exit('Invalid route management action.');
}

$terminals = $pdo->query(
    'SELECT t.*, td.landmark, td.gps_link
     FROM terminals t
     LEFT JOIN terminal_details td ON td.terminal_id = t.id
     ORDER BY t.municipality, t.terminal_name'
)->fetchAll();
$flash = (string) ($_SESSION['admin_flash'] ?? '');
unset($_SESSION['admin_flash']);
$terminalError = (string) ($_SESSION['admin_terminal_error'] ?? '');
unset($_SESSION['admin_terminal_error']);

page_start('Terminals and routes', 'admin-routes');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading"><div><span class="eyebrow"><span class="eyebrow-dot"></span> NETWORK CONFIGURATION</span><h1>Terminals & routes</h1><p>Manage terminal areas, locations, landmarks, and operating status.</p></div><span class="admin-access-badge"><span aria-hidden="true">📍</span> Admin-managed data</span></div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <section class="admin-queue" id="terminals">
    <div class="admin-queue-heading"><div><h2>Terminals</h2><p>Support Batangas City, Mabini, and any approved intermediate terminal.</p></div></div>
    <form method="post" class="admin-form-grid" action="admin-routes.php#terminals">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_terminal">
      <label>Area<input name="municipality" maxlength="100" required placeholder="Batangas City or Mabini"></label>
      <label>Location<input name="terminal_name" maxlength="120" required placeholder="Terminal or place name"></label>
      <label>Landmark<input name="landmark" maxlength="255" required placeholder="Nearby landmark"></label>
      <label>GPS link (Google Maps)<input type="url" name="gps_link" maxlength="2048" required placeholder="https://maps.app.goo.gl/…" data-terminal-maps-link></label>
      <button class="button button-primary" type="submit">Add terminal</button>
    </form>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Area / location</th><th>Landmark</th><th>GPS link</th><th>Status</th><th>Edit</th></tr></thead><tbody>
      <?php foreach ($terminals as $terminal): ?>
        <tr><td data-label="Area / location"><strong><?= h((string) $terminal['municipality']) ?></strong><small><?= h((string) $terminal['terminal_name']) ?></small></td>
          <td data-label="Landmark"><?= h((string) ($terminal['landmark'] ?? 'Not set')) ?></td>
          <td data-label="GPS link"><?php if (is_terminal_maps_link((string) ($terminal['gps_link'] ?? ''))): ?><a href="<?= h((string) $terminal['gps_link']) ?>" target="_blank" rel="noopener noreferrer">Open in Google Maps ↗</a><?php else: ?>Not set<?php endif; ?></td>
          <td data-label="Status"><?= (int) $terminal['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
          <td data-label="Edit"><button class="button button-outline admin-manage-button" type="button"
            data-terminal-edit
            data-terminal-id="<?= h((string) $terminal['id']) ?>"
            data-area="<?= h((string) $terminal['municipality']) ?>"
            data-location="<?= h((string) $terminal['terminal_name']) ?>"
            data-landmark="<?= h((string) ($terminal['landmark'] ?? '')) ?>"
            data-gps-link="<?= h((string) ($terminal['gps_link'] ?? '')) ?>"
            data-status="<?= (int) $terminal['is_active'] === 1 ? '1' : '0' ?>"
            aria-label="Edit <?= h((string) $terminal['terminal_name']) ?>">Edit</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($terminals === []): ?><tr><td colspan="5">No terminals configured.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <dialog class="admin-manage-dialog terminal-edit-dialog" id="terminal-edit-dialog" aria-labelledby="terminal-edit-title">
    <div class="admin-manage-dialog-header">
      <div><span class="eyebrow"><span class="eyebrow-dot"></span> TERMINAL MANAGEMENT</span><h2 id="terminal-edit-title">Edit terminal details</h2><p>Update the terminal area, location, landmark, and operating status.</p></div>
      <button class="admin-manage-dialog-close" type="button" data-terminal-dialog-close aria-label="Close dialog">×</button>
    </div>
    <div class="terminal-edit-dialog-body">
      <form method="post" class="admin-form-grid terminal-edit-form" action="admin-routes.php#terminals">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_terminal"><input type="hidden" name="terminal_id" data-terminal-field="id">
        <label>Area<input name="municipality" data-terminal-field="area" maxlength="100" required placeholder="Batangas City"></label>
        <label>Location<input name="terminal_name" data-terminal-field="location" maxlength="120" required placeholder="Terminal or place name"></label>
        <label>Landmark<input name="landmark" data-terminal-field="landmark" maxlength="255" required placeholder="Nearby landmark"></label>
        <label class="terminal-edit-gps-link">GPS link (Google Maps)<input type="url" name="gps_link" data-terminal-field="gps-link" maxlength="2048" required placeholder="https://maps.app.goo.gl/…" data-terminal-maps-link></label>
        <label>Status<select name="is_active" data-terminal-field="status"><option value="1">Active</option><option value="0">Inactive</option></select></label>
        <div class="terminal-edit-actions">
          <button class="button button-outline" type="button" data-terminal-dialog-close>Cancel</button>
          <button class="button button-primary" type="submit">Save terminal</button>
        </div>
      </form>
    </div>
  </dialog>

</section>
<script>
function showTerminalReminder(input, message = 'Terminal not saved. GPS link must be a Google Maps location link.') {
  window.showToast(message, 'error', 5000, false);
  input?.focus();
}
<?php if ($terminalError !== ''): ?>
showTerminalReminder(document.querySelector('[data-terminal-maps-link]'), <?= json_encode($terminalError, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php endif; ?>
const terminalMapsPattern = new RegExp(<?= json_encode('^(?:' . TERMINAL_MAPS_LINK_PATTERN . ')$', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, 'i');
document.querySelectorAll('[data-terminal-maps-link]').forEach(input => {
  const validate = () => {
    const valid = input.value.trim().length <= 2048 && terminalMapsPattern.test(input.value.trim());
    input.setCustomValidity(!valid
      ? 'Terminal not saved. GPS link must be a Google Maps location link.'
      : '');
    if (valid) input.removeAttribute('aria-invalid');
  };
  input.addEventListener('invalid', event => {
    event.preventDefault();
    validate();
    input.setAttribute('aria-invalid', 'true');
    showTerminalReminder(input);
  });
  input.addEventListener('input', validate);
  input.addEventListener('change', () => { input.value = input.value.trim(); validate(); });
  input.form.addEventListener('submit', event => {
    input.value = input.value.trim();
    validate();
    if (!input.reportValidity()) event.preventDefault();
  });
});
const terminalEditDialog = document.getElementById('terminal-edit-dialog');
const terminalEditFields = {
      id: terminalEditDialog?.querySelector('[data-terminal-field="id"]'),
      area: terminalEditDialog?.querySelector('[data-terminal-field="area"]'),
      location: terminalEditDialog?.querySelector('[data-terminal-field="location"]'),
      landmark: terminalEditDialog?.querySelector('[data-terminal-field="landmark"]'),
      gpsLink: terminalEditDialog?.querySelector('[data-terminal-field="gps-link"]'),
      status: terminalEditDialog?.querySelector('[data-terminal-field="status"]')
};

document.querySelectorAll('[data-terminal-edit]').forEach((button) => {
      button.addEventListener('click', () => {
        Object.entries(terminalEditFields).forEach(([field, input]) => {
          if (input) input.value = button.dataset[field === 'id' ? 'terminalId' : field] || '';
        });
        terminalEditFields.gpsLink?.dispatchEvent(new Event('input'));
        terminalEditDialog?.showModal();
        terminalEditFields.location?.focus();
      });
});

document.querySelectorAll('[data-terminal-dialog-close]').forEach((button) => {
      button.addEventListener('click', () => terminalEditDialog?.close());
});

terminalEditDialog?.addEventListener('click', (event) => {
      if (event.target === terminalEditDialog) terminalEditDialog.close();
});

</script>
<?php page_end(); ?>
