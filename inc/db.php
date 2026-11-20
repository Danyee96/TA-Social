<?php
/**
 * Thin PDO wrapper. Works with MySQL/MariaDB (Hostinger) and SQLite.
 */
declare(strict_types=1);

function db_connect(array $c): PDO
{
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['sqlite_path'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'] ?? 'localhost',
        (int)($c['port'] ?? 3306),
        $c['name'] ?? ''
    );
    $pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', $opts);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect(cfg('db', []));
    }
    return $pdo;
}

function db_driver(): string
{
    return cfg('db.driver', 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
}

function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->rowCount();
}

function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function db_val(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    db_exec($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
    return db_exec("UPDATE $table SET $set WHERE $where", array_merge(array_values($data), $whereParams));
}

function db_tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Builds "?,?,?" for IN() lists. */
function db_in(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}
