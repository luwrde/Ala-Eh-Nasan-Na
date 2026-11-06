<?php
declare(strict_types=1);

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $query = db()->prepare(
            'SELECT session_data FROM app_sessions WHERE session_id = ? AND expires_at > ? LIMIT 1'
        );
        $query->execute([$id, time()]);
        $data = $query->fetchColumn();

        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        $expiresAt = time() + max(1, (int) ini_get('session.gc_maxlifetime'));
        $save = db()->prepare(
            'INSERT INTO app_sessions (session_id, session_data, expires_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE session_data = VALUES(session_data), expires_at = VALUES(expires_at)'
        );
        $save->bindValue(1, $id, PDO::PARAM_STR);
        $save->bindValue(2, $data, PDO::PARAM_LOB);
        $save->bindValue(3, $expiresAt, PDO::PARAM_INT);

        return $save->execute();
    }

    public function destroy(string $id): bool
    {
        $delete = db()->prepare('DELETE FROM app_sessions WHERE session_id = ?');

        return $delete->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $delete = db()->prepare('DELETE FROM app_sessions WHERE expires_at <= ?');
        if (!$delete->execute([time()])) {
            return false;
        }

        return $delete->rowCount();
    }

    public function validateId(string $id): bool
    {
        $query = db()->prepare(
            'SELECT 1 FROM app_sessions WHERE session_id = ? AND expires_at > ? LIMIT 1'
        );
        $query->execute([$id, time()]);

        return $query->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $expiresAt = time() + max(1, (int) ini_get('session.gc_maxlifetime'));
        $update = db()->prepare(
            'UPDATE app_sessions SET expires_at = ? WHERE session_id = ? AND expires_at > ?'
        );
        $update->execute([$expiresAt, $id, time()]);

        return $update->rowCount() > 0 || $this->write($id, $data);
    }
}
