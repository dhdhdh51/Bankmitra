<?php

declare(strict_types=1);

namespace App\Core;

use Lib\Logger;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin PDO wrapper. Every query goes through prepared statements - there is
 * no string interpolation of user input anywhere in this application.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = (string) Config::get('db.host', 'localhost');
        $port    = (int) Config::get('db.port', 3306);
        $name    = (string) Config::get('db.name', '');
        $user    = (string) Config::get('db.user', '');
        $pass    = (string) Config::get('db.pass', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');
        $socket  = (string) Config::get('db.unix_socket', '');

        // A few cPanel hosts expose MySQL only over a unix socket rather than
        // TCP on localhost. Setting db.unix_socket in config.php switches to it.
        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $name, $charset)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Predictable behaviour regardless of what the host configured.
            self::$pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            // Do NOT expose credentials or the DSN to the browser. The driver
            // message (which names the DB user) goes to the log only.
            Logger::error('Database connection failed: ' . $e->getMessage());
            throw new SetupException(
                'LRMS could not connect to the MySQL database. The credentials in '
                . 'config/config.php do not match what cPanel has.',
                'Cannot connect to the database',
                [
                    'In cPanel > MySQL Databases, confirm the database name, the '
                        . 'user name and that the user is assigned to the database '
                        . 'with ALL PRIVILEGES.',
                    'cPanel prefixes both names with your account, e.g. '
                        . 'cpuser_lrms - use the full prefixed names in config.php.',
                    'Re-type the password in config/config.php rather than pasting '
                        . 'it, in case a stray space crept in.',
                    'Still refused? A few hosts only expose MySQL over a unix '
                        . 'socket. Ask support for the path and set '
                        . "'unix_socket' => '/var/lib/mysql/mysql.sock' inside the "
                        . "'db' array.",
                    'The exact driver error is in storage/logs/app-'
                        . date('Y-m-d') . '.log.',
                ],
                $e
            );
        }

        return self::$pdo;
    }

    /**
     * Turn "table doesn't exist" into an actionable setup message.
     *
     * The database name is taken from our own config, not from the driver
     * message, and no credentials are included - that is what makes this safe
     * to show in production.
     */
    private static function schemaMissing(PDOException $e): SetupException
    {
        $dbName = (string) Config::get('db.name', '');

        return new SetupException(
            'LRMS connected to the database'
            . ($dbName === '' ? '' : ' "' . $dbName . '"')
            . ' successfully, but the tables are not there. The schema has not '
            . 'been imported yet, so there is nothing for LRMS to read or write.',
            'Database schema has not been imported',
            [
                'Open cPanel > phpMyAdmin and select the database'
                    . ($dbName === '' ? '.' : ' "' . $dbName . '".'),
                'Go to the Import tab, choose the file database/schema.sql from '
                    . 'this installation, and click Import.',
                'You should end up with 28 tables plus 2 views. Re-importing is '
                    . 'safe - every statement uses IF NOT EXISTS / INSERT IGNORE.',
                'Reload this page. Then sign in with ADMIN001 and the default '
                    . 'password, and change it immediately.',
            ],
            $e
        );
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            Logger::error('Query failed: ' . $e->getMessage(), [
                'sql' => $sql,
                // Parameter values may contain PII, so log only their shape.
                'param_keys' => array_keys($params),
            ]);

            // SQLSTATE 42S02 = base table or view not found. On a fresh install
            // this means database/schema.sql was never imported. Say so plainly
            // instead of letting a raw PDOException become an opaque 500.
            if ($e->getCode() === '42S02') {
                throw self::schemaMissing($e);
            }

            throw $e;
        }
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = self::run($sql, $params)->fetchAll();
        return $rows;
    }

    /** @param array<string|int,mixed> $params */
    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $cols) . '`',
            ':' . implode(', :', $cols)
        );
        self::run($sql, self::bindable($data));
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE. Returns the affected row count
     * (1 = inserted, 2 = updated in MySQL semantics).
     *
     * @param array<string,mixed> $data
     * @param list<string>        $updateColumns columns to refresh on conflict
     */
    public static function upsert(string $table, array $data, array $updateColumns): int
    {
        $cols = array_keys($data);
        $assignments = [];
        foreach ($updateColumns as $col) {
            $assignments[] = sprintf('`%s` = VALUES(`%s`)', $col, $col);
        }
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $table,
            '`' . implode('`, `', $cols) . '`',
            ':' . implode(', :', $cols),
            implode(', ', $assignments)
        );
        return self::run($sql, self::bindable($data))->rowCount();
    }

    /**
     * @param array<string,mixed>     $data
     * @param array<string,mixed>     $where simple equality conditions
     */
    public static function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            throw new RuntimeException('Database::update needs both data and a where clause.');
        }

        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = sprintf('`%s` = :set_%s', $col, $col);
        }
        $conds = [];
        foreach (array_keys($where) as $col) {
            $conds[] = sprintf('`%s` = :where_%s', $col, $col);
        }

        $params = [];
        foreach (self::bindable($data) as $k => $v) {
            $params['set_' . ltrim($k, ':')] = $v;
        }
        foreach (self::bindable($where) as $k => $v) {
            $params['where_' . ltrim($k, ':')] = $v;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), implode(' AND ', $conds));
        return self::run($sql, $params)->rowCount();
    }

    /** @param array<string,mixed> $where */
    public static function delete(string $table, array $where): int
    {
        $conds = [];
        foreach (array_keys($where) as $col) {
            $conds[] = sprintf('`%s` = :%s', $col, $col);
        }
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, implode(' AND ', $conds));
        return self::run($sql, self::bindable($where))->rowCount();
    }

    public static function begin(): void
    {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    /** Wrap a closure in a transaction, rolling back on any exception. */
    public static function transaction(callable $fn): mixed
    {
        self::begin();
        try {
            $result = $fn();
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    public static function tableExists(string $table): bool
    {
        $row = self::first(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table]
        );
        return $row !== null;
    }

    /**
     * Booleans must be sent as int, and DateTimeInterface as a string,
     * otherwise MySQL in STRICT mode complains.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function bindable(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 1 : 0;
            } elseif ($v instanceof \DateTimeInterface) {
                $v = $v->format('Y-m-d H:i:s');
            } elseif (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
