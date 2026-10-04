<?php
declare(strict_types=1);

final class Db
{
    private const SCHEMA_VERSION = 1;

    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect((string) config('db_path'));
        }
        return self::$pdo;
    }

    public static function connect(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // 複数端末からの同時書き込みに備えて WAL + busy_timeout
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::migrate($pdo);
        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // 別プロセスが先に移行した場合に備えて再確認
            $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            if ($version < 1) {
                $pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
            }
            // 将来のマイグレーションはここに追加: if ($version < 2) { ... }
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return mixed */
    public static function value(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
