<?php

declare(strict_types=1);

namespace Cpdc\Http\Middleware;

use PDO;

/**
 * PHP session storage in the php_session table, so sessions survive a restart of a host without a lasting disk
 * (Render's free plan). Session rules (idle and absolute expiry, user checks) stay in Session; this only stores.
 * There is no row lock: two requests of one person at the same moment write the last one's data, which for this
 * app is only a newer "seen_at".
 */
final class DbSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /** @param \Closure(): int $clock unix time */
    public function __construct(private readonly PDO $pdo, private readonly \Closure $clock)
    {
    }

    public function has(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM php_session WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT data FROM php_session WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetchColumn();

        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        // REPLACE works the same on MySQL and MariaDB (Exabytes).
        $this->pdo->prepare('REPLACE INTO php_session (id, data, updated_at) VALUES (?, ?, ?)')
            ->execute([$id, $data, ($this->clock)()]);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->pdo->prepare('DELETE FROM php_session WHERE id = ?')->execute([$id]);

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM php_session WHERE updated_at < ?');
        $stmt->execute([($this->clock)() - $max_lifetime]);

        return $stmt->rowCount();
    }

    /** Strict mode: PHP accepts only an id that is already stored, and otherwise makes a new one. */
    public function validateId(string $id): bool
    {
        return $this->has($id);
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->pdo->prepare('UPDATE php_session SET updated_at = ? WHERE id = ?')->execute([($this->clock)(), $id]);

        return true;
    }
}
