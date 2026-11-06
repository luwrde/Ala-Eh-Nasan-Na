<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/admin-auth.php';

function commuter_account(): ?array
{
    return isset($_SESSION['commuter']) && is_array($_SESSION['commuter']) ? $_SESSION['commuter'] : null;
}

function commuter_account_input(mixed $input): array
{
    if (!is_array($input)) throw new InvalidArgumentException('Enter your name, email, and password.');
    $name = commuter_name_input($input['full_name'] ?? '');
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    if ($name === '' || strlen($name) > 120) throw new InvalidArgumentException('Enter a valid full name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) throw new InvalidArgumentException('Enter a valid email address.');
    if (strlen($password) < 8 || strlen($password) > 72) throw new InvalidArgumentException('Password must be 8 to 72 characters.');
    return [$name, $email, $password];
}

function commuter_name_input(mixed $value): string
{
    $name = trim((string) $value);
    if ($name === '' || strlen($name) > 120) throw new InvalidArgumentException('Enter a valid full name.');
    return $name;
}

function register_commuter_account(array $input): array
{
    [$name, $email, $password] = commuter_account_input($input);
    try {
        $query = db()->prepare('INSERT INTO commuter_accounts (full_name, email, password_hash) VALUES (?, ?, ?)');
        $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        return [201, 'Account created. You can now sign in.'];
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000') return [409, 'An account with that email already exists.'];
        throw $error;
    }
}

function login_commuter_account(array $input): array
{
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') return [401, 'Invalid email or password.'];
    $pdo = db();
    $keys = login_bucket_keys($email, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if (!begin_login_attempt($pdo, $keys)) return [429, 'Too many sign-in attempts. Please wait 15 minutes before trying again.'];
    if (authenticate_admin_account($pdo, $email, $password)) {
        clear_login_attempts($pdo, $keys);
        return [200, 'Signed in.', 'admin'];
    }
    $query = $pdo->prepare("SELECT id, full_name, email, password_hash FROM commuter_accounts WHERE email = ? AND account_status = 'active' LIMIT 1");
    $query->execute([$email]);
    $account = $query->fetch();
    if (!$account || !password_verify($password, (string) $account['password_hash'])) return [401, 'Invalid email or password.'];
    clear_login_attempts($pdo, $keys);
    session_regenerate_id(true);
    unset($_SESSION['admin'], $_SESSION['driver'], $_SESSION['csrf_token']);
    $_SESSION['commuter'] = ['id' => (int) $account['id'], 'full_name' => $account['full_name'], 'email' => $account['email']];
    record_commuter_activity($pdo);
    return [200, 'Signed in.', 'commuter'];
}

function record_commuter_activity(?PDO $connection = null): bool
{
    $account = commuter_account();
    if ($account === null) return false;
    $pdo = $connection ?? db();
    $query = $pdo->prepare(
        "UPDATE commuter_accounts
         SET last_activity_at = CURRENT_TIMESTAMP
         WHERE id = ? AND account_status = 'active'"
    );
    $query->execute([(int) $account['id']]);
    if ($query->rowCount() === 1) return true;
    $access = $pdo->prepare("SELECT account_status FROM commuter_accounts WHERE id = ? AND account_status = 'active'");
    $access->execute([(int) $account['id']]);
    return $access->fetchColumn() === 'active';
}

function logout_commuter_account(): void
{
    unset($_SESSION['commuter']);
}

function update_commuter_account_name(mixed $input): array
{
    $account = commuter_account();
    if ($account === null) return [401, 'Please sign in again.'];
    $name = commuter_name_input($input);
    $query = db()->prepare('UPDATE commuter_accounts SET full_name = ? WHERE id = ?');
    $query->execute([$name, (int) $account['id']]);
    $_SESSION['commuter']['full_name'] = $name;
    return [200, 'Profile name updated.', $_SESSION['commuter']];
}

/** Validate a password change before updating the authenticated commuter only. */
function commuter_password_change_error(mixed $current, mixed $next, mixed $confirm, string $storedHash): ?string
{
    if (!is_string($current) || !is_string($next) || !is_string($confirm) || $current === '') {
        return 'Enter your current and new password.';
    }
    if (strlen($next) < 12 || strlen($next) > 72) return 'Use a new password between 12 and 72 bytes.';
    if (!hash_equals($next, $confirm)) return 'The new passwords do not match.';
    if (!password_verify($current, $storedHash)) return 'Current password is incorrect.';
    if (password_verify($next, $storedHash)) return 'Choose a password different from your current one.';
    return null;
}

function change_commuter_password(array $input, ?PDO $connection = null): array
{
    $account = commuter_account();
    if ($account === null) return [401, 'Please sign in again.'];
    $pdo = $connection ?? db();
    $query = $pdo->prepare('SELECT password_hash FROM commuter_accounts WHERE id = ? LIMIT 1');
    $query->execute([(int) $account['id']]);
    $storedHash = $query->fetchColumn();
    if (!is_string($storedHash)) return [401, 'Please sign in again.'];
    $error = commuter_password_change_error($input['current_password'] ?? null,
        $input['new_password'] ?? null, $input['confirm_password'] ?? null, $storedHash);
    if ($error !== null) return [422, $error];
    $update = $pdo->prepare('UPDATE commuter_accounts SET password_hash = ? WHERE id = ? AND password_hash = ?');
    $update->execute([password_hash($input['new_password'], PASSWORD_DEFAULT), (int) $account['id'], $storedHash]);
    if ($update->rowCount() !== 1) return [409, 'Your password changed in another session. Reload and try again.'];
    session_regenerate_id(true);
    return [200, 'Your password was changed successfully.'];
}
