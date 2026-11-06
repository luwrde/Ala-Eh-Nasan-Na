<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function migration_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function migration_rows(mixed $value, array $fields): array
{
    if (!is_array($value) || count($value) > 1000) {
        migration_error(400, 'Invalid account migration data.');
    }

    foreach ($value as $row) {
        if (!is_array($row)) {
            migration_error(400, 'Invalid account migration data.');
        }
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                migration_error(400, 'Invalid account migration data.');
            }
        }
    }
    return $value;
}

function account_id(PDO $pdo, string $table, array $row, array &$idMap, array &$counts): void
{
    $email = strtolower(trim((string) $row['email']));
    $hash = (string) $row['password_hash'];
    $sourceId = (int) $row['id'];
    if ($sourceId < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($hash) < 20) {
        migration_error(400, 'Invalid account migration data.');
    }

    $lookup = $pdo->prepare("SELECT id, password_hash FROM {$table} WHERE email = ? LIMIT 1");
    $lookup->execute([$email]);
    $existing = $lookup->fetch();
    if ($existing !== false) {
        if (!hash_equals((string) $existing['password_hash'], $hash)) {
            throw new DomainException('A production account conflicts with a local account.');
        }
        $idMap[$sourceId] = (int) $existing['id'];
        $counts['existing']++;
        return;
    }

    $idLookup = $pdo->prepare("SELECT email FROM {$table} WHERE id = ? LIMIT 1");
    $idLookup->execute([$sourceId]);
    $idCollision = $idLookup->fetchColumn() !== false;
    $values = match ($table) {
        'admins' => [
            'email' => $email,
            'password_hash' => $hash,
            'created_at' => $row['created_at'],
        ],
        'drivers' => [
            'full_name' => $row['full_name'],
            'email' => $email,
            'phone' => $row['phone'],
            'license_number' => $row['license_number'],
            'password_hash' => $hash,
            'verification_status' => $row['verification_status'],
            'created_at' => $row['created_at'],
        ],
        'commuter_accounts' => [
            'full_name' => $row['full_name'],
            'email' => $email,
            'password_hash' => $hash,
            'account_status' => $row['account_status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'phone' => $row['phone'],
        ],
    };

    if (!$idCollision) {
        $values = ['id' => $sourceId] + $values;
    }
    $columns = array_keys($values);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnSql = implode(', ', $columns);
    $insert = $pdo->prepare("INSERT INTO {$table} ({$columnSql}) VALUES ({$placeholders})");
    $insert->execute(array_values($values));
    $idMap[$sourceId] = $idCollision ? (int) $pdo->lastInsertId() : $sourceId;
    $counts['imported']++;
}

function migrate_related_rows(PDO $pdo, array $payload, array $adminIds, array $driverIds): void
{
    foreach ($payload['admin_roles'] as $row) {
        $adminId = $adminIds[(int) $row['admin_id']] ?? null;
        if ($adminId === null || !in_array($row['role'], ['owner', 'admin', 'viewer'], true)
            || !in_array($row['account_status'], ['active', 'deactivated'], true)) {
            migration_error(400, 'Invalid administrator role data.');
        }
        $createdBy = $row['created_by'] === null ? null : ($adminIds[(int) $row['created_by']] ?? null);
        $lookup = $pdo->prepare('SELECT role, account_status FROM admin_roles WHERE admin_id = ?');
        $lookup->execute([$adminId]);
        $existing = $lookup->fetch();
        if ($existing !== false) {
            if ($existing['role'] !== $row['role'] || $existing['account_status'] !== $row['account_status']) {
                throw new DomainException('A production administrator role conflicts with a local role.');
            }
            continue;
        }
        $insert = $pdo->prepare(
            'INSERT INTO admin_roles (admin_id, role, account_status, created_by, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$adminId, $row['role'], $row['account_status'], $createdBy, $row['created_at']]);
    }

    foreach ($payload['driver_admin_status'] as $row) {
        $driverId = $driverIds[(int) $row['driver_id']] ?? null;
        if ($driverId === null || !in_array($row['account_status'], ['active', 'suspended', 'deactivated'], true)) {
            migration_error(400, 'Invalid driver account status data.');
        }
        $lookup = $pdo->prepare('SELECT account_status FROM driver_admin_status WHERE driver_id = ?');
        $lookup->execute([$driverId]);
        $existing = $lookup->fetchColumn();
        if ($existing !== false) {
            if ($existing !== $row['account_status']) {
                throw new DomainException('A production driver status conflicts with a local status.');
            }
            continue;
        }
        $updatedBy = $row['updated_by'] === null ? null : ($adminIds[(int) $row['updated_by']] ?? null);
        $insert = $pdo->prepare(
            'INSERT INTO driver_admin_status (driver_id, account_status, updated_by)
             VALUES (?, ?, ?)'
        );
        $insert->execute([$driverId, $row['account_status'], $updatedBy]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    migration_error(405, 'POST is required.');
}

$expectedToken = getenv('ACCOUNT_MIGRATION_TOKEN');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!is_string($expectedToken) || strlen($expectedToken) < 32
    || !str_starts_with($authorization, 'Bearer ')
    || !hash_equals($expectedToken, substr($authorization, 7))) {
    migration_error(403, 'Migration is not authorized.');
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 1_000_000) {
    migration_error(413, 'Migration payload is too large.');
}
try {
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    migration_error(400, 'Invalid account migration data.');
}
if (!is_array($payload)) {
    migration_error(400, 'Invalid account migration data.');
}

$payload['admins'] = migration_rows($payload['admins'] ?? null, ['id', 'email', 'password_hash', 'created_at']);
$payload['admin_roles'] = migration_rows(
    $payload['admin_roles'] ?? null,
    ['admin_id', 'role', 'account_status', 'created_by', 'created_at']
);
$payload['drivers'] = migration_rows(
    $payload['drivers'] ?? null,
    ['id', 'full_name', 'email', 'phone', 'license_number', 'password_hash', 'verification_status', 'created_at']
);
$payload['driver_admin_status'] = migration_rows(
    $payload['driver_admin_status'] ?? null,
    ['driver_id', 'account_status', 'updated_by']
);
$payload['commuter_accounts'] = migration_rows(
    $payload['commuter_accounts'] ?? null,
    ['id', 'full_name', 'email', 'password_hash', 'account_status', 'created_at', 'updated_at', 'phone']
);

$sourceAdminIds = [];
foreach ($payload['admins'] as $row) {
    if ((int) $row['id'] < 1 || !filter_var(strtolower(trim((string) $row['email'])), FILTER_VALIDATE_EMAIL)
        || strlen((string) $row['password_hash']) < 20) {
        migration_error(400, 'Invalid administrator account data.');
    }
    $sourceAdminIds[(int) $row['id']] = true;
}
$sourceDriverIds = [];
foreach ($payload['drivers'] as $row) {
    if ((int) $row['id'] < 1 || !filter_var(strtolower(trim((string) $row['email'])), FILTER_VALIDATE_EMAIL)
        || strlen((string) $row['password_hash']) < 20
        || !in_array($row['verification_status'], ['pending_verification', 'verified', 'rejected'], true)) {
        migration_error(400, 'Invalid driver account data.');
    }
    $sourceDriverIds[(int) $row['id']] = true;
}
foreach ($payload['commuter_accounts'] as $row) {
    if ((int) $row['id'] < 1 || !filter_var(strtolower(trim((string) $row['email'])), FILTER_VALIDATE_EMAIL)
        || strlen((string) $row['password_hash']) < 20
        || !in_array($row['account_status'], ['active', 'suspended', 'deactivated'], true)) {
        migration_error(400, 'Invalid passenger account data.');
    }
}
foreach ($payload['admin_roles'] as $row) {
    if (!isset($sourceAdminIds[(int) $row['admin_id']])
        || !in_array($row['role'], ['owner', 'admin', 'viewer'], true)
        || !in_array($row['account_status'], ['active', 'deactivated'], true)
        || ($row['created_by'] !== null && !isset($sourceAdminIds[(int) $row['created_by']]))) {
        migration_error(400, 'Invalid administrator role data.');
    }
}
foreach ($payload['driver_admin_status'] as $row) {
    if (!isset($sourceDriverIds[(int) $row['driver_id']])
        || !in_array($row['account_status'], ['active', 'suspended', 'deactivated'], true)
        || ($row['updated_by'] !== null && !isset($sourceAdminIds[(int) $row['updated_by']]))) {
        migration_error(400, 'Invalid driver account status data.');
    }
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    $lockKey = 'one_time_local_account_import_completed';
    $lock = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? FOR UPDATE');
    $lock->execute([$lockKey]);
    if ($lock->fetchColumn() !== false) {
        $pdo->rollBack();
        migration_error(409, 'The one-time account migration has already been completed.');
    }
    $reserve = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
    $reserve->execute([$lockKey, 'running']);

    $counts = ['imported' => 0, 'existing' => 0];
    $adminIds = [];
    foreach ($payload['admins'] as $row) {
        account_id($pdo, 'admins', $row, $adminIds, $counts);
    }
    $driverIds = [];
    foreach ($payload['drivers'] as $row) {
        account_id($pdo, 'drivers', $row, $driverIds, $counts);
    }
    $commuterIds = [];
    foreach ($payload['commuter_accounts'] as $row) {
        account_id($pdo, 'commuter_accounts', $row, $commuterIds, $counts);
    }
    migrate_related_rows($pdo, $payload, $adminIds, $driverIds);
    $complete = $pdo->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
    $complete->execute([gmdate('c'), $lockKey]);
    $pdo->commit();
    echo json_encode(['imported' => $counts['imported'], 'existing' => $counts['existing']]);
} catch (DomainException $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    migration_error(409, $error->getMessage());
} catch (PDOException $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('One-time account migration failed: ' . $error->getCode());
    migration_error(500, 'The account migration failed and no changes were committed.');
}
