<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $driverId = filter_var($_POST['driver_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $newStatus = match ($action) {
        'approve' => 'verified',
        'reject' => 'rejected',
        default => null,
    };

    if ($driverId === false || $driverId === null || $driverId < 1 || $newStatus === null) {
        http_response_code(400);
        exit('Invalid application action.');
    }

    $update = db()->prepare(
        "UPDATE drivers
         SET verification_status = :status
         WHERE id = :id AND verification_status = 'pending_verification'"
    );
    $update->execute(['status' => $newStatus, 'id' => $driverId]);
    $_SESSION['admin_flash'] = $update->rowCount() === 1
        ? 'Application ' . ($newStatus === 'verified' ? 'approved' : 'rejected') . ' successfully.'
        : 'This application was already reviewed or could not be found.';
    redirect('admin-dashboard.php');
}

$allowedFilters = ['pending_verification', 'verified', 'rejected', 'all'];
$filter = $_GET['status'] ?? 'pending_verification';
if (!is_string($filter) || !in_array($filter, $allowedFilters, true)) {
    $filter = 'pending_verification';
}

$pdo = db();
$counts = $pdo->query(
    "SELECT COUNT(*) AS total,
        COALESCE(SUM(verification_status = 'pending_verification'), 0) AS pending,
        COALESCE(SUM(verification_status = 'verified'), 0) AS verified,
        COALESCE(SUM(verification_status = 'rejected'), 0) AS rejected
     FROM drivers"
)->fetch();

if ($filter === 'all') {
    $drivers = $pdo->query(
        'SELECT id, full_name, email, phone, license_number, verification_status, created_at
         FROM drivers ORDER BY created_at DESC LIMIT 100'
    )->fetchAll();
} else {
    $query = $pdo->prepare(
        'SELECT id, full_name, email, phone, license_number, verification_status, created_at
         FROM drivers WHERE verification_status = :status
         ORDER BY created_at DESC LIMIT 100'
    );
    $query->execute(['status' => $filter]);
    $drivers = $query->fetchAll();
}

$flash = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);

page_start('Admin dashboard', 'admin-dashboard');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading">
    <div>
      <span class="eyebrow"><span class="eyebrow-dot"></span> ADMINISTRATION</span>
      <h1>Driver applications</h1>
      <p>Welcome, <?= h((string) $admin['email']) ?>. Review and manage driver verification.</p>
    </div>
    <span class="admin-access-badge"><span aria-hidden="true">✓</span> Admin access</span>
  </div>

  <div class="admin-stats" aria-label="Application statistics">
    <a class="admin-stat" href="admin-dashboard.php?status=all">
      <span class="admin-stat-icon">◎</span><span class="admin-stat-label">Total applications</span>
      <strong><?= h((string) $counts['total']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-pending" href="admin-dashboard.php?status=pending_verification">
      <span class="admin-stat-icon">◷</span><span class="admin-stat-label">Awaiting review</span>
      <strong><?= h((string) $counts['pending']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-verified" href="admin-dashboard.php?status=verified">
      <span class="admin-stat-icon">✓</span><span class="admin-stat-label">Approved</span>
      <strong><?= h((string) $counts['verified']) ?></strong>
    </a>
    <a class="admin-stat admin-stat-rejected" href="admin-dashboard.php?status=rejected">
      <span class="admin-stat-icon">×</span><span class="admin-stat-label">Rejected</span>
      <strong><?= h((string) $counts['rejected']) ?></strong>
    </a>
  </div>

  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><span aria-hidden="true">✓</span> <?= h((string) $flash) ?></p><?php endif; ?>

  <section class="admin-queue">
    <div class="admin-queue-heading">
      <div>
        <h2><?= $filter === 'pending_verification' ? 'Applications awaiting review' : 'Driver applications' ?></h2>
        <p>Review driver details before approving or rejecting an application.</p>
      </div>
      <nav class="admin-filters" aria-label="Filter applications">
        <a class="<?= $filter === 'pending_verification' ? 'is-active' : '' ?>" href="admin-dashboard.php?status=pending_verification">Pending</a>
        <a class="<?= $filter === 'verified' ? 'is-active' : '' ?>" href="admin-dashboard.php?status=verified">Approved</a>
        <a class="<?= $filter === 'rejected' ? 'is-active' : '' ?>" href="admin-dashboard.php?status=rejected">Rejected</a>
        <a class="<?= $filter === 'all' ? 'is-active' : '' ?>" href="admin-dashboard.php?status=all">All</a>
      </nav>
    </div>

    <?php if ($drivers === []): ?>
      <div class="admin-empty">
        <span aria-hidden="true">✓</span>
        <h3><?= $filter === 'pending_verification' ? 'You’re all caught up' : 'No applications here yet' ?></h3>
        <p><?= $filter === 'pending_verification' ? 'New driver applications awaiting review will appear here.' : 'Try another status filter to see more applications.' ?></p>
      </div>
    <?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Driver</th><th>Contact</th><th>License number</th><th>Submitted</th><th>Status</th><?php if ($filter === 'pending_verification' || $filter === 'all'): ?><th>Action</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($drivers as $driver): ?>
            <tr>
              <td data-label="Driver"><strong><?= h((string) $driver['full_name']) ?></strong></td>
              <td data-label="Contact"><a href="mailto:<?= h((string) $driver['email']) ?>"><?= h((string) $driver['email']) ?></a><small><?= h((string) $driver['phone']) ?></small></td>
              <td data-label="License number"><?= h((string) $driver['license_number']) ?></td>
              <td data-label="Submitted"><?= h(date('M j, Y', strtotime((string) $driver['created_at']))) ?></td>
              <td data-label="Status"><span class="admin-status status-<?= h((string) $driver['verification_status']) ?>"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span></td>
              <?php if ($filter === 'pending_verification' || $filter === 'all'): ?>
              <td data-label="Action">
                <?php if ($driver['verification_status'] === 'pending_verification'): ?>
                  <div class="admin-actions">
                    <form method="post" action="admin-dashboard.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="driver_id" value="<?= h((string) $driver['id']) ?>">
                      <button class="button button-approve" type="submit" name="action" value="approve">Approve</button>
                    </form>
                    <form method="post" action="admin-dashboard.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="driver_id" value="<?= h((string) $driver['id']) ?>">
                      <button class="button button-reject" type="submit" name="action" value="reject">Reject</button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="admin-no-action">Reviewed</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($drivers) === 100): ?><p class="admin-limit-note">Showing the 100 most recent applications.</p><?php endif; ?>
    <?php endif; ?>
  </section>
</section>
<?php page_end(); ?>
