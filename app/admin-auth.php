<?php
declare(strict_types=1);

/** Called only after the portal has checked its shared login rate limit. */
function authenticate_admin_account(PDO $pdo, string $email, string $password): bool
{
    $query = $pdo->prepare("SELECT a.id, a.email, a.password_hash, r.role
        FROM admins a INNER JOIN admin_roles r ON r.admin_id = a.id
        WHERE a.email = :email AND r.account_status = 'active' LIMIT 1");
    $query->execute(['email' => $email]);
    $account = $query->fetch();
    if (!$account || !password_verify($password, (string) $account['password_hash'])) return false;
    if (password_needs_rehash((string) $account['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([
            password_hash($password, PASSWORD_DEFAULT), (int) $account['id'],
        ]);
    }
    session_regenerate_id(true);
    unset($_SESSION['driver'], $_SESSION['commuter'], $_SESSION['csrf_token']);
    $_SESSION['admin'] = ['id' => (int) $account['id'], 'email' => $account['email'], 'role' => $account['role']];
    return true;
}
